#!/bin/sh
# Usuario de mínimo privilegio: solo bases cuyo nombre empieza por condor_restore_.
# No puede leer ni tocar ninguna otra base del servidor.
set -eu
app_password="$(cat /run/secrets/app_password)"
mariadb -uroot -p"$(cat /run/secrets/root_password)" <<SQL
CREATE USER IF NOT EXISTS 'condor_restore'@'%' IDENTIFIED BY '${app_password}';
GRANT ALL PRIVILEGES ON \`condor\\_restore\\_%\`.* TO 'condor_restore'@'%';
FLUSH PRIVILEGES;
SQL
