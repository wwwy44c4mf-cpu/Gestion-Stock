#!/bin/sh
set -eu
psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
    --set=ON_ERROR_STOP=1 \
    --set=app_user="$APP_DB_USER" \
    --set=app_database="$POSTGRES_DB" \
    --set=app_password="$(cat /run/secrets/db_password)" <<'SQL'
CREATE USER :"app_user" WITH PASSWORD :'app_password' NOSUPERUSER NOCREATEDB NOCREATEROLE;
ALTER DATABASE :"app_database" OWNER TO :"app_user";
SQL
