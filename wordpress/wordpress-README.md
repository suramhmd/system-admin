# System Administration – WordPress Server

A Linux system administration project demonstrating the deployment,
configuration, security, and maintenance of a WordPress server on
Ubuntu.

## Overview

This project includes:

- Ubuntu server administration
- Nginx web server
- PHP 8.4 and PHP-FPM
- MySQL database
- WordPress installation
- Nginx virtual/server configuration
- HTTP Basic Authentication for `/wp-admin/`
- Automated MySQL database backups
- Gzip compression
- Backup retention
- Cron-based scheduled backups
- Basic troubleshooting and service management

## Server Environment

| Component           | Technology              |
|---------------------|-------------------------|
| Operating System    | Ubuntu                  |
| Web Server          | Nginx                   |
| PHP                 | PHP 8.4                 |
| PHP Process Manager | PHP 8.4-FPM             |
| Database            | MySQL                   |
| CMS                 | WordPress               |
| Backup              | Bash + mysqldump + gzip |
| Scheduler           | Cron                    |

## Architecture

``` text
                         Client
                            |
                            v
                         Nginx
                            |
                    +-------+-------+
                    |               |
                    v               v
               WordPress       PHP 8.4-FPM
                    |               |
                    +-------+-------+
                            |
                            v
                          MySQL
                            |
                            v
                  WordPress Database
                            |
                            v
                     Backup Script
                            |
                            v
                 /var/backups/wordpress
                            |
                            v
                          gzip
                            |
                            v
                       Cron Job
```

## Installation and Configuration

### 1. Update the Server

``` bash
sudo apt update
sudo apt upgrade -y
```

### 2. Install Nginx

``` bash
sudo apt install nginx -y
sudo systemctl enable nginx
sudo systemctl status nginx
```

Test the configuration:

``` bash
sudo nginx -t
```

Reload Nginx after configuration changes:

``` bash
sudo systemctl reload nginx
```

Important Nginx directories:

``` text
/etc/nginx/
/etc/nginx/sites-available/
/etc/nginx/sites-enabled/
```

### 3. Install PHP 8.4

Install PHP and PHP-FPM:

``` bash
sudo apt install php8.4 php8.4-fpm -y
```

Install the WordPress PHP extensions:

``` bash
sudo apt install php8.4-mysql php8.4-curl php8.4-gd \
php8.4-mbstring php8.4-xml php8.4-zip php8.4-intl -y
```

Verify PHP:

``` bash
php -v
```

Check PHP-FPM:

``` bash
sudo systemctl status php8.4-fpm
sudo systemctl enable php8.4-fpm
```

The PHP-FPM socket used by Nginx is:

``` text
/run/php/php8.4-fpm.sock
```

### 4. Install MySQL

``` bash
sudo apt install mysql-server -y
sudo systemctl enable mysql
sudo systemctl status mysql
```

Log in to MySQL:

``` bash
mysql -u root -p
```

### 5. Create the WordPress Database

Inside MySQL:

``` sql
CREATE DATABASE WordPress
CHARACTER SET utf8mb4
COLLATE utf8mb4_general_ci;

CREATE USER 'WordPressUser'@'localhost'
IDENTIFIED BY 'your_password';

GRANT ALL PRIVILEGES ON WordPress.* 
TO 'WordPressUser'@'localhost';

FLUSH PRIVILEGES;
```

Exit MySQL:

``` sql
EXIT;
```

> **Security:** Never commit the real database password to GitHub.

### 6. WordPress Installation

The WordPress installation is located at:

``` text
/var/www/html/wordpress
```

The main WordPress configuration file is:

``` text
/var/www/html/wordpress/wp-config.php
```

`wp-config.php` contains database credentials and must **not** be
uploaded to GitHub.

Check the WordPress directory:

``` bash
ls -la /var/www/html/wordpress
```

## Nginx Configuration

The project uses Nginx to serve WordPress and forward PHP requests to
PHP 8.4-FPM.

The configuration includes:

- WordPress document root
- PHP 8.4-FPM integration
- WordPress pretty URLs
- HTTP Basic Authentication for `/wp-admin/`
- Protection against `.ht*` files
- A server block listening on port `8080`

Example configuration:

``` nginx
server {
    listen 80 default_server;
    listen [::]:80 default_server;

    root /var/www/html/wordpress;

    index index.php index.html index.htm;

    server_name _;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }

    location /wp-admin/ {
        auth_basic "login";
        auth_basic_user_file /etc/nginx/.htpasswd;

        location ~ \.php$ {
            include snippets/fastcgi-php.conf;
            fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        }
    }
}

server {
    listen 8080;
    listen [::]:8080;

    server_name _;

    root /var/www/html/wordpress;
    index index.php index.html;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }

    location ~ /\.ht {
        deny all;
    }
}
```

After editing the configuration:

``` bash
sudo nginx -t
sudo systemctl reload nginx
```

## HTTP Basic Authentication

The WordPress administration area is protected with HTTP Basic
Authentication.

Password file:

``` text
/etc/nginx/.htpasswd
```

Install the required package:

``` bash
sudo apt install apache2-utils -y
```

Create the password file:

``` bash
sudo htpasswd -c /etc/nginx/.htpasswd admin
```

The Nginx configuration uses:

``` nginx
location /wp-admin/ {
    auth_basic "login";
    auth_basic_user_file /etc/nginx/.htpasswd;
}
```

The `.htpasswd` file must not be committed to GitHub.

## Automated Database Backups

Backups are stored in:

``` text
/var/backups/wordpress
```

Backup naming format:

``` text
wordpress_YYYY-MM-DD_HH-MM-SS.sql.gz
```

Example:

``` text
wordpress_2026-09-14_02-00-00.sql.gz
```

The backup process is:

1.  Run `mysqldump`
2.  Export the WordPress database
3.  Compress the SQL output with `gzip`
4.  Store the compressed backup
5.  Delete backups older than the retention period

### Backup Script

Repository copy:

``` text
scripts/backup.sh
```

Example:

``` bash
#!/bin/bash

BACKUP_DIR="/var/backups/wordpress"
DB_NAME="WordPress"

DATE=$(date +"%Y-%m-%d_%H-%M-%S")
BACKUP_FILE="$BACKUP_DIR/wordpress_$DATE.sql.gz"

mkdir -p "$BACKUP_DIR"

mysqldump --no-tablespaces "$DB_NAME" | gzip > "$BACKUP_FILE"

find "$BACKUP_DIR" -type f -name "wordpress_*.sql.gz" -mtime +6 -delete
```

Make the installed script executable:

``` bash
sudo chmod +x /usr/local/bin/backup.sh
```

Create the backup directory:

``` bash
sudo mkdir -p /var/backups/wordpress
```

## Database Authentication for Backups

The backup script does not contain the database password.

For a root-run backup, MySQL client credentials can be stored in:

``` text
/root/.my.cnf
```

Example:

``` ini
[client]
user=WordPressUser
password=your_password
```

Protect the file:

``` bash
sudo chmod 600 /root/.my.cnf
```

> **Security:** `/root/.my.cnf` must never be uploaded to GitHub.

## Test the Backup Manually

Run:

``` bash
sudo /usr/local/bin/backup.sh
```

Check the generated files:

``` bash
ls -lh /var/backups/wordpress
```

A successful backup should look similar to:

``` text
wordpress_2026-09-14_02-00-00.sql.gz
```

You can inspect the beginning of a compressed backup without extracting
it:

``` bash
zcat /var/backups/wordpress/wordpress_DATE.sql.gz | head
```

## Cron Job

Cron is used to run the backup automatically.

Repository file:

``` text
cron/wordpress-backup
```

Example:

``` cron
0 2 * * * /usr/local/bin/backup.sh
```

This runs the backup every day at **02:00**.

Cron fields:

``` text
0       minute
2       hour
*       day of month
*       month
*       day of week
```

To run the backup at 03:00 instead:

``` cron
0 3 * * * /usr/local/bin/backup.sh
```

Edit the root user’s cron jobs:

