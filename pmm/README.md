===============================================================
README: Adding PMM monitoring to the Galera + ProxySQL cluster
===============================================================

1. OVERVIEW
-----------
PMM (Percona Monitoring and Management) is a free, open-source tool
that monitors MySQL/MariaDB, shows dashboards, and analyzes queries.
Here it is added to the existing cluster so all 4 servers are monitored
from one web interface.

PMM has two parts:
  - PMM Server: the web UI (Grafana-based) and the metrics database.
    It runs as a Docker container on its own server.
  - PMM Client (pmm-agent): installed on each monitored server. It
    collects metrics and sends them to the PMM Server.


2. SERVERS
----------
  Role              Hostname          IP
  ----------------  ----------------  ---------------
  Galera node 1     SURA-mysql01      134.122.66.240
  Galera node 2     SURA-mysql02      164.92.241.139
  Galera node 3     SURA-mysql03      167.99.143.219
  ProxySQL          (ProxySQL server) 134.209.243.73
  PMM Server        PMM-SURA          209.38.215.62

The Galera cluster is Codership "Galera Cluster for MySQL" 8.4, so some
panels in the Percona XtraDB Cluster dashboards may be empty.


3. REQUIREMENTS
---------------
  - PMM Server needs at least 2 GB RAM (4 GB recommended) and several
    GB of free disk. A 1 GB server runs out of memory and PMM returns
    502/504/500 errors.
  - Docker installed on the PMM server.
  - Network: the clients must reach the PMM server on TCP 443, and the
    PMM server must reach the clients on TCP 42000-42999.


4. STEP 0: MAKE SURE THE GALERA CLUSTER IS HEALTHY
--------------------------------------------------
Before adding monitoring, confirm that all 3 nodes are up and synced:

  mysql -u root -p -e "SHOW STATUS LIKE 'wsrep_cluster_size';"

It must return 3. Also check that the status is "Primary".

If all nodes are down (full cluster stop), do NOT start them all at
once. Bootstrap from the most advanced node:

  a) On each node, compare the last recovered position:
       cat /var/lib/mysql/grastate.dat
       journalctl -u mysql --no-pager | grep "Recovered position" | tail -3
     The node with the highest seqno has the newest data
     (in our case node2 with seqno 605).
  b) On that node only, start it as a new cluster:
       systemctl set-environment MYSQLD_OPTS="--wsrep-new-cluster"
       systemctl start mysql
       systemctl unset-environment MYSQLD_OPTS
     (Unsetting the option matters: if it stays, a later restart
      would create a separate cluster.)
  c) Start the other nodes one at a time and wait until each shows
     "Synced" before starting the next:
       systemctl start mysql
       tail -f /var/log/mysql/error.log
  d) Verify wsrep_cluster_size = 3, then check ProxySQL shows the
     nodes as ONLINE:
       SELECT hostgroup_id, hostname, status FROM runtime_mysql_servers;

Why: a 3-node Galera cluster needs a majority (2 nodes) to stay
Primary. If two nodes go down, the third stops serving. Maintenance
should always be done one node at a time.


5. STEP 1: INSTALL PMM SERVER (on 209.38.215.62)
------------------------------------------------
  docker volume create pmm-data
  docker run -d --restart always --name pmm-server \
    -p 443:8443 -v pmm-data:/srv \
    percona/pmm-server:3

Explanation: the volume keeps the data when the container is
recreated. Port 443 on the host maps to PMM's internal 8443.

Open https://209.38.215.62 in a browser. PMM uses a self-signed
certificate, so the browser shows "Your connection isn't private".
Click Advanced -> Proceed. Log in with admin / admin and change the
password immediately.

Check the server is ready:
  curl -k https://209.38.215.62/v1/server/readyz

If you get 502/504/500 or the UI will not load, check:
  free -h
  docker exec pmm-server supervisorctl status
  docker logs --tail 100 pmm-server
Low memory is the usual cause. Resize the server to 2 GB+ (or add swap
as a temporary workaround), then run: docker restart pmm-server


6. STEP 2: CREATE THE MONITORING USER ON MYSQL
----------------------------------------------
Run on ONE Galera node (Galera replicates it to the other two):

  CREATE USER 'pmm'@'127.0.0.1' IDENTIFIED BY '<PMM_DB_PASSWORD>'
    WITH MAX_USER_CONNECTIONS 10;
  GRANT SELECT, PROCESS, REPLICATION CLIENT, RELOAD, BACKUP_ADMIN
    ON *.* TO 'pmm'@'127.0.0.1';

