# Nginx Load Balancer with Weighted Round-Robin + Custom Access Logging

A standalone Docker Compose project demonstrating nginx as a **load balancer** in front of two simple backend websites, with one backend intentionally receiving more traffic than the other, plus a **custom log format** that proves it's actually working.

This is a self-contained learning project, independent from the WordPress stack — it has its own `docker-compose.yml` and its own network, so it can be started/stopped without affecting anything else.

## Concept

Normally, a reverse proxy (`proxy_pass`) points to **one single backend**. A **load balancer** instead points to a **group of backends** (nginx calls this an `upstream` block), and nginx decides — request by request — which one handles it, based on a strategy.

- **Default strategy:** round-robin — alternates evenly (1, 2, 1, 2, 1, 2...)
- **This setup:** weighted round-robin — each backend gets a numeric weight, and nginx sends traffic proportionally more to the higher-weighted one

Here, **Website 1** is weighted `3` and **Website 2** is weighted `1` — meaning Website 1 receives roughly **3 requests for every 1** Website 2 receives (~75%/25% split).

## Architecture

```
Browser
   │
   ├── :8090 → [loadbalancer] ──upstream (weighted)──▶ [web1:80] (weight 3)
   │                                                 └▶ [web2:80] (weight 1)
   │
   ├── :8091 → [web1] directly
   └── :8092 → [web2] directly
```

Ports 8091/8092 exist purely for testing — to confirm each backend works on its own, separate from the load-balanced entry point on 8090.

## Files

```
loadbalancer/
├── docker-compose.yml
├── web1/
│   └── index.html
├── web2/
│   └── index.html
├── nginx-lb/
│   └── nginx.conf
└── logs/
    └── custom_access.log   (created automatically once running)
```

## 1. The two backend "websites"

Plain static HTML, no PHP/database — kept intentionally simple so the exercise focuses purely on the load-balancing mechanism, not application complexity.

`web1/index.html`:
```html
<!DOCTYPE html>
<html>
<head><title>Website 1</title></head>
<body style="background:#d1e7dd; text-align:center; padding-top:100px;">
<h1>This is Website 1</h1>
</body>
</html>
```

`web2/index.html` — identical structure, different text/color, so the two are visually distinguishable when testing.

These are served by the plain official `nginx:stable` image with zero custom config — just a folder bind-mounted onto nginx's default webroot (see the Compose file below).

## 2. The load balancer's config — `nginx-lb/nginx.conf`

```nginx
log_format lb_format '$remote_addr - [$time_local] "$request" '
                      'upstream: $upstream_addr '
                      'status: $status '
                      'bytes: $body_bytes_sent';

upstream backend {
    server web1:80 weight=3;
    server web2:80 weight=1;
}

server {
    listen 80;
    server_name _;

    access_log /var/log/nginx/custom_access.log lb_format;

    location / {
        proxy_pass http://backend;
        proxy_set_header Host $host;
        proxy_set_header X-Real-IP $remote_addr;
    }
}
```

### Explanation

**Load balancing:**

- **`upstream backend { ... }`** — defines a named group of backend servers called `backend` (the name is arbitrary). This is nginx's core load-balancing feature: instead of `proxy_pass` pointing at one fixed host, it points at this group, and nginx picks a member per request.
- **`server web1:80 weight=3;`** / **`server web2:80 weight=1;`** — lists backends by their Docker service name (resolved via Docker's internal DNS, same mechanism used everywhere else in this project) and port. `weight=3` vs `weight=1` is what creates the 3:1 traffic split. Without any `weight=`, nginx defaults every server to `weight=1` (plain even round-robin).
- **`proxy_pass http://backend;`** — proxies to the **upstream group name** rather than a specific container; nginx handles picking which actual server per request.

**Custom logging:**

- **`log_format lb_format '...';`** — defines a custom log line format, named `lb_format` (arbitrary name), built from nginx variables:
  - `$remote_addr` — visitor's IP
  - `$time_local` — timestamp
  - `$request` — the raw HTTP request line (e.g. `GET / HTTP/1.1`)
  - **`$upstream_addr`** — the key variable for this task: nginx automatically fills this in with whichever backend (`web1:80` or `web2:80`) actually handled that specific request. This variable only exists/populates when proxying through an `upstream` block — it's what lets the log prove the weighting is real, rather than just eyeballing the browser.
  - `$status` — HTTP response code
  - `$body_bytes_sent` — response size in bytes
- **`access_log /var/log/nginx/custom_access.log lb_format;`** — activates the custom format. Without explicitly naming `lb_format` here, nginx would silently fall back to its built-in default format instead. The path is *inside the container*; it's connected to the host via a bind mount (below).

## 3. `docker-compose.yml`

```yaml
services:
  web1:
    image: nginx:stable
    container_name: web1
    restart: unless-stopped
    ports:
      - "8091:80"
    volumes:
      - ./web1:/usr/share/nginx/html:ro
    networks:
      - lb_net

  web2:
    image: nginx:stable
    container_name: web2
    restart: unless-stopped
    ports:
      - "8092:80"
    volumes:
      - ./web2:/usr/share/nginx/html:ro
    networks:
      - lb_net

  loadbalancer:
    image: nginx:stable
    container_name: wp_loadbalancer
    restart: unless-stopped
    depends_on:
      - web1
      - web2
    ports:
      - "8090:80"
    volumes:
      - ./nginx-lb/nginx.conf:/etc/nginx/conf.d/default.conf:ro
      - ./logs:/var/log/nginx
    networks:
      - lb_net

networks:
  lb_net:
```

### Explanation

- **`./web1:/usr/share/nginx/html:ro`** — a **whole-folder bind mount** (not a single file). `/usr/share/nginx/html` is nginx's default webroot inside the official image. Mounting the entire folder (rather than just `index.html`) means any file later added to `web1/` on the host — a second page, a stylesheet, images — becomes instantly visible inside the container too, with no rebuild or restart needed. `:ro` prevents the container from writing back to the host folder.
- **`./logs:/var/log/nginx`** — bind mount for the log directory, **deliberately without `:ro`**. Unlike config files (which the container should only read), nginx needs write access here to actually create and append to the log file. Because it's a bind mount rather than a named volume, the log file is directly readable from the host with `cat`/`tail`, and it survives container restarts/recreation.
- **`lb_net`** — a dedicated network for this project, separate from the WordPress stack's `wp_net`, keeping this a fully independent, self-contained example.

## Running it

```bash
cd loadbalancer
docker compose config   # validate YAML first
docker compose up -d
docker compose ps -a
```

## Testing the weighting

Generate a burst of requests through the load balancer:
```bash
for i in {1..10}; do curl -s http://localhost:8090/ > /dev/null; done
```

Read the custom log directly from the host:
```bash
cat logs/custom_access.log
```

Each line shows `upstream: web1:80` or `upstream: web2:80`. Out of 10 requests, expect roughly **7–8 lines showing `web1:80`** and **2–3 showing `web2:80`** — confirming the 3:1 weighted distribution is real, not just a visual impression from refreshing a browser.

Testing each backend individually (bypassing the load balancer):
```bash
curl http://localhost:8091/   # always Website 1
curl http://localhost:8092/   # always Website 2
```

## How bind mounts work (relevant to both the sites and the logs folder)

A bind mount doesn't copy files into the container — it links a host path and a container path to the **same underlying data on disk**, using the Linux kernel's `mount` mechanism. Editing a file on the host is instantly visible inside the container (and vice versa, if writable) because there's no syncing involved — it's the same file, accessed through two different filesystem paths. This is why adding a new file to `web1/` or reading `logs/custom_access.log` never requires a container restart.