``` bash
sudo crontab -e
```

Check configured jobs:

``` bash
sudo crontab -l
```

## Backup Retention

The backup script removes old backups using:

``` bash
find "$BACKUP_DIR" -type f -name "wordpress_*.sql.gz" -mtime +6 -delete
```

This keeps approximately the most recent seven days of matching backups
and removes older files.

## Important Server Paths

| Purpose                  | Path                                    |
|--------------------------|-----------------------------------------|
| WordPress                | `/var/www/html/wordpress`               |
| WordPress configuration  | `/var/www/html/wordpress/wp-config.php` |
| Nginx                    | `/etc/nginx/`                           |
| Nginx available sites    | `/etc/nginx/sites-available/`           |
| Nginx enabled sites      | `/etc/nginx/sites-enabled/`             |
| Basic Auth               | `/etc/nginx/.htpasswd`                  |
| PHP-FPM socket           | `/run/php/php8.4-fpm.sock`              |
| PHP-FPM configuration    | `/etc/php/8.4/fpm/`                     |
| MySQL                    | `/etc/mysql/`                           |
| WordPress backups        | `/var/backups/wordpress`                |
| Backup script            | `/usr/local/bin/backup.sh`              |
| MySQL client credentials | `/root/.my.cnf`                         |
| Root cron                | `/var/spool/cron/crontabs/root`         |

## Useful Service Commands

### Nginx

``` bash
sudo systemctl status nginx
sudo systemctl start nginx
sudo systemctl stop nginx
sudo systemctl restart nginx
sudo systemctl reload nginx
sudo nginx -t
```

### PHP 8.4-FPM

``` bash
sudo systemctl status php8.4-fpm
sudo systemctl start php8.4-fpm
sudo systemctl restart php8.4-fpm
sudo systemctl reload php8.4-fpm
```

### MySQL

``` bash
sudo systemctl status mysql
sudo systemctl start mysql
sudo systemctl restart mysql
```

## Troubleshooting

Check listening ports:

``` bash
sudo ss -tulpn
```

Check Nginx error logs:

``` bash
sudo tail -f /var/log/nginx/error.log
```

Check Nginx access logs:

``` bash
sudo tail -f /var/log/nginx/access.log
```

Check PHP-FPM logs:

``` bash
sudo journalctl -u php8.4-fpm
```

Check Nginx service logs:

``` bash
sudo journalctl -u nginx
```

Check MySQL logs:

``` bash
sudo journalctl -u mysql
```

Check disk space:

``` bash
df -h
```

Check memory:

``` bash
free -h
```

Check running processes:

``` bash
ps aux
```

## GitHub Repository Structure

``` text
system-admin/
│
├── README.md
│
├── nginx/
│   └── wordpress.conf
│
├── scripts/
│   └── backup.sh
│
└── cron/
    └── wordpress-backup
```

The repository contains the configuration and scripts used for the
server setup.

## Sensitive Files

The following files must **not** be committed to GitHub:

``` text
wp-config.php
.htpasswd
.my.cnf
*.sql
*.sql.gz
.env
SSH private keys
password files
```

These files may contain credentials, passwords, private keys, or
database backups.

Consider adding appropriate patterns to `.gitignore` before pushing the
repository.

## Technologies

- Linux / Ubuntu
- Nginx
- PHP 8.4
- PHP-FPM
- MySQL
- WordPress
- Bash
- Cron
- gzip
- HTTP Basic Authentication

## Project Summary

The final server architecture is:

``` text
Nginx
   ↓
PHP 8.4-FPM
   ↓
WordPress
   ↓
MySQL
   ↓
Automated Backup Script
   ↓
gzip-compressed backups
   ↓
Cron Scheduler
```

This project demonstrates practical Linux system administration skills
including web-server configuration, PHP-FPM integration, database
management, access control, automation, backup management, and basic
server troubleshooting.

> **Note:** The GitHub documentation intentionally presents the
> environment as **PHP 8.4 + PHP 8.4-FPM**. Real credentials and
> sensitive server files should never be committed to the repository.
