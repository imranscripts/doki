FROM php:8.1-cli

RUN apt-get update && \
    apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        libsqlite3-dev \
        openssh-client \
        python3 \
        sqlite3 \
        tar \
        xz-utils \
    && docker-php-ext-install pdo_sqlite \
    && curl -fsSL https://get.docker.com | sh \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /workspace

COPY docker/update-helper/server.py /opt/doki-update-helper/server.py

EXPOSE 8100

CMD ["python3", "/opt/doki-update-helper/server.py"]
