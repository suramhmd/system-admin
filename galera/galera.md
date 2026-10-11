# **MySQL Galera Cluster \+ ProxySQL \+ XtraBackup \- Documentation**

## **1\. Overview**

This project builds a highly available database layer:

* **3 MySQL Galera nodes** that stay synchronized with each other (multi-master, synchronous replication).  
* **1 ProxySQL server** that sits in front of the cluster. Applications connect only to ProxySQL, which health-checks the nodes, routes queries, and fails over automatically.  
* **Query rules** in ProxySQL that split reads and writes.  
* **XtraBackup** on one node, run daily by a script and cron, for hot backups.

Application (for example Cachet)  
        |  
        v  
ProxySQL  (134.209.243.73, client port 6033, admin port 6032\)  
        |  
   \+----+-----+  
   v          v          v  
Node 1      Node 2      Node 3  
134.122.66.240   164.92.241.139   167.99.143.219  
        (Galera replication: 4567 / 4568 / 4444\)

### **Servers**

| Role | Hostname | IP |
| :---- | :---- | :---- |
| Galera node 1 (bootstrap node, backups run here) | SURA-mysql01 | 134.122.66.240 |
| Galera node 2 | SURA-mysql02 | 164.92.241.139 |
| Galera node 3 | SURA-mysql03 | 167.99.143.219 |
| ProxySQL | \- | 134.209.243.73 |

Cluster name: mysql-galera-cluster. SST method: rsync. Passwords in this document are placeholders; use unique strong passwords and never reuse one across accounts.

### **Key terms**

* **Galera**: a replication layer that makes several MySQL servers behave as one database. Every node holds a full copy of the data.  
* **Node**: one server in the cluster.  
* **Bootstrap**: starting the very first node of a brand-new cluster. Done once only.  
* **SST (State Snapshot Transfer)**: a full data copy sent to a node that joins with no or stale data. **IST** is the lighter catch-up for a node that was only briefly away.  
* **Quorum**: majority voting that stops two halves of a split cluster from both accepting writes (split-brain). This is why we use 3 nodes.  
* **Hostgroup (ProxySQL)**: a numbered group of backend servers that share a role.  
* **Query rule (ProxySQL)**: an if-this-query-matches-then-send-it-there instruction.

## **2\. Phase 1 \- Galera cluster (3 nodes)**

### **Step 1 \- Add the Codership repositories (all 3 nodes)**

sudo apt update  
sudo apt install \-y gnupg2 curl lsb-release  
sudo gpg \--no-default-keyring \--keyring /usr/share/keyrings/galera-keyring.gpg \--keyserver hkp://keyserver.ubuntu.com \--recv-keys 8DA84635  
echo 'deb \[signed-by=/usr/share/keyrings/galera-keyring.gpg\] http\://releases.galeracluster.com/mysql-wsrep-8.4/ubuntu noble main' | sudo tee /etc/apt/sources.list.d/galera.list  
echo 'deb \[signed-by=/usr/share/keyrings/galera-keyring.gpg\] http\://releases.galeracluster.com/galera-4/ubuntu noble main' | sudo tee \-a /etc/apt/sources.list.d/galera.list  
**Why:** this build is split into two packages: mysql-wsrep-8.4 (MySQL compiled with the wsrep replication API) and galera-4 (the replication library). The signing key lets apt verify the packages.

### **Step 2 \- Pin the repository and install (all 3 nodes)**

sudo tee /etc/apt/preferences.d/galera.pref \<\< 'EOF'  
Package: \*  
Pin: origin releases.galeracluster.com  
Pin-Priority: 1001  
EOF  
sudo apt update  
sudo apt install \-y galera-4 galera-arbitrator-4 mysql-wsrep-8.4  
sudo systemctl stop mysql  
**Why:** the pin stops apt from mixing in Ubuntu's own MySQL packages. MySQL auto-starts after install, so we stop it until Galera is configured.

### **Step 3 \- Configure Galera (all 3 nodes)**

File: /etc/mysql/mysql.conf.d/mysqld.cnf, under \[mysqld\]. Node 1 shown; on nodes 2 and 3 change only wsrep\_node\_name, wsrep\_node\_address and wsrep\_sst\_receive\_address.

wsrep\_on=ON  
wsrep\_provider=/usr/lib/galera/libgalera\_smm.so  
wsrep\_cluster\_address=gcomm://134.122.66.240,164.92.241.139,167.99.143.219  
wsrep\_cluster\_name=mysql-galera-cluster  
wsrep\_node\_name=node-1  
wsrep\_node\_address=134.122.66.240  
wsrep\_sst\_receive\_address=134.122.66.240  
wsrep\_sst\_method=rsync  
binlog\_format=ROW  
default\_storage\_engine=InnoDB  
innodb\_autoinc\_lock\_mode=2  
bind-address=0.0.0.0

