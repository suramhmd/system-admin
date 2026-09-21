# Percona XtraDB Cluster (Galera) — Node 1 Setup

Single-node bootstrap of a Percona XtraDB Cluster (PXC 8.0), the first node in a planned 3-node Galera cluster for MySQL high availability. This repo documents the setup process, configuration, and troubleshooting notes from getting node 1 running.

## Overview

- **Cluster type:** Percona XtraDB Cluster (PXC) 8.0 — MySQL + Galera replication
- **Topology (planned):** 3 nodes, multi-master, synchronous replication
- **Status:** Node 1 bootstrapped and running. Nodes 2/3 not yet configured.
- **OS:** Ubuntu (DigitalOcean droplet, 1 vCPU / 1GB RAM)

## Why PXC

Chosen over Codership's reference Galera build for MySQL because it ships as a single integrated package (`percona-xtradb-cluster`), has wider production adoption, active vendor support/security patching, and bundles useful tooling (`xtrabackup` for SST).

## Node Details

| Setting | Value |
|---|---|
| Node name | `pxc-cluster-node-1` |
| Node IP | `134.209.243.73` |
| Cluster name | `pxc-cluster` |
| Config file | `/etc/mysql/mysql.conf.d/mysqld.cnf` |
| SST method | `xtrabackup-v2` |

## Setup Steps

### 1. Add Percona repository & install

```bash
sudo apt update
sudo apt install -y wget gnupg2 lsb-release curl

wget https://repo.percona.com/apt/percona-release_latest.$(lsb_release -sc)_all.deb
sudo dpkg -i percona-release_latest.$(lsb_release -sc)_all.deb
sudo apt update
sudo percona-release setup pxc80

sudo apt install -y percona-xtradb-cluster
```

### 2. Stop auto-started standalone instance

```bash
sudo systemctl stop mysql
```

The installer starts MySQL as a normal (non-clustered) instance. Stop it before editing Galera config.

### 3. Configure Galera

Edit `/etc/mysql/mysql.conf.d/mysqld.cnf`, add under `[mysqld]`:

```ini
wsrep_provider=/usr/lib/galera4/libgalera_smm.so
wsrep_cluster_address=gcomm://
wsrep_cluster_name=pxc-cluster
wsrep_node_name=pxc-cluster-node-1
wsrep_node_address=134.209.243.73
wsrep_sst_method=xtrabackup-v2
binlog_format=ROW
innodb_autoinc_lock_mode=2
```

> `wsrep_cluster_address` is left empty on the first node — this signals "start a new cluster" rather than joining existing peers. It gets filled in with all node IPs once node2/node3 exist.

### 4. Open firewall ports

```bash
sudo ufw allow 3306   # MySQL client connections
sudo ufw allow 4567   # Galera replication (TCP/UDP)
sudo ufw allow 4568   # IST
sudo ufw allow 4444   # SST
```

### 5. Bootstrap the cluster (first node, first time only)

```bash
sudo systemctl start mysql@bootstrap
```

Starts `mysqld` with `--wsrep-new-cluster`, creating a brand-new single-node cluster. **Only ever run once per cluster's lifetime, on one node.**

### 6. Verify

```bash
mysql -u root -p -e "SHOW STATUS LIKE 'wsrep_cluster_size';"    # 1
mysql -u root -p -e "SHOW STATUS LIKE 'wsrep_cluster_status';"  # Primary
mysql -u root -p -e "SHOW STATUS LIKE 'wsrep_ready';"           # ON
```

### 7. Switch from bootstrap to normal service

```bash
sudo systemctl stop mysql@bootstrap
systemctl status mysql@bootstrap   # confirm inactive before continuing
sudo systemctl start mysql
```

`mysql@bootstrap` and `mysql` both run the same `mysqld` against the same data directory — only one can be active at a time. All restarts after the initial bootstrap use the normal `mysql` service.

## Troubleshooting Notes

**`unknown variable 'wsrep_sst_auth=...'`**
`wsrep_sst_auth` was removed in PXC 8.0 — SST authentication is now handled internally with an auto-generated, single-use credential. Remove the line entirely; no replacement setting needed.

**`PXC is in bootstrap mode` when starting `mysql.service`**
Caused by `mysql@bootstrap` still running when trying to start the normal service. Stop bootstrap first, confirm `inactive`, then start normally.

**`mysql@bootstrap.service` fails to start on an already-running node**
Bootstrap creates a *new* cluster — it can't run against a node that's already part of a live one. Use `systemctl start/restart mysql` for anything after the initial bootstrap.

## Key Concepts

| Term | Meaning |
|---|---|
| **Bootstrap** | One-time action to start a brand-new cluster from a single node with no peers |
| **SST** | Full data copy sent to a node joining with no/stale data |
| **IST** | Partial catch-up for a briefly disconnected node |
| **wsrep** | Write-Set Replication — the API Galera plugs into MySQL through |
| **Quorum** | Majority-vote mechanism preventing split-brain during network partitions |

## TODO

- [ ] Set up node 2 and node 3 (normal `mysql` start, not bootstrap)
- [ ] Update `wsrep_cluster_address` on all nodes to list all three IPs
- [ ] Restrict firewall rules to specific inter-node IPs only
- [ ] Add ProxySQL or HAProxy in front of the cluster
