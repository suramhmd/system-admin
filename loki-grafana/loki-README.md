# Loki + Grafana Monitoring Setup

## Server Info
- Host: `ubuntu-s-1vcpu-1gb-fra1-SURA`
- Ubuntu 24.04.4 LTS, 1 vCPU / 1GB RAM
- Public IP: `134.209.243.73`
- Docker already installed (running an older container from a previous task)

---

## 1. Swap

Added since the server only has 1GB RAM and no swap, to avoid OOM kills under memory pressure.

```bash
fallocate -l 2G /swapfile
chmod 600 /swapfile
mkswap /swapfile
swapon /swapfile
echo '/swapfile none swap sw 0 0' >> /etc/fstab
```

---

## 2. Grafana APT Repository

Covers Grafana, Loki, and Alloy from one repo.

```bash
sudo apt-get install -y apt-transport-https software-properties-common wget
sudo mkdir -p /etc/apt/keyrings/
wget -q -O - https://apt.grafana.com/gpg.key | gpg --dearmor | sudo tee /etc/apt/keyrings/grafana.gpg > /dev/null
echo "deb [signed-by=/etc/apt/keyrings/grafana.gpg] https://apt.grafana.com stable main" | sudo tee /etc/apt/sources.list.d/grafana.list
sudo apt-get update
```

**Note:** on the first attempt, `tee` was pointed at the `/etc/apt/sources.list.d` directory instead of a file inside it, so the repo entry wasn't actually created. Fixed by writing to `/etc/apt/sources.list.d/grafana.list` explicitly.

---

## 3. Install Loki and Grafana

```bash
sudo apt-get install -y loki grafana
```

Installed as native services (no Docker):
- Config: `/etc/loki/`, `/etc/grafana/`
- systemd units: `loki.service`, `grafana-server.service`

---

## 4. Start Services

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now loki
sudo systemctl enable --now grafana-server
sudo systemctl status loki grafana-server
```

---

## 5. Log Shipping Agent (Alloy) — installed, then removed

Alloy was installed initially to ship logs to Loki, using this config (`/etc/alloy/config.alloy`):

```
logging {
  level = "warn"
}

prometheus.exporter.unix "default" {
  include_exporter_metrics = true
  disable_collectors       = ["mdadm"]
}

prometheus.scrape "default" {
  targets = array.concat(
    prometheus.exporter.unix.default.targets,
    [{
      job         = "alloy",
      __address__ = "127.0.0.1:12345",
    }],
  )

  forward_to = []
}

local.file_match "logs" {
  path_targets = [{"__path__" = "/var/log/*.log"}]
}

loki.source.file "local_files" {
  targets    = local.file_match.logs.targets
  forward_to = [loki.write.local_loki.receiver]
}

loki.write "local_loki" {
  endpoint {
    url = "http://localhost:3100/loki/api/v1/push"
  }
}
```

Alloy was later uninstalled:
```bash
sudo systemctl stop alloy
sudo systemctl disable alloy
sudo apt-get purge -y alloy
sudo rm -rf /etc/alloy
```

**Status:** no log-shipping agent currently running. Loki and Grafana are up, but nothing is actively pushing logs into Loki until a new agent is chosen/configured.

---

## 6. Grafana Data Source

- Connections → Data sources → Add data source → Loki
- URL: `http://localhost:3100`
- No auth (Loki has no built-in authentication — kept bound to localhost, not exposed externally)
- Saved and tested successfully

---

## 7. Security Notes
- Loki (port 3100): localhost only, never exposed to the internet.
- Grafana (port 3000): reachable externally. Default admin password was changed on first login.
- Firewall (UFW) not yet configured on this box — worth revisiting if external access needs restricting to specific IPs.

---

# SNMP Connection to Monitoring Server (LibreNMS)

This server was also connected to a separate monitoring server (LibreNMS, referred to as "Libra") over SNMP, so that server can poll metrics from this one.

## Install and configure snmpd

```bash
sudo apt update && sudo apt install -y snmpd
sudo nano /etc/snmp/snmpd.conf
```

Config set in `/etc/snmp/snmpd.conf`:
```
sysLocation Remote Loki Server
sysContact Admin <admin@example.com>
sysServices 72

agentAddress udp:161

rocommunity public 201.79.3.136
```

- `sysLocation` / `sysContact` / `sysServices` — descriptive metadata about this host, shown in the monitoring tool
- `agentAddress udp:161` — snmpd listens on UDP port 161 (standard SNMP port)
- `rocommunity public 201.79.3.136` — grants **read-only** SNMP access using the community string `public`, restricted to only accept queries from `201.79.3.136` (the LibreNMS server's IP)

## Apply and verify

```bash
sudo systemctl restart snmpd
sudo systemctl status snmpd
```

**Status:** `snmpd` active and listening; LibreNMS server (`201.79.3.136`) can now poll this host's SNMP metrics.
