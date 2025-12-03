FROM php:8.1-apache

LABEL maintainer="VPS API Video Processor"
LABEL description="PHP API with FFmpeg for HLS video processing - Coolify optimized"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update && apt-get install -y \
    ffmpeg \
    redis-server \
    cron \
    libcurl4-openssl-dev \
    pkg-config \
    libssl-dev \
    curl \
    wget \
    zip \
    unzip \
    git \
    && docker-php-ext-install pcntl \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Enable Apache modules for reverse proxy and security
RUN a2enmod rewrite headers expires remoteip proxy_http

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html

RUN mkdir -p /var/www/html/videos /var/www/html/hls /var/www/html/logs /var/www/html/queue \
    && mkdir -p /var/www/media/uploads /var/www/media/content \
    && chown -R www-data:www-data /var/www/html/videos /var/www/html/hls /var/www/html/logs /var/www/html/queue \
    && chown -R www-data:www-data /var/www/media \
    && chmod -R 755 /var/www/html/videos /var/www/html/hls /var/www/html/logs /var/www/html/queue \
    && chmod -R 755 /var/www/media

# Configure Redis directories
RUN mkdir -p /var/lib/redis /var/log/redis /var/run/redis \
    && chown -R www-data:www-data /var/lib/redis /var/log/redis /var/run/redis \
    && chmod -R 755 /var/lib/redis /var/log/redis /var/run/redis

WORKDIR /var/www/html

RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Configure Apache for reverse proxy (Coolify/Traefik)
RUN { \
    echo '<Directory /var/www/html>'; \
    echo '    Options -Indexes +FollowSymLinks'; \
    echo '    AllowOverride All'; \
    echo '    Require all granted'; \
    echo '    DirectoryIndex index.html index.php health.php'; \
    echo '</Directory>'; \
    echo ''; \
    echo '# Trust reverse proxy headers'; \
    echo 'RemoteIPHeader X-Forwarded-For'; \
    echo 'RemoteIPTrustedProxy 10.0.0.0/8'; \
    echo 'RemoteIPTrustedProxy 172.16.0.0/12'; \
    echo 'RemoteIPTrustedProxy 192.168.0.0/16'; \
} >> /etc/apache2/apache2.conf

# Health check for monitoring (Apache + Worker)
HEALTHCHECK --interval=30s --timeout=10s --start-period=40s --retries=3 \
    CMD curl -f http://localhost/health.php && pgrep -f worker.php > /dev/null || exit 1

EXPOSE 80

# Copy Redis configuration and startup scripts
COPY redis.conf /etc/redis/redis.conf
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

# Use custom entrypoint
ENTRYPOINT ["/usr/local/bin/docker-entrypoint.sh"]
