# syntax=docker/dockerfile:1@sha256:4edf897a3ffa55b89f906fc8cc78afdb3f1834cc9c7083565e611a8a7d5fe99e
FROM composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac AS composer

FROM php:8.4.27-fpm-alpine@sha256:8ce34c869fae0f8da2c82d8aeda222f0782e8e5ae2b370fb43cbad84e9e2ea55 AS php-base
# Bump this revision when refreshing Alpine security packages.
ARG OS_SECURITY_REVISION=20261022
RUN test -n "$OS_SECURITY_REVISION" && apk upgrade --no-cache \
    && apk add --no-cache icu-libs libpq fcgi unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS icu-dev postgresql-dev linux-headers \
    && docker-php-ext-install -j"$(nproc)" intl pdo_pgsql pcntl opcache \
    && apk del .build-deps \
    && addgroup -g 10001 app && adduser -D -u 10001 -G app app
WORKDIR /app
COPY docker/php/php.ini /usr/local/etc/php/conf.d/app.ini
COPY docker/php/pool.conf /usr/local/etc/php-fpm.d/zz-app.conf

FROM php-base AS dependencies
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-interaction --prefer-dist --no-progress

FROM php-base AS runtime
COPY --from=dependencies /app/vendor ./vendor
COPY --chown=app:app . .
RUN mkdir -p var && chown app:app var
USER app
ENV APP_ENV=prod APP_DEBUG=0
ENTRYPOINT ["/app/docker/entrypoint.sh"]
CMD ["php-fpm", "-F"]
STOPSIGNAL SIGQUIT

FROM runtime AS test
USER root
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
ENV COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_HOME=/tmp/composer
RUN composer install --no-scripts --no-interaction --prefer-dist --no-progress \
    && chown -R app:app vendor
USER app
CMD ["vendor/bin/phpunit"]

FROM nginx:stable-alpine@sha256:970364228b1e4f932abc22d321932816782cca8a20b85a77552a7cfe9acfb3b5 AS web
# Bump this revision when refreshing Alpine security packages.
ARG OS_SECURITY_REVISION=20261022
# Aucun module dynamique n’est chargé par la configuration fournie.
RUN test -n "$OS_SECURITY_REVISION" && apk del nginx-module-acme nginx-module-geoip nginx-module-image-filter nginx-module-njs nginx-module-xslt \
    && apk upgrade --no-cache
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY public/ /app/public/
USER nginx
ENTRYPOINT ["nginx"]
CMD ["-g", "daemon off;"]

FROM postgres:18-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873 AS database
# Bump this revision when refreshing Alpine security packages.
ARG OS_SECURITY_REVISION=20261022
# Le gosu embarqué utilise un ancien runtime Go ; su-exec fournit ici le même
# changement d’UID sans ce runtime. L’entrypoint officiel reste utilisé.
RUN test -n "$OS_SECURITY_REVISION" && apk upgrade --no-cache \
    && apk add --no-cache su-exec \
    && sed -i 's/exec gosu /exec su-exec /' /usr/local/bin/docker-entrypoint.sh \
    && rm /usr/local/bin/gosu

FROM python:3.13-alpine@sha256:2d9aefe2fef018a7eb2c13064c89c71929800fd2e5dccdbf52ea5da5bb8d929a AS contract-test
ENV PYTHONDONTWRITEBYTECODE=1
WORKDIR /contract
COPY docker/contract-requirements.txt /tmp/requirements.txt
RUN pip install --no-cache-dir -r /tmp/requirements.txt
COPY docs/openapi.json docs/openapi.json
COPY docs/schemas/openapi-3.1.schema.json docs/schemas/openapi-3.1.schema.json
COPY tests/contract_test.py tests/contract_test.py
USER 10001:10001
CMD ["python", "tests/contract_test.py"]

FROM postgres:18-alpine@sha256:77f585114c32fbca283dc835b0596f4e52b51b4c6662d7810b2f4084f60a1873 AS backup
# Bump this revision when refreshing Alpine security packages.
ARG OS_SECURITY_REVISION=20261022
RUN test -n "$OS_SECURITY_REVISION" && apk upgrade --no-cache \
    && apk add --no-cache python3 py3-psycopg2 age \
    && rm /usr/local/bin/gosu \
    && addgroup -g 10001 backup && adduser -D -u 10001 -G backup backup \
    && mkdir -p /backups /work && chown backup:backup /backups /work
COPY docker/backup/backup.py /opt/backup.py
USER backup
WORKDIR /work
ENTRYPOINT ["python3", "/opt/backup.py"]
CMD ["schedule"]

FROM backup AS backup-test
COPY tests/backup_test.py /tests/backup_test.py
ENTRYPOINT ["python3", "/tests/backup_test.py"]

# Portainer Standalone: no host-side checkout or relative bind mounts required.
FROM database AS portainer-database
COPY docker/postgres/init-app.sh /docker-entrypoint-initdb.d/10-app.sh
COPY docker/privacy/retention.sql /opt/gestion-stock/retention.sql

FROM backup AS portainer-backup
COPY . /source
COPY docker/portainer/setup.py /opt/portainer-setup.py

# Rebuild Caddy with patched Go and x/net; dependency graph is locked in the repo.
FROM golang:1.27.3-alpine@sha256:f2c872bcee949b7be9c490efd02b48a8a2fbf947cdf533f1c58525ec9d8170fd AS proxy-build
WORKDIR /build
COPY docker/production/proxy/go.mod docker/production/proxy/go.sum ./
RUN go mod download
COPY docker/production/proxy/main.go ./
RUN go list -mod=readonly -deps . > /dependencies.txt \
    && ! grep -q '^golang.org/x/crypto/openpgp' /dependencies.txt
RUN CGO_ENABLED=0 go build -mod=readonly -trimpath -ldflags='-s -w -X github.com/caddyserver/caddy/v2.CustomVersion=v2.11.7-gestion-stock-ops02' -o /caddy .

FROM caddy:2.11.7-alpine@sha256:d8542f48d34a9cf4e4c11a478865229840e87e4c96ea3f439101f31a5d35f75f AS proxy
# Bump this revision when refreshing Alpine security packages.
ARG OS_SECURITY_REVISION=20261022
RUN test -n "$OS_SECURITY_REVISION" && apk upgrade --no-cache && chown -R 10001:10001 /data /config
COPY --from=proxy-build /caddy /usr/bin/caddy
COPY --from=proxy-build /dependencies.txt /usr/share/gestion-stock/proxy-dependencies.txt
USER 10001:10001
