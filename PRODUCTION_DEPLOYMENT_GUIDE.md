# GUIDE DE DÉPLOIEMENT EN PRODUCTION - DONTPLAY

## 1. RECOMMANDATIONS VPS

### Pour Petite Équipe / MVP (< 100k users/mois)

**Provider:** DigitalOcean Droplet ou AWS Lightsail
**Configuration:**
- OS: Ubuntu 24.04 LTS
- RAM: 4GB
- vCPU: 2-core
- Storage: 80GB SSD
- Bandwidth: 4TB/mois
- Estimé: **$24/mois DigitalOcean | $20/mois AWS**

**Avantages:**
- Simple à déployer (1-click apps)
- Pricing prévisible
- Uptime 99.99%
- Bon support

**Installation rapide:**
```bash
# SSH sur droplet
ssh root@your_droplet_ip

# Update système
apt update && apt upgrade -y

# Installer Node.js 24.x
curl -fsSL https://deb.nodesource.com/setup_24.x | sudo -E bash -
apt install -y nodejs

# Installer Nginx
apt install -y nginx

# Installer PM2 globalement
npm install -g pm2

# Installer Certbot (SSL)
apt install -y certbot python3-certbot-nginx
```

---

### Pour Trafic Moyen (100k-1M users/mois)

**Provider:** Hetzner CPX31 ou Vultr Cloud Compute
**Configuration:**
- OS: Ubuntu 24.04 LTS
- RAM: 8GB
- vCPU: 4-core
- Storage: 160GB NVMe
- Estimé: **€10.49/mois Hetzner | $20/mois Vultr**

**Infrastructure:**
- 1x Main server (app + nginx)
- Redis pour sessions (1GB)
- Backups quotidiens (auto)
- CloudFlare CDN gratuit

---

### Pour Production Fort Trafic (> 1M users/mois)

**Provider:** AWS Auto-Scaling ou OVH Bare Metal
**Configuration:**
- Load Balancer (ALB)
- 3x servers (t3.large minimum)
- RDS PostgreSQL (au lieu de JSON)
- S3 pour assets
- CloudFront CDN
- ElastiCache Redis
- Estimé: **$200-500+/mois**

**Architecture:**
```
Internet
  ↓
CloudFlare (DDoS/WAF/Cache)
  ↓
AWS ALB (Load Balancer)
  ↓
[Server 1] [Server 2] [Server 3] (Auto-scaling)
  ↓
RDS PostgreSQL + ElastiCache Redis
```

---

## 2. DÉPLOIEMENT NGINX REVERSE PROXY

### Configuration Nginx (sécurisée)

```nginx
# /etc/nginx/sites-available/dontplay.conf

# Rate limit zones
limit_req_zone $binary_remote_addr zone=api_limit:10m rate=10r/s;
limit_req_zone $binary_remote_addr zone=auth_limit:10m rate=1r/s;
limit_req_zone $binary_remote_addr zone=sms_limit:10m rate=5r/s;

# Redirect HTTP → HTTPS
server {
    listen 80;
    listen [::]:80;
    server_name dontplay.com www.dontplay.com;
    return 301 https://$server_name$request_uri;
}

# Main HTTPS server
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name dontplay.com www.dontplay.com;

    # SSL Certificates (Let's Encrypt)
    ssl_certificate /etc/letsencrypt/live/dontplay.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/dontplay.com/privkey.pem;

    # SSL Configuration (A+ rating)
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 10m;
    ssl_stapling on;
    ssl_stapling_verify on;
    add_header Strict-Transport-Security "max-age=31536000; includeSubDomains; preload" always;

    # Security Headers
    add_header X-Frame-Options "DENY" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-XSS-Protection "1; mode=block" always;
    add_header Referrer-Policy "strict-origin-when-cross-origin" always;
    add_header Content-Security-Policy "default-src 'self'; script-src 'self' 'unsafe-inline' https://unpkg.com; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; img-src 'self' data: https:; font-src 'self' https://fonts.gstatic.com;" always;

    # Gzip compression
    gzip on;
    gzip_types text/plain text/css text/xml text/javascript application/x-javascript application/json;
    gzip_min_length 1024;
    gzip_disable "msie6";

    # Client max body size (5MB for SMS uploads)
    client_max_body_size 5m;

    # Upstream Node.js
    upstream node_app {
        server 127.0.0.1:3000;
        keepalive 64;
    }

    # API rate limiting
    location /api/auth/ {
        limit_req zone=auth_limit burst=2 nodelay;
        proxy_pass http://node_app;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }

    location /api/sender/sms/ {
        limit_req zone=sms_limit burst=5 nodelay;
        proxy_pass http://node_app;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }

    location /api/ {
        limit_req zone=api_limit burst=20 nodelay;
        proxy_pass http://node_app;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
    }

    # Main app proxy
    location / {
        proxy_pass http://node_app;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 90;
    }

    # Deny access to sensitive paths
    location ~ /\. { deny all; }
    location ~ /config\.js { deny all; }
    location ~ /data/ { deny all; }
    location ~ /\.env { deny all; }
    location ~ /node_modules/ { deny all; }
}
```

