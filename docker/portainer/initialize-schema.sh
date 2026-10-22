#!/bin/sh
set -eu
if [ -f /deployment-state/schema-ready ]; then
    # Updates must be backed up and migrated explicitly by the operator.
    php bin/console doctrine:migrations:up-to-date --no-interaction
else
    php bin/console doctrine:migrations:migrate --no-interaction
fi
php bin/console messenger:setup-transports --no-interaction
touch /deployment-state/schema-ready
