# Doki Runtime Architecture: FPM and Docker

## Overview

Doki uses a **dual-container architecture** to support apps with custom PHP extensions while keeping the main application lightweight. Apps that need specific PHP extensions run in isolated PHP-FPM containers, while the main Doki container (Apache + PHP) acts as a reverse proxy.

## Architecture Components

### 1. Main Doki Container (Apache + PHP)

**Image**: `php:8.1-apache`  
**Container Name**: `doki-main-app`  
**Port**: 8099 (mapped to host)

**Responsibilities**:
- Serves the main Doki web interface
- Handles authentication, templates, commands, and apps without custom requirements
- Acts as a reverse proxy for apps requiring custom PHP extensions
- Manages Docker operations (building images, starting containers)

**Key Features**:
- PHP 8.1 with base extensions (yaml, pdo_sqlite)
- Apache web server
- Docker CLI installed (for managing FPM containers)
- Node.js 20.x (for Playwright and other tools)
- Shared session storage at `/var/www/html/data/sessions`

### 2. FPM Runtime Containers (Custom PHP Extensions)

**Base Image**: `php:8.1-fpm`  
**Container Naming**: `doki-fpm-{appId}`  
**Image Naming**: `doki-app-{appId}:latest`  
**Port**: 9000 (FastCGI, internal only)

**Responsibilities**:
- Run apps that require specific PHP extensions
- Execute PHP code with custom extensions installed
- Share code and data with main container via volumes

**Key Features**:
- PHP-FPM 8.1 with custom extensions
- Docker CLI (for apps needing `exec.docker` capability)
- Shared volumes:
  - `/var/www/html:ro` - Read-only app code
  - `/var/www/html/data:rw` - Writable data directory
  - `/var/run/docker.sock` - Docker socket access

## Request Flow

### Apps Without Custom Extensions

```
User Request → Apache (Main Container) → PHP 8.1 → Response
```

These apps run directly in the main container, no proxying needed.

### Apps With Custom Extensions

```
User Request → Apache (Main Container)
              ↓
         app-bootstrap.php
              ↓
    Checks manifest.yaml for runtime requirements
              ↓
    AppImageBuilder checks if FPM container exists & running
              ↓
    FastCGIClient → FastCGI Protocol → FPM Container (Port 9000)
              ↓
         PHP-FPM processes request
              ↓
         Response → FastCGIClient → Apache → User
```

## Detailed Component Breakdown

### app-bootstrap.php

**Location**: `app/includes/app-bootstrap.php`

**Function**:
1. Detects if current request is for an app (`/apps/{appId}/`)
2. Checks if app has custom runtime requirements in `manifest.yaml`
3. If no custom requirements → continue normally
4. If custom requirements → check FPM container status
5. If FPM ready → proxy via FastCGI
6. If FPM not ready → show "setup required" page

**Key Logic**:
```php
// Already in FPM container - continue normally
if (getenv('DOKI_FPM_CONTAINER') === 'true') {
    return; // No proxy needed
}

// Check if app needs custom runtime
$extensions = $config['runtime']['phpExtensions'] ?? [];
if (empty($extensions)) {
    return; // No custom runtime needed
}

// Check FPM container status
$status = $builder->getStatus();
if ($status['ready']) {
    // Proxy to FPM container
    $client = new FastCGIClient($ip, 9000);
    $result = $client->execute($scriptPath, $params, $stdin);
}
```

### AppImageBuilder

**Location**: `app/includes/AppImageBuilder.php`

**Responsibilities**:
1. **Generate Dockerfile**: Creates custom Dockerfile from template + app requirements
2. **Build Image**: Builds Docker image with required PHP extensions
3. **Start Container**: Creates and starts FPM container with proper volumes
4. **Status Management**: Tracks build status, container state

**Extension Support**:
- Standard extensions: `docker-php-ext-install {ext}`
- PECL extensions: `pecl install {ext}` (imagick, redis, mongodb, etc.)
- Configure flags: Special handling for extensions like `gd`
- System dependencies: Automatically installs required system packages

**Container Configuration**:
```bash
docker run -d \
  --name doki-fpm-{appId} \
  --label "doki.app={appId}" \
  --label "doki.type=fpm" \
  -e HOST_APP_PATH=/path/to/app \
  -v /path/to/app:/var/www/html:ro \
  -v /path/to/app/data:/var/www/html/data:rw \
  -v /var/run/docker.sock:/var/run/docker.sock \
  --network {doki-network} \
  doki-app-{appId}:latest
```

### FastCGIClient

**Location**: `app/includes/FastCGIClient.php`

**Function**: Implements FastCGI protocol to communicate with PHP-FPM

**Protocol Flow**:
1. Connect to FPM socket (IP:9000)
2. Send `FCGI_BEGIN_REQUEST` (role: responder)
3. Send `FCGI_PARAMS` (HTTP headers, script path, etc.)
4. Send `FCGI_STDIN` (request body if POST/PUT)
5. Read `FCGI_STDOUT` (response body)
6. Read `FCGI_STDERR` (error output)
7. Read `FCGI_END_REQUEST` (completion)

**Key Features**:
- Handles multipart form data
- Preserves HTTP headers
- Streams large responses
- Timeout handling

### Dockerfile Template

**Location**: `docker/app-runtime/Dockerfile.template`