### Activer la configuration:
```bash
ln -s /etc/nginx/sites-available/dontplay.conf /etc/nginx/sites-enabled/
nginx -t  # Test syntax
systemctl restart nginx
```

---

## 3. CONFIGURATION HTTPS & SSL

### Let's Encrypt avec Certbot (GRATUIT)

```bash
# Générer certificat
certbot certonly --nginx -d dontplay.com -d www.dontplay.com

# Auto-renewal
systemctl enable certbot.timer
systemctl start certbot.timer

# Vérifier auto-renewal
certbot renew --dry-run
```

### Certificat Auto-Renew Check
```bash
# Test que le renouvellement automatique marche
certbot renew --dry-run

# Certificate expiration info
certbot certificates
```

---

## 4. PROCESS MANAGEMENT AVEC PM2

### Installation & Config

```bash
# Installer PM2
npm install -g pm2

# Créer ecosystem.config.js (DÉJÀ PRÉSENT)
# À la racine du projet

# Démarrer app avec PM2
pm2 start ecosystem.config.js --env production

# Sauvegarder startup config
pm2 startup
pm2 save

# Logs
pm2 logs
pm2 logs --lines 1000
```

### File: ecosystem.config.js (Vérifier/Adapter)
```javascript
module.exports = {
  apps: [
    {
      name: 'dontplay-api',
      script: 'server.js',
      instances: 'max',
      exec_mode: 'cluster',
      env: {
        NODE_ENV: 'production',
        PORT: 3000,
      },
      error_file: '/var/log/pm2/err.log',
      out_file: '/var/log/pm2/out.log',
      log_file: '/var/log/pm2/combined.log',
      time: true,
    },
  ],
};
```

### Monitoring Logs
```bash
# Real-time logs
pm2 monit

# View last 1000 lines
pm2 logs 0 --lines 1000

# Filter by app
pm2 logs dontplay-api
```

---

## 5. CONFIGURATION ENVIRONNEMENT PRODUCTION

### .env.production

```env
# Server
NODE_ENV=production
PORT=3000
HOST=0.0.0.0

# Database (JSON storage)
DB_PATH=/var/lib/dontplay/db.json

# Sessions
SESSION_SECRET=<GENERATE_WITH: node -e "console.log(require('crypto').randomBytes(32).toString('hex'))">
SESSION_NAME=sid

# SMS Configuration
SMS_PROVIDER_ACCOUNT=SSTM12-46
SMS_PROVIDER_PASSWORD=<CHANGE_REGULARLY>  # Change all 90 days
SMS_STORAGE_PATH=/var/lib/dontplay/sms.json

# Security
BCRYPT_ROUNDS=12
JWT_SECRET=<GENERATE_WITH: node -e "console.log(require('crypto').randomBytes(32).toString('hex'))">

# Rate Limiting (production values)
RATE_LIMIT_WINDOW=900000  # 15 min
RATE_LIMIT_MAX=5           # 5 attempts

# CORS (if needed)
CORS_ORIGIN=https://dontplay.com,https://www.dontplay.com

# Logging
LOG_LEVEL=info
LOG_FILE=/var/log/dontplay/app.log
```

