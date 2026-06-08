module.exports = {
  apps: [
    {
      name: 'dontplay-api',
      script: 'server.js',
      cwd: '/var/www/dontplay',
      env_file: '/var/www/dontplay/.env',
      instances: 'max',
      exec_mode: 'cluster',
      watch: false,
      max_memory_restart: '1G',
      error_file: '/var/log/pm2/dontplay-err.log',
      out_file: '/var/log/pm2/dontplay-out.log',
      log_file: '/var/log/pm2/dontplay-combined.log',
      time: true,
    },
  ]
};
