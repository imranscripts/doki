<?php
/**
 * FastCGI Client
 * 
 * A simple FastCGI client for proxying PHP requests to FPM containers.
 * Based on the FastCGI protocol specification.
 */

class FastCGIClient {
    private string $host;
    private int $port;
    private int $timeout;
    private $socket = null;
    
    // FastCGI constants
    private const FCGI_VERSION_1 = 1;
    private const FCGI_BEGIN_REQUEST = 1;
    private const FCGI_ABORT_REQUEST = 2;
    private const FCGI_END_REQUEST = 3;
    private const FCGI_PARAMS = 4;
    private const FCGI_STDIN = 5;
    private const FCGI_STDOUT = 6;
    private const FCGI_STDERR = 7;
    private const FCGI_RESPONDER = 1;
    
    public function __construct(string $host, int $port = 9000, int $timeout = 30) {
        $this->host = $host;
        $this->port = $port;
        $this->timeout = $timeout;
    }
    
    /**
     * Execute a PHP script via FastCGI
     */
    public function execute(string $scriptPath, array $params = [], string $stdin = ''): array {
        // Connect to FPM
        $this->socket = @fsockopen($this->host, $this->port, $errno, $errstr, $this->timeout);
        
        if (!$this->socket) {
            return [
                'success' => false,
                'error' => "Failed to connect to FPM: {$errstr} ({$errno})",
                'stdout' => '',
                'stderr' => ''
            ];
        }
        
        stream_set_timeout($this->socket, $this->timeout);
        
        $requestId = 1;
        
        // Send BEGIN_REQUEST (8 bytes: role[2] + flags[1] + reserved[5])
        $this->sendPacket(self::FCGI_BEGIN_REQUEST, $requestId, 
            pack('nCCCCCC', self::FCGI_RESPONDER, 0, 0, 0, 0, 0, 0));
        
        // Build and send PARAMS
        $paramsData = '';
        foreach ($params as $name => $value) {
            $paramsData .= $this->encodeNameValue($name, $value);
        }
        $this->sendPacket(self::FCGI_PARAMS, $requestId, $paramsData);
        $this->sendPacket(self::FCGI_PARAMS, $requestId, ''); // Empty to signal end
        
        // Send STDIN (request body)
        if (!empty($stdin)) {
            // Send in chunks of 65535 bytes max
            $chunks = str_split($stdin, 65535);
            foreach ($chunks as $chunk) {
                $this->sendPacket(self::FCGI_STDIN, $requestId, $chunk);
            }
        }
        $this->sendPacket(self::FCGI_STDIN, $requestId, ''); // Empty to signal end
        
        // Read response
        $stdout = '';
        $stderr = '';
        $appStatus = 0;
        $packetCount = 0;
        $maxPackets = 10000; // Safety limit
        
        while ($packetCount < $maxPackets) {
            $packet = $this->readPacket();
            
            if ($packet === false) {
                error_log("FastCGIClient: readPacket returned false at packet #{$packetCount}");
                break;
            }
            
            $packetCount++;
            
            switch ($packet['type']) {
                case self::FCGI_STDOUT:
                    $stdout .= $packet['content'];
                    break;
                case self::FCGI_STDERR:
                    $stderr .= $packet['content'];
                    break;
                case self::FCGI_END_REQUEST:
                    $appStatus = unpack('Nstatus', substr($packet['content'], 0, 4))['status'] ?? 0;
                    error_log("FastCGIClient: Received END_REQUEST after {$packetCount} packets, stdout length: " . strlen($stdout));
                    break 2; // Exit while loop
            }
        }
        
        if ($packetCount >= $maxPackets) {
            error_log("FastCGIClient: WARNING - Hit max packet limit ({$maxPackets}), response may be incomplete");
        }
        
        fclose($this->socket);
        $this->socket = null;
        
        return [
            'success' => true,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'appStatus' => $appStatus
        ];
    }
    
