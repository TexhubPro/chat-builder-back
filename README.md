## Backend deployment (`safina.texhub.pro`)

### 1) Environment

Set production values in `.env`:

```env
APP_URL=https://safina.texhub.pro
SANCTUM_STATEFUL_DOMAINS=bot.texhub.pro,safina.texhub.pro
CORS_ALLOWED_ORIGINS=https://bot.texhub.pro
```

Also keep integration callbacks on production domains:

```env
BILLING_ALIF_CALLBACK_URL=https://safina.texhub.pro/api/billing/alif/callback
BILLING_ALIF_RETURN_URL=https://bot.texhub.pro/billing
META_INSTAGRAM_REDIRECT_URI=https://safina.texhub.pro/callback
META_INSTAGRAM_FRONTEND_REDIRECT_URL=https://bot.texhub.pro/integrations
```

### 2) Preferred Apache setup (recommended)

Point Apache `DocumentRoot` to `back/public`.

```apache
<VirtualHost *:80>
    ServerName safina.texhub.pro
    DocumentRoot /var/www/chat-flow/back/public

    <Directory /var/www/chat-flow/back/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 3) Shared-hosting fallback (when `public/` cannot be document root)

This repo includes:

1. `back/.htaccess` to internally route requests into `public/`.
2. `back/index.php` that boots `public/index.php`.

In this mode Apache `DocumentRoot` can be `back/`, but `AllowOverride All` is still required.

### 4) Post-deploy commands

```bash
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:clear
php artisan route:clear
php artisan config:cache
php artisan route:cache
```
