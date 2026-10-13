#!/bin/sh
set -e

# Provision in the background: the official entrypoint creates wp-config.php and
# then exec's php-fpm, which must remain PID 1's process.
/usr/local/bin/wp-bootstrap.sh &

exec docker-entrypoint.sh "$@"
