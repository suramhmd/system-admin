# hello-app — PM2 Practice Project

A small Node.js/Express app used to learn and practice [PM2](https://pm2.keymetrics.io/), a process manager for Node.js applications. Cloned from [heroku/node-js-getting-started](https://github.com/heroku/node-js-getting-started).

## What is PM2?

PM2 is a **process manager** for Node.js apps. Instead of running your app directly with `node app.js` (which dies the moment you close your terminal, doesn't restart on crash, and won't survive a server reboot), you run it *through* PM2, which supervises it in the background.

### What PM2 gives you

- **Keeps your app alive** — if it crashes, PM2 restarts it automatically.
- **Runs in the background** — closing your SSH session doesn't stop the app.
- **Survives server reboots** — with `pm2 save` + `pm2 startup`, PM2 relaunches your apps automatically after a reboot.
- **Log management** — captures stdout/stderr into log files (`pm2 logs`).
- **Monitoring** — `pm2 status` / `pm2 monit` show CPU, memory, uptime, and restart count for every app.
- **Clustering** — can run multiple instances of an app across all CPU cores with zero-downtime reloads (`pm2 start app.js -i max`).

Analogy: running `node app.js` is like manually holding down a car's gas pedal — let go, and it stops. PM2 is cruise control plus a mechanic: it keeps the app running, restarts it if it stalls, and logs everything along the way.

## Setup

### 1. Prerequisites

```bash
node -v
npm -v
git --version
```

Node.js, npm, and git must be installed.

### 2. Clone the project

```bash
git clone https://github.com/heroku/node-js-getting-started.git
cd node-js-getting-started
```

### 3. Install dependencies

```bash
npm install
```

This reads `package.json` and downloads the required packages (e.g. Express) into `node_modules`.

### 4. Install PM2 globally

```bash
npm install -g pm2
```

### 5. Start the app with PM2

```bash
pm2 start index.js --name hello-app
```

The app listens on `process.env.PORT || 5006` (see `index.js`).

## Useful PM2 Commands

| Command | What it does |
|---|---|
| `pm2 status` | List all apps PM2 is managing, with CPU/memory/restarts |
| `pm2 logs hello-app` | Tail logs for this app |
| `pm2 restart hello-app` | Restart the app |
| `pm2 stop hello-app` | Stop the app (stays registered in PM2) |
| `pm2 delete hello-app` | Remove the app from PM2 entirely |
| `pm2 save` | Save the current process list |
| `pm2 startup` | Generate the command to auto-launch PM2 on server boot |

## Verifying it works

```bash
curl http://localhost:5006
```

Should return the app's HTML homepage. If testing from a browser on a remote server, make sure the port is open:

```bash
sudo ufw status
sudo ufw allow 5006/tcp   # if ufw is active and the port isn't listed
```

Also check any cloud provider firewall (e.g. DigitalOcean Cloud Firewall) if the port is still unreachable after `ufw` is configured.

## Making the app survive a reboot

```bash
pm2 save
pm2 startup
```

`pm2 startup` prints a command to run once (registers PM2 as a system service). `pm2 save` snapshots the current process list so PM2 knows what to relaunch after a reboot.