**Base Structure**:
```dockerfile
FROM php:8.1-fpm

# Install base dependencies
RUN apt-get update && apt-get install -y \
    libyaml-dev libsqlite3-dev curl unzip \
    && curl -fsSL https://get.docker.com | sh \
    && usermod -aG docker www-data

# Install base PHP extensions
RUN pecl install yaml && docker-php-ext-enable yaml
RUN docker-php-ext-install pdo_sqlite

{{CUSTOM_EXTENSIONS}}  # Replaced by AppImageBuilder

# Configure PHP-FPM
RUN sed -i 's/listen = 127.0.0.1:9000/listen = 0.0.0.0:9000/' \
    /usr/local/etc/php-fpm.d/www.conf

# PHP configuration
RUN echo "upload_max_filesize = 100M" > /usr/local/etc/php/conf.d/uploads.ini
RUN echo "session.save_path = /var/www/html/data/sessions" >> ...

ENV DOKI_FPM_CONTAINER=true
EXPOSE 9000
CMD ["php-fpm"]
```

## Volume Mounts

### Main Container Volumes

```yaml
volumes:
  - ./app:/var/www/html:rw,z          # App code (read-write)
  - ./templates:/var/www/templates:ro # Templates (read-only)
  - ./infrastructure:/var/www/infrastructure:ro
  - ./docker:/var/www/docker:ro
  - /var/run/docker.sock:/var/run/docker.sock  # Docker socket
  - /:/host_root:ro                    # Host filesystem access
```

### FPM Container Volumes

```yaml
volumes:
  - {HOST_APP_PATH}:/var/www/html:ro           # App code (read-only)
  - {HOST_APP_PATH}/data:/var/www/html/data:rw # Data (read-write)
  - /var/run/docker.sock:/var/run/docker.sock  # Docker socket
```

**Why Read-Only for Code?**
- Prevents FPM containers from modifying source code
- Ensures consistency across containers
- Data directory is writable for databases, sessions, uploads

## Network Architecture

```
┌─────────────────────────────────────────────────────────┐
│                    Docker Network                       │
│                  (doki_default or                      │
│              docker-compose network)                    │
│                                                         │
│  ┌──────────────────────┐    ┌──────────────────────┐  │
│  │  Main Container      │    │  FPM Container       │  │
│  │  (php-command-       │    │  (doki-fpm-{appId})  │  │
│  │   executor)          │    │                      │  │
│  │                      │    │                      │  │
│  │  Apache:80           │    │  PHP-FPM:9000        │  │
│  │  PHP 8.1            │◄───┤  PHP 8.1 + Extensions│  │
│  │                      │    │                      │  │
│  │  FastCGI Client      │    │  FastCGI Server      │  │
│  └──────────────────────┘    └──────────────────────┘  │
│           │                              │                │
│           └──────────┬───────────────────┘                │
│                      │                                    │
│              Shared Volumes                                │
│         /var/www/html (code)                              │
│         /var/www/html/data (data)                         │
└─────────────────────────────────────────────────────────┘
```

## Session Sharing

Both containers share the same session storage:

```
/var/www/html/data/sessions
```

This allows:
- User sessions to persist across main container and FPM containers
- Single sign-on experience
- Session data accessible from both containers

## Example: App with Custom Extensions

### 1. App Manifest

```yaml
# app/apps/pdf-to-word/manifest.yaml
runtime:
  phpExtensions:
    - imagick
    - zip
  systemPackages:
    - ghostscript
```

### 2. Build Process

1. Admin triggers build via UI
2. `AppImageBuilder` generates Dockerfile:
   ```dockerfile
   FROM php:8.1-fpm
   # ... base setup ...
   
   # Install system dependencies
   RUN apt-get install -y libmagickwand-dev libzip-dev ghostscript
   
   # Install PHP extensions
   RUN pecl install imagick && docker-php-ext-enable imagick
   RUN docker-php-ext-install zip
   ```

3. Docker builds image: `doki-app-pdf-to-word:latest`
4. Container created: `doki-fpm-pdf-to-word`
5. Container started and connected to network

### 3. Request Handling

```
User → /apps/pdf-to-word/index.php
  ↓
app-bootstrap.php detects custom runtime
  ↓
FastCGIClient connects to doki-fpm-pdf-to-word:9000
  ↓
FPM processes request with imagick + zip extensions
  ↓
Response proxied back to user
```

## Benefits of This Architecture

1. **Isolation**: Each app with custom requirements runs in its own container
2. **Flexibility**: Different apps can have different PHP extensions
3. **Performance**: FPM containers are lightweight, only created when needed
4. **Security**: Code is read-only in FPM containers
5. **Scalability**: Can run multiple FPM containers for different apps
6. **Maintainability**: Main container stays clean, extensions isolated

## Limitations

1. **Build Time**: First request to app with custom runtime requires image build
2. **Resource Usage**: Each FPM container consumes memory
3. **Network Overhead**: FastCGI proxy adds slight latency
4. **Complexity**: More moving parts than single-container setup

## Status Management

Build and container status tracked in:
```
app/data/app-build-status/{appId}.json
```

Contains:
- Build status (building, ready, failed)
- Container state (running, stopped)
- Build logs
- Timestamps

## Admin Interface

Admins can:
- View app runtime requirements
- Trigger image builds
- Start/stop FPM containers
- View build logs
- Monitor container status

All managed via `/admin/apps.php` interface.
