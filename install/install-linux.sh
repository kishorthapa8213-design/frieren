#!/bin/sh
set -eu

SOURCE_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
INSTALL_DIR=/opt/frieren
DATA_DIR=/var/lib/frieren
NGINX_SITE=/etc/nginx/sites-available/frieren
SUDOERS_FILE=/etc/sudoers.d/frieren

umask 022

fail() {
    echo "Frieren Linux installer: $*" >&2
    exit 1
}

[ "$(id -u)" -eq 0 ] || fail "run this installer as root (for example: sudo $0)"
[ -f /etc/os-release ] || fail "this installer supports Debian, Ubuntu, and Kali Linux only"

. /etc/os-release
case "${ID:-} ${ID_LIKE:-}" in
    *debian*|*ubuntu*|*kali*) ;;
    *) fail "unsupported distribution: ${PRETTY_NAME:-unknown}; Debian/Ubuntu/Kali are supported" ;;
esac

case "$(uname -m)" in
    x86_64|amd64) ;;
    *) fail "unsupported architecture: $(uname -m); this installer targets x86_64/amd64" ;;
esac

[ -f "$SOURCE_DIR/frieren-front/package.json" ] || fail "run this from a Frieren source checkout"
SOURCE_REAL=$(realpath -m "$SOURCE_DIR")
INSTALL_REAL=$(realpath -m "$INSTALL_DIR")
[ "$SOURCE_REAL" != "$INSTALL_REAL" ] || fail "the install directory must not be the source checkout"
[ "$INSTALL_REAL" = "$INSTALL_DIR" ] || fail "the install directory must not be a symlink"
[ ! -L "$DATA_DIR" ] || fail "$DATA_DIR must not be a symlink"
[ ! -L "$NGINX_SITE" ] || fail "$NGINX_SITE must not be a symlink"
[ ! -L "$SUDOERS_FILE" ] || fail "$SUDOERS_FILE must not be a symlink"
[ ! -L /etc/systemd/system/frieren-wireless-monitor.service ] \
    || fail "the Frieren monitor service path must not be a symlink"
[ ! -L /usr/local/sbin/frieren-package-manager ] \
    || fail "the Frieren package-manager path must not be a symlink"
[ ! -L /usr/local/sbin/frieren-wireless-monitor ] \
    || fail "the Frieren monitor helper path must not be a symlink"
case "$SOURCE_REAL/" in
    "$INSTALL_REAL/"*) fail "keep the source checkout outside $INSTALL_DIR" ;;
esac

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y ca-certificates curl gnupg
install -d -m 0755 /etc/apt/keyrings
curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key \
    | gpg --dearmor --yes -o /etc/apt/keyrings/nodesource.gpg
echo "deb [arch=amd64 signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main" \
    >/etc/apt/sources.list.d/nodesource.list
apt-get update
apt-get install -y \
    ca-certificates curl git iproute2 iputils-ping iw nginx network-manager \
    nodejs php-cli php-curl php-fpm php-mbstring php-sqlite3 php-xml \
    rsync sudo traceroute usbutils

TTYD_VERSION=1.7.7
TTYD_SHA256=8a217c968aba172e0dbf3f34447218dc015bc4d5e59bf51db2f2cd12b7be4f55
TTYD_TMP=$(mktemp)
if ! curl -fsSL "https://github.com/tsl0922/ttyd/releases/download/${TTYD_VERSION}/ttyd.x86_64" -o "$TTYD_TMP"; then
    rm -f "$TTYD_TMP"
    fail "unable to download ttyd ${TTYD_VERSION}"
fi
if ! printf '%s  %s\n' "$TTYD_SHA256" "$TTYD_TMP" | sha256sum --check --status; then
    rm -f "$TTYD_TMP"
    fail "ttyd ${TTYD_VERSION} checksum verification failed"
fi
install -o root -g root -m 0755 "$TTYD_TMP" /usr/bin/ttyd
rm -f "$TTYD_TMP"

NODE_VERSION=$(node --version 2>/dev/null || true)
NODE_MAJOR=$(printf '%s' "$NODE_VERSION" | sed 's/^v//' | cut -d. -f1)
case "$NODE_MAJOR" in
    ''|*[!0-9]*) fail "Node.js 22 or newer is required; install it and rerun (found ${NODE_VERSION:-none})" ;;
