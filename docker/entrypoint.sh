#!/bin/sh
set -eu
mkdir -p /app/var/cache /app/var/log
exec "$@"
