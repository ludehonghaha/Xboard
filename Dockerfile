FROM phpswoole/swoole:php8.2-alpine AS vendor
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install     --no-dev     --no-interaction     --prefer-dist     --no-autoloader     --no-scripts     --no-security-blocking \
    --ignore-platform-req=ext-pcntl

COPY . .
RUN composer dump-autoload --no-dev --optimize --no-scripts

FROM phpswoole/swoole:php8.2-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN CFLAGS="-O0" install-php-extensions pcntl     && CFLAGS="-O0 -g0" install-php-extensions bcmath     && install-php-extensions zip     && apk --no-cache add su-exec redis nginx sqlite     && addgroup -S -g 1000 www     && adduser -S -G www -u 1000 www     && (getent group redis || addgroup -S redis)     && (getent passwd redis || adduser -S -G redis -H -h /data redis)

WORKDIR /www

COPY --from=vendor /app /www

COPY .docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY .docker/php/zz-xboard.ini /usr/local/etc/php/conf.d/zz-xboard.ini

RUN rm -f bootstrap/cache/*.php \
    && php artisan package:discover --ansi     && php artisan storage:link     && chown -R www:www /www     && chmod -R 775 /www     && mkdir -p /data     && chown redis:redis /data

ENV ENABLE_WEB=true     ENABLE_HORIZON=true     ENABLE_REDIS=true     ENABLE_WS_SERVER=true     ENABLE_PROXY=true

EXPOSE 7001
COPY .docker/entrypoint.sh /entrypoint.sh
COPY .docker/run-services.sh /run-services.sh
RUN chmod +x /entrypoint.sh /run-services.sh
ENTRYPOINT ["/entrypoint.sh"]
CMD ["/run-services.sh"]
