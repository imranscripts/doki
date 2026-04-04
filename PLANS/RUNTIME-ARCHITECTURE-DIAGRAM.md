# Doki Runtime Architecture Diagram

## System Architecture Overview

```mermaid
graph TB
    subgraph "Host Machine"
        subgraph "Docker Network (doki_default)"
            subgraph "Main Container: doki-main-app"
                Apache[Apache Web Server<br/>Port 80 → 8099]
                PHP[PHP 8.1<br/>Base Extensions]
                Bootstrap[app-bootstrap.php<br/>Request Router]
                FastCGI[FastCGIClient<br/>FastCGI Protocol]
                DockerCLI[Docker CLI<br/>Container Manager]
            end
            
            subgraph "FPM Container: doki-fpm-{appId}"
                FPM[PHP-FPM 8.1<br/>Port 9000]
                CustomPHP[PHP 8.1<br/>+ Custom Extensions]
                AppCode[App Code<br/>Read-Only]
            end
        end
        
        HostFS[Host Filesystem<br/>./app, ./templates, etc.]
        DockerSocket["/var/run/docker.sock"]
    end
    
    User[User Browser] -->|HTTP Request| Apache
    Apache --> Bootstrap
    Bootstrap -->|No Custom Extensions| PHP
    Bootstrap -->|Custom Extensions Needed| FastCGI
    FastCGI -->|FastCGI Protocol| FPM
    FPM --> CustomPHP
    CustomPHP --> AppCode
    
    DockerCLI -->|Build/Start| FPM
    DockerCLI -.->|Manage| DockerSocket
    
    Apache -.->|Mount| HostFS
    FPM -.->|Mount:ro| HostFS
    FPM -.->|Mount:rw| HostFS
    
    PHP -->|Response| Apache
    CustomPHP -->|Response| FastCGI
    FastCGI -->|Response| Apache
    Apache -->|HTTP Response| User
    
    style Main fill:#e1f5ff
    style FPM fill:#fff4e1
    style Bootstrap fill:#ffe1f5
    style FastCGI fill:#e1ffe1
```

## Request Flow: App Without Custom Extensions

```mermaid
sequenceDiagram
    participant User
    participant Apache
    participant Bootstrap
    participant PHP
    
    User->>Apache: GET /apps/simple-app/index.php
    Apache->>Bootstrap: Include app-bootstrap.php
    Bootstrap->>Bootstrap: Check manifest.yaml
    Bootstrap->>Bootstrap: No custom extensions needed
    Bootstrap->>PHP: Continue normal execution
    PHP->>PHP: Process request
    PHP->>Apache: HTTP Response
    Apache->>User: HTTP Response
```

## Request Flow: App With Custom Extensions

```mermaid
sequenceDiagram
    participant User
    participant Apache
    participant Bootstrap
    participant AppImageBuilder
    participant FastCGI
    participant FPM
    
    User->>Apache: GET /apps/pdf-to-word/index.php
    Apache->>Bootstrap: Include app-bootstrap.php
    Bootstrap->>Bootstrap: Check manifest.yaml
    Bootstrap->>Bootstrap: Custom extensions: imagick, zip
    Bootstrap->>AppImageBuilder: Check FPM container status
    AppImageBuilder->>AppImageBuilder: Container running?
    alt Container Not Running
        AppImageBuilder->>AppImageBuilder: Build image if needed
        AppImageBuilder->>FPM: Start container
    end
    AppImageBuilder->>Bootstrap: Container IP: 172.17.0.3:9000
    Bootstrap->>FastCGI: Connect to FPM
    FastCGI->>FPM: FCGI_BEGIN_REQUEST
    FastCGI->>FPM: FCGI_PARAMS (headers, script path)
    FastCGI->>FPM: FCGI_STDIN (request body)
    FPM->>FPM: Process with custom extensions
    FPM->>FastCGI: FCGI_STDOUT (response)
    FPM->>FastCGI: FCGI_END_REQUEST
    FastCGI->>Bootstrap: Response data
    Bootstrap->>Apache: HTTP Response
    Apache->>User: HTTP Response
```

## Container Lifecycle

