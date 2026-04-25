#!/bin/sh
set -e

PGVER=15
PGBIN="/usr/lib/postgresql/$PGVER/bin"

# Wait for PostgreSQL to accept connections.
echo "Waiting for PostgreSQL to be ready..."
RETRIES=30
until su - postgres -c "$PGBIN/pg_isready -h localhost -p 5432" > /dev/null 2>&1; do
  RETRIES=$((RETRIES - 1))
  if [ "$RETRIES" -le 0 ]; then
    echo "ERROR: PostgreSQL did not become ready in time."
    exit 1
  fi
  sleep 1
done
echo "PostgreSQL is ready."

# Application role.
ROLE_EXISTS="$(su - postgres -c "$PGBIN/psql -Atqc \"SELECT 1 FROM pg_roles WHERE rolname = '$DB_USERNAME'\" postgres" || true)"
if [ "$ROLE_EXISTS" = "1" ]; then
  su - postgres -c "$PGBIN/psql -v ON_ERROR_STOP=1 -d postgres -c \"ALTER ROLE \\\"$DB_USERNAME\\\" LOGIN PASSWORD '$DB_PASSWORD';\""
else
  su - postgres -c "$PGBIN/psql -v ON_ERROR_STOP=1 -d postgres -c \"CREATE ROLE \\\"$DB_USERNAME\\\" LOGIN PASSWORD '$DB_PASSWORD';\""
fi

# Application database.
DB_EXISTS="$(su - postgres -c "$PGBIN/psql -Atqc \"SELECT 1 FROM pg_database WHERE datname = '$DB_NAME'\" postgres" || true)"
if [ "$DB_EXISTS" != "1" ]; then
  echo "Creating database \"$DB_NAME\"..."
  su - postgres -c "$PGBIN/psql -v ON_ERROR_STOP=1 -d postgres -c \"CREATE DATABASE \\\"$DB_NAME\\\" OWNER \\\"$DB_USERNAME\\\";\""
fi

# Apply baseline schema once. The session table is the only one Phase 1 needs;
# Phase 2 will introduce migrations for antibodies / agents / etc.
SESSION_EXISTS="$(su - postgres -c "$PGBIN/psql -Atqc \"SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'session'\" \"$DB_NAME\"" || true)"
if [ "$SESSION_EXISTS" != "1" ] && [ -f /var/www/html/sql/init.sql ]; then
  echo "Applying sql/init.sql..."
  su - postgres -c "$PGBIN/psql -v ON_ERROR_STOP=1 -d \"$DB_NAME\" -f /var/www/html/sql/init.sql"
fi

# Grant privileges across every existing schema.
echo "Granting privileges to \"$DB_USERNAME\"..."
GRANT_FILE=$(mktemp)
cat > "$GRANT_FILE" <<EOSQL
DO \$body\$
DECLARE
    _sch text;
BEGIN
    FOR _sch IN SELECT nspname FROM pg_namespace WHERE nspname NOT LIKE 'pg_%' AND nspname <> 'information_schema' LOOP
        EXECUTE format('GRANT ALL PRIVILEGES ON SCHEMA %I TO "$DB_USERNAME"', _sch);
        EXECUTE format('GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA %I TO "$DB_USERNAME"', _sch);
        EXECUTE format('GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA %I TO "$DB_USERNAME"', _sch);
        EXECUTE format('GRANT ALL PRIVILEGES ON ALL FUNCTIONS IN SCHEMA %I TO "$DB_USERNAME"', _sch);
    END LOOP;
END
\$body\$;
EOSQL
chmod 644 "$GRANT_FILE"
su - postgres -c "$PGBIN/psql -v ON_ERROR_STOP=1 -d \"$DB_NAME\" -f \"$GRANT_FILE\""
rm -f "$GRANT_FILE"

echo "Database initialization complete."
