#!/usr/bin/env bash
# Provision script for Ubuntu 22.04+ (adapt for 25.04 as needed)
set -euo pipefail

# Variables
APP_USER=deploy
APP_DIR=/var/www/dontplay
REPO_URL="<REPLACE_WITH_GIT_URL>"
NODE_VERSION=24

echo "Updating system..."
apt update && apt upgrade -y

echo "Installing essentials..."
apt install -y curl git build-essential ufw fail2ban nginx certbot python3-certbot-nginx

# Node.js
curl -fsSL https://deb.nodesource.com/setup_${NODE_VERSION}.x | bash -
apt install -y nodejs
npm install -g pm2

# Create app user
if ! id -u "$APP_USER" >/dev/null 2>&1; then
  adduser --disabled-password --gecos "" $APP_USER
  usermod -aG sudo $APP_USER
fi

# Create directories
mkdir -p $APP_DIR
chown -R $APP_USER:$APP_USER $APP_DIR
mkdir -p /var/lib/dontplay
chown -R $APP_USER:$APP_USER /var/lib/dontplay

# Clone repo (if REPO_URL set)
if [ "$REPO_URL" != "<REPLACE_WITH_GIT_URL>" ]; then
  sudo -u $APP_USER git clone "$REPO_URL" "$APP_DIR"
fi

# Nginx default config - link later
systemctl enable nginx
systemctl start nginx

# UFW
ufw default deny incoming
ufw default allow outgoing
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

# Fail2Ban enable
systemctl enable fail2ban

cat <<'EOF'
Provision complete.
Next steps:
 - Edit /var/www/dontplay/.env (copy .env.production.example)
 - As $APP_USER: cd /var/www/dontplay && npm install --production
 - Setup pm2: pm2 start ecosystem.config.js --env production; pm2 save
 - Configure nginx: create site file and obtain TLS with certbot
EOF
