# Dockerized WordPress (Nginx + PHP-FPM 8.4 + MySQL) with Basic Auth on /wp-admin

This repo contains a Docker Compose setup that runs a WordPress site using three separate containers:

- **nginx** — web server, serves static files, proxies PHP requests, and enforces HTTP Basic Auth on `/wp-admin/`
- **wordpress (PHP-FPM 8.4)** — runs the actual WordPress/PHP code
- **mysql** — the database

Each service runs in its own container ("one process per container"), which mirrors a traditional bare-metal LEMP setup (Linux, nginx, MySQL, PHP) but with every piece isolated, reproducible, and portable.

## Architecture

```
Browser
   │
   ▼
[nginx container] :80  ──auth_basic on /wp-admin/──
   │
   │  fastcgi_pass wordpress:9000
   ▼
[wordpress container] (PHP-FPM 8.4, no built-in web server)
   │
   │  WORDPRESS_DB_HOST=mysql:3306
   ▼
[mysql container] :3306
```

nginx and PHP-FPM communicate over Docker's internal network using **service names as hostnames** (`wordpress`, `mysql`) — Docker's built-in DNS resolves these automatically for any containers on the same network.

## Prerequisites

- Ubuntu/Debian server
- Docker Engine + Docker Compose plugin (installation steps below)
- At least 2GB RAM recommended. **On a 1GB server, you will very likely need swap space** — see the Troubleshooting section, this was required in practice.

## 1. Install Docker & Docker Compose

The Ubuntu repo package (`docker.io`) is often outdated and doesn't include Compose. This installs Docker's official repo instead, so you get the latest stable engine plus the Compose plugin.

```bash
# Remove any old/conflicting packages (safe even if none exist)
sudo apt remove docker docker-engine docker.io containerd runc -y

# Prerequisites
sudo apt update
sudo apt install ca-certificates curl gnupg -y

# Add Docker's official GPG key
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

# Add Docker's repository
echo \
  "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \
  $(. /etc/os-release && echo "$VERSION_CODENAME") stable" | \
  sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

# Install Docker Engine + Compose plugin
sudo apt update
sudo apt install docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin -y

# Allow running docker without sudo (log out/in or `newgrp docker` after)
sudo usermod -aG docker $USER
```

Verify:
```bash
docker --version
docker compose version
```

> **If migrating from an existing bare-metal WordPress install:** stop and disable the old nginx, mysql, and php-fpm services once the Docker containers are confirmed working, so they don't compete for ports/RAM:
> ```bash
> sudo systemctl stop nginx mysql php8.4-fpm
> sudo systemctl disable nginx mysql php8.4-fpm
> ```

## 2. Project structure

```
wordpress-docker/
├── docker-compose.yml
├── .env
└── nginx/
    ├── nginx.conf
    └── .htpasswd
```

```bash
mkdir -p ~/wordpress-docker/nginx
cd ~/wordpress-docker
```

## 3. `.env` — credentials

Kept separate from `docker-compose.yml` so secrets aren't hardcoded into version-controlled config. **Do not commit this file** — add it to `.gitignore`.

```env
MYSQL_ROOT_PASSWORD=your_root_password
MYSQL_DATABASE=WordPress
MYSQL_USER=WordPressUser
MYSQL_PASSWORD=your_wp_db_password
```

`docker-compose.yml` reads these via `${VARIABLE_NAME}` syntax.

## 4. `docker-compose.yml`

