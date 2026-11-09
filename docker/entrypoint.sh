#!/bin/sh
set -eu
php /var/www/reintegrafacil/scripts/configurar_container.php
php /var/www/reintegrafacil/scripts/instalar.php
chown -R www-data:www-data /var/www/reintegrafacil/private
exec apache2-foreground
