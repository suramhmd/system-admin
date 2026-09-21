# Postfix Mail Relay — Server Setup Runbook

A step-by-step guide to prepare a new server as a restricted Postfix mail relay for cPanel servers with poor sending IP reputation.

**Placeholders used below — replace before running:**
- `<RELAY_PUBLIC_IP>` — public IP of this new relay server
- `<RELAY_FQDN>` — real domain/hostname assigned to this relay (e.g. `relay.company.com`)
- `<CPANEL_SERVER_IP>` — IP address(es) of the cPanel server(s) allowed to use this relay

---

## 1. Install Postfix

```bash
sudo apt update
sudo apt install postfix -y
```

During the interactive setup screen:
- **General type of mail configuration:** select **"Internet Site"**
- **System mail name:** enter `<RELAY_FQDN>` if already known; otherwise leave to default.

Verify installation:

```bash
sudo postfix status
ps aux | grep master
```

Expected: `the Postfix mail system is running: PID: <pid>` and a running `/usr/lib/postfix/sbin/master` process.

---

## 2. Configure Postfix (`/etc/postfix/main.cf`)

Apply each setting individually with `postconf -e`, verifying after each change.

### 2.1 Set the relay's hostname

```bash
sudo postconf -e 'myhostname = <RELAY_FQDN>'
sudo postconf myhostname mydomain
```

### 2.2 Restrict relay access to allowed cPanel IP(s) only

Check the current value first, then append the allowed IP(s) with an explicit `/32` host mask (never use a broad range or `0.0.0.0/0`):

```bash
sudo postconf mynetworks
sudo postconf -e 'mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128 <CPANEL_SERVER_IP>/32'
sudo postconf mynetworks
```

For multiple cPanel servers, add each as its own `/32` entry, space-separated:
```
mynetworks = 127.0.0.0/8 [::ffff:127.0.0.0]/104 [::1]/128 <CPANEL_IP_1>/32 <CPANEL_IP_2>/32
```

### 2.3 Enforce strict relay restrictions

```bash
sudo postconf -e 'smtpd_relay_restrictions = permit_mynetworks reject_unauth_destination'
sudo postconf smtpd_relay_restrictions
```

This permits only clients in `mynetworks` to relay, and **permanently rejects** (not defers) any relay attempt to a destination outside that trust — the core anti-open-relay control.

### 2.4 Settings intentionally left at package defaults

- `mydestination` - not changed; this server does not accept final delivery for any company domain.
- `relay_domains` - not used; this design restricts by *source* IP (`mynetworks`), not by destination domain.
- `inet_interfaces = all` - required as-is, so the relay accepts connections on its public IP.

---

## 3. Validate and apply the configuration

Always check syntax before reloading:

```bash
sudo postfix check
```

Expected: no output = no errors. Then apply:

```bash
sudo systemctl reload postfix
sudo systemctl status postfix
```

Review the full effective (non-default) configuration:

```bash
sudo postconf -n
```

---

## 4. Verify the relay is listening correctly

```bash
sudo ss -tlnp | grep :25
```

Expected: entries for both `0.0.0.0:25` and `[::]:25` owned by the `master` process.

---

## 5. Verify open-relay protection (critical — do not skip)

**Test A — from an allowed source** (run locally on the relay):

```bash
telnet localhost 25
```
```
EHLO test.local
MAIL FROM:<test@example.com>
RCPT TO:<someone@gmail.com>
```
Expected: `250 2.1.5 Ok` (accepted, since localhost is trusted).

**Test B — from an unauthorized external source** (run from a different machine — NOT the relay, NOT the allowed cPanel IP; a free cloud shell such as `shell.cloud.google.com` works well, since home ISPs often block outbound port 25):

```bash
telnet <RELAY_PUBLIC_IP> 25
```
Same EHLO/MAIL FROM/RCPT TO sequence as above.