```yaml
services:
  mysql:
    image: mysql:8.0
    container_name: wp_mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
      MYSQL_DATABASE: ${MYSQL_DATABASE}
      MYSQL_USER: ${MYSQL_USER}
      MYSQL_PASSWORD: ${MYSQL_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    networks:
      - wp_net

  wordpress:
    image: wordpress:php8.4-fpm
    container_name: wp_php
    restart: unless-stopped
    depends_on:
      - mysql
    environment:
      WORDPRESS_DB_HOST: mysql:3306
      WORDPRESS_DB_USER: ${MYSQL_USER}
      WORDPRESS_DB_PASSWORD: ${MYSQL_PASSWORD}
      WORDPRESS_DB_NAME: ${MYSQL_DATABASE}
    volumes:
      - wp_data:/var/www/html
    networks:
      - wp_net

  nginx:
    image: nginx:stable
    container_name: wp_nginx
    restart: unless-stopped
    depends_on:
      - wordpress
    ports:
      - "80:80"
    volumes:
      - wp_data:/var/www/html
      - ./nginx/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - ./nginx/.htpasswd:/etc/nginx/.htpasswd:ro
    networks:
      - wp_net

volumes:
  db_data:
  wp_data:

networks:
  wp_net:
```

### Explanation, service by service

**`mysql`**
- `image: mysql:8.0` — official MySQL image.
- `environment` — the official image reads these exact variable names on first boot to auto-create the root password, database, and a non-root user.
- `volumes: - db_data:/var/lib/mysql` — a **named volume**. MySQL's data directory is persisted here so the database survives container recreation. Without this, deleting the container would wipe the database.
- `networks: - wp_net` — puts this container on a private Docker network so other containers can reach it by service name.

**`wordpress`**
- `image: wordpress:php8.4-fpm` — the **FPM variant**, meaning PHP-FPM only, no built-in web server. nginx handles serving requests separately; this container only executes PHP.
- `depends_on: - mysql` — waits for the mysql container to *start* before starting (not a full readiness check — just container start order).
- `WORDPRESS_DB_HOST: mysql:3306` — `mysql` here is the **service name**, resolved via Docker's internal DNS to the mysql container's internal IP. `3306` is MySQL's default port.
- `volumes: - wp_data:/var/www/html` — another named volume holding all WordPress core files, themes, plugins, and `wp-config.php`.

**`nginx`**
- `ports: - "80:80"` — the only service exposed to the outside world. Format is `host_port:container_port`. MySQL and PHP-FPM never need a `ports:` entry since only nginx talks to them directly, container-to-container.
- `volumes:` — mounts the **same** `wp_data` volume as the wordpress service (so nginx can serve static files like images/CSS directly), plus two **bind mounts** (`nginx.conf`, `.htpasswd`) — these map specific files from the host filesystem into the container, `:ro` (read-only) so the container can't modify your config files.

### Why `db_data` / `wp_data` are named volumes, not bind mounts

| | Named volume | Bind mount |
|---|---|---|
| Location | Docker-managed, you don't need to know the path | You choose the exact host path |
| Used for | Data the container owns (DB files, WP core) | Files *you* edit directly (`nginx.conf`) |
| Permissions | Initialized cleanly by Docker | Inherits host filesystem ownership (can cause permission issues) |
| Portability | Works identically if moved to another host | Hardcoded to a specific host path |

## 5. `nginx/nginx.conf`

```nginx
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    root /var/www/html;
    index index.php index.html index.htm;
    server_name _;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        try_files $uri =404;
        include fastcgi_params;
        fastcgi_split_path_info ^(.+\.php)(/.+)$;
        fastcgi_pass wordpress:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    location ~ /\.ht {
        deny all;
    }

    location /wp-admin/ {
        auth_basic "login";
        auth_basic_user_file /etc/nginx/.htpasswd;

        location ~ \.php$ {
            try_files $uri =404;
            include fastcgi_params;
            fastcgi_split_path_info ^(.+\.php)(/.+)$;
            fastcgi_pass wordpress:9000;
            fastcgi_index index.php;
            fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        }
    }
}
```

### Explanation