```mermaid
stateDiagram-v2
    [*] --> AppInstalled: App installed with custom extensions
    AppInstalled --> ImageBuilding: Admin triggers build
    ImageBuilding --> ImageBuilt: Docker build completes
    ImageBuilt --> ContainerCreating: Start container
    ContainerCreating --> ContainerRunning: Container started
    ContainerRunning --> Ready: FPM listening on 9000
    Ready --> HandlingRequests: User requests app
    HandlingRequests --> Ready: Request completed
    ContainerRunning --> ContainerStopped: Admin stops
    ContainerStopped --> ContainerRunning: Admin starts
    ContainerRunning --> [*]: App uninstalled
    ImageBuilding --> BuildFailed: Build error
    BuildFailed --> ImageBuilding: Retry build
```

## Volume Mounts Architecture

```mermaid
graph LR
    subgraph "Host Filesystem"
        AppCode[./app/<br/>All app code]
        AppData[./app/data/<br/>Databases, sessions, uploads]
        Templates[./templates/<br/>Template definitions]
    end
    
    subgraph "Main Container"
        MC_AppCode[/var/www/html<br/>Read-Write]
        MC_Data[/var/www/html/data<br/>Read-Write]
        MC_Templates[/var/www/templates<br/>Read-Only]
    end
    
    subgraph "FPM Container"
        FPM_AppCode[/var/www/html<br/>Read-Only]
        FPM_Data[/var/www/html/data<br/>Read-Write]
    end
    
    AppCode -->|:rw| MC_AppCode
    AppData -->|:rw| MC_Data
    Templates -->|:ro| MC_Templates
    
    AppCode -->|:ro| FPM_AppCode
    AppData -->|:rw| FPM_Data
    
    style MC_AppCode fill:#e1f5ff
    style FPM_AppCode fill:#fff4e1
    style MC_Data fill:#ffe1f5
    style FPM_Data fill:#ffe1f5
```

## Network Communication

```mermaid
graph TB
    subgraph "Docker Network: doki_default"
        Main[Main Container<br/>172.17.0.2]
        FPM1[FPM Container 1<br/>172.17.0.3:9000]
        FPM2[FPM Container 2<br/>172.17.0.4:9000]
        FPM3[FPM Container 3<br/>172.17.0.5:9000]
    end
    
    Main -->|FastCGI| FPM1
    Main -->|FastCGI| FPM2
    Main -->|FastCGI| FPM3
    
    Main -.->|Docker API| DockerSocket["/var/run/docker.sock"]
    
    style Main fill:#e1f5ff
    style FPM1 fill:#fff4e1
    style FPM2 fill:#fff4e1
    style FPM3 fill:#fff4e1
```

## FastCGI Protocol Flow

```mermaid
sequenceDiagram
    participant Client as FastCGIClient
    participant Socket as TCP Socket
    participant FPM as PHP-FPM
    
    Client->>Socket: Connect (IP:9000)
    Socket->>FPM: Connection established
    
    Client->>Socket: FCGI_BEGIN_REQUEST<br/>(role: responder)
    Socket->>FPM: Begin request
    
    Client->>Socket: FCGI_PARAMS<br/>(HTTP headers, SCRIPT_FILENAME, etc.)
    Socket->>FPM: Parameters
    
    Client->>Socket: FCGI_PARAMS<br/>(empty - end params)
    Socket->>FPM: End parameters
    
    opt POST/PUT Request
        Client->>Socket: FCGI_STDIN<br/>(request body chunks)
        Socket->>FPM: Request body
    end
    
    Client->>Socket: FCGI_STDIN<br/>(empty - end stdin)
    Socket->>FPM: End request body
    
    FPM->>Socket: FCGI_STDOUT<br/>(response headers + body)
    Socket->>Client: Response data
    
    opt Errors
        FPM->>Socket: FCGI_STDERR<br/>(error messages)
        Socket->>Client: Error output
    end
    
    FPM->>Socket: FCGI_END_REQUEST<br/>(status code)
    Socket->>Client: Request complete
    
    Client->>Socket: Close connection
```

## AppImageBuilder Process