* wsrep\_provider: path to the Galera library. **The real path on this package is /usr/lib/galera/libgalera\_smm.so** (verify with find / \-name libgalera\_smm.so).  
* wsrep\_cluster\_address: all node IPs, used by a node to find its peers.  
* wsrep\_cluster\_name: must be identical on every node.  
* binlog\_format=ROW and innodb\_autoinc\_lock\_mode=2: required by Galera.  
* wsrep\_sst\_receive\_address: pins the address used for data transfers.

### **Step 4 \- Bootstrap node 1 (node 1 only, once)**

sudo \-u mysql /usr/sbin/mysqld \--wsrep-new-cluster \--user=mysql \--pid-file=/var/run/mysqld/mysqld.pid &  
mysql \-u root \-p \-e "SHOW STATUS LIKE 'wsrep\_cluster\_size';"  
Expected: 1. This build has no mysql@bootstrap systemd unit, so the first node is started by hand with \--wsrep-new-cluster. Do not restart node 1 under systemd while the others are down, because a lone node restarting normally cannot re-form a primary component.

### **Step 5 \- Join nodes 2 and 3 (one at a time)**

sudo systemctl start mysql  
Each node connects to node 1, receives an SST, and syncs. Check from any node: wsrep\_cluster\_size must reach 3.

### **Step 6 \- Hand node 1 over to systemd (important)**

Once all 3 nodes are up, stop the manually started process cleanly and start the managed service:

sudo mysqladmin \-u root \-p shutdown  
sudo systemctl start mysql  
pgrep \-a mysqld        \# must show exactly one mysqld  
**Lesson learned:** the manual bootstrap process was left running for a day. systemd then failed to start its own copy (Address already in use), and systemctl stop mysql did not stop the real server, which made a failover test look broken. Always confirm there is a single mysqld.

### **Step 7 \- Verify**

SHOW STATUS LIKE 'wsrep\_cluster\_size';          \-- 3  
SHOW STATUS LIKE 'wsrep\_cluster\_status';        \-- Primary  
SHOW STATUS LIKE 'wsrep\_ready';                 \-- ON  
SHOW STATUS LIKE 'wsrep\_local\_state\_comment';   \-- Synced

## **3\. Phase 2 \- ProxySQL (server 4\)**

### **Step 1 \- Install**

wget \-O \- 'https\://repo.proxysql.com/ProxySQL/repo\_pub\_key' | sudo apt-key add \-  
echo "deb https\://repo.proxysql.com/ProxySQL/proxysql-2.6.x/\$(lsb\_release \-sc)/ ./" | sudo tee /etc/apt/sources.list.d/proxysql.list  
sudo apt update  
sudo apt install \-y proxysql mysql-client-core-8.0  
sudo systemctl enable \--now proxysql  
The MySQL client package is needed to talk to ProxySQL's admin interface.

### **Step 2 \- Change the admin password** 

mysql \-u admin \-padmin \-h 127.0.0.1 \-P 6032

UPDATE global\_variables SET variable\_value='admin:\<ADMIN\_PASSWORD\>' WHERE variable\_name='admin-admin\_credentials';  
LOAD ADMIN VARIABLES TO RUNTIME;  
SAVE ADMIN VARIABLES TO DISK;  
The default admin/admin is publicly known.

### **Step 3 \- Monitoring user (run on a Galera node; it replicates to all nodes)**

CREATE USER 'proxysql\_monitor'@'134.209.243.73' IDENTIFIED BY '\<MONITOR\_PASSWORD\>';  
GRANT USAGE, REPLICATION CLIENT ON \*.\* TO 'proxysql\_monitor'@'134.209.243.73';  
FLUSH PRIVILEGES;  
A minimal-privilege account ProxySQL uses to check each node.

### **Step 4 \- Register nodes, monitor credentials, Galera hostgroups (ProxySQL admin)**

INSERT INTO mysql\_servers (hostgroup\_id, hostname, port) VALUES (10, '134.122.66.240', 3306);  
INSERT INTO mysql\_servers (hostgroup\_id, hostname, port) VALUES (10, '164.92.241.139', 3306);  
INSERT INTO mysql\_servers (hostgroup\_id, hostname, port) VALUES (10, '167.99.143.219', 3306);

