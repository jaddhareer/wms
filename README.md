# WMS LSN — Warehouse Management System

## Requirements
- PHP >= 8.0
- MySQL >= 5.7 / MariaDB >= 10.4
- Apache with mod_rewrite **OR** Nginx
- Composer (untuk export XLSX)

---

## Instalasi

### 1. Clone / Extract project
```
/var/www/html/wms/   ← contoh path
```

### 2. Konfigurasi Database
Edit file `config/database.php`:
```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'wms_lsn');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Import Database
```bash
mysql -u root -p < setup.sql
```

### 4. Install Dependencies (XLSX Export)
```bash
composer install
```

### 5. Konfigurasi Web Server

#### Apache (web root = `/public`)
Arahkan DocumentRoot ke folder `/public`:
```apache
<VirtualHost *:80>
    DocumentRoot /var/www/html/wms/public
    ServerName wms.local
    <Directory /var/www/html/wms/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

#### Nginx
```nginx
server {
    listen 80;
    root /var/www/html/wms/public;
    index index.php;
    location / { try_files $uri $uri/ /index.php?$query_string; }
    location ~ \.php$ { fastcgi_pass unix:/run/php/php8.0-fpm.sock; include fastcgi_params; fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name; }
    location ~* ^/(config|functions)/ { deny all; return 403; }
}
```

#### XAMPP / Laragon (Development)
Jika menggunakan XAMPP dan tidak bisa set DocumentRoot ke `/public`,
akses via: `http://localhost/wms/public/`

---

## Login Default
setup Users di database
---

## Struktur Direktori
```
wms/
├── setup.sql              # Database schema
├── composer.json          # Dependencies
├── config/
│   ├── database.php       # DB connection
│   └── config.php         # App constants
├── functions/
│   ├── auth.php           # Authentication
│   ├── csrf.php           # CSRF protection
│   └── helpers.php        # Helper functions
├── public/
│   ├── index.php          # Entry point (set ini sebagai web root)
│   └── api/
│       ├── auth.php
│       ├── dashboard.php
│       ├── inbound.php
│       ├── outbound.php
│       ├── softcase.php
│       ├── moving.php
│       ├── stock.php
│       ├── movements.php
│       ├── softcase_monitoring.php
│       ├── users.php
│       └── export.php
├── assets/
│   ├── css/style.css
│   └── js/app.js
└── vendor/                # (setelah composer install)
```

---

## Fitur
| Fitur                  |
|------------------------|
| Dashboard              |
| Inbound                |
| Outbound               |
| Moving                 |
| Softcase Check         |
| Stock Overview         |
| Movements              |
| Softcase Monitoring    |
| User Management        |

---

## Export XLSX
Pastikan `composer install` sudah dijalankan.
Jika PhpSpreadsheet tidak tersedia, export akan fallback ke CSV.
