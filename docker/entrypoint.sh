#!/bin/sh
set -e
# When code is bind-mounted, vendor may be missing; install it once
if [ ! -d vendor ] || [ ! -f vendor/autoload.php ]; then
  composer install --no-dev --optimize-autoloader --no-interaction
fi
exec "$@"
