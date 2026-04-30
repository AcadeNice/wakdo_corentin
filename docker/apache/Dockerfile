# Wakdo - image web Apache httpd (reverse proxy FastCGI vers PHP-FPM)
#
# Base : httpd:2.4-alpine (stable, legere, maintenue par Apache Foundation).
# Role : servir les assets statiques (HTML, CSS, JS, images) et relayer les
#        requetes *.php vers wakdo-app:9000 en FastCGI.
# Reseau : attache a la fois au reseau interne wakdo_internal (pour parler
#          a wakdo-app) et au reseau externe du reverse proxy (pour Traefik).

FROM httpd:2.4-alpine

# httpd:2.4-alpine compile mod_proxy_fcgi mais ne le charge pas par defaut.
# On le charge dans la conf (voir httpd.conf) plutot que de rebuild l'image.
# On installe juste curl pour le healthcheck.
RUN set -eux; \
    apk add --no-cache curl; \
    rm -rf /var/cache/apk/* /tmp/*

# Copie de la conf projet :
# - httpd.conf      : main config avec LoadModule + include vhosts
# - vhost.conf      : vhosts kiosk + admin/API + reverse FCGI
# - mpm.conf        : tuning MPM event (workers)
COPY httpd.conf /usr/local/apache2/conf/httpd.conf
COPY vhost.conf /usr/local/apache2/conf/extra/wakdo-vhost.conf
COPY mpm.conf   /usr/local/apache2/conf/extra/wakdo-mpm.conf

# Le DocumentRoot doit exister dans l'image meme si le code source est
# bind-mounte en dev. Sans ca, Apache refuse de demarrer.
RUN mkdir -p /var/www/html/public

# Fichier statique servi par le vhost healthz (Alias /healthz). Evite
# le RewriteRule [R=200] qui declenche le template ErrorDocument d'Apache
# et pollue le body de la reponse.
# Place dans /usr/local/apache2/htdocs/ et non /var/www/html/ : ce dernier
# est bind-monte depuis ./src au runtime (cf. docker-compose.yml), ce qui
# masquerait le fichier copie ici. /usr/local/apache2/htdocs/ est un chemin
# Apache natif jamais bind-monte.
COPY healthz.txt /usr/local/apache2/htdocs/healthz.txt

# Healthcheck : vhost par defaut (0.0.0.0:80) doit repondre.
# Le endpoint /healthz est defini dans vhost.conf, repond 200 "ok".
HEALTHCHECK --interval=30s --timeout=5s --start-period=5s --retries=3 \
    CMD curl -fsS http://localhost/healthz || exit 1

EXPOSE 80
