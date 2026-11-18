# Tesla Energy Foundation — Business Mailer

High-throughput dual-gateway bulk mailer (6,000 emails/day) built in pure PHP.
Sends to **any domain** — Gmail, Yahoo, Outlook, Hotmail, .com, .org, .net — with full **To, CC, BCC** support.

---

## Architecture

| Gateway | Daily Capacity | Protocol |
|---|---|---|
| Resend Paid API | ~4,000 / day | HTTPS (cURL) |
| Google Workspace SMTP | ~2,000 / day | ssl://smtp.gmail.com:465 |
| **Combined** | **~6,000 / day** | Auto-balanced + failover |

**Routing modes:** Auto-Balance (60/40 split) · Resend Only · Google Only · Failover

---

## Deploy on Railway

### 1. Create a Railway project

Go to [railway.app](https://railway.app) → **New Project** → **Deploy from GitHub repo** → select `teslaenergy-mailer`.

### 2. Add a MySQL database (optional but recommended)

In your Railway project → **+ Add Service** → **Database** → **MySQL**.
Railway auto-injects `MYSQLHOST`, `MYSQLDATABASE`, `MYSQLUSER`, `MYSQLPASSWORD`, `MYSQLPORT`.
If you skip this, the app falls back to SQLite (requires a persistent `/data` volume).

### 3. Add a persistent volume (for SQLite fallback)

In your Railway service → **Settings** → **Volumes** → Mount path: `/data`.

### 4. Set environment variables

In your Railway service → **Variables** → add all of these:

```
RESEND_API_KEY=re_xxxxxxxxxxxxxxxxxxxxxxxxxxxx
SMTP_USER=grants@teslaenergyfoundation.com
SMTP_PASS=xxxx xxxx xxxx xxxx
ADMIN_EMAIL=admin@teslaenergyfoundation.com
ADMIN_PASSWORD=YourStrongPasswordHere
SENDING_DOMAIN=teslaenergyfoundation.com
DEFAULT_REPLY_TO=support@teslafoundationreward.com
```

> `SMTP_PASS` is a **16-character Google App Password** (not your Google account password).
> Generate it at: [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)

### 5. Generate a public domain

In your Railway service → **Settings** → **Networking** → **Generate Domain**.

### 6. First login

Navigate to your Railway URL. Sign in with the `ADMIN_EMAIL` and `ADMIN_PASSWORD` you set.
Then go to **Settings & Integrations** to verify both gateways show **configured** status.

---

## Cloudflare DNS Records

Add these to `teslaenergyfoundation.com` in Cloudflare — set all to **DNS Only (grey cloud)**:

| Type | Name | Value | Purpose |
|---|---|---|---|
| TXT | `@` | `v=spf1 include:resend.com include:_spf.google.com ~all` | SPF — authorizes both gateways |
| TXT | `_dmarc` | `v=DMARC1; p=quarantine; rua=mailto:dmarc@teslafoundationreward.com` | DMARC |
| CNAME | `resend._domainkey` | *(provided by Resend dashboard after domain verification)* | Resend DKIM |
| TXT | `google._domainkey` | *(provided by Google Workspace Admin → Apps → Gmail → Authenticate email)* | Google DKIM |

---

## Sending Features

- **To / CC / BCC** — full support in both Campaign Studio and Quick Send
- **Merge tokens** — `{{name}}`, `{{email}}`, `{{ref_id}}`, `{{grant_amount}}`, `{{date}}`
- **Reply-To** — always injected; all replies route to `support@teslafoundationreward.com`
- **Batch dispatch engine** — throttled batches with live progress terminal, pause/halt controls
- **Auto-failover** — if Resend fails mid-batch, engine auto-retries via Google SMTP (and vice versa)
- **Audit log** — every dispatch recorded with provider, message ID, CC/BCC, timestamp
- **Domain-agnostic** — delivers to Gmail, Yahoo, Outlook, Hotmail, iCloud, and all .com/.org/.net domains

---

## Getting Your Google App Password

1. Go to [myaccount.google.com/security](https://myaccount.google.com/security)
2. Enable **2-Step Verification** (required)
3. Go to [myaccount.google.com/apppasswords](https://myaccount.google.com/apppasswords)
4. Select app: **Mail** · Select device: **Other** → type `Tesla Mailer`
5. Copy the 16-character password → paste into `SMTP_PASS` on Railway

---

## Project Structure

```
teslaenergy-mailer/
├── index.php              # App shell — auth, all views, actions
├── api.php                # Batch dispatch API, live tests, stats
├── health.php             # Railway health check endpoint
├── includes/
│   ├── config.php         # Constants, env, session init
│   └── functions.php      # DB, gateways, router, schema, helpers
├── assets/
│   └── css/app.css        # Tesla Red design system
├── Dockerfile             # Railway-compatible PHP 8.2 + Apache
├── docker-entrypoint.sh   # Startup script (creates /data dir)
└── railway.json           # Railway build & deploy config
```

---

*Tesla Energy Foundation · teslaenergyfoundation.com · Inbound: teslafoundationreward.com*
