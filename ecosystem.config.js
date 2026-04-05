module.exports = {
  apps: [
    {
      name: 'aura-ai',
      script: 'src/server.js',
      instances: 1,
      exec_mode: 'fork',
      watch: false,
      max_memory_restart: '512M',
      env: {
        APP_ENV: 'development',
        PORT: 3000,
      },
      env_production: {
        APP_ENV: 'production',
      },
      out_file: 'logs/pm2-out.log',
      error_file: 'logs/pm2-error.log',
      merge_logs: true,
      time: true,
      kill_timeout: 5000,
      listen_timeout: 10000,
      autorestart: true,
      exp_backoff_restart_delay: 100,
    },
  ],
};