esac
[ "$NODE_MAJOR" -ge 22 ] || fail "Node.js 22 or newer is required; found $NODE_VERSION"

if ! command -v corepack >/dev/null 2>&1; then
    npm install --global corepack
fi
corepack enable

mkdir -p "$INSTALL_DIR" "$DATA_DIR" /etc/nginx/sites-available /etc/nginx/sites-enabled
[ ! -L "$DATA_DIR/auth.json" ] || fail "$DATA_DIR/auth.json must not be a symlink"
[ ! -L "$DATA_DIR/settings.json" ] || fail "$DATA_DIR/settings.json must not be a symlink"
rsync -a --delete \
    --filter='P /frieren-back/modules/***' \
    --exclude='.git/' \
    --exclude='node_modules/' \
    --exclude='vendor/' \
    --exclude='dist/' \
    --exclude='.phpunit.cache/' \
    "$SOURCE_DIR/" "$INSTALL_DIR/"

cd "$INSTALL_DIR/frieren-terminal"
corepack yarn install --frozen-lockfile
corepack yarn build

cd "$INSTALL_DIR/frieren-front"
corepack yarn install --immutable
VITE_TERMINAL_PATH=/terminal/ws corepack yarn build

PHP_VERSION=$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
PHP_FPM_SERVICE="php${PHP_VERSION}-fpm"
PHP_FPM_SOCKET="/run/php/php${PHP_VERSION}-fpm.sock"
[ -S "$PHP_FPM_SOCKET" ] || fail "PHP-FPM socket not found at $PHP_FPM_SOCKET"

install -o root -g root -m 0755 \
    "$INSTALL_DIR/frieren-back/modules/packages/bin/package-manager-call.sh" \
    /usr/local/sbin/frieren-package-manager
install -o root -g root -m 0755 \
    "$INSTALL_DIR/install/frieren-wireless-monitor.sh" \
    /usr/local/sbin/frieren-wireless-monitor
install -o root -g root -m 0644 \
    "$INSTALL_DIR/install/frieren-wireless-monitor.service" \
    /etc/systemd/system/frieren-wireless-monitor.service

cat > "$SUDOERS_FILE" <<'EOF'
www-data ALL=(root) NOPASSWD: /usr/local/sbin/frieren-package-manager *
www-data ALL=(root) NOPASSWD: /usr/local/sbin/frieren-wireless-monitor *
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl *
www-data ALL=(root) NOPASSWD: /usr/bin/nmcli *
www-data ALL=(root) NOPASSWD: /usr/bin/hostnamectl *
www-data ALL=(root) NOPASSWD: /usr/bin/timedatectl *
www-data ALL=(root) NOPASSWD: /usr/bin/date *
www-data ALL=(root) NOPASSWD: /usr/bin/ttyd *
www-data ALL=(root) NOPASSWD: /usr/bin/pkill -x ttyd
EOF
chmod 0440 "$SUDOERS_FILE"
visudo -cf "$SUDOERS_FILE" >/dev/null || fail "invalid sudoers configuration"

mkdir -p "$DATA_DIR"
if [ ! -f "$DATA_DIR/auth.json" ]; then
    [ -r /dev/tty ] && [ -w /dev/tty ] || fail "an interactive terminal is required to set the admin password"
    printf 'Create the Frieren admin password: ' >/dev/tty
    stty -echo </dev/tty
    restore_terminal_echo() {
        stty echo </dev/tty 2>/dev/null || true
    }
    trap 'restore_terminal_echo; exit 1' HUP INT TERM
    trap restore_terminal_echo EXIT
    IFS= read -r ADMIN_PASSWORD </dev/tty
    printf '\nConfirm the Frieren admin password: ' >/dev/tty
    IFS= read -r ADMIN_PASSWORD_CONFIRM </dev/tty
    stty echo </dev/tty
    trap - EXIT HUP INT TERM
    printf '\n' >/dev/tty
    [ -n "$ADMIN_PASSWORD" ] || fail "the admin password must not be empty"
    [ "$ADMIN_PASSWORD" = "$ADMIN_PASSWORD_CONFIRM" ] || fail "the admin passwords did not match"
    PASSWORD_HASH=$(printf '%s' "$ADMIN_PASSWORD" | php -r '$password = stream_get_contents(STDIN); echo password_hash($password, PASSWORD_DEFAULT);')
    unset ADMIN_PASSWORD ADMIN_PASSWORD_CONFIRM
    AUTH_TEMP=$(mktemp "$DATA_DIR/auth.json.XXXXXX")
    printf '{"passwordHash":"%s"}\n' "$PASSWORD_HASH" >"$AUTH_TEMP"
    chmod 0640 "$AUTH_TEMP"
    mv -f "$AUTH_TEMP" "$DATA_DIR/auth.json"
