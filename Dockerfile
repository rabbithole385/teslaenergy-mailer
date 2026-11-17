# Tesla Energy Foundation Mailer — Railway / Docker Image
FROM php:8.3-apache

RUN a2dismod -f mpm_event mpm_worker 2>/dev/null || true \
 && a2enmod mpm_prefork rewrite headers expires deflate remoteip \
 && sed -ri 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf \
 && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf && a2enconf servername \
 && printf 'RemoteIPHeader X-Forwarded-For\nRemoteIPInternalProxy 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.0/8\n' > /etc/apache2/conf-available/remoteip.conf && a2enconf remoteip \
 && mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
 && printf 'expose_php=Off\nupload_max_filesize=32M\npost_max_size=32M\nmax_execution_time=300\nmemory_limit=256M\nlog_errors=On\nerror_log=/dev/stderr\nsession.save_path=/tmp\n' > "$PHP_INI_DIR/conf.d/tesla-mailer.ini"

COPY . /var/www/html/
RUN rm -rf /var/www/html/data/*.sqlite* /var/www/html/Dockerfile /var/www/html/docker-entrypoint.sh \
 && chown -R www-data:www-data /var/www/html

COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENV DATA_DIR=/data PORT=8080
EXPOSE 8080
ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