SET mysql-monitor\_username='proxysql\_monitor';  
SET mysql-monitor\_password='\<MONITOR\_PASSWORD\>';

INSERT INTO mysql\_galera\_hostgroups (writer\_hostgroup, backup\_writer\_hostgroup, reader\_hostgroup, offline\_hostgroup, active, max\_writers, writer\_is\_also\_reader, max\_transactions\_behind) VALUES (10, 20, 30, 40, 1, 3, 1, 100);

LOAD MYSQL SERVERS TO RUNTIME;  SAVE MYSQL SERVERS TO DISK;  
LOAD MYSQL VARIABLES TO RUNTIME; SAVE MYSQL VARIABLES TO DISK;  
Every ProxySQL change must be loaded to RUNTIME (takes effect now) and saved to DISK (survives restart). With the Galera hostgroup definition, ProxySQL reads wsrep\_\* status and only uses nodes that are Primary and Synced. Hostgroups: 10 writer, 20 backup writer, 30 readers, 40 offline.

### **Step 5- Application user**

On a Galera node:

CREATE USER 'appuser'@'134.209.243.73' IDENTIFIED BY '\<APP\_PASSWORD\>';  
GRANT ALL PRIVILEGES ON \<database\>.\* TO 'appuser'@'134.209.243.73';  
FLUSH PRIVILEGES;  
In ProxySQL admin:

INSERT INTO mysql\_users (username, password, default\_hostgroup, active) VALUES ('appuser', '\<APP\_PASSWORD\>', 10, 1);  
LOAD MYSQL USERS TO RUNTIME; SAVE MYSQL USERS TO DISK;  
ProxySQL authenticates the application itself, so each user must exist in both places with the same password. Applications must connect with host 127.0.0.1 and port 6033 when on the ProxySQL server (a TCP connection; localhost would try a local socket).

### **Step 6 \- Test**

mysql \-u appuser \-p \-h 134.209.243.73 \-P 6033 \-e 'SELECT @@hostname;'  
The hostname returned is the real node that served the query, proving traffic goes app, then ProxySQL, then Galera.

## **4\. Phase 3 \- Query rules (read/write split)**

**Problem:** by default every query, read or write, went to hostgroup 10 (one writer), so two of three nodes were idle. **Goal:** spread reads across all nodes while keeping writes on one node (writing to several Galera nodes at once risks certification conflicts).

INSERT INTO mysql\_query\_rules (rule\_id, active, match\_pattern, destination\_hostgroup, apply) VALUES (100, 1, '(?i)^SELECT.\*FOR UPDATE', 10, 1);  
INSERT INTO mysql\_query\_rules (rule\_id, active, match\_pattern, destination\_hostgroup, apply) VALUES (200, 1, '(?i)^SELECT', 30, 1);  
LOAD MYSQL QUERY RULES TO RUNTIME;  
SAVE MYSQL QUERY RULES TO DISK;

* Rules are evaluated in ascending rule\_id; apply=1 means stop at the first match.  
* Rule 100 keeps SELECT ... FOR UPDATE on the writer, because it takes a row lock in preparation for a write and the lock only protects data on the writer node. It must come before rule 200\.  
* Rule 200 sends every other SELECT to hostgroup 30 (all synced nodes).  
* Writes match no rule and fall back to the user's default\_hostgroup (10).  
* (?i) makes the pattern case-insensitive. Without it only uppercase SELECT matched, but applications such as Cachet send lowercase SQL.

**Verify:**

SELECT hostgroup, digest\_text, count\_star FROM stats\_mysql\_query\_digest WHERE hostgroup=30 ORDER BY count\_star DESC LIMIT 10;  
Use runtime\_mysql\_servers (not mysql\_servers) to see live node status; mysql\_servers only shows configuration.

**Known trade-off (read-your-writes):** a read issued right after a write may reach a node that has not applied that write yet (milliseconds on a healthy cluster). Acceptable for a status page; if an application needs strict consistency, add a rule that pins such queries to hostgroup 10, or use wsrep\_sync\_wait.

## **5\. Phase 4 \- Backups with Percona XtraBackup (node 1\)**

**What it is:** XtraBackup copies InnoDB data files from a running database without stopping it (a hot, physical backup). It is used only for backups; the cluster's SST method remains rsync. The version must match the server (8.4 for MySQL 8.4.6).

### **Step 1 \- Install**

curl \-O https\://repo.percona.com/apt/percona-release\_latest.generic\_all.deb  
sudo apt install \-y gnupg2 lsb-release ./percona-release\_latest.generic\_all.deb  
sudo percona-release enable-only tools release  
sudo apt update  
sudo apt install \-y percona-xtrabackup-84  
xtrabackup \--version

