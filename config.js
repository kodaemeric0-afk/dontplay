/**
 * Dontplay — Configuration centrale
 * Modifiez ce fichier avant de passer en production.
 */

module.exports = {

  server: {
    port:     process.env.PORT      || 3000,
    host:     process.env.HOST      || '0.0.0.0',
    serverIp: process.env.SERVER_IP || '45.154.98.81',
  },

  certbot: {
    email:       process.env.CERTBOT_EMAIL    || '',
    webroot:     process.env.CERTBOT_WEBROOT  || '/var/www/certbot',
    phpFpmSock:  process.env.PHP_FPM_SOCK     || '/run/php/php8.3-fpm.sock',
    nginxConfDir:process.env.NGINX_CONF_DIR   || '/etc/nginx/conf.d',
    sslParamsFile: '/etc/letsencrypt/options-ssl-nginx.conf',
    dhparamFile:   '/etc/letsencrypt/ssl-dhparams.pem',
  },

  session: {
    secret: process.env.SESSION_SECRET || 'de0daa510033b5da6cb18817fdac3957068d266f4e08533c33903585a221a4931787424b44bb718c9bfeca23913dde54aca0b382acc933a9ded2572d546601aa',
    name: 'dmskeujdlsodkejfns5638209DksncHD.sid',
    maxAge: 2 * 60 * 60 * 1000,
  },

  security: {
    bcryptRounds: 12,
    maxLoginAttempts: 5,
    lockoutDuration: 30 * 60 * 1000,
    rateLimitWindow: 15 * 60 * 1000,
    rateLimitMax: 10,
    passwordMinLength: 10,
    passwordRequireUppercase: true,
    passwordRequireNumber: true,
    passwordRequireSpecial: true,
  },

  plans: {
    starter: {
      id: 'starter', name: 'Starter', price: 150,
      pagesMax: 1, domainsMax: 1, durationDays: 30,
      color: 'teal', features: ['1 page déployée', '1 nom de domaine', 'Firewall', 'Notifications Telegram', 'Apple Pay / Captcha'],
    },
    premium: {
      id: 'premium', name: 'Premium', price: 230,
      pagesMax: 2, domainsMax: 2, durationDays: 30,
      color: 'violet', features: ['2 pages déployées', '2 noms de domaine', 'Firewall avancé', 'Notifications Telegram', 'Apple Pay / Captcha'],
    },
    max: {
      id: 'max', name: 'Max', price: 400,
      pagesMax: 5, domainsMax: 5, durationDays: 30,
      color: 'amber', features: ['5 pages déployées', '5 noms de domaine', 'Firewall avancé', 'Notifications Telegram', 'Apple Pay / Captcha', 'License Sender', '1k Fiche DB / NL Check'],
    },
  },

  /**
   * Namecheap — Enregistrement de domaines
   */
  namecheap: {
    user:    process.env.NC_USER    || '',
    apiKey:  process.env.NC_API_KEY || '',
    testMode: process.env.NC_TEST_MODE === 'true',
  },

  /**
   * NowPayments.io — Paiements Crypto (BTC, ETH, SOL, LTC, USDT, USDC)
   */
  nowpayments: {
    apiKey:  process.env.NOWPAYMENTS_API_KEY || '',
    ipnSecret: process.env.NOWPAYMENTS_IPN_SECRET || '',
    apiUrl: 'https://api.nowpayments.io/v1',
    currencies: ['btc', 'eth', 'sol', 'ltc', 'usdt', 'usdc', 'trx'],
    confirmations: 2, // confirmations blockchain requises
    isFeePaidByUser: true, // frais réseau payés par le client
    currencyNetworks: {
      usdt: [
        { id: 'trx', label: 'TRC20' },
        { id: 'erc20', label: 'ERC20' },
        { id: 'bep20', label: 'BEP20' },
      ],
      usdc: [
        { id: 'erc20', label: 'ERC20' },
        { id: 'sol', label: 'SOL' },
        { id: 'bep20', label: 'BEP20' },
      ],
      trx: [],
    },
    defaultNetwork: {
      usdt: 'trx',
      usdc: 'erc20',
    },
  },

  /**
   * Sender SMS — providers, queue et limites.
   * Les providers réels sont gérés dans data/sms.json via l'admin.
   */
  sms: {
    storagePath: process.env.SMS_STORAGE_PATH || './data/sms.json',
    costPerSms: parseFloat(process.env.SMS_COST_PER_SMS || '1'),
    maxCampaignsRunningPerUser: parseInt(process.env.SMS_MAX_RUNNING || '10', 10),
    maxSmsPerHourPerUser: parseInt(process.env.SMS_MAX_PER_HOUR || '5000', 10),
    global: {
      retryAttempts: parseInt(process.env.SMS_RETRY_ATTEMPTS || '3', 10),
      retryDelay: parseInt(process.env.SMS_RETRY_DELAY || '1000', 10),
      webhookUrl: process.env.SMS_WEBHOOK_URL || null,
    },
    defaultProviders: {
      default: [],
    },
  },

  db: {
    type: process.env.DB_TYPE || 'json',
    json: { path: './data/users.json' },
    mysql: {
      host:     process.env.DB_HOST     || 'localhost',
      port:     process.env.DB_PORT     || 3306,
      user:     process.env.DB_USER     || 'root',
      password: process.env.DB_PASSWORD || '',
      database: process.env.DB_NAME     || 'dontplay',
    },
  },

};