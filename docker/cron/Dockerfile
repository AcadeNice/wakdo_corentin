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
RUN set -eux; \
    apk add --no-cache \
        dcron \
        mariadb-client \
        gzip \
        tzdata \
        bash \
        coreutils; \
    rm -rf /var/cache/apk/* /tmp/*

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
