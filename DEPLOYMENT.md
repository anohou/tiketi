# Production Deployment Guide: Multi-Tenancy (Separate Databases)

This guide outlines the steps to deploy your `stancl/tenancy` application to a production environment (e.g., Ubuntu/Nginx).

## 1. Domain & DNS Configuration
You need two types of DNS records: one for the **Central Domain** and one for **Tenant Subdomains**.

*   **Central Domain**: Creates the main entry point (Landlord).
    *   `A Record`: `admin.transport.ci` -> `YOUR_SERVER_IP`
*   **Wildcard Subdomain**: Allows dynamic tenant creation (e.g., `agency-a.transport.ci`).
    *   `A Record`: `*.transport.ci` -> `YOUR_SERVER_IP`

> [!NOTE]
> If using **Custom Domains** (e.g., `agency-a.com`), you must point the A Record of that custom domain to your server IP as well.

---

## 2. Server Configuration (Nginx)
You generally only need **ONE** Nginx server block. The application handles the routing internally based on the Host header.

### Nginx Config (`/etc/nginx/sites-available/transport`)
```nginx
server {
    listen 80;
    listen [::]:80;
    server_name transport.ci *.transport.ci; # Catch-all for wildcard
    root /var/www/transport/public;
 
    add_header X-Frame-Options "SAMEORIGIN";
    add_header X-XSS-Protection "1; mode=block";
    add_header X-Content-Type-Options "nosniff";
 
    index index.php;
 
    charset utf-8;
 
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
 
    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock; # Adjust PHP version
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
 
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 3. SSL Configuration (Let's Encrypt)
To secure both the main domain and dynamic subdomains, you need a **Wildcard Certificate**.

1.  **Install Certbot**:
    ```bash
    sudo apt install certbot python3-certbot-nginx
    ```
2.  **Generate Wildcard Cert** (Requires DNS Validation usually, or allow HTTP validation if configured properly):
    ```bash
    sudo certbot --nginx -d transport.ci -d *.transport.ci
    ```

---

## 4. Environment Variables (`.env`)
Update your production `.env` file carefully.

```env
APP_URL=https://transport.ci
 
# Tenancy Configuration
# Ensure this includes your central domain(s)
TENANCY_CENTRAL_DOMAINS=admin.transport.ci,transport.ci

# Database Permissions
# The DB User MUST have 'CREATE DATABASE' privileges to create new tenant DBs!
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=transport_central
DB_USERNAME=root_user       # Must have CREATE privilege
DB_PASSWORD=secret_password
```

---

## 5. Deployment Steps

### A. Initial Setup
1.  **Clone Code**: `git clone ... /var/www/transport`
2.  **Install Dependencies**:
    ```bash
    composer install --optimize-autoloader --no-dev
    npm install && npm run build
    ```
3.  **Permissions**:
    ```bash
    chown -R www-data:www-data storage bootstrap/cache
    ```

### B. Database Migration (Central)
Migrate the central database (Landlord). This creates `tenants`, `domains`, and `users` tables.
```bash
php artisan migrate --path=database/landlord_migrations --force
```

### C. Create Superadmin
Seed the database to create your platform admin account.
```bash
php artisan db:seed --class=PlatformAdminSeeder --force
```

### D. Start Reverb (WebSocket)
For real-time features, you need to run the Reverb server.
Use **Supervisor** (Linux process manager) to keep it running.

`/etc/supervisor/conf.d/reverb.conf`:
```ini
[program:reverb]
command=php /var/www/transport/artisan reverb:start
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/transport/storage/logs/reverb.log
```

---

## 6. Creating Tenants in Production
1.  Log in to `https://admin.transport.ci` (Superadmin).
2.  Go to **Tenants**.
3.  Click **Create Tenant**.
    *   **ID**: `agency-a`
    *   **Domain**: `agency-a.transport.ci` (or `agency-a.com` if DNS is set)
4.  The system will:
    *   Create database `t_agency-a`.
    *   Migrate `t_agency-a` automatically.
    *   Seed default user (`admin@transport.ci` / `password`).

## 7. Troubleshooting
*   **404 on Tenant**: Ensure the DNS record exists (`A record` pointing to server).
*   **Database Error**: Ensure the DB User has `CREATE DATABASE` permission.
*   **500 Error**: Check `storage/logs/laravel.log`.
