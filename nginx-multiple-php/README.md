# Nginx with Multiple PHP Versions

## Overview

This project demonstrates how to configure **Nginx** to run two different PHP versions on the same Ubuntu server.

The server is configured with:

- Ubuntu 24.04
- Nginx
- PHP 8.2
- PHP 8.4
- PHP-FPM
- Two separate Nginx server blocks
- PHP-FPM Unix sockets
- `www-data` for Nginx workers and PHP-FPM workers

The goal is to be able to use **PHP 8.2 and PHP 8.4 at the same time** on the same server.

---

# 1. Update the Server

First, update the Ubuntu package list and upgrade installed packages.

```bash
sudo apt update
sudo apt upgrade -y
```

---

# 2. Install Nginx

Install Nginx:

```bash
sudo apt install nginx -y
```

Check the Nginx version:

```bash
nginx -v
```

Check the service:

```bash
sudo systemctl status nginx
```

Enable Nginx to start automatically after reboot:

```bash
sudo systemctl enable nginx
```

---

# 3. Install the PHP Repository

Ubuntu 24.04 provides PHP packages, but to install multiple PHP versions, the Ondřej Surý PPA was used.

Install the required packages:

```bash
sudo apt install software-properties-common -y
```

Add the PHP PPA:

```bash
sudo add-apt-repository ppa:ondrej/php
```

Update the package list:

```bash
sudo apt update
```

---

# 4. Install PHP 8.2

Install PHP 8.2 and PHP-FPM:

```bash
sudo apt install php8.2 php8.2-fpm -y
```

Check the PHP version:

```bash
php8.2 -v
```

Check PHP-FPM:

```bash
sudo systemctl status php8.2-fpm
```

Enable PHP-FPM at boot:

```bash
sudo systemctl enable php8.2-fpm
```

---

# 5. Install PHP 8.4

Install PHP 8.4 and PHP-FPM:

```bash
sudo apt install php8.4 php8.4-fpm -y
```

Check the PHP version:

```bash
php8.4 -v
```

Check PHP-FPM:

```bash
sudo systemctl status php8.4-fpm
```

Enable PHP-FPM at boot:

```bash
sudo systemctl enable php8.4-fpm
```

---

# 6. Verify Both PHP-FPM Services

Check both services:

```bash
sudo systemctl status php8.2-fpm
sudo systemctl status php8.4-fpm
```

Check the PHP-FPM sockets:

```bash
ls -l /run/php/
```

The important sockets are:

```text
/run/php/php8.2-fpm.sock
/run/php/php8.4-fpm.sock
```

These sockets allow Nginx to send PHP requests to the correct PHP-FPM version.

---

# 7. Create Website Directories

Create a separate directory for each PHP version:

```bash
sudo mkdir -p /var/www/php2
sudo mkdir -p /var/www/php4
```

The structure is:

```text
/var/www/
├── php2/
└── php4/
```

---

# 8. Create PHP Test Files

Create the PHP 8.2 test file:

```bash
sudo nano /var/www/php2/info.php
```

Add:

```php
<?php
phpinfo();
?>
```

Create the PHP 8.4 test file:

```bash
sudo nano /var/www/php4/info.php
```

Add:

```php
<?php
phpinfo();
?>
```

These files are used to verify which PHP version is processing each request.

---

# 9. Configure Nginx for PHP 8.2

Create the Nginx configuration:

```bash
sudo nano /etc/nginx/sites-available/php2
```

Configuration:

```nginx
server {
    listen 80;
    server_name php2;

    root /var/www/php2;
    index index.php index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }
}
```

### Important part

This line connects Nginx to PHP 8.2:

```nginx
fastcgi_pass unix:/run/php/php8.2-fpm.sock;
```

When Nginx receives a PHP request, it sends it to the PHP 8.2-FPM socket.

---

# 10. Configure Nginx for PHP 8.4

Create another Nginx configuration:

```bash
sudo nano /etc/nginx/sites-available/php4
```

Configuration:

```nginx
server {
    listen 8080;
    server_name php8.4;

    root /var/www/php4;
    index index.php index.html;

    location / {
        try_files $uri $uri/ =404;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

The important part is:

```nginx
fastcgi_pass unix:/run/php/php8.4-fpm.sock;
```

This sends PHP requests to PHP 8.4-FPM.

---

# 11. Enable Both Nginx Sites

Create symbolic links:

```bash
sudo ln -s /etc/nginx/sites-available/php2 /etc/nginx/sites-enabled/php2
```

```bash
sudo ln -s /etc/nginx/sites-available/php4 /etc/nginx/sites-enabled/php4
```

Check the enabled sites:

```bash
ls -l /etc/nginx/sites-enabled/
```

---

# 12. Disable the Default Nginx Site

If the default site is not needed:

```bash
sudo rm /etc/nginx/sites-enabled/default
```

This removes the symbolic link only. It does not delete the original configuration from `sites-available`.

---

# 13. Test the Nginx Configuration

Before reloading Nginx, always test the configuration:

```bash
sudo nginx -t
```

Expected result:

```text
syntax is ok
test is successful
```

Reload Nginx:

```bash
sudo systemctl reload nginx
```

---

# 14. Configure Nginx Workers to Use www-data

Nginx uses a master/worker architecture.

Check the Nginx configuration:

```bash
grep '^user' /etc/nginx/nginx.conf
```

The expected result is:

```text
user www-data;
```

This means the Nginx worker processes run as `www-data`.

Check the running processes:

```bash
ps aux | grep nginx
```

Example:

```text
root       nginx: master process
www-data   nginx: worker process
```

The Nginx master process remains `root`, while the worker process that handles requests runs as `www-data`.

---

# 15. Configure PHP-FPM to Use www-data

Check the PHP 8.2 pool:

```bash
grep -E '^(user|group) =' /etc/php/8.2/fpm/pool.d/www.conf
```

Check the PHP 8.4 pool:

```bash
grep -E '^(user|group) =' /etc/php/8.4/fpm/pool.d/www.conf
```

Expected result:

```text
user = www-data
group = www-data
```

This means PHP-FPM worker processes execute PHP code as `www-data`.

Check the running processes:

```bash
ps aux | grep php-fpm
```

Example:

```text
root       php-fpm: master process
www-data   php-fpm: pool www
www-data   php-fpm: pool www
```

The PHP-FPM master process can remain `root`, while the PHP worker processes run as `www-data`.

---

# 16. Set Website Ownership

Make `www-data` the owner of the website directories:

```bash
sudo chown -R www-data:www-data /var/www/php2
sudo chown -R www-data:www-data /var/www/php4
```

Set basic permissions:

```bash
sudo chmod -R 755 /var/www/php2
sudo chmod -R 755 /var/www/php4
```

Check ownership:

```bash
ls -la /var/www/
```

---

# 17. How Two PHP Versions Work on the Same Server

The important concept is that **Nginx does not directly execute PHP**.

The flow is:

```text
Browser
   |
   v
Nginx
   |
   +----------------------+
   |                      |
   v                      v
Port 80                Port 8080
   |                      |
   v                      v
PHP 8.2-FPM            PHP 8.4-FPM
   |                      |
   v                      v
php8.2-fpm.sock       php8.4-fpm.sock
```

Each Nginx server block points to a different PHP-FPM socket.

### PHP 8.2

```nginx
fastcgi_pass unix:/run/php/php8.2-fpm.sock;
```

Therefore:

```text
Nginx → PHP 8.2-FPM
```

### PHP 8.4

```nginx
fastcgi_pass unix:/run/php/php8.4-fpm.sock;
```

Therefore:

```text
Nginx → PHP 8.4-FPM
```

Both PHP versions can run at the same time because they have separate PHP-FPM services and separate sockets.

---

# 18. Test PHP 8.2

PHP 8.2 is configured on port `80`.

Open:

```text
http://SERVER_IP/info.php
```

For example:

```text
http://134.209.243.73/info.php
```

The PHP information page should show:

```text
PHP Version 8.2.x
```

---

# 19. Test PHP 8.4

PHP 8.4 is configured on port `8080`.

Open:

```text
http://SERVER_IP:8080/info.php
```

For example:

```text
http://134.209.243.73:8080/info.php
```

The PHP information page should show:

```text
PHP Version 8.4.x
```

---

# 20. Verify the Processes

Check Nginx:

```bash
ps aux | grep nginx
```

Check PHP-FPM:

```bash
ps aux | grep php-fpm
```

The expected architecture is:

```text
Nginx:
root       nginx: master process
www-data   nginx: worker process

PHP 8.2:
root       php-fpm: master process
www-data   php-fpm: pool www

PHP 8.4:
root       php-fpm: master process
www-data   php-fpm: pool www
```

The important point is that the processes handling web requests do not run as root.

---

# 21. Useful Troubleshooting Commands

### Check Nginx configuration

```bash
sudo nginx -t
```

### Check Nginx status

```bash
sudo systemctl status nginx
```

### Check Nginx error log

```bash
sudo tail -f /var/log/nginx/error.log
```

### Check PHP 8.2-FPM status

```bash
sudo systemctl status php8.2-fpm
```

### Check PHP 8.4-FPM status

```bash
sudo systemctl status php8.4-fpm
```

### Check PHP-FPM sockets

```bash
ls -l /run/php/
```

### Check listening ports

```bash
sudo ss -tulpn
```

### Check running Nginx and PHP processes

```bash
ps aux | grep -E 'nginx|php-fpm'
```

---

# 22. Final Result

The final server configuration is:

```text
                         Nginx
                           |
              +------------+------------+
              |                         |
           Port 80                  Port 8080
              |                         |
              v                         v
         PHP 8.2-FPM               PHP 8.4-FPM
              |                         |
              v                         v
    php8.2-fpm.sock            php8.4-fpm.sock
              |                         |
              +------------+------------+
                           |
                       www-data
```

This allows two different PHP versions to run on the same Ubuntu server simultaneously.

## Security / Privilege Model

The master processes may run as `root` because they perform system-level operations.

The actual Nginx workers and PHP-FPM workers run as the unprivileged `www-data` user.

```text
root
 ├── Nginx master
 │     └── www-data → Nginx worker
 │
 ├── PHP 8.2-FPM master
 │     └── www-data → PHP worker
 │
 └── PHP 8.4-FPM master
       └── www-data → PHP worker
```

This is the normal privilege separation model for Nginx and PHP-FPM.