    /**
     * Build FastCGI params for a web request
     */
    public static function buildWebParams(string $scriptFilename, array $serverVars = []): array {
        $defaults = [
            'GATEWAY_INTERFACE' => 'FastCGI/1.0',
            'SERVER_SOFTWARE' => 'Doki/1.0',
            'SERVER_PROTOCOL' => $_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1',
            'SERVER_NAME' => $_SERVER['SERVER_NAME'] ?? 'localhost',
            'SERVER_PORT' => $_SERVER['SERVER_PORT'] ?? '80',
            'SERVER_ADDR' => $_SERVER['SERVER_ADDR'] ?? '127.0.0.1',
            'REQUEST_METHOD' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
            'REQUEST_URI' => $_SERVER['REQUEST_URI'] ?? '/',
            'QUERY_STRING' => $_SERVER['QUERY_STRING'] ?? '',
            'CONTENT_TYPE' => $_SERVER['CONTENT_TYPE'] ?? '',
            'CONTENT_LENGTH' => $_SERVER['CONTENT_LENGTH'] ?? '0',
            'SCRIPT_FILENAME' => $scriptFilename,
            'SCRIPT_NAME' => $scriptFilename,
            'DOCUMENT_ROOT' => '/var/www/html',
            'REMOTE_ADDR' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'REMOTE_PORT' => $_SERVER['REMOTE_PORT'] ?? '0',
        ];
        
        // Add HTTP headers
        foreach ($_SERVER as $key => $value) {
            if (strpos($key, 'HTTP_') === 0) {
                $defaults[$key] = $value;
            }
        }
        
        return array_merge($defaults, $serverVars);
    }
    
    /**
     * Send a FastCGI packet
     */
    private function sendPacket(int $type, int $requestId, string $content): void {
        $contentLength = strlen($content);
        $paddingLength = (8 - ($contentLength % 8)) % 8;
        
        $header = pack('CCnnCC',
            self::FCGI_VERSION_1,
            $type,
            $requestId,
            $contentLength,
            $paddingLength,
            0 // reserved
        );
        
        fwrite($this->socket, $header . $content . str_repeat("\0", $paddingLength));
    }
    
    /**
     * Read a FastCGI packet
     */
    private function readPacket(): array|false {
        // Read header (8 bytes)
        $header = '';
        $bytesRead = 0;
        while ($bytesRead < 8) {
            $chunk = fread($this->socket, 8 - $bytesRead);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($this->socket);
                if ($meta['timed_out']) {
                    error_log("FastCGIClient: Socket timeout while reading header");
                }
                return false;
            }
            $header .= $chunk;
            $bytesRead += strlen($chunk);
        }
        
        $data = unpack('Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved', $header);
        
        // Read content
        $content = '';
        if ($data['contentLength'] > 0) {
            $bytesRead = 0;
            while ($bytesRead < $data['contentLength']) {
                $chunk = fread($this->socket, $data['contentLength'] - $bytesRead);
                if ($chunk === false || $chunk === '') {
                    $meta = stream_get_meta_data($this->socket);
                    if ($meta['timed_out']) {
                        error_log("FastCGIClient: Socket timeout while reading content (read {$bytesRead} of {$data['contentLength']})");
                    }
                    return false;
                }
                $content .= $chunk;
                $bytesRead += strlen($chunk);
            }
        }
        
        // Read padding
        if ($data['paddingLength'] > 0) {
            fread($this->socket, $data['paddingLength']); // Discard padding
        }
        
        return [
            'type' => $data['type'],
            'requestId' => $data['requestId'],
            'content' => $content
        ];
    }
    
    /**
     * Encode a name-value pair for FCGI_PARAMS
     */
    private function encodeNameValue(string $name, string $value): string {
        $nameLen = strlen($name);
        $valueLen = strlen($value);
        
        $result = '';
        
        // Encode name length
        if ($nameLen < 128) {
            $result .= chr($nameLen);
        } else {
            $result .= pack('N', $nameLen | 0x80000000);
        }
        
        // Encode value length
        if ($valueLen < 128) {
            $result .= chr($valueLen);
        } else {
            $result .= pack('N', $valueLen | 0x80000000);
        }
        
        return $result . $name . $value;
    }
}