```mermaid
flowchart TD
    Start[App Requires Custom Runtime] --> CheckManifest{Read manifest.yaml}
    CheckManifest --> HasExtensions{Has phpExtensions?}
    HasExtensions -->|No| NoCustom[No custom image needed]
    HasExtensions -->|Yes| GenerateDockerfile[Generate Dockerfile]
    
    GenerateDockerfile --> ReadTemplate[Read Dockerfile.template]
    ReadTemplate --> ResolveDeps[Resolve extension dependencies]
    ResolveDeps --> AddSystemPackages[Add system package installs]
    AddSystemPackages --> AddExtensions[Add PHP extension installs]
    AddExtensions --> ReplacePlaceholder[Replace {{CUSTOM_EXTENSIONS}}]
    
    ReplacePlaceholder --> BuildImage[Build Docker Image]
    BuildImage --> ImageExists{Image exists?}
    ImageExists -->|Yes| CheckContainer{Container exists?}
    ImageExists -->|No| BuildError[Build failed]
    
    CheckContainer -->|Yes| StartContainer[Start existing container]
    CheckContainer -->|No| CreateContainer[Create new container]
    
    CreateContainer --> MountVolumes[Mount volumes:<br/>- Code :ro<br/>- Data :rw<br/>- Docker socket]
    MountVolumes --> SetNetwork[Connect to Doki network]
    SetNetwork --> StartContainer
    
    StartContainer --> WaitReady[Wait for FPM ready]
    WaitReady --> GetIP[Get container IP]
    GetIP --> Ready[Container ready on IP:9000]
    
    style Start fill:#e1f5ff
    style Ready fill:#e1ffe1
    style BuildError fill:#ffe1e1
```

## Component Interaction Matrix

| Component | Interacts With | Protocol/Mechanism | Purpose |
|-----------|---------------|-------------------|---------|
| Apache | User | HTTP/HTTPS | Web server |
| app-bootstrap.php | manifest.yaml | File read | Detect runtime requirements |
| app-bootstrap.php | AppImageBuilder | PHP class | Check container status |
| app-bootstrap.php | FastCGIClient | PHP class | Proxy requests |
| FastCGIClient | FPM Container | FastCGI Protocol | Execute PHP in FPM |
| AppImageBuilder | Docker CLI | Shell commands | Build images, manage containers |
| AppImageBuilder | Dockerfile.template | File read | Generate Dockerfile |
| FPM Container | Main Container | FastCGI | Receive proxied requests |
| FPM Container | Host Filesystem | Volume mounts | Access code and data |
| Main Container | Host Filesystem | Volume mounts | Access all files |
| Both Containers | Docker Socket | Unix socket | Docker operations |

## Data Flow: File Access

```mermaid
graph TB
    subgraph "Read Operations"
        UserRequest[User Request] --> Bootstrap[app-bootstrap.php]
        Bootstrap --> CheckType{App Type?}
        CheckType -->|Standard| MainPHP[Main Container PHP]
        CheckType -->|Custom| FPM[FPM Container PHP]
        MainPHP --> ReadCode1[Read /var/www/html]
        FPM --> ReadCode2[Read /var/www/html :ro]
    end
    
    subgraph "Write Operations"
        MainPHP --> WriteData1[Write /var/www/html/data]
        FPM --> WriteData2[Write /var/www/html/data]
        WriteData1 --> SharedData[Shared Data Directory]
        WriteData2 --> SharedData
    end
    
    style MainPHP fill:#e1f5ff
    style FPM fill:#fff4e1
    style SharedData fill:#ffe1f5
```

## Security Boundaries

```mermaid
graph TB
    subgraph "Main Container - Full Access"
        MainCode[Code: Read-Write]
        MainData[Data: Read-Write]
        MainDocker[Docker: Full Access]
    end
    
    subgraph "FPM Container - Restricted"
        FPMCode[Code: Read-Only]
        FPMData[Data: Read-Write]
        FPMDocker[Docker: Limited<br/>via AppContext]
    end
    
    MainCode -.->|Can modify| FPMCode
    FPMCode -.->|Cannot modify| MainCode
    
    MainData <-->|Shared| FPMData
    
    MainDocker -.->|Manages| FPMDocker
    FPMDocker -.->|Restricted by| AppContext
    
    style MainCode fill:#e1f5ff
    style FPMCode fill:#fff4e1
    style MainData fill:#ffe1f5
    style FPMData fill:#ffe1f5
```

## Image Compression App Flow

