FROM php:8.2-apache

# Install PostgreSQL client library and PDO PostgreSQL extension
RUN apt-get update && apt-get install -y libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql pgsql

# Enable Apache mod_rewrite
RUN a2enmod rewrite

# Copy source code to the Apache document root
COPY . /var/www/html/

# Expose port 80 (Apache default)
EXPOSE 80

# Make sure files are owned by www-data
RUN chown -R www-data:www-data /var/www/html