### Générer secrets sécurisés:
```bash
node -e "console.log(require('crypto').randomBytes(32).toString('hex'))"
# Copier la sortie dans SESSION_SECRET, JWT_SECRET

# OU utiliser openssl:
openssl rand -hex 32
```

---

## 6. STRUCTURE RÉPERTOIRES PRODUCTION

```bash
# Créer répertoires
mkdir -p /var/lib/dontplay
mkdir -p /var/log/dontplay
mkdir -p /var/log/pm2

# Permissions
chown -R nodejs:nodejs /var/lib/dontplay
chown -R nodejs:nodejs /var/log/dontplay
chmod 750 /var/lib/dontplay

# Copier application
cp -r /root/dontplay-site /var/www/dontplay
chown -R nodejs:nodejs /var/www/dontplay
```

---

## 7. BACKUPS AUTOMATISÉS

### Script de backup quotidien

```bash
#!/bin/bash
# /root/backup-dontplay.sh

BACKUP_DIR="/backups/dontplay"
DATA_DIR="/var/lib/dontplay"
RETENTION_DAYS=30

# Create backup
mkdir -p $BACKUP_DIR
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
tar -czf $BACKUP_DIR/dontplay_$TIMESTAMP.tar.gz $DATA_DIR/

# Delete old backups (> 30 days)
find $BACKUP_DIR -name "dontplay_*.tar.gz" -mtime +$RETENTION_DAYS -delete

# Keep last 30 backups minimum
ls -t $BACKUP_DIR/dontplay_*.tar.gz | tail -n +31 | xargs -r rm

echo "Backup created: $BACKUP_DIR/dontplay_$TIMESTAMP.tar.gz"
```

### Ajouter à crontab:
```bash
crontab -e

# Ajouter:
0 2 * * * /root/backup-dontplay.sh  # Chaque jour à 2h du matin
```

---

## 8. MONITORING & ALERTES

### Vérification hebdomadaire:

```bash
# Vérifier certificat SSL expiration
certbot certificates

# Vérifier Node.js version
node --version

# Vérifier PM2 status
pm2 status

# Vérifier espace disque
df -h

# Vérifier logs pour erreurs
pm2 logs 0 --lines 100 | grep ERROR
```

### Health Check Script

```bash
#!/bin/bash
# /root/health-check.sh

DOMAIN="dontplay.com"
HEALTH_ENDPOINT="https://$DOMAIN/api/auth/csrf"

# Test API
curl -s -o /dev/null -w "%{http_code}" $HEALTH_ENDPOINT

# If not 200, send alert
if [ "$(curl -s -o /dev/null -w "%{http_code}" $HEALTH_ENDPOINT)" != "200" ]; then
    echo "ALERTE: API DOWN sur $DOMAIN" | mail -s "DontPlay Alert" admin@dontplay.com
    pm2 restart all
fi
```

### Cron pour health check:
```bash
# Toutes les 5 minutes
*/5 * * * * /root/health-check.sh
```

---

## 9. CHECKLIST PRÉ-PRODUCTION

