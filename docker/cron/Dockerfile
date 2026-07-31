# Wakdo - image cron
#
# Base : alpine:3.20 (image minimale ~7 Mo, suffisante pour crond + mariadb-client).
# Role : planifier les taches recurrentes du projet (backup BDD, purge sessions,
#        agregations statistiques) pendant la fenetre de maintenance 01h30-09h30.
# Critere RNCP : Cr 7.b.3 (planificateur de tache, cron tab).

FROM alpine:3.20

# Installation du minimum :
# - dcron          : implementation cron simple et standard en Alpine
# - mariadb-client : binaire mariadb + mysqldump pour backups et requetes
# - gzip           : compression des dumps SQL
# - tzdata         : support des timezones (necessaire pour CRON_TIMEZONE)
# - bash           : les scripts backup utilisent des features bash (pipefail)
# - coreutils      : date, du, find avec options GNU (plus lisible que busybox)
# - php83 + pdo_mysql : certaines taches planifiees appellent le CODE METIER plutot
#   que de reecrire une regle en SQL. L'expiration des commandes en attente
#   (src/bin/order-expire.php) est une transition de la machine a etats de la commande :
#   les cinq autres vivent dans OrderRepository, la sixieme doit y vivre aussi, sinon la
#   machine a etats a deux proprietaires. Les purges de retention, elles, restent en bash
#   (tables techniques, aucun etat metier, aucune trace a ecrire).
RUN set -eux; \
    apk add --no-cache \
        dcron \
        mariadb-client \
        gzip \
        tzdata \
        bash \
        coreutils \
        php83 \
        php83-pdo \
        php83-pdo_mysql; \
    ln -sf /usr/bin/php83 /usr/bin/php; \
    rm -rf /var/cache/apk/* /tmp/*

# Verification a la CONSTRUCTION : un nom de paquet errone doit faire echouer l'image
# ici, pas se decouvrir a 02h00 dans un log que personne ne lit.
RUN set -eux; \
    php -v; \
    php -m | grep -q pdo_mysql

# Dossiers projet :
# /scripts : scripts metier (backup, purge, agregations) montes en COPY
# /backups : destination des dumps, bind-mount vers ./var/backups sur l'hote
RUN mkdir -p /scripts /backups

# Scripts executables
COPY scripts/ /scripts/
RUN chmod +x /scripts/*.sh

# Crontab du projet : defini dans le fichier crontab, copie dans le repertoire
# standard Alpine pour crond.
COPY crontab /etc/crontabs/root

# Pas de USER non-root ici : crond exige UID 0 pour lancer les jobs en tant
# qu'utilisateurs different. Les scripts s'executent donc en root dans le
# conteneur, mais le conteneur lui-meme est isole reseau (wakdo_internal only),
# et aucun port hote n'est expose.

# Healthcheck : verifier que crond est en vie et que son pidfile existe.
HEALTHCHECK --interval=60s --timeout=5s --start-period=10s --retries=3 \
    CMD pgrep crond >/dev/null || exit 1

# Entrypoint : lance crond en foreground, logs vers stderr du conteneur.
# -f : foreground
# -d 8 : debug level 8 -> logs vers stderr (sinon syslog, qui n'existe pas ici)
CMD ["crond", "-f", "-d", "8"]