Explanation: PMM reads status variables (including wsrep_*) and query
statistics. This user has only the privileges it needs. The
connection limit stops PMM from using too many connections.
This DB password is different from the PMM web admin password.


7. STEP 3: INSTALL PMM CLIENT (on all 4 servers)
------------------------------------------------
  wget https://repo.percona.com/apt/percona-release_latest.generic_all.deb
  sudo dpkg -i percona-release_latest.generic_all.deb
  sudo apt update
  sudo percona-release enable pmm3-client release
  sudo apt install -y pmm-client


8. STEP 4: REGISTER EACH CLIENT WITH THE PMM SERVER
---------------------------------------------------
Run on each server with its own IP and name. Use single quotes around
the URL and replace <PMM_ADMIN_PASSWORD> (no angle brackets) with the
real PMM web password:

  sudo pmm-admin config --server-insecure-tls --force \
    --server-url='https://admin:<PMM_ADMIN_PASSWORD>@209.38.215.62:443' \
    <THIS_SERVER_IP> generic <NAME>

  Node 1:   134.122.66.240   name: node1
  Node 2:   164.92.241.139   name: SURA-mysql02
  Node 3:   167.99.143.219   name: node3
  ProxySQL: 134.209.243.73   name: proxysql

Explanation: this links the local agent to the PMM server.
--server-insecure-tls is needed because of the self-signed certificate.
Check with: pmm-admin status