- **`root /var/www/html;`** — must match wherever WordPress's files actually land inside the `wp_data` volume. This is the single most common source of a 404: if `root` points to a subfolder (e.g. `/var/www/html/wordpress`) that doesn't actually exist in the volume, nginx returns 404 for everything.
- **`location / { try_files ... }`** — WordPress's standard pretty-permalinks rule: try the exact file, then a directory, then fall back to `index.php` with the query string, which is how WordPress's own routing takes over.
- **`location ~ \.php$`** — any request ending in `.php` gets forwarded to PHP-FPM.
  - **`fastcgi_pass wordpress:9000;`** — the key line connecting nginx to PHP-FPM. `wordpress` is the Docker service name (not `localhost`, since PHP-FPM is in a separate container); `9000` is PHP-FPM's default listening port. In bare-metal setups this is usually a Unix socket (`unix:/run/php/php8.4-fpm.sock`) — but containers don't share a filesystem, so a TCP host:port connection is used instead.
  - **`include fastcgi_params;`** + the two `fastcgi_split_path_info` / `fastcgi_param SCRIPT_FILENAME` lines — inline replacement for what Ubuntu's `snippets/fastcgi-php.conf` normally provides; that snippet file doesn't exist in the plain official nginx image, so its contents are written out directly.
- **`location ~ /\.ht { deny all; }`** — blocks direct browser access to `.htpasswd`/`.htaccess` files as defense-in-depth.
- **`location /wp-admin/ { auth_basic ... }`** — HTTP Basic Auth is scoped to `/wp-admin/` only, not the whole site, so visitors can browse the public site normally, but logging into the dashboard requires the auth prompt. It has its own nested `\.php$` block because nginx's `location` matching doesn't automatically inherit the outer PHP handling — the auth-protected area needs its own explicit FastCGI rule.

## 6. Basic Auth credentials (`.htpasswd`)

```bash
sudo apt install apache2-utils -y
htpasswd -c nginx/.htpasswd yourusername
```
(drop `-c` if the file already exists and you're adding more users)

This generates a bcrypt/apr1-hashed username:password file, mounted read-only into the nginx container.

## 7. Start the stack

```bash
docker compose up -d
docker compose ps -a
```

First run pulls all three images from Docker Hub and creates the named volumes automatically.

## 8. (If migrating an existing site) Import files & database

```bash
# Copy existing WordPress files into the running container's volume
docker cp /path/to/old/wordpress/. wp_php:/var/www/html/
docker exec wp_php chown -R www-data:www-data /var/www/html

# Fix wp-config.php's DB_HOST — it was "localhost" on bare metal,
# but MySQL is now a separate container reachable only by service name
docker exec wp_php sed -i "s/define( 'DB_HOST', 'localhost' );/define( 'DB_HOST', 'mysql:3306' );/" /var/www/html/wp-config.php

# Import the database dump
docker exec -i wp_mysql mysql -u WordPressUser -p'yourpassword' WordPress < wordpress_backup.sql
```

## Troubleshooting (issues actually encountered while building this)

**Port 80 already in use**
Old bare-metal nginx was still running and holding the port. Only one process can bind a given host port — stop and disable the old service:
```bash
sudo systemctl stop nginx && sudo systemctl disable nginx
```

**MySQL container exits with code 137**
137 = OOM-killed. On a low-RAM server (e.g. a 1GB droplet), MySQL needs more memory than is available, and the Linux OOM killer terminates it. Fix by adding swap space:
```bash
sudo fallocate -l 1G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

**`Access denied for user` even with the correct password**
MySQL only runs its first-boot user/database creation **once**, the very first time it starts with a completely empty volume. If that first boot is interrupted (e.g. by an OOM kill above), the volume is left half-initialized and later restarts won't retry setup. Fix by removing the broken volume and letting MySQL reinitialize cleanly (make sure swap is in place first, so it doesn't get OOM-killed again mid-setup):
```bash
docker compose down
docker volume rm <project>_db_data
docker compose up -d
```

**Site returns 404 on every page, but nginx itself is running fine**
`root` in `nginx.conf` doesn't match where the WordPress files actually are inside the volume — check with:
```bash
docker exec wp_nginx ls -la /var/www/html
```
and adjust the `root` directive to match.

## Notes

- The `wordpress:php8.4-fpm` image tag should be checked against Docker Hub before deploying, since official tags can lag behind the latest PHP point release.
- For production use, add HTTPS (e.g. via a certbot container or a reverse proxy in front of this stack) and use strong, unique passwords in `.env`.
