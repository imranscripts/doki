# Doki Runtime Architecture - Quick Summary

## TL;DR

Doki uses a **dual-container architecture**:
- **Main Container** (Apache + PHP): Handles web requests, manages apps, proxies to FPM
- **FPM Containers** (PHP-FPM + custom extensions): Run apps requiring specific PHP extensions

Apps without custom extensions run directly in the main container. Apps with custom extensions are proxied via FastCGI to isolated FPM containers.

## Key Concepts

### 1. Main Container (`doki-main-app`)
- **Image**: `php:8.1-apache`
- **Purpose**: Web server, app manager, reverse proxy
- **Access**: Full read-write to all files
- **Port**: 8099 (host) → 80 (container)

### 2. FPM Containers (`doki-fpm-{appId}`)
- **Image**: `doki-app-{appId}:latest` (custom built)
- **Purpose**: Run apps with custom PHP extensions
- **Access**: Read-only code, read-write data
- **Port**: 9000 (FastCGI, internal only)

### 3. Request Routing (`app-bootstrap.php`)
- Checks app's `manifest.yaml` for `runtime.phpExtensions`
- If empty → run in main container
- If present → proxy to FPM container via FastCGI

### 4. Image Building (`AppImageBuilder`)
- Reads `docker/app-runtime/Dockerfile.template`
- Adds custom PHP extensions and system packages
- Builds Docker image: `doki-app-{appId}:latest`
- Creates and starts container: `doki-fpm-{appId}`

### 5. FastCGI Proxy (`FastCGIClient`)
- Implements FastCGI protocol
- Connects to FPM container on port 9000
- Forwards HTTP request as FastCGI
- Returns response to main container

## Architecture Diagram (Simplified)

```
┌─────────────────────────────────────────────────────────────┐
│                    User Browser                             │
└──────────────────────┬────────────────────────────────────┘
                       │ HTTP
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  Main Container: doki-main-app                               │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   Apache     │  │  PHP 8.1     │  │  Bootstrap   │      │
│  │   :80        │  │  (Base)      │  │  Router      │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│         │                  │                  │              │
│         └──────────────────┴──────────────────┘              │
│                            │                                 │
│                   ┌────────▼────────┐                        │
│                   │  FastCGIClient │                        │
│                   └────────┬────────┘                        │
└────────────────────────────┼─────────────────────────────────┘
                             │ FastCGI Protocol
                             ▼
┌─────────────────────────────────────────────────────────────┐
│  FPM Container: doki-fpm-{appId}                            │
│  ┌──────────────┐  ┌──────────────┐                        │
│  │   PHP-FPM     │  │  PHP 8.1     │                        │
│  │   :9000       │  │  + Extensions│                        │
│  └──────────────┘  └──────────────┘                        │
└─────────────────────────────────────────────────────────────┘
```

## Request Flow Comparison

### Standard App (No Custom Extensions)
```
User → Apache → PHP 8.1 → Response
```

### Custom Runtime App
```
User → Apache → Bootstrap → FastCGI → FPM → PHP 8.1 + Extensions → Response
```

## Volume Mounts

### Main Container
- `./app:/var/www/html:rw` - Full access
- `./templates:/var/www/templates:ro` - Templates
- `/var/run/docker.sock` - Docker socket

### FPM Container
- `./app:/var/www/html:ro` - **Read-only code**
- `./app/data:/var/www/html/data:rw` - **Writable data**
- `/var/run/docker.sock` - Docker socket (for AppContext)

## File Locations

| Component | File Path |
|-----------|-----------|
| Main Dockerfile | `Dockerfile` |
| FPM Template | `docker/app-runtime/Dockerfile.template` |
| Bootstrap Logic | `app/includes/app-bootstrap.php` |
| Image Builder | `app/includes/AppImageBuilder.php` |
| FastCGI Client | `app/includes/FastCGIClient.php` |
| Build Status | `app/data/app-build-status/{appId}.json` |

## Key Environment Variables

| Variable | Container | Purpose |
|----------|-----------|---------|
| `DOKI_FPM_CONTAINER=true` | FPM | Prevents proxy loop |
| `HOST_APP_PATH` | Both | Path translation for volumes |

## Container Naming

| Resource | Naming Pattern | Example |
|----------|---------------|---------|
| Image | `doki-app-{appId}:latest` | `doki-app-pdf-to-word:latest` |
| Container | `doki-fpm-{appId}` | `doki-fpm-pdf-to-word` |
| Network | `doki_default` or compose network | Auto-detected |

## Extension Installation Methods

| Extension Type | Installation Command |
|----------------|---------------------|
| Standard | `docker-php-ext-install {ext}` |
| PECL | `pecl install {ext} && docker-php-ext-enable {ext}` |
| Configure Flags | `docker-php-ext-configure {ext} {flags} && docker-php-ext-install {ext}` |

## Status States

| State | Description |
|-------|-------------|
| `not_needed` | App doesn't require custom runtime |
| `not_started` | Build hasn't been triggered |
| `building` | Docker image is being built |
| `ready` | Image built, container ready |
| `failed` | Build failed |
| `containerRunning` | FPM container is running |

## Common Operations

### Build FPM Image
```php
$builder = new AppImageBuilder($appId, $manifest);
$result = $builder->buildImage();
```

### Start FPM Container
```php
$result = $builder->startContainer();
```

### Check Status
```php
$status = $builder->getStatus();
// Returns: needsCustomImage, imageExists, containerRunning, ready
```

### Proxy Request
```php
$client = new FastCGIClient($ip, 9000);
$result = $client->execute($scriptPath, $params, $stdin);
```

## Benefits

✅ **Isolation**: Each app with custom requirements isolated  
✅ **Flexibility**: Different extensions per app  
✅ **Security**: Code read-only in FPM containers  
✅ **Performance**: Lightweight FPM containers  
✅ **Scalability**: Multiple FPM containers possible  

## Limitations

⚠️ **Build Time**: First request requires image build  
⚠️ **Memory**: Each FPM container uses resources  
⚠️ **Latency**: FastCGI proxy adds slight overhead  
⚠️ **Complexity**: More moving parts than single container  

## Debugging

### Check Container Status
```bash
docker ps --filter "name=doki-fpm-"
```

### View Build Logs
```bash
cat app/data/app-build-status/{appId}-build.log
```

### Check FPM Logs
```bash
docker logs doki-fpm-{appId}
```

### Test FastCGI Connection
```bash
docker exec doki-main-app telnet {fpm-ip} 9000
```

## Related Documentation

- **Full Architecture**: `RUNTIME-ARCHITECTURE.md`
- **Visual Diagrams**: `RUNTIME-ARCHITECTURE-DIAGRAM.md`
- **Main Architecture**: `ARCHITECTURE-v3.md`