If special characters in the password cause trouble, URL-encode them
(@ = %40, # = %23) or use a simple alphanumeric password. After
finishing, clear the shell history (history -c) because the password
was typed on the command line.


9. STEP 5: ADD THE SERVICES
---------------------------
On each of the 3 Galera nodes (use the same --cluster value on all
three so the dashboards group them together):

  sudo pmm-admin add mysql --username=pmm --password='<PMM_DB_PASSWORD>' \
    --host=127.0.0.1 --port=3306 --query-source=perfschema \
    --cluster=pxc-cluster <NODE_NAME>-mysql

Make sure performance_schema=ON in my.cnf for Query Analytics.

On the ProxySQL server:

  sudo pmm-admin add proxysql --username=<PROXYSQL_ADMIN_USER> \
    --password='<PROXYSQL_ADMIN_PASSWORD>' \
    --host=127.0.0.1 --port=6032 proxysql-01

Find the ProxySQL admin credentials if forgotten:
  grep -n -A3 "admin_credentials" /etc/proxysql.cnf
  sqlite3 /var/lib/proxysql/proxysql.db "SELECT variable_name,
    variable_value FROM global_variables WHERE variable_name IN
    ('admin-admin_credentials','admin-stats_credentials');"
(The config file is only read on the first start; the live values are
in the sqlite database.)


11. VERIFY
----------
On each client:
  pmm-admin status
  pmm-admin list

In the web UI (https://209.38.215.62):
  - Dashboards -> MySQL -> PXC/Galera Cluster Summary
  - ProxySQL Instance Summary
  - Query Analytics

Things to watch on the Galera dashboard: cluster size (should be 3),
node state (Synced), flow control, replication queues, and
certification conflicts.


12. TROUBLESHOOTING
-------------------
  Problem                               Cause / fix
  ------------------------------------  --------------------------------
  "-bash: PASS: No such file"            <PASS> was a placeholder. Replace
                                        it, with the angle brackets.
  Browser: ERR_CERT_AUTHORITY_INVALID   Normal (self-signed cert).
                                        Click Advanced -> Proceed.
  502 / 504 / 500 on PMM or register    PMM server is out of memory (needs
                                        2 GB+). Resize or add swap, then
                                        docker restart pmm-server.
  Cannot connect to MySQL socket        mysqld is down. Check
                                        journalctl -u mysql and the error
                                        log. See Step 0.
  "could not be resolved" warning       Harmless reverse-DNS lookup
  in the MySQL error log                warning for ProxySQL's IP.
  Empty panels in PXC dashboards        The cluster is Codership Galera,
                                        not Percona XtraDB Cluster.


13. SECURITY NOTES
------------------
  - Change the default PMM admin password (admin/admin) right away.
  - Do not store real passwords in this file.
  - Restrict the PMM server's port 443 to trusted IPs only.
  - Clear shell history after typing passwords in commands.
  
  
  14. SLOW QUERY LOG TRACKING (extension to the task)
--------------------------------------------------
Goal: enable the MySQL slow query log on the Galera nodes and have PMM
track it in Query Analytics.

NOTE: this replaces the "--query-source=perfschema" option used in
section 9. The final setup uses "--query-source=slowlog".


14.1 PERFORMANCE SCHEMA vs SLOW QUERY LOG
-----------------------------------------
PMM can collect query data from one of two sources per MySQL service:

  Performance Schema
    - Statistics kept in memory by MySQL, aggregated per query pattern.
    - Covers all queries, fast and slow.
    - Needs no extra files or disk space and no file permissions, so it
      is the easiest option to set up.
    - Shows totals and averages per query pattern, mostly with
      placeholders instead of the real values.

  Slow query log
    - A text file where MySQL writes every query slower than
      long_query_time.
    - Covers only slow queries (1 second in our setup).
    - Shows each execution with real values, lock time, rows sent and
      examined, temp tables, etc.
    - Needs the log enabled, a file path, read permission for pmm-agent,
      and disk space (PMM rotates the file).

Why the slow log was chosen: the task was to track slow queries, and the
slow log shows exactly which queries are slow and how, with real values
that can be reproduced and optimized.

Trade-off: a service uses only ONE query source. With the slow log at
1 second, a query that takes 50 ms but runs millions of times will not
appear. To catch those, lower long_query_time (more disk writes) or use
Performance Schema.


14.2 ENABLE THE SLOW LOG ON EACH GALERA NODE
--------------------------------------------
These settings are NOT replicated by Galera, so repeat on all 3 nodes.

Create the log directory:

  mkdir -p /var/log/mysql
  chown mysql:adm /var/log/mysql
  chmod 750 /var/log/mysql

Enable it at runtime (no restart needed):

  SET PERSIST slow_query_log_file = '/var/log/mysql/mysql-slow.log';
  SET PERSIST long_query_time = 1;
  SET PERSIST log_slow_extra = ON;
  SET PERSIST slow_query_log = ON;

Explanation:
  - long_query_time = 1 logs queries slower than 1 second. Very low
    values (or 0) write a lot to disk.
  - log_slow_extra = ON adds extra fields that PMM uses.
  - SET PERSIST saves the values so they survive a restart.

Verify:

  SHOW VARIABLES LIKE 'slow_query%';
  SHOW VARIABLES LIKE 'long_query_time';
  SELECT SLEEP(2);
  tail /var/log/mysql/mysql-slow.log


14.3 LET PMM READ THE FILE
--------------------------
Find the user the agent runs as and check the file permissions:

  ps -o user= -C pmm-agent
  ls -l /var/log/mysql/

If the agent is not root, give it read access, for example:

  usermod -aG adm pmm-agent
  systemctl restart pmm-agent

(Use the group shown by ls -l if it is not "adm".)


14.4 SWITCH THE PMM SERVICE TO THE SLOW LOG
-------------------------------------------
The step that makes PMM read the slow log file is this flag:

  --query-source=slowlog

On each Galera node:

  sudo pmm-admin add mysql --username=pmm \
    --password='<PMM_DB_PASSWORD>' \
    --host=127.0.0.1 --port=3306 --query-source=slowlog \
    --size-slow-logs=1GB \
    --cluster=pxc-cluster <NODE_NAME>

Explanation:
  - --size-slow-logs sets the size at which PMM rotates the log, so it
    does not fill the disk. The rotation runs FLUSH SLOW LOGS, which is
    covered by the RELOAD privilege already granted to the pmm user.
  - Keep the same --cluster value on all nodes so the Galera dashboards
    group them together.

If the service was first added with perfschema, REMOVE the old one
before adding the new one. Otherwise MySQL is monitored twice (two
services and two mysqld_exporter agents), which duplicates metrics:

  sudo pmm-admin list
  sudo pmm-admin remove mysql <OLD_SERVICE_NAME>

Final check on each node with "pmm-admin list": there must be only ONE
MySQL service per node, with a mysql_slowlog_agent attached and no
mysql_perfschema_agent.


14.5 SERVICE NAME vs NODE NAME
------------------------------
PMM has two different names:

  Service name: shown by "pmm-admin list" and in the MySQL dashboards.
                Set with: pmm-admin add mysql ... <SERVICE_NAME>
  Node name:    shown in Inventory -> Nodes and in the OS/Node
                dashboards. Set with:
                pmm-admin config ... generic <NODE_NAME>

PMM cannot rename a node in place. To change a node name, re-register
it (its graphs start fresh; old history stays under the old name):

  sudo pmm-admin remove mysql <SERVICE_NAME>
  sudo pmm-admin unregister --force
  sudo pmm-admin config --server-insecure-tls --force \
    --server-url='https://admin:<PMM_ADMIN_PASSWORD>@209.38.215.62:443' \
    <THIS_SERVER_IP> generic <NEW_NODE_NAME>
  sudo pmm-admin add mysql --username=pmm \
    --password='<PMM_DB_PASSWORD>' --host=127.0.0.1 --port=3306 \
    --query-source=slowlog --size-slow-logs=1GB \
    --cluster=pxc-cluster <SERVICE_NAME>

If "unregister" fails, delete the old node in the web UI under
Inventory -> Nodes (use the force option).


14.6 VERIFY
-----------
On each Galera node:

  pmm-admin list      (one MySQL service, mysql_slowlog_agent Running)
  pmm-admin status    (pmm-agent Connected)

In the web UI (https://209.38.215.62):
  - Inventory -> Nodes / Services: all servers and services listed,
    no duplicates, cluster label "pxc-cluster" on each MySQL service.
  - Query Analytics: run SELECT SLEEP(2); on a node and check it
    appears for that node within a minute or two.


14.7 ADDITIONAL TROUBLESHOOTING
-------------------------------
  Problem                               Cause / fix
  ------------------------------------  --------------------------------
  Same MySQL server appears twice       Old perfschema service was not
  in PMM / duplicate metrics            removed. Remove it with
                                        pmm-admin remove mysql <name>.
  Test query is in the slow log file    pmm-agent cannot read the file
  but not in Query Analytics            (check permissions, section
                                        14.3) or the service is not on
                                        slowlog (pmm-admin list).
  Query is not in the slow log at all   It is faster than long_query_time,
                                        or slow_query_log is OFF on that
                                        node (the setting is per node).
  Node name differs between             Service name and node name are
  pmm-admin list and the web UI         different things (section 14.5).
  Slow log file grows too large         Check --size-slow-logs and do not
                                        also rotate the same file with
                                        logrotate.
                                        
                                        
                                        
                                        
                                        
15. PROXYSQL QUERY LOGGING (extension to the task)
-------------------------------------------------
Goal: record the queries that pass through ProxySQL in a log file, so
traffic can be audited and troubleshooted from one place.


15.1 WHAT IT IS AND WHY WE USE IT
---------------------------------
ProxySQL sits between the applications and the 3 Galera nodes, so every
query passes through it. Its query logging (the "events log") writes a
record of each logged query to a file on the ProxySQL server. Each
record contains:
  - the query text and its digest (generic pattern ID)
  - the client address, MySQL user and schema
  - the hostgroup and backend node that ran it
  - the execution time and the rows sent or affected

Why it is useful:
  - One place for the whole cluster. The MySQL slow log exists per node
    and only shows queries slower than long_query_time.
  - Shows which client and user sent a query. On the nodes, every
    query appears to come from ProxySQL's IP.
  - Confirms read/write splitting: it shows which hostgroup and node
    ran each query (10 = writer, 30 = readers).
  - Can include fast queries too, for auditing and troubleshooting.

Cost: disk space and some CPU. The ProxySQL server is a small
1 vCPU / 1 GB machine that also hosts Cachet, so we log selectively
(by rule) and not every query.

Note: PMM's ProxySQL service collects ProxySQL metrics. It does not
read this log file, and Query Analytics does not show it. The log is
read directly on the ProxySQL server.


15.2 SERVER
-----------
  ProxySQL server: 134.209.243.73
  Admin interface: port 6032 (configuration)
  Application port: port 6033 (client traffic, the one that is logged)
  Log directory:   /var/lib/proxysql/
  Users:           cachet (Cachet application), appuser (test user)
  Hostgroups:      10 = writer, 30 = readers


15.3 STEP 1: CONNECT TO THE ADMIN INTERFACE
-------------------------------------------
  proxysql --version
  mysql -u admin -p -h 127.0.0.1 -P 6032

Why: all ProxySQL configuration is done through the admin interface
(port 6032), not through MySQL. The events log exists in ProxySQL 2.x,
so check the version first.


15.4 STEP 2: SET THE LOG FILE, FORMAT AND SIZE
----------------------------------------------
  SET mysql-eventslog_filename='queries.log';
  SET mysql-eventslog_format=2;
  SET mysql-eventslog_filesize=104857600;

Why each one:
  - eventslog_filename: the file is created in the ProxySQL data
    directory (/var/lib/proxysql/). ProxySQL adds a sequence number,
    so the real files are named queries.log.00000001, and so on.
  - eventslog_format=2: JSON format, readable with normal tools. The
    default is binary and needs a special reader.
  - eventslog_filesize: when a file reaches this size (100 MB),
    ProxySQL starts a new one.

We keep mysql-eventslog_default_log=0, so nothing is logged unless a
query rule asks for it (Step 4).


15.5 STEP 3: CHECK THE EXISTING QUERY RULES
-------------------------------------------
  SELECT rule_id, active, username, match_digest,
         destination_hostgroup, apply FROM mysql_query_rules;

Why: the read/write split works through query rules, which are
evaluated in order of rule_id. Existing rules:
  - rule 100: sends writes to hostgroup 10
  - rule 200: sends reads to hostgroup 30
The new logging rules must have a LOWER rule_id so they run first.


15.6 STEP 4: ADD THE LOGGING RULES
----------------------------------
Rule 1: log everything from the test user.

  INSERT INTO mysql_query_rules
    (rule_id, active, username, match_digest, log, apply)
  VALUES (1, 1, 'appuser', '.', 1, 0);

Rule 2: log only the write queries from Cachet.

  INSERT INTO mysql_query_rules
    (rule_id, active, username, match_digest, log, apply)
  VALUES (2, 1, 'cachet', '^(INSERT|UPDATE|DELETE)', 1, 0);

Why:
  - log=1 means "log the queries that match this rule".
  - apply=0 means "keep evaluating the next rules". Without it, the
    rule would stop the evaluation and the routing rules (100, 200)
    would never run.
  - Cachet is very chatty (many small repeated SELECT and SET
    queries), so logging all of its queries would fill the log fast.
    Logging only its writes keeps the file small.
  - To log all queries of a user, use '.' as match_digest. To log
    everything from every user, set mysql-eventslog_default_log=1
    (heavy, not recommended on this server).


15.7 STEP 5: ACTIVATE AND SAVE
------------------------------
  LOAD MYSQL VARIABLES TO RUNTIME;
  SAVE MYSQL VARIABLES TO DISK;
  LOAD MYSQL QUERY RULES TO RUNTIME;
  SAVE MYSQL QUERY RULES TO DISK;

Why: ProxySQL has three layers (memory, runtime, disk). SET and INSERT
only change memory. LOAD ... TO RUNTIME makes the change take effect
now. SAVE ... TO DISK makes it survive a restart. Skipping a step is
the most common reason a ProxySQL change seems to do nothing.

Note: LOAD MYSQL QUERY RULES TO RUNTIME resets the rule hit counters
in stats_mysql_query_rules.


15.8 STEP 6: TEST IT
--------------------
Test rule 1 (read through port 6033 with the test user):

  mysql -u appuser -p -h 127.0.0.1 -P 6033 -e "SELECT NOW();"
  ls -l /var/lib/proxysql/ | grep queries
  tail -n 1 /var/lib/proxysql/queries.log.00000001 | jq .

Test rule 2 (a harmless write that changes no data, with Cachet's
database user):

  mysql -u cachet -p -h 127.0.0.1 -P 6033 cachet \
    -e "UPDATE settings SET value = value WHERE name = 'app_name';"
  grep '"username":"cachet"' /var/lib/proxysql/queries.log.* | tail -n 3 | jq .

Why: connect on port 6033. Queries sent to the admin port 6032 do not
go through the logging path.

Results seen:
  - Rule 1: SELECT NOW() was logged with hostgroup_id 30 and server
    164.92.241.139:3306 (a reader), so reads are routed to the
    readers.
  - Rule 2: the UPDATE was logged with hostgroup_id 10 (the writer),
    rows_affected 0 (expected, nothing was changed).


15.9 STEP 7: LOG CLEANUP AND DISK SPACE
---------------------------------------
ProxySQL starts new files at the size limit but never deletes old
ones. A cron job removes files older than 7 days:

  echo '0 3 * * * root find /var/lib/proxysql -name "queries.log.*" -mtime +7 -delete' > /etc/cron.d/proxysql-logs

Check:
  cat /etc/cron.d/proxysql-logs
  df -h /
  du -sh /var/lib/proxysql

Why: on a 1 GB server a full disk would stop both ProxySQL and Cachet.
The log files can contain real query values (emails, IDs), so keep
them readable only by root and the proxysql user (the files are
created with mode 660, owner proxysql).

Edge case: if nothing matches the rules for more than 7 days, the file
currently in use could also be deleted. If the log stops growing while
queries should be matching, run in the admin interface:

  PROXYSQL FLUSH LOGS;

Then check that the newest file has a recent timestamp:
  ls -lt /var/lib/proxysql/ | grep queries


15.10 VERIFY
------------
Settings are active and saved (both lists must show the same values):

  SELECT variable_name, variable_value FROM runtime_global_variables
    WHERE variable_name LIKE 'mysql-eventslog%';
  SELECT variable_name, variable_value FROM disk.global_variables
    WHERE variable_name LIKE 'mysql-eventslog%';

Expected: filename queries.log, format 2, filesize 104857600,
default_log 0.

Rules are active and saved:

  SELECT rule_id, active, username, match_digest, log, apply
    FROM runtime_mysql_query_rules;
  SELECT rule_id, active, username, match_digest, log, apply
    FROM disk.mysql_query_rules;

Expected: rule 1 (appuser, log=1, apply=0), rule 2 (cachet,
^(INSERT|UPDATE|DELETE), log=1, apply=0), rules 100 and 200 unchanged.

Rule hit counters:

  SELECT rule_id, hits FROM stats_mysql_query_rules;


15.11 READING THE LOG
---------------------
Requires jq (apt install -y jq).

  # last entries in readable form
  tail -n 5 /var/lib/proxysql/queries.log.00000001 | jq .

  # slowest logged queries (duration in microseconds)
  jq -r '[.duration_us, .username, .hostgroup_id, .query] | @tsv' \
    /var/lib/proxysql/queries.log.* | sort -rn | head

  # number of logged queries per user
  jq -r '.username' /var/lib/proxysql/queries.log.* | sort | uniq -c | sort -rn

Main fields:
  client                 address of the connection that sent the query
  username / schemaname  MySQL user and database used
  hostgroup_id / server  where it ran (10 = writer, 30 = readers) and
                         the exact node
  duration_us            execution time in microseconds
  rows_sent /            rows returned by a read, or changed by a write
  rows_affected
  digest                 pattern ID, the same as in
                         stats_mysql_query_digest


15.12 LIGHTWEIGHT ALTERNATIVE: BUILT-IN DIGEST STATS
----------------------------------------------------
To find the most expensive query patterns without any log file:

  SELECT hostgroup, username, schemaname, count_star, sum_time,
         digest_text
  FROM stats_mysql_query_digest
  ORDER BY sum_time DESC LIMIT 10;

These totals are kept in memory per query pattern (not per execution).
To clear the counters and start fresh:

  SELECT count(*) FROM stats_mysql_query_digest_reset;


15.13 TURN IT OFF
-----------------
  SET mysql-eventslog_default_log=0;
  UPDATE mysql_query_rules SET log=0 WHERE rule_id IN (1,2);
  LOAD MYSQL VARIABLES TO RUNTIME;
  LOAD MYSQL QUERY RULES TO RUNTIME;
  SAVE MYSQL VARIABLES TO DISK;
  SAVE MYSQL QUERY RULES TO DISK;


15.14 ADDITIONAL TROUBLESHOOTING
--------------------------------
  Problem                               Cause / fix
  ------------------------------------  --------------------------------
  "Access denied for user" on port      The user does not exist in
  6033                                  ProxySQL. Check
                                        SELECT username FROM
                                        runtime_mysql_users; and use a
                                        real user. The password must
                                        match the one in MySQL.
  No log file or nothing is logged      Step 5 (LOAD/SAVE) was skipped,
                                        the rule's username does not
                                        match the user, or the test was
                                        sent to port 6032 instead of
                                        6033.
  Rule has 0 hits                       No query matched yet, or
                                        counters were reset by LOAD
                                        MYSQL QUERY RULES TO RUNTIME.
  Logging stops after a restart         Settings or rules were not
                                        saved to disk (check
                                        disk.global_variables and
                                        disk.mysql_query_rules).
  Routing stops working after adding    The logging rule has apply=1 or
  a rule                                a higher rule_id than the
                                        routing rules. Use apply=0 and
                                        a lower rule_id.
  Log file grew too large               Logging rule too broad. Narrow
                                        the match_digest, or log fewer
                                        users.
  Log stopped growing, queries still    The active file was deleted by
  match                                 the cleanup job. Run PROXYSQL
                                        FLUSH LOGS;                                        
