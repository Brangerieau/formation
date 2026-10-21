# syntax=docker/dockerfile:1

# Dev image only: the code arrives through the bind mount declared in
# compose.yaml, nothing is copied in.
FROM dunglas/frankenphp:1-php8.4-bookworm

# The FrankenPHP image does not ship Composer; zip is what it unpacks dist
# archives with. pdo_sqlite already ships with the base image.
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
RUN install-php-extensions zip

RUN ln -snf /usr/share/zoneinfo/Europe/Paris /etc/localtime \
 && echo Europe/Paris > /etc/timezone

COPY docker/app/Caddyfile /etc/frankenphp/Caddyfile
COPY docker/app/php.dev.ini /usr/local/etc/php/conf.d/zz-app.ini

# The base image probes Caddy's admin API, which the Caddyfile turns off.
HEALTHCHECK NONE

# /config and /data belong to root in the base image, and Caddy writes there.
RUN chown -R www-data:www-data /config /data

USER www-data

EXPOSE 8080
