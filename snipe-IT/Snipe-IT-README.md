# Snipe-IT Installation on Ubuntu 24.04

This document explains how I installed **Snipe-IT** on an Ubuntu 24.04 server using:

- Nginx
- PHP 8.3 and PHP-FPM
- MariaDB
- Composer
- Git
- PHP extensions required by Snipe-IT

## 1. Server Information

| Item | Value |
|---|---|
| Operating System | Ubuntu 24.04 LTS |
| Web Server | Nginx |
| PHP Version | PHP 8.3.6 |
| PHP-FPM Service | `php8.3-fpm` |
| Database Server | MariaDB |
| Application | Snipe-IT |
| Application Path | `/var/www/snipeit` |
| Public Web Root | `/var/www/snipeit/public` |
| Server IP | `134.209.243.73` |
| Application URL | `http://134.209.243.73` |

> Replace the IP address and other values if you use this guide on another server.

---

## 2. Update the Server

Update the package list and upgrade installed packages:

```bash
sudo apt update
```

```bash
sudo apt upgrade -y
```

### Explanation

- `apt update` refreshes the available package information.
- `apt upgrade -y` installs available updates.
- `-y` automatically answers `yes` to confirmation prompts.

---

## 3. Install PHP and Required Extensions

Install PHP and the main extensions:

```bash
sudo apt install -y php-fpm php-cli php-mysql php-mbstring php-xml php-bcmath php-curl php-gd php-zip php-ldap php-tokenizer php-redis
```

During the installation process, additional PHP extensions were required by Composer. They were installed explicitly with:

```bash
sudo apt install -y php8.3-xml
```

```bash
sudo apt install -y php8.3-bcmath
```

```bash
sudo apt install -y php8.3-gd
```

```bash
sudo apt install -y php8.3-mysql
```

### Why these extensions are important

- `php-fpm`: Allows Nginx to execute PHP files.
- `php-cli`: Allows PHP commands to run in the terminal.
- `php-mysql`: Allows PHP to connect to MySQL/MariaDB.
- `php-mbstring`: Supports multibyte strings.
- `php-xml`: Provides XML-related extensions such as DOM and SimpleXML.
- `php-bcmath`: Provides mathematical functions required by some dependencies.
- `php-curl`: Allows HTTP requests from PHP.
- `php-gd`: Provides image-processing functionality.
- `php-zip`: Supports ZIP archives.
- `php-ldap`: Provides LDAP support.
- `php-redis`: Provides Redis support.

Check the installed PHP version:

```bash
php -v
```

Check the MySQL PHP extensions:

```bash
php -m | grep -E 'mysqli|pdo_mysql'
```

Expected output should include:

```text
mysqli
pdo_mysql
```

---

## 4. Install MariaDB

Install the database server and client:

```bash
sudo apt install -y mariadb-server mariadb-client
```

Run the security configuration wizard:

```bash
sudo mysql_secure_installation
```

Follow the prompts to secure the MariaDB installation.

Check the MariaDB service:

```bash
sudo systemctl status mariadb
```

---

## 5. Create the Snipe-IT Database

Open the MariaDB console:

```bash
sudo mariadb
```

Create the database:

```sql
CREATE DATABASE snipeit;
```

Create the database user:

```sql
CREATE USER 'snipeit'@'localhost' IDENTIFIED BY 'snipeit';
```

Grant permissions:

```sql
GRANT ALL PRIVILEGES ON snipeit.* TO 'snipeit'@'localhost';
```

Reload the privileges:

```sql
FLUSH PRIVILEGES;
```

Exit MariaDB:

```sql
EXIT;
```

### Important

Be careful when typing the password. A leading or trailing space is considered part of the password.

Test the database connection:

```bash
mariadb -u snipeit -p snipeit
```

Enter the password when prompted.

---

## 6. Install Nginx, Composer, Git, and Unzip

Install the required tools:

```bash
sudo apt install -y nginx composer git unzip
```

Check the Composer version:

```bash
composer --version
```

Check the Git version:

```bash
git --version
```

---

## 7. Download Snipe-IT

Clone the Snipe-IT repository into `/var/www`:

```bash
cd /var/www
```

```bash
sudo git clone https://github.com/snipe/snipe-it.git snipeit
```

Move into the application directory:

```bash
cd /var/www/snipeit
```

---

## 8. Create the Linux Application User

Create a system user for Snipe-IT:

```bash
sudo useradd -r -s /bin/bash -d /var/www/snipeit snipeit
```

Set the ownership of the application directory:

```bash
sudo chown -R snipeit:snipeit /var/www/snipeit
```

### Explanation

Using a separate application user is safer than running the application as `root`.

