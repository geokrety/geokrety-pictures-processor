#!/bin/sh
set -e

# first arg is `-f` or `--some-option`
if [ "$1" = 'apache2-foreground' ]; then
    # Generate and optimize autoloader
    composer dump-autoload --optimize
fi

exec "$@"