fi

[ -f "$DATA_DIR/settings.json" ] || printf '{}\n' >"$DATA_DIR/settings.json"
chown -R www-data:www-data "$DATA_DIR"
chmod 0750 "$DATA_DIR"
chmod 0640 "$DATA_DIR/auth.json" "$DATA_DIR/settings.json"
if getent group systemd-journal >/dev/null 2>&1; then
    usermod -a -G systemd-journal www-data
fi

# Keep built-in PHP sources root-owned and read-only to the web worker. The
# modules directory itself is writable so the authenticated module installer
# can create/remove third-party modules.
chown root:www-data "$INSTALL_DIR/frieren-back/modules"
chmod 2775 "$INSTALL_DIR/frieren-back/modules"
chmod 0755 \
    "$INSTALL_DIR/frieren-back/modules/system/bin/diagnostics-linux.sh" \
    "$INSTALL_DIR/frieren-back/modules/packages/bin/package-manager-call.sh" \
    "$INSTALL_DIR/frieren-back/modules/packages/bin/dependency-installer.sh"

cat >"$NGINX_SITE" <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root $INSTALL_DIR/frieren-front/dist;
    index index.html;

    location = /api/index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $INSTALL_DIR/frieren-back/api/index.php;
        fastcgi_param SCRIPT_NAME /api/index.php;
        fastcgi_pass unix:$PHP_FPM_SOCKET;
    }

    location ~ ^/modules/.*\\.php$ {
        deny all;
    }

    location /modules/ {
        alias $INSTALL_DIR/frieren-back/modules/;
        autoindex off;
    }

    location /terminal/ {
        proxy_pass http://127.0.0.1:5001/;
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host \$host;
        proxy_read_timeout 365d;
        proxy_send_timeout 365d;
    }

    location ~ \\.php$ {
        return 404;
    }

    location / {
        try_files \$uri \$uri/ /index.html;
    }
}
EOF

if [ -L /etc/nginx/sites-enabled/default ]; then
    DEFAULT_SITE_TARGET=$(readlink -f /etc/nginx/sites-enabled/default)
    [ -f "$DEFAULT_SITE_TARGET" ] || fail "cannot resolve the existing nginx default site"
    DEFAULT_SITE_BACKUP="$DATA_DIR/nginx-default-enabled-target"
    if [ ! -e "$DEFAULT_SITE_BACKUP" ] && [ ! -L "$DEFAULT_SITE_BACKUP" ]; then
        ln -s "$DEFAULT_SITE_TARGET" "$DEFAULT_SITE_BACKUP"
    fi
    rm -- /etc/nginx/sites-enabled/default
elif [ -e /etc/nginx/sites-enabled/default ]; then
    fail "move the existing nginx default site out of sites-enabled before installing Frieren"
fi
ln -sfn "$NGINX_SITE" /etc/nginx/sites-enabled/frieren
nginx -t || fail "nginx configuration validation failed"
systemctl enable --now "$PHP_FPM_SERVICE" nginx
systemctl restart "$PHP_FPM_SERVICE"
systemctl daemon-reload
systemctl enable frieren-wireless-monitor.service

if ! systemctl is-active --quiet NetworkManager; then
    echo "NetworkManager is installed but not active. Enable it when safe for this host's network:"
    echo "  sudo systemctl enable --now NetworkManager"
fi

echo "Frieren installed at $INSTALL_DIR"
echo "Open http://<host-address>/ and log in as admin."
echo "Use HTTPS before exposing the panel beyond a trusted local network."
