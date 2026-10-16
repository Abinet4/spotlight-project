#!/usr/bin/env bash
# Starts the API container. ROLE picks what runs here:
#   all        web server + queue worker + scheduler in one container (default; photos on one disk)
#   web        web server only (also runs migrations)
#   worker     queue worker only (emails, texts)
#   scheduler  scheduler only (expires unpaid online orders)
set -euo pipefail
cd /var/www/html
ROLE="${ROLE:-all}"

say() { echo "[start] $*"; }
fail() { echo "[start] ERROR: $*" >&2; exit 1; }
as_www() { setpriv --reuid=www-data --regid=www-data --init-groups "$@"; }

case "$ROLE" in all|web|worker|scheduler) ;; *) fail "ROLE must be all, web, worker or scheduler (got '$ROLE')." ;; esac
[ -n "${APP_KEY:-}" ] || fail "APP_KEY is not set. Make one (see docs/DEPLOY-TEST.md) and add it to the variables."
[ -n "${DB_URL:-}${DB_HOST:-}" ] || fail "No database settings. Set DB_URL (or DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD)."

# Photos are saved under storage/app. Without a lasting disk there they vanish on the next deploy
# while the listings still point at them.
if { [ "$ROLE" = all ] || [ "$ROLE" = web ]; } && ! mountpoint -q storage/app; then
  say "WARNING: storage/app is not on a lasting disk (volume); uploaded photos will be lost on the next deploy."
fi

# A freshly attached disk is empty: make the folders Laravel writes to.
mkdir -p storage/app/public storage/app/private storage/framework/cache/data storage/framework/sessions \
  storage/framework/views storage/logs
chown -R www-data:www-data storage bootstrap/cache

# Settings are read once here; change a variable and restart (redeploy) for it to apply.
as_www php artisan optimize --no-interaction

if [ "$ROLE" = all ] || [ "$ROLE" = web ]; then
  # The database may still be starting on a fresh deploy.
  for attempt in $(seq 1 20); do
    if as_www php artisan migrate --force --no-interaction; then break; fi
    [ "$attempt" = 20 ] && fail "Could not reach the database to set up its tables."
    say "Database not ready yet, trying again in 3 seconds ($attempt/20)."
    sleep 3
  done
  # Sub-cities and categories on a new database; with SEED_DEMO=true also demo data, once.
  if [ "${SEED_DEMO:-false}" = true ]; then
    as_www php artisan marketplace:prepare --demo --no-interaction || fail "Demo data was not added (see the reason above)."
    # Demo photos added before the photo disk was attached are gone after a redeploy: draw them again.
    as_www php artisan marketplace:demo-pictures --no-interaction || say "Demo pictures were not redrawn (see the reason above)."
  else
    as_www php artisan marketplace:prepare --no-interaction
  fi

  if [ -n "${ADMIN_EMAIL:-}" ]; then
    [ -n "${ADMIN_PASSWORD:-}" ] || fail "ADMIN_EMAIL is set but ADMIN_PASSWORD is empty."
    as_www php artisan marketplace:create-admin "$ADMIN_EMAIL" --password-from-env --if-missing --no-interaction \
      || fail "The administrator was not created (see the reason above). Fix ADMIN_EMAIL or ADMIN_PASSWORD and redeploy."
  fi
fi

# Restarts a background command whenever it stops (the worker exits every hour on purpose to stay fresh).
keep_running() {
  while true; do
    as_www "$@" || say "'$*' stopped with an error; restarting."
    sleep 3
  done
}

# Apache refuses to start with more than one MPM, and some hosts (Railway) start the container with an
# extra one enabled. PHP here needs prefork, so keep only that one.
if [ "$ROLE" = all ] || [ "$ROLE" = web ]; then
  for module in /etc/apache2/mods-enabled/mpm_*.load; do
    [ -e "$module" ] || continue
    name=$(basename "$module" .load)
    [ "$name" = mpm_prefork ] && continue
    say "Turning off Apache's $name (only mpm_prefork works with PHP here)."
    rm -f "/etc/apache2/mods-enabled/$name.load" "/etc/apache2/mods-enabled/$name.conf"
  done
  [ -e /etc/apache2/mods-enabled/mpm_prefork.load ] || a2enmod -q mpm_prefork
  # If Apache's settings are still wrong, show where modules are loaded from before it stops.
  apache2ctl -t 2>/dev/null || grep -Rn "^[[:space:]]*LoadModule mpm_" /etc/apache2/apache2.conf /etc/apache2/*-enabled || true
fi

case "$ROLE" in
  all)
    keep_running php artisan queue:work --sleep=3 --tries=3 --max-time=3600 &
    keep_running php artisan schedule:work &
    say "Web server, queue worker and scheduler starting."
    exec apache2-foreground
    ;;
  web) exec apache2-foreground ;;
  worker) keep_running php artisan queue:work --sleep=3 --tries=3 --max-time=3600 ;;
  scheduler) keep_running php artisan schedule:work ;;
esac
