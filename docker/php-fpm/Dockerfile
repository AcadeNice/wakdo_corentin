# Wakdo - image applicative PHP-FPM
#
# Base : php:8.3-fpm-alpine3.20 (LTS PHP support jusqu'en 2027, Alpine 3.20 stable).
# Role : execute le code PHP (back-office + API REST), expose FastCGI sur 9000.
# Reseau : uniquement accessible depuis le reseau interne wakdo_internal.
# Lie a wakdo-db via PDO mysql + wakdo-web via FastCGI reverse.

FROM php:8.3-fpm-alpine3.20

# Extensions PHP requises par Wakdo :
#  - pdo_mysql : connexion MariaDB (Cr 4.e.1 prepared statements anti-SQLi)
#  - opcache   : cache bytecode (Cr 1.e.8 perf + Cr 4.g.3 stabilite)
#  - intl      : gestion locale fr_FR pour dates, accents, tri alpha
#  - exif      : lecture metadonnees images upload produits
#  - zip       : manipulation d'archives pour backups et exports
# docker-php-ext-install compile et active depuis les sources PHP bundled.
# Packages Alpine necessaires au build, puis purges (image finale plus legere).
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        icu-dev \
        libzip-dev \
        oniguruma-dev; \
    apk add --no-cache \
        icu-libs \
        libzip \
        tini; \
    docker-php-ext-install -j"$(nproc)" \
        pdo_mysql \
        opcache \
        intl \
        exif \
        zip; \
    apk del --purge .build-deps; \
    rm -rf /var/cache/apk/* /tmp/*

# Configuration PHP projet :
# - php.ini : parametres runtime (memory, upload, session, display_errors)
# - www.conf : pool FPM (pm mode, workers, listen, access log)
COPY php.ini /usr/local/etc/php/conf.d/zz-wakdo.ini
COPY www.conf /usr/local/etc/php-fpm.d/zz-wakdo.conf

# WORKDIR = racine applicative. Le code sera bind-mounte en dev via
# docker-compose.yml et COPY-e en prod (override compose-prod).
WORKDIR /var/www/html

# Healthcheck : verifie que PHP-FPM repond via son ping endpoint
# (expose par pm.status_path dans www.conf).
# cgi-fcgi permet de parler FastCGI depuis le shell pour le test.
HEALTHCHECK --interval=30s --timeout=5s --start-period=10s --retries=3 \
    CMD php -r "exit(0);" || exit 1

# Tini = init minimal qui reape les zombies et transmet SIGTERM proprement.
# Sans lui, PHP-FPM en PID 1 ne recoit pas les signaux correctement.
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php-fpm", "--nodaemonize"]

EXPOSE 9000