### **Step 2 \- Dedicated backup user**

CREATE USER 'xtrabackup'@'localhost' IDENTIFIED BY '\<BACKUP\_PASSWORD\>';  
GRANT RELOAD, PROCESS, LOCK TABLES, REPLICATION CLIENT ON \*.\* TO 'xtrabackup'@'localhost';  
GRANT BACKUP\_ADMIN ON \*.\* TO 'xtrabackup'@'localhost';  
GRANT SELECT ON performance\_schema.replication\_group\_members TO 'xtrabackup'@'localhost';  
GRANT SELECT ON performance\_schema.keyring\_component\_status TO 'xtrabackup'@'localhost';  
GRANT SELECT ON performance\_schema.log\_status TO 'xtrabackup'@'localhost';  
The three Performance Schema grants were added after the first attempt failed with SELECT command denied. This is a minimal set; no SUPER and no global SELECT.

### **Step 3 \- Credentials file and backup directory**

sudo mkdir \-p /backup/mysql && sudo chmod 700 /backup/mysql  
sudo tee /root/.my.cnf \<\< 'EOF'  
\[client\]  
user=xtrabackup  
password=\<BACKUP\_PASSWORD\>  
EOF  
sudo chmod 600 /root/.my.cnf  
mysql \-e 'SELECT USER(), CURRENT\_USER();'  
The credentials file keeps the password off the command line (where ps would show it) and readable by root only.

### **Step 4 \- First manual backup and prepare**

mkdir \-p /backup/mysql/full-\$(date \+%F) && chmod 700 /backup/mysql/full-\$(date \+%F)  
xtrabackup \--backup \--galera-info \--target-dir=/backup/mysql/full-\$(date \+%F)  
xtrabackup \--prepare \--target-dir=/backup/mysql/full-\$(date \+%F)

* \--backup copies the data files while the database keeps changing, so the copy is not yet internally consistent. It also saves the redo log.  
* \--prepare replays that redo log so every file reflects one consistent moment; only then is the backup restorable.  
* \--galera-info writes xtrabackup\_galera\_info (cluster UUID and last committed sequence number), so the backup can later seed a node inside the cluster.  
* Preparing immediately is right for full-only backups. If incremental backups are ever added, the full backup must stay unprepared (or use \--apply-log-only).  
* Backing up one node is enough because all nodes hold identical data.

### 

## **6\. Troubleshooting log**

| Symptom | Cause | Fix |
| :---- | :---- | :---- |
| mysqld aborts, dlopen ... libgalera\_smm.so: No such file | Wrong wsrep\_provider path | Use the real path found with find: /usr/lib/galera/libgalera\_smm.so |
| Joiner fails, posix\_spawnp wsrep\_sst\_rsync ... Permission denied; donor log says Command did not run | AppArmor profile blocks the rsync SST helper on donor and joiner | aa-complain /usr/sbin/mysqld on all nodes |
| Misleading State transfer ... failed: Remote address changed | Generic message when the SST script cannot run | Read the **donor** node's error log for the real cause |
| systemd start fails, Address already in use (4567) | Manually bootstrapped mysqld still running | Stop it with mysqladmin shutdown, confirm with pgrep \-a mysqld, start via systemd |
| ProxySQL shows node ONLINE although stopped | Querying mysql\_servers (configuration) | Query runtime\_mysql\_servers |
| Only uppercase queries were split | Case-sensitive regex | Add (?i) to rule patterns |
| CREATE USER returns a ProxySQL admin syntax error | Running real MySQL commands in the ProxySQL admin prompt | Run them on a Galera node; ProxySQL admin only manages its own tables |
| XtraBackup: SELECT command denied on performance\_schema tables | Missing grants | Add the three SELECT grants |

## **7\. Quick reference**

\# Cluster health (any Galera node)  
mysql \-u root \-p \-e "SHOW STATUS LIKE 'wsrep\_%';" | grep \-E 'cluster\_size|cluster\_status|local\_state\_comment|ready'

\# Live backend status (ProxySQL server)  
mysql \-u admin \-p \-h 127.0.0.1 \-P 6032 \-e 'SELECT hostgroup\_id, hostname, status FROM runtime\_mysql\_servers;'

\# End-to-end test  
mysql \-u appuser \-p \-h 134.209.243.73 \-P 6033 \-e 'SELECT @@hostname, NOW();'

\# Backup status  
tail \-n 20 /var/log/mysql-xtrabackup.log; ls \-lh /backup/mysql/