Expected: **`554 5.7.1 Relay access denied`**. If you instead get `250 Ok`, this is an open relay — stop and fix `mynetworks`/`smtpd_relay_restrictions` immediately before proceeding.


---

## 6. DNS requirements for production deliverability

Postfix-level configuration alone will not make mail deliverable to major providers (Gmail, Outlook, etc.). Before production use, configure on the web dashboard cPanel:

| Record | Purpose |
|---|---|
| **A** | `<RELAY_FQDN>` → `<RELAY_PUBLIC_IP>` |
| **PTR (reverse DNS)** | `<RELAY_PUBLIC_IP>` → `<RELAY_FQDN>` — set via the hosting provider's control panel |
| **SPF (TXT)** | On each sending domain, add `<RELAY_PUBLIC_IP>` as an authorized sender |
| **DKIM (TXT)** | Recommended — sign outgoing mail so it passes DKIM at the recipient |
| **DMARC (TXT)** | Recommended — under `_dmarc.<domain>`, defines policy if SPF/DKIM fail |

Without these, expect deliveries to be accepted by Postfix but **bounced by the recipient** (e.g. Gmail's `550 5.7.26 ... unauthenticated`), even though the relay itself is configured correctly.

### 6.1 SPF setup

This must be repeated **for every sending domain** hosted on the cPanel servers that will send mail through this relay (not for the relay's own domain alone).

**Step 1 — check if the domain already has an SPF record**

```bash
dig TXT <SENDING_DOMAIN> +short
```

Look for a line starting with `v=spf1`. Domains often already have one (commonly authorizing the cPanel server itself, e.g. `include:cpanel-host...` or `a` / `mx`) — if so, you will **extend it**, not replace it.

**Step 2 — build the new SPF value**

- If no SPF record exists yet, create one:
  ```
  v=spf1 ip4:<RELAY_PUBLIC_IP> ~all
  ```
- If an SPF record already exists, add the relay's IP into the existing one rather than creating a second record (a domain must have only **one** SPF TXT record):
  ```
  v=spf1 ip4:<OLD_IP_IF_KEPT> ip4:<RELAY_PUBLIC_IP> include:<any_existing_include> ~all
  ```

Notes on the syntax:
- `ip4:<RELAY_PUBLIC_IP>` — authorizes this exact IP to send as the domain.
- `~all` (soft fail) — recommended while testing; anything not listed is flagged but usually still delivered/marked suspicious. Switch to `-all` (hard fail) once confident, for a stricter policy that tells receivers to reject unlisted senders outright.
- Only **one** SPF record per domain is allowed.

**Step 3 — publish the record**

Add it as a **TXT** record at the domain's DNS provider:

- **Host/Name:** `@` (root domain) — or the specific subdomain if the sending address uses one
- **Type:** `TXT`
- **Value:** the SPF string built in Step 2

**Step 4 — verify propagation and correctness**

```bash
dig TXT <SENDING_DOMAIN> +short
```

Confirm the new value appears (DNS propagation can take minutes to a few hours). Optionally validate with an online SPF checker (e.g. mxtoolbox.com's SPF lookup) to catch syntax errors before relying on it.

**Step 5 — confirm it works with a real test**

Send a real test email through the relay to a Gmail address, then check the authentication results in the received message (Gmail: open the email -> "Show original"). Look for:
```
SPF: PASS with IP <RELAY_PUBLIC_IP>
```

---

## 7. End-to-end test

```bash
echo -e "Subject: Relay test\n\nTest message." | sendmail -f test@<RELAY_FQDN> your-real-test-address@example.com
```

Then check delivery status in the logs (step 10).

---

## 8. Check logs

```bash
sudo tail -n 50 /var/log/mail.log
```

Look for:
- `status=sent` — delivered successfully
- `status=bounced` — permanently rejected by the recipient (read the reason in the log line, e.g. SPF/DKIM failure)
- `status=deferred` — temporary failure, will retry
- `Relay access denied` from unexpected sources — confirms the open-relay protection is active

---

