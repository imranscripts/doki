FROM php:8.1-apache

# Install dependencies and PHP extensions
RUN apt-get update && \
    apt-get install -y libyaml-dev libsqlite3-dev curl unzip jq openssh-client sshpass && \
    pecl install yaml && \
    docker-php-ext-enable yaml && \
    docker-php-ext-install pdo_sqlite

# Install Docker CLI and add www-data to docker group
RUN curl -fsSL https://get.docker.com | sh && \
    usermod -aG docker www-data

# Install Node.js 20.x
RUN curl -fsSL https://deb.nodesource.com/setup_20.x | bash - && \
    apt-get install -y nodejs

# Install Playwright dependencies
RUN apt-get install -y \
    libnss3 \
    libnspr4 \
    libatk1.0-0 \
    libatk-bridge2.0-0 \
    libcups2 \
    libdrm2 \
    libdbus-1-3 \
    libxkbcommon0 \
    libxcomposite1 \
    libxdamage1 \
    libxfixes3 \
    libxrandr2 \
    libgbm1 \
    libasound2 \
    libpango-1.0-0 \
    libcairo2 \
    libatspi2.0-0

# Enable Apache modules (if needed)
RUN a2enmod rewrite

# Configure shared session storage outside the bind-mounted source tree
RUN echo "session.save_path = /var/www/sessions" > /usr/local/etc/php/conf.d/sessions.ini

# Allow larger uploads (keeps base PHP in sync with app FPM containers)
RUN echo "upload_max_filesize = 100M" > /usr/local/etc/php/conf.d/uploads.ini && \
    echo "post_max_size = 100M" >> /usr/local/etc/php/conf.d/uploads.ini && \
    echo "memory_limit = 256M" >> /usr/local/etc/php/conf.d/uploads.ini

# Create cache directories with proper permissions
RUN mkdir -p /.npm /.cache && chown -R 1000:997 /.npm /.cache

# Set npm and Playwright to use writable cache directories
ENV NPM_CONFIG_CACHE=/var/www/html/.npm-cache
ENV PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.playwright-browsers
ENV HOME=/var/www/html
# Ensure node is in PATH for all processes (including PHP shell_exec)
ENV PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"

# Set the working directory
WORKDIR /var/www/html

# Make sure shell scripts are executable (will be applied to mounted volume)
RUN echo '#!/bin/bash' > /docker-entrypoint.sh && \
    echo 'export PATH="/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin:$PATH"' >> /docker-entrypoint.sh && \
    echo 'export HOME=/var/www/html' >> /docker-entrypoint.sh && \
    echo 'export NPM_CONFIG_CACHE=/var/www/html/.npm-cache' >> /docker-entrypoint.sh && \
    echo 'export PLAYWRIGHT_BROWSERS_PATH=/var/www/html/.playwright-browsers' >> /docker-entrypoint.sh && \
    echo 'chmod +x /var/www/html/playwright/*.sh 2>/dev/null || true' >> /docker-entrypoint.sh && \
    echo '# Allow www-data to access Docker socket' >> /docker-entrypoint.sh && \
    echo 'chmod 666 /var/run/docker.sock 2>/dev/null || true' >> /docker-entrypoint.sh && \
    echo '# Create shared sessions directory' >> /docker-entrypoint.sh && \
    echo 'mkdir -p /var/www/sessions && chmod 1733 /var/www/sessions' >> /docker-entrypoint.sh && \
    echo 'exec apache2-foreground' >> /docker-entrypoint.sh && \
    chmod +x /docker-entrypoint.sh

CMD ["/docker-entrypoint.sh"]
