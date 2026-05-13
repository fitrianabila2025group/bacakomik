# =============================================================================
# BacaKomik — production Dockerfile (PHP 8.3 + Apache)
# Build:   docker build -t bacakomik .
# Run:     docker run -p 8080:80 \
#            -e DB_HOST=mysql -e DB_USER=root -e DB_PASS=secret -e DB_NAME=bacakomik \
#            bacakomik
# =============================================================================
FROM php:8.3-apache

ENV APACHE_DOCUMENT_ROOT=/var/www/html/public

# System deps + PHP extensions
RUN apt-get update && apt-get install -y --no-install-recommends \
        libzip-dev libpng-dev libjpeg-dev libwebp-dev libfreetype6-dev \
        zip unzip git curl ca-certificates \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install pdo_mysql gd zip opcache \
    && a2enmod rewrite headers expires

# Sensible PHP defaults for production
RUN { \
        echo 'memory_limit=512M'; \
        echo 'upload_max_filesize=32M'; \
        echo 'post_max_size=32M'; \
        echo 'max_execution_time=300'; \
        echo 'date.timezone=Asia/Jakarta'; \
        echo 'expose_php=Off'; \
        echo 'opcache.enable=1'; \
        echo 'opcache.memory_consumption=192'; \
        echo 'opcache.max_accelerated_files=20000'; \
        echo 'opcache.validate_timestamps=0'; \
    } > /usr/local/etc/php/conf.d/zz-bacakomik.ini

# Apache vhost: docroot = /public, AllowOverride All so .htaccess (mod_rewrite) works
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/000-default.conf \
    && sed -ri -e 's!/var/www/!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf \
    && printf '<Directory %s>\n\tAllowOverride All\n\tRequire all granted\n</Directory>\n' "${APACHE_DOCUMENT_ROOT}" \
        >> /etc/apache2/apache2.conf

WORKDIR /var/www/html

# Copy app
COPY . /var/www/html

# Storage dirs + permissions
RUN mkdir -p storage/comics storage/covers storage/cache storage/settings \
    && chown -R www-data:www-data storage public/assets

# Entrypoint: optional auto-install on first boot
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["apache2-foreground"]