---

## 9. Create the Environment File

Copy the example environment file:

```bash
sudo -u snipeit cp /var/www/snipeit/.env.example /var/www/snipeit/.env
```

Open the environment file:

```bash
sudo nano /var/www/snipeit/.env
```

Important settings include:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=http://134.209.243.73
APP_TIMEZONE='Asia/Amman'
APP_LOCALE=en-US

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=snipeit
DB_USERNAME=snipeit
DB_PASSWORD=snipeit
```

Save the file and exit Nano.

> Do not commit the `.env` file to GitHub. It contains sensitive configuration such as database credentials and application secrets.

---

## 10. Install Composer Dependencies

Run Composer as the `snipeit` user:

```bash
sudo -u snipeit composer install --no-dev --prefer-dist --no-interaction
```

### Explanation

- `composer install`: Installs dependencies listed in `composer.lock`.
- `--no-dev`: Skips development-only packages.
- `--prefer-dist`: Prefers distribution archives instead of cloning repositories.
- `--no-interaction`: Runs without asking interactive questions.

A successful installation creates:

```text
/var/www/snipeit/vendor/autoload.php
```

This file is required by Laravel and Snipe-IT.

### Notes about warnings

Composer may display a warning about the cache directory because the `snipeit` system user may not have a normal home directory. If Composer completes successfully, this warning is not a failure.

Composer may also report abandoned packages. Do not run `composer update` just because of that warning. Use the locked dependencies for a stable installation.

---

## 11. Generate the Application Key

Run:

```bash
cd /var/www/snipeit
```

```bash
sudo -u snipeit php artisan key:generate --force
```

Expected output:

```text
INFO  Application key set successfully.
```

### Explanation

Laravel uses the application key for encryption and secure application data.

---

## 12. Configure Application Permissions

Make the storage directory writable by the web server:

```bash
sudo chown -R www-data:www-data /var/www/snipeit/storage
```

```bash
sudo chmod -R 775 /var/www/snipeit/storage
```

Configure the Laravel cache directory:

```bash
sudo chown -R www-data:www-data /var/www/snipeit/bootstrap/cache
```

```bash
sudo chmod -R 775 /var/www/snipeit/bootstrap/cache
```

### Explanation

Nginx passes PHP requests to PHP-FPM. PHP-FPM normally runs as `www-data`, so it needs permission to write:

- Application logs
- Cache files
- Sessions
- Uploaded files
- Other runtime data

---

## 13. Create the Storage Link

Run:

```bash
cd /var/www/snipeit
```

```bash
sudo -u snipeit php artisan storage:link
```

Expected output:

```text
INFO  The [public/storage] link has been connected to [storage/app/public].
```

### Explanation

This creates a symbolic link from:

```text
/var/www/snipeit/public/storage
```

to:

```text
/var/www/snipeit/storage/app/public
```

This allows publicly accessible uploaded files to be served correctly.

---

## 14. Configure Nginx

Create the Snipe-IT Nginx configuration:

```bash
sudo nano /etc/nginx/sites-available/snipeit
```

Use the following configuration:

```nginx
server {
    listen 80;
    listen [::]:80;

    server_name 134.209.243.73;

    root /var/www/snipeit/public;
    index index.php index.html;

    access_log /var/log/nginx/snipeit_access.log;
    error_log /var/log/nginx/snipeit_error.log;

    client_max_body_size 20M;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }

    location ~ /\. {
        deny all;
    }
}
```

### Explanation

- `listen 80`: Nginx listens on HTTP port 80.
- `server_name`: Defines the IP address or domain for this site.
- `root`: Points to Snipe-IT's `public` directory.
- `index`: Defines the default index files.
- `try_files`: Sends Laravel routes to `index.php`.
- `fastcgi_pass`: Sends PHP requests to PHP 8.3-FPM.
- `client_max_body_size 20M`: Allows uploads up to 20 MB.
- `location ~ /\.`: Blocks access to hidden files such as `.env`.

---

## 15. Enable the Nginx Site

Remove the default site only if it is not being used by another application:

```bash
sudo rm -f /etc/nginx/sites-enabled/default
```

Create the symbolic link:

```bash
sudo ln -s /etc/nginx/sites-available/snipeit /etc/nginx/sites-enabled/snipeit
```

> If the symbolic link already exists, do not create it again.

Check the Nginx configuration:

```bash
sudo nginx -t
```

Expected output:

```text
syntax is ok
test is successful
```

Reload Nginx:

```bash
sudo systemctl reload nginx
```

---

## 16. Start and Enable PHP-FPM

Install PHP-FPM if it was not already installed:

```bash
sudo apt install -y php8.3-fpm
```

Start PHP-FPM:

```bash
sudo systemctl start php8.3-fpm
```

Enable it at boot:

```bash
sudo systemctl enable php8.3-fpm
```

Check the service:

```bash
sudo systemctl status php8.3-fpm
```

Check the PHP-FPM socket:

```bash
ls -l /run/php/
```

Expected socket:

```text
php8.3-fpm.sock
```

Restart or reload PHP-FPM when configuration changes:

```bash
sudo systemctl reload php8.3-fpm
```

---

## 17. Troubleshooting the HTTP 500 Error

During the installation, the application initially returned:

```text
HTTP/1.1 500 Internal Server Error
```

The Nginx error log showed:

```text
The stream or file "/var/www/snipeit/storage/logs/laravel.log"
could not be opened in append mode: Permission denied
```

The issue was fixed by changing the ownership and permissions of the `storage` directory:

```bash
sudo chown -R www-data:www-data /var/www/snipeit/storage
```

```bash
sudo chmod -R 775 /var/www/snipeit/storage
```

To inspect the Nginx error log:

```bash
sudo tail -n 50 /var/log/nginx/snipeit_error.log
```

To inspect PHP-FPM logs:

```bash
sudo journalctl -u php8.3-fpm --no-pager -n 50
```

To check the Laravel application from the command line:

```bash
cd /var/www/snipeit
```

```bash
sudo -u snipeit php artisan about
```

To test the HTTP response locally:

```bash
curl -I http://127.0.0.1
```

A successful initial setup redirect may look like:

```text
HTTP/1.1 302 Found
Location: http://134.209.243.73/setup
```

---

## 18. Complete the Web Installer

Open the setup page in a browser:

```text
http://134.209.243.73/setup
```

The setup wizard checks:

- PHP version
- Application URL
- Database connection
- `.env` protection
- Environment mode
- File ownership
- Directory permissions
- Debug mode
- GD image library

After the checks pass:

1. Click **Next Step**.
2. Allow Snipe-IT to create or check database tables.
3. If the output says:

   ```text
   INFO  Nothing to migrate.
   ```

   it means there are no pending database migrations.
4. Create the first **Super Admin** user.
5. Configure the basic application settings.

Suggested initial settings:

| Setting | Example |
|---|---|
| Site Name | `Snipe-IT` or the company name |
| Username | `sura` |
| Default Language | `English, US` |
| Default Currency | `JOD` |
| Asset Tag Prefix | `AST` |
| Asset Tag Length | `6` |
| Auto-incrementing Asset Tags | Enabled |
| Multiple Companies Support | Enable only if needed |
| Email Credentials | Leave disabled until SMTP is configured |

---

## 19. Final Verification

Check the Nginx configuration:

```bash
sudo nginx -t
```

Check PHP-FPM:

```bash
sudo systemctl status php8.3-fpm
```

Check Nginx:

```bash
sudo systemctl status nginx
```

Check the application response:

```bash
curl -I http://134.209.243.73
```

Open the application:

```text
http://134.209.243.73
```

---

## 20. Important Security Notes

- Do not upload `.env` to GitHub.
- Do not upload passwords or API keys to GitHub.
- Do not upload database dumps containing sensitive information.
- Use HTTPS with a domain name in a production environment.
- Configure SMTP before relying on email notifications.
- Use a strong password for the Snipe-IT administrator account.
- Avoid running Composer or Laravel commands as `root` unless necessary.
- Keep Ubuntu, PHP, Nginx, MariaDB, and Snipe-IT updated.
- Do not delete the default Nginx site if it is used by another application on the same server.

---

## 21. Useful Paths

| Purpose | Path |
|---|---|
| Snipe-IT project | `/var/www/snipeit` |
| Public directory | `/var/www/snipeit/public` |
| Environment file | `/var/www/snipeit/.env` |
| Storage directory | `/var/www/snipeit/storage` |
| Laravel cache directory | `/var/www/snipeit/bootstrap/cache` |
| Nginx available configuration | `/etc/nginx/sites-available/snipeit` |
| Nginx enabled configuration | `/etc/nginx/sites-enabled/snipeit` |
| Nginx access log | `/var/log/nginx/snipeit_access.log` |
| Nginx error log | `/var/log/nginx/snipeit_error.log` |
| PHP-FPM socket | `/run/php/php8.3-fpm.sock` |

---

## 22. GitHub Files to Upload

Recommended files for the repository:

```text
system-admin/
└── snipe-it/
    └── README.md
```

Only upload documentation and safe configuration examples.

Do not upload:

```text
.env
vendor/
storage/logs/
database dumps
password files
private keys
API tokens
```
