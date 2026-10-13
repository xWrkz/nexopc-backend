#!/bin/bash
set -e

mkdir -p /var/www/html/wp-content/mu-plugins
cp -a /opt/nexopc-mu-plugins/. /var/www/html/wp-content/mu-plugins/

exec /usr/local/bin/docker-entrypoint.sh "$@"