```mermaid
sequenceDiagram
    participant User
    participant Doki as Doki Web UI
    participant FPM as FPM Container<br/>(image-compress app)
    participant AppContext as AppContext
    participant ImageMagick as ImageMagick Container<br/>(dpokidov/imagemagick)
    participant Storage as App Storage

    User->>Doki: Upload image file
    Doki->>FPM: POST /apps/image-compress/index.php
    FPM->>Storage: Save uploaded image<br/>(uploads/ directory)
    
    FPM->>AppContext: compressImage()
    AppContext->>AppContext: Check ImageMagick image exists
    alt Image not found
        AppContext->>ImageMagick: Pull image<br/>(dpokidov/imagemagick:7.1.1-15)
    end
    
    AppContext->>ImageMagick: Execute compression command<br/>(magick with quality settings)
    Note over ImageMagick: Process image:<br/>- Strip metadata<br/>- Apply compression<br/>- Format-specific optimization
    
    ImageMagick->>Storage: Write compressed image<br/>(compressed/ directory)
    ImageMagick->>AppContext: Return success
    AppContext->>FPM: Compression result
    FPM->>Storage: Save to history<br/>(compressions.json)
    FPM->>Doki: Return JSON response<br/>(with file info)
    Doki->>User: Show compressed image<br/>(with download link)
```

## Architecture Map: Container Boundaries

```mermaid
graph TB
    subgraph "Host Machine"
        subgraph "Docker Network: doki_default"
            subgraph "Main Container: doki-main-app"
                Apache[Apache Web Server<br/>Port 80 → 8099]
                PHP[PHP 8.1<br/>Base Extensions]
                Bootstrap[app-bootstrap.php<br/>Request Router]
                FastCGI[FastCGIClient<br/>FastCGI Protocol]
                DockerCLI[Docker CLI<br/>Container Manager]
                AppManager[AppManager<br/>App Discovery]
            end
            
            subgraph "FPM Container: doki-fpm-image-compress"
                FPM_IC[PHP-FPM 8.1<br/>Port 9000]
                PHP_IC[PHP 8.1<br/>+ zip extension]
                AppCode_IC[image-compress app code<br/>Read-Only]
                AppContext_IC[AppContext<br/>Capability Manager]
            end
            
            subgraph "FPM Container: doki-fpm-pdf-to-word"
                FPM_PDF[PHP-FPM 8.1<br/>Port 9000]
                PHP_PDF[PHP 8.1<br/>+ zip extension]
                AppCode_PDF[pdf-to-word app code<br/>Read-Only]
            end
            
            subgraph "Service Container: ImageMagick"
                ImageMagick[ImageMagick CLI<br/>dpokidov/imagemagick:7.1.1-15]
                Note_IM[On-demand execution<br/>via docker run]
            end
        end
        
        subgraph "Host Filesystem"
            AppCode[./app/apps/<br/>All app code]
            AppData[./app/data/<br/>Databases, sessions, uploads]
            Templates[./templates/<br/>Template definitions]
            DockerSocket["/var/run/docker.sock"]
        end
    end
    
    User[User Browser] -->|HTTP Request| Apache
    Apache --> Bootstrap
    Bootstrap -->|No Custom Extensions| PHP
    Bootstrap -->|Custom Extensions Needed| FastCGI
    FastCGI -->|FastCGI Protocol| FPM_IC
    FastCGI -.->|FastCGI Protocol| FPM_PDF
    FPM_IC --> PHP_IC
    PHP_IC --> AppCode_IC
    AppCode_IC --> AppContext_IC
    AppContext_IC -->|docker run| ImageMagick
    
    DockerCLI -->|Build/Start| FPM_IC
    DockerCLI -->|Build/Start| FPM_PDF
    DockerCLI -.->|Manage| DockerSocket
    AppContext_IC -.->|docker run| ImageMagick
    
    Apache -.->|Mount:rw| AppCode
    Apache -.->|Mount:rw| AppData
    FPM_IC -.->|Mount:ro| AppCode
    FPM_IC -.->|Mount:rw| AppData
    FPM_PDF -.->|Mount:ro| AppCode
    FPM_PDF -.->|Mount:rw| AppData
    ImageMagick -.->|Mount:ro| AppData
    ImageMagick -.->|Mount:rw| AppData
    
    PHP -->|Response| Apache
    PHP_IC -->|Response| FastCGI
    FastCGI -->|Response| Apache
    Apache -->|HTTP Response| User
    
    style Apache fill:#e1f5ff
    style PHP fill:#e1f5ff
    style Bootstrap fill:#ffe1f5
    style FastCGI fill:#e1ffe1
    style FPM_IC fill:#fff4e1
    style FPM_PDF fill:#fff4e1
    style PHP_IC fill:#fff4e1
    style PHP_PDF fill:#fff4e1
    style AppCode_IC fill:#fff4e1
    style AppCode_PDF fill:#fff4e1
    style AppContext_IC fill:#fff4e1
    style ImageMagick fill:#ffe1e1
    style DockerCLI fill:#e1f5ff
```