- [ ] .env.production configuré avec secrets
- [ ] Base de données JSON backup (data/db.json)
- [ ] SMS provider credentials configuré (data/sms.json)
- [ ] Certificat SSL/TLS activé (Let's Encrypt)
- [ ] Nginx reverse proxy configuré avec rate limiting
- [ ] PM2 ecosystem.config.js optimisé
- [ ] IP whitelist admin (optionnel)
- [ ] Firewall UFW configuré
- [ ] Backups automatisés mise en place
- [ ] Health check script actif
- [ ] Logging centralisé (CloudWatch ou ELK)
- [ ] DDoS protection (CloudFlare)
- [ ] WAF rules (CloudFlare ou Nginx)
- [ ] Session cookies sécurisées
- [ ] CSP headers strictes
- [ ] Credentials jamais dans logs
- [ ] npm audit sans vulnerabilities
- [ ] Tests API end-to-end OK
- [ ] Load test < 80% CPU/RAM

---

## 10. COMMANDES DE DÉPLOIEMENT PRODUCTION

```bash
# SSH sur serveur
ssh root@your_vps_ip

# Clone repo (si git)
cd /var/www && git clone <your-repo> dontplay

# OU copier manuellement
scp -r /Users/local/dontplay root@vps_ip:/var/www/

# Installer dépendances
cd /var/www/dontplay
npm install --production

# Créer .env.production
nano .env.production  # Ajouter secrets

# Test server start
node server.js  # Ctrl+C pour arrêter

# Démarrer avec PM2
pm2 start ecosystem.config.js --env production

# Verify SSL
curl -I https://dontplay.com

# Test API
curl https://dontplay.com/api/auth/csrf
```

---

## 11. SÉCURITÉ LINUX - UFW FIREWALL

```bash
# Activer UFW
ufw enable

# Règles SSH (IMPORTANT!)
ufw allow 22/tcp
ufw allow 80/tcp
ufw allow 443/tcp

# Deny all incoming par défaut
ufw default deny incoming
ufw default allow outgoing

# Vérifier statut
ufw status

# Bloquer une IP (si attaque)
ufw deny from 192.168.1.1
```

---

## 12. CLOUDFLARE CONFIGURATION (Recommandé)

### Setup gratuit pour DDoS protection:

1. Enregistrer domaine sur Cloudflare
2. Copier nameservers (généralement 2)
3. Update chez registrar (GoDaddy, OVH, etc)
4. Attendre 24-48h propagation DNS
5. Setup dans Cloudflare Dashboard:
   - SSL/TLS: Flexible or Full (recommandé Full)
   - Speed: Enable Gzip, Brotli
   - Security: Enable WAF, Bot Management
   - Caching: Standard
   - Page Rules:
     - `/api/*` - Cache Level: Bypass
     - `/api/auth/*` - Challenge (CAPTCHA)

### WAF Rules (Free tier):
```
- Block: Country != Pays cible
- Block: Path matches /admin* (or IP whitelist)
- Challenge: Path matches /api/auth* (rate limit per IP)
- Block: User-Agent contains SQL injection patterns
```

---

## 13. MONITORING AVEC NEW RELIC (OPTIONNEL)

```bash
# Install agent
npm install newrelic

# Create newrelic.js
npx newrelic create-config-file

# Add license key from New Relic
nano newrelic.js

# Start app with agent
node -r newrelic server.js
```

---

## 14. PRODUCTION CHECKLIST FINAL

```
✅ VPS sélectionné et configuré
✅ OS: Ubuntu 24.04 LTS installé
✅ Node.js 24.x installé
✅ Nginx reverse proxy configuré
✅ SSL/TLS (Let's Encrypt) activé
✅ PM2 process manager setup
✅ Firewall (UFW) activé
✅ .env.production secrets configuré
✅ Backups quotidiens automatisés
✅ Health monitoring actif
✅ CloudFlare DDoS protection activé
✅ npm dependencies audit OK
✅ API endpoints testés (HTTPS)
✅ SMS provider credentials sécurisées
✅ Session cookies httpOnly + Secure
✅ CSRF protection validée
✅ Rate limiting par endpoint
✅ Logging centralisé (PM2 logs)
✅ Admin accès sécurisé (SSH keys only)
✅ Monitoring alertes configurées
```

---

## RÉSUMÉ COÛTS ESTIMÉS

| VPS | CPU | RAM | Storage | Prix/mois | Trafic |
|-----|-----|-----|---------|-----------|---------|
| DigitalOcean (4GB) | 2 | 4GB | 80GB SSD | $24 | 4TB |
| Hetzner CPX31 | 4 | 8GB | 160GB NVMe | €10.49 | Illim |
| AWS Lightsail (4GB) | 2 | 4GB | 80GB | $20 | 4TB |
| **+ CloudFlare** | - | - | - | **Free-$20** | DDoS |
| **TOTAL Startup** | - | - | - | **$20-50/mois** | - |

---

**LET'S ENCRYPT (gratuit!)** - Certificat SSL valide 90 jours, auto-renewal inclus.
**CloudFlare (gratuit!)** - DDoS protection, WAF, CDN, jusqu'à 200M requêtes/jour.
**PM2 (gratuit!)** - Process management avec cluster mode.

**Budget total première année: ~$300-600** (domaine + VPS + tools payants optionnels)

