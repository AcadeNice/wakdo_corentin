#!/bin/bash
#
# Wakdo - durcissement du privilege du user applicatif (moindre privilege).
#
# L'image mariadb cree MARIADB_USER avec GRANT ALL PRIVILEGES sur la base
# MARIADB_DATABASE. C'est trop large : le code applicatif expose (back-office)
# n'a besoin que de DML, jamais de DDL (CREATE/ALTER/DROP), de GRANT OPTION ni
# de DROP. Les migrations tournent separement en root (db/migrate.sh).
#
# Ce script s'execute UNIQUEMENT au premier demarrage sur volume vierge
# (/docker-entrypoint-initdb.d). Pour une base deja initialisee, appliquer le
# meme REVOKE/GRANT manuellement en root (voir db/init/README ou la PR).
#
# Set retenu : DML (SELECT/INSERT/UPDATE/DELETE) + ce dont mysqldump peut avoir
# besoin (SHOW VIEW, TRIGGER, LOCK TABLES). Pas de DDL, pas de GRANT, pas de DROP.
set -euo pipefail

mariadb --protocol=socket -uroot -p"${MARIADB_ROOT_PASSWORD}" <<-EOSQL
	REVOKE ALL PRIVILEGES ON \`${MARIADB_DATABASE}\`.* FROM '${MARIADB_USER}'@'%';
	GRANT SELECT, INSERT, UPDATE, DELETE, SHOW VIEW, TRIGGER, LOCK TABLES
	    ON \`${MARIADB_DATABASE}\`.* TO '${MARIADB_USER}'@'%';
	FLUSH PRIVILEGES;
EOSQL

echo "[init] privilege du user '${MARIADB_USER}' restreint au moindre privilege sur '${MARIADB_DATABASE}'."
