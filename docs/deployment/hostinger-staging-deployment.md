# Hostinger VPS KVM 2 Staging Deployment Guide (Sub-Domain)

This guide provides step-by-step instructions for deploying the IPOS application to a Hostinger VPS KVM 2 instance for staging/demo purposes, running on a subdomain (e.g., `staging.yourdomain.com`).

## 1. Prerequisites (VPS Setup)

Ensure your Hostinger VPS (Ubuntu 22.04/24.04 recommended) has the following installed:
- **PHP 8.2 or 8.3** (with extensions: bcmath, ctype, fileinfo, json, mbstring, openssl, pdo, tokenizer, xml, curl, zip, gd)
- **Composer** (v2+)
- **Node.js** (v20+) & **npm**
- **MySQL 8** or **MariaDB 10.6+**
- **Nginx**
- **Supervisor** (for queue workers)
- **Git**

## 2. DNS Configuration
In your Hostinger hPanel or domain registrar:
1. Go to your Domain's **DNS Zone Editor**.
2. Add an **A Record**:
   - **Name:** `staging` (or your preferred subdomain prefix)
   - **Points to:** `[Your VPS Public IP Address]`
   - **TTL:** Default

## 3. Database Creation
Connect to your VPS via SSH and create the staging database:

```bash
mysql -u root -p
```
Inside the MySQL prompt:
```sql
CREATE DATABASE ipos_staging;
CREATE USER 'ipos_user'@'localhost' IDENTIFIED BY 'your_strong_password';
GRANT ALL PRIVILEGES ON ipos_staging.* TO 'ipos_user'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

## 4. Clone the Repository
Navigate to your web root (usually `/var/www/`) and clone the project.

```bash
cd /var/www/
git clone https://github.com/iamrgalisanao/IPOS.git staging.yourdomain.com
cd staging.yourdomain.com

# If your staging environment should track the latest guardrail branch:
git checkout g-002-guardrail-refresh
```

## 5. Application Setup (Env, Dependencies, Build)

Set up your environment configuration and perform manual credential reinjection (G-009).

```bash
# 1. Copy the example environment file
cp .env.example .env

# 2. Edit the .env file with your specific staging details
nano .env
```

**Crucial `.env` Updates:**
```ini
APP_NAME="IPOS Staging"
APP_ENV=staging
APP_KEY= # (Run php artisan key:generate later)
APP_DEBUG=false
APP_URL=https://staging.yourdomain.com

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=ipos_staging
DB_USERNAME=ipos_user
DB_PASSWORD=your_strong_password

# For IPOS Async features (Epic 34 & 35)
QUEUE_CONNECTION=database
```

**Install Dependencies & Build Assets:**
```bash
composer install --optimize-autoloader --no-dev
php artisan key:generate
php artisan migrate --force

npm install
npm run build
```

**Set Proper Permissions:**
```bash
sudo chown -R www-data:www-data /var/www/staging.yourdomain.com
sudo chmod -R 775 /var/www/staging.yourdomain.com/storage
sudo chmod -R 775 /var/www/staging.yourdomain.com/bootstrap/cache
```

## 6. Configure Nginx & SSL

Create a new Nginx server block for the subdomain.

```bash
sudo nano /etc/nginx/sites-available/staging.yourdomain.com
```

Paste the following configuration:
```nginx
server {
    listen 80;
    server_name staging.yourdomain.com;
    root /var/www/staging.yourdomain.com/public;

    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-Content-Type-Options "nosniff";

    index index.php;

    charset utf-8;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Adjust PHP version if needed
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable the site and secure it with Let's Encrypt:
```bash
sudo ln -s /etc/nginx/sites-available/staging.yourdomain.com /etc/nginx/sites-enabled/
sudo nginx -t
sudo systemctl reload nginx

# Install SSL Certificate (Required for IPOS PWA/Service Workers)
sudo certbot --nginx -d staging.yourdomain.com
```

## 7. Configure Supervisor (Critical for Background Jobs)
IPOS Epics 34 (Async Reporting) and 35 (Recipe Stock Deduction) rely on Laravel queues. They will fail silently if workers aren't running.

Create a Supervisor configuration file:
```bash
sudo nano /etc/supervisor/conf.d/ipos-staging-worker.conf
```

Paste the following:
```ini
[program:ipos-staging-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/staging.yourdomain.com/artisan queue:work database --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/www/staging.yourdomain.com/storage/logs/worker.log
stopwaitsecs=3600
```

Start the workers:
```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start ipos-staging-worker:*
```

## 8. Final Cache Clearing & Verification
```bash
cd /var/www/staging.yourdomain.com
php artisan optimize:clear
php artisan optimize
php artisan view:cache
php artisan event:cache
```

Your staging application should now be fully accessible, offline-PWA capable, and processing queues seamlessly at `https://staging.yourdomain.com`.
