FROM php:8.2-apache
RUN docker-php-ext-install mysqli pdo pdo_mysql && a2enmod rewrite
# Allow .htaccess overrides
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf
COPY . /var/www/html/
RUN chown -R www-data:www-data /var/www/html
EXPOSE 80
