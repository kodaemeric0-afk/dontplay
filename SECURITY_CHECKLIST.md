# SECURITY CHECKLIST - DONTPLAY

## ✅ RÉALISÉ

### SMS Sending
- ✅ Test `/dev/send-sms` - **FONCTIONNEL** - SMS envoyé avec succès
- ✅ Provider API configuré - Endpoint `/sendsms` conforme
- ✅ Message template variables - `{nom}`, `{prenom}` supportés
- ✅ Multi-campaign simultané - 10 max par user (rate limit)
- ✅ Play/Pause/Resume - Routes présentes
- ✅ Live history/logs - Stockage JSON + API `/campaigns/:id/logs`
- ✅ Template SMS - CRUD complet + variables

### Redirections
- ✅ Création domain → domain - **TESTÉ OK**
- ✅ Création domain → external URL - **TESTÉ OK**
- ✅ Multi-redirect par domain - **TESTÉ OK** (même domaine, slugs différents)
- ✅ 301/302 redirects - Configurable
- ✅ Stats par redirect - Tracking clicks + daily breakdown

### Core Infrastructure
- ✅ Helmet headers de sécurité
- ✅ Session sécurisée (httpOnly, sameSite=strict)
- ✅ CSRF token validation
- ✅ Bcrypt password hashing
- ✅ Rate limiting auth (15 min lockout après 5 tentatives)
- ✅ Blocage fichiers sensibles (.env, db.js, node_modules, etc)

---

## ❌ À FAIRE - SÉCURITÉ CRITIQUE

### Input Validation & Sanitization
- [ ] Ajouter validator.js pour validation robuste
- [ ] Sanitizer pour XSS prevention (DOMPurify côté client, sanitize-html côté serveur)
- [ ] Validation des slugs redirections (alphanumeric + - _ uniquement)
- [ ] Validation des domaines (RFC 1123 compatible)
- [ ] Validation des URLs (scheme + domain validation)
- [ ] Protection contre l'injection de paramètres (qs pollution)
- [ ] Validation des numéros de téléphone (format international)
- [ ] Maxlength sur tous les inputs

### Output Encoding
- [ ] HTML encoding pour toutes les données sérialisées en JSON
- [ ] Content-Type: application/json; charset=utf-8 strictement appliqué
- [ ] Pas de reflection d'erreurs sensibles au client

### DDoS / Rate Limiting Avancé
- [ ] Rate limit global par IP (1000 req/min)
- [ ] Rate limit API campaigns (10 req/min par user)
- [ ] Rate limit sender SMS (100 req/min per user)
- [ ] Rate limit redirects création (50 req/min per user)
- [ ] Slowdown exponentiel après dépassement
- [ ] Captcha après 3 lockouts en 1h

### Logging & Monitoring
- [ ] Logging des accès (IP, user, action, timestamp)
- [ ] Logging des tentatives de sécurité (failed login, suspicious)
- [ ] Alertes sur patterns malveillants
- [ ] Retention logs = 30 jours max

### Secrets & Credentials
- [ ] Vérifier que .env n'est pas committé
- [ ] Rotation secrets sms.json credentials (changer password API tous les 90 jours)
- [ ] AWS Secrets Manager ou Vault pour production
- [ ] Pas de credentials en logs

### API Security
- [ ] Validation de toutes les sorties JSON (schéma)
- [ ] Endpoint versioning (/api/v1/ instead of /api/)
- [ ] Disable HTTP methods non nécessaires (OPTIONS, TRACE)
- [ ] X-Frame-Options: DENY
- [ ] X-Content-Type-Options: nosniff
- [ ] Strict-Transport-Security: max-age=31536000
- [ ] Content-Security-Policy stricte

### Database Security (JSON)
- [ ] Backup quotidien fichiers JSON
- [ ] Chiffrement données sensibles (mots de passe, clés API)
- [ ] Validation de l'intégrité JSON au démarrage
- [ ] Atomic writes (tmp file + rename)

### Admin & Accounts
- [ ] Force reset password au 1er login (si défaut)
- [ ] Forbid weak passwords (< 12 chars, pas spéciaux)
- [ ] 2FA optionnel pour admin
- [ ] Audit trail des actions admin
- [ ] Session timeout 30 min inactivité

### Deployment Security
- [ ] HTTPS/TLS obligatoire (port 443, redirect 80 → 443)
- [ ] Certificate pinning ou HSTS preload
- [ ] Disabled debug mode en production
- [ ] Secrets en variables d'env, jamais en code
- [ ] Minimal dependencies (audit npm weekly)

---

## VPS RECOMMENDATIONS

### Petite Équipe (< 100k users/mois)
**AWS EC2 t3.medium** or **DigitalOcean Droplet 4GB**
- 2 vCPU, 4GB RAM, 80GB SSD
- ~$20-30/mois
- Inclut: backups, DDoS protection basique, monitoring

### Scale Moyenne (100k-1M users/mois)
**AWS EC2 t3.large** or **Hetzner CPX31**
- 4 vCPU, 8GB RAM, 160GB NVMe
- ~$40-60/mois
- Ajouter: CloudFlare (DDoS + WAF), Redis pour sessions

### Production Fort Trafic (> 1M users/mois)
**AWS ELB + 3x EC2 c5.xlarge** or **OVH Bare Metal**
- Load balancing, Auto-scaling, Multi-AZ
- RDS pour DB au lieu de JSON
- CDN CloudFlare ou AWS CloudFront
- ~$200-500/mois minimum

---

## STACK SÉCURITÉ RECOMMANDÉE

```
Frontend:
- DOMPurify (sanitize user inputs)
- CSP meta tags
- HTTPS only (no mixed content)

Backend:
- express-validator (validation schemas)
- sanitize-html (output encoding)
- helmet (security headers)
- express-rate-limit (DDoS basic protection)
- morgan (HTTP logging)
- express-slow-down (gradual slowdown)

DevOps:
- CloudFlare (DDoS/WAF/cache)
- Let's Encrypt (free SSL)
- fail2ban (IDS)
- logrotate (log management)
- systemd timer (automated backups)
```

---

## NEXT STEPS

1. Implement input validation (validator.js)
2. Add output sanitization (sanitize-html)
3. Enhance rate limiting (per-endpoint)
4. Setup logging (morgan + file)
5. Choose VPS provider
6. Setup SSL/TLS + CloudFlare
7. Run OWASP ZAP scan
8. Penetration testing

