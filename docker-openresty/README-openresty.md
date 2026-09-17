# OpenResty as a Reverse Proxy in Front of Nginx (WordPress stack)

This adds **OpenResty** as a new front door for the [Dockerized WordPress](./README.md) stack. OpenResty sits in front of the existing nginx container and forwards every request to it — nginx keeps doing exactly what it already did (Basic Auth on `/wp-admin/`, static files, PHP-FPM forwarding), it's just no longer the first thing the browser talks to.

## What OpenResty is

OpenResty is **nginx bundled with LuaJIT** (a scripting engine) — it's built directly on the nginx source code, so it supports every standard nginx directive, plus the ability to run custom Lua scripts for more advanced logic. This setup doesn't use any Lua — it only uses OpenResty's core nginx-level reverse-proxying ability (`proxy_pass`), the same directive plain nginx has.

## Architecture

```
Browser
   │
   ├── :80    → [OpenResty] ──proxy_pass http://nginx:80──▶ [nginx] ──▶ [wordpress:9000] ──▶ [mysql:3306]
   │
   └── :8080  → [nginx] directly ──▶ [wordpress:9000] ──▶ [mysql:3306]
```

- Port **80** → OpenResty → nginx → PHP-FPM → MySQL (the "real" public entry point)
- Port **8080** → nginx directly, bypassing OpenResty entirely (useful for testing/comparison)

nginx no longer publishes port 80 itself — that ownership moved to OpenResty, freeing port 80 up. nginx moved to port 8080 instead, so both remain independently reachable.

## Why this pattern is useful in general

Putting a proxy in front of another proxy is a common real-world pattern for:
- Adding a layer that can later handle SSL termination, caching, rate limiting, or custom Lua logic (auth checks, A/B testing, header rewriting) **without touching the WordPress-facing nginx config at all**
- Swapping or scaling the "front door" independently of the backend web server
- Learning how reverse proxy chains work before introducing something more complex (e.g. a full load balancer — see the separate load-balancer README)

## Files

```
wordpress-docker/
├── docker-compose.yml       (modified: nginx port + new openresty service)
└── openresty/
    └── nginx.conf
```

## 1. OpenResty's config — `openresty/nginx.conf`

```nginx
server {
    listen 80;
    server_name _;

    location / {
        proxy_pass http://nginx:80;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
    }
}
```

### Explanation

- **`listen 80;`** — port 80 *inside OpenResty's own container*. This doesn't conflict with anything on the host until it's actually published via `ports:` in Compose.
- **`location / { ... }`** — matches every incoming request, regardless of path.
- **`proxy_pass http://nginx:80;`** — the core of the setup. Unlike `fastcgi_pass` (which speaks the FastCGI protocol to PHP-FPM), `proxy_pass` makes OpenResty act as an **HTTP client**, sending a normal HTTP request to another web server and relaying the response back. `nginx` is the Docker Compose **service name** of the existing nginx container — resolved automatically via Docker's internal DNS, since both containers share the same Docker network (`wp_net`).
- **`proxy_set_header Host $host;`** — without this, nginx would see every request as coming from the internal hostname `nginx` instead of the domain/IP the visitor actually typed, breaking `server_name` matching and WordPress's own URL generation.
- **`proxy_set_header X-Real-IP $remote_addr;`** and **`X-Forwarded-For $proxy_add_x_forwarded_for;`** — without these, nginx (and WordPress) would think every visitor is coming from OpenResty's internal container IP. These headers preserve the real visitor IP through the proxy chain — relevant for access logs, comment/security plugins, and rate limiting.
- **`X-Forwarded-Proto $scheme;`** — tells the next layer whether the original request was HTTP or HTTPS (matters once SSL is added later, likely terminated at OpenResty).

## 2. Changes to `docker-compose.yml`

**nginx's port mapping changes** so OpenResty can take over port 80:
```yaml
  nginx:
    ports:
      - "8080:80"   # was "80:80"
```
Only the **host-facing** side changes — nginx's internal container port stays `80`; nothing inside nginx's own config changes.

**New `openresty` service:**
```yaml
  openresty:
    image: openresty/openresty:alpine
    container_name: wp_openresty
    restart: unless-stopped
    depends_on:
      - nginx
    ports:
      - "80:80"
    volumes:
      - ./openresty/nginx.conf:/etc/nginx/conf.d/default.conf:ro
    networks:
      - wp_net
```

### Explanation

- **`image: openresty/openresty:alpine`** — the official OpenResty image, `alpine` variant (a minimal Linux base for a smaller image size).
- **`depends_on: - nginx`** — waits for the nginx container to *start* before starting OpenResty (container start order, not a full readiness check).
- **`ports: - "80:80"`** — OpenResty is now the only service claiming host port 80.
- **`volumes:`** — a **bind mount** (read-only) connecting the config file written above into the container, same pattern used for nginx's own config elsewhere in this project.
- **`networks: - wp_net`** — critical: OpenResty must be on the **same Docker network** as nginx to reach it by service name. Without this, `proxy_pass http://nginx:80` would fail with a "host not found" error, since Docker's internal DNS only resolves names for containers on the same network.

## Applying the change

```bash
docker compose config   # validate the YAML first
docker compose up -d
docker compose ps -a
```

## Testing

```bash
curl -I http://localhost/        # → OpenResty → nginx → WordPress
curl -I http://localhost:8080/   # → nginx directly
```

Both should return `200 OK`. `/wp-admin/` should prompt for Basic Auth either way, since that logic still lives entirely in nginx's own config — OpenResty is a transparent pass-through and has no awareness of WordPress or auth at all.

> **Note:** if testing via browser, be aware some browsers auto-suggest a previously visited port (e.g. `:8080`) when you start typing just the IP address, due to address bar autocomplete — not a server-side issue. Use a private/incognito window or `curl` from the server to test unambiguously.

## Summary (short version)

OpenResty and nginx are containers on the same Docker network. OpenResty uses nginx's `proxy_pass` directive to forward all incoming traffic to the nginx container by its Docker service name, so requests flow:

```
browser → OpenResty (port 80) → nginx (internal) → PHP-FPM (internal) → MySQL
```
