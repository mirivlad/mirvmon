#!/bin/sh

set -eu

if [ -n "${DB_PASSWORD_FILE:-}" ]; then
    if [ ! -r "$DB_PASSWORD_FILE" ]; then
        echo "DB_PASSWORD_FILE is not readable." >&2
        exit 1
    fi
    DB_PASSWORD="$(cat "$DB_PASSWORD_FILE")"
    export DB_PASSWORD
fi

if [ -n "${APP_KEY_FILE:-}" ]; then
    if [ ! -r "$APP_KEY_FILE" ]; then
        echo "APP_KEY_FILE is not readable." >&2
        exit 1
    fi
    APP_KEY="$(cat "$APP_KEY_FILE")"
    export APP_KEY
fi

if [ -n "${SETUP_TOKEN_FILE:-}" ]; then
    if [ ! -r "$SETUP_TOKEN_FILE" ]; then
        echo "SETUP_TOKEN_FILE is not readable." >&2
        exit 1
    fi
    SETUP_TOKEN="$(cat "$SETUP_TOKEN_FILE")"
    export SETUP_TOKEN
fi

for required_name in DB_HOST DB_NAME DB_USERNAME DB_PASSWORD APP_KEY SETUP_TOKEN; do
    eval "required_value=\${$required_name:-}"
    if [ -z "$required_value" ]; then
        echo "$required_name is required." >&2
        exit 1
    fi
done

if [ "${#DB_PASSWORD}" -lt 16 ]; then
    echo "DB_PASSWORD must contain at least 16 characters." >&2
    exit 1
fi

if [ "${#SETUP_TOKEN}" -lt 32 ]; then
    echo "SETUP_TOKEN must contain at least 32 characters." >&2
    exit 1
fi

# The dollar expressions below are PHP variables, not shell variables.
# shellcheck disable=SC2016
php -r '
if (version_compare(PHP_VERSION, "8.5.0", "<")) {
    fwrite(STDERR, "PHP 8.5 or newer is required.\n");
    exit(1);
}
foreach (["curl", "intl", "pcntl", "pdo_pgsql", "sodium"] as $extension) {
    if (!extension_loaded($extension)) {
        fwrite(STDERR, "Missing PHP extension: {$extension}\n");
        exit(1);
    }
}
$key = base64_decode((string) getenv("APP_KEY"), true);
if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
    fwrite(STDERR, "APP_KEY must be a base64-encoded 32-byte key.\n");
    exit(1);
}
'

attempt=1
max_attempts="${DB_STARTUP_ATTEMPTS:-60}"
until php -r '
require "/app/vendor/autoload.php";
try {
    App\Database\ConnectionFactory::fromEnvironment()->query("SELECT 1");
} catch (Throwable) {
    exit(1);
}
' >/dev/null 2>&1; do
    if [ "$attempt" -ge "$max_attempts" ]; then
        echo "Database did not become ready after $max_attempts attempts." >&2
        exit 1
    fi
    attempt=$((attempt + 1))
    sleep 2
done

php /app/bin/migrate

exec "$@"
