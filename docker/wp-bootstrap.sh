#!/bin/sh
# Idempotent first-boot provisioning: installs WordPress into an empty database and
# activates the plugins/theme baked into the image. Safe to run on every task start.
# Run WP-CLI as the PHP-FPM user: files it creates (e.g. WooCommerce's uploads
# placeholders) must stay writable by WordPress.
WP="runuser -u www-data -- wp --path=/var/www/html"
log() { echo "[wp-bootstrap] $*"; }

# A fresh uploads volume is created root-owned; WordPress writes media and
# Elementor's generated CSS there.
mkdir -p /var/www/html/wp-content/uploads
chown www-data:www-data /var/www/html/wp-content/uploads

db_ready() {
  php -r 'mysqli_report(MYSQLI_REPORT_OFF); $h = explode(":", getenv("WORDPRESS_DB_HOST") . ":3306"); exit(@mysqli_connect($h[0], getenv("WORDPRESS_DB_USER"), getenv("WORDPRESS_DB_PASSWORD"), getenv("WORDPRESS_DB_NAME"), (int) $h[1]) ? 0 : 1);'
}

i=0
until [ -f /var/www/html/wp-config.php ] && db_ready; do
  i=$((i + 1))
  [ "$i" -gt 60 ] && { log "database not reachable, giving up"; exit 1; }
  sleep 5
done

if ! $WP core is-installed 2>/dev/null; then
  if [ -z "$WP_ADMIN_PASSWORD" ] || [ -z "$WP_SITE_URL" ]; then
    log "WP_ADMIN_PASSWORD/WP_SITE_URL not set; skipping install"
    exit 0
  fi
  log "installing WordPress"
  $WP core install \
    --url="$WP_SITE_URL" \
    --title="${WP_SITE_TITLE:-AutoSourceChina}" \
    --admin_user="${WP_ADMIN_USER:-admin}" \
    --admin_password="$WP_ADMIN_PASSWORD" \
    --admin_email="${WP_ADMIN_EMAIL:-admin@example.com}" \
    --skip-email || $WP core is-installed || exit 1
fi

# DISALLOW_FILE_MODS does not affect WP-CLI activation.
for p in ${WP_ACTIVATE_PLUGINS:-woocommerce amazon-s3-and-cloudfront blocksy-companion elementor wpforms-lite}; do
  $WP plugin activate "$p" || log "failed to activate $p"
done
[ -n "$WP_ACTIVATE_THEME" ] && $WP theme activate "$WP_ACTIVATE_THEME"
log "done"
