FROM php:8.3-apache
RUN apt-get update && apt-get install -y --no-install-recommends libonig-dev libcurl4-openssl-dev \
    && docker-php-ext-install pdo_mysql mbstring curl \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /var/www/reintegrafacil
COPY . .
RUN sed -i 's@/var/www/html@/var/www/reintegrafacil/public_html@g' /etc/apache2/sites-available/000-default.conf \
    && printf '<Directory /var/www/reintegrafacil/public_html>\nOptions -Indexes\nRequire all granted\n</Directory>\n' > /etc/apache2/conf-available/reintegrafacil.conf \
    && a2enconf reintegrafacil \
    && chmod +x docker/entrypoint.sh
ENTRYPOINT ["/var/www/reintegrafacil/docker/entrypoint.sh"]
