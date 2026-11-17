# Tesla Energy Foundation — Dual Gateway Bulk Mailer (6,000 / Day)

A high-performance, Railway-ready PHP bulk mailer engineered for **teslaenergyfoundation.com**, balancing dispatches across **Resend Paid API** and **Google Workspace Paid SMTP** with automated reply routing to **teslafoundationreward.com**.

---

## ⚡ Architecture Overview

1. **Sending Domain**: `teslaenergyfoundation.com` (Cloudflare-managed DNS).
2. **Inbound / Reply Destination**: `teslafoundationreward.com` (Injected automatically into `Reply-To` headers).
3. **Daily Volume Target**: **6,000 emails/day**
   * **Resend Paid API**: Handles ~4,000/day with high-speed HTTPS dispatches.
   * **Google Workspace SMTP**: Handles ~2,000/day (staying safely inside Google's 2k daily user threshold).
   * **Auto-Balance**: Intelligently alternates gateways and provides instant failover if either provider encounters temporary rate limits.

---

## 🗄️ Database Architecture (Auto-Detects Railway MySQL or SQLite)

The mailer includes a **universal database engine** that requires **zero manual SQL imports**. All tables (`users`, `campaigns`, `recipients`, `email_logs`, `templates`, `settings`) and the default admin account are initialized automatically upon first launch.

You can use either:

### Option A: Railway MySQL Database (Recommended)
1. In your Railway Project, click **+ New** &rarr; **Database** &rarr; **Add MySQL**.
2. Railway will automatically inject `MYSQLHOST`, `MYSQLPORT`, `MYSQLUSER`, `MYSQLPASSWORD`, and `MYSQLDATABASE`.
3. The mailer automatically detects these variables, connects to MySQL, and sets up all tables instantly.

### Option B: SQLite with Persistent Volume
1. If you prefer not to create a MySQL service, the app falls back seamlessly to SQLite.
2. In Railway, click your service &rarr; go to **Volumes** &rarr; click **Add Volume** &rarr; set Mount Path to `/data`.

---

## 🚀 Railway Deployment Guide

### Step 1: Deploy to Railway
1. Push this folder to GitHub or deploy via Railway CLI:
   ```bash
   railway up
   ```
2. The project contains a pre-configured `Dockerfile`, `railway.json`, and `/health.php` endpoint.

### Step 2: Configure Environment Variables (Optional)
You can set these in Railway under **Variables** (or configure them directly inside the Mailer web dashboard under **Settings**):

| Variable | Description | Example |
| :--- | :--- | :--- |
| `RESEND_API_KEY` | Your Resend API Key | `re_123456789...` |
| `SMTP_HOST` | Google Workspace SMTP Host | `smtp.gmail.com` |
| `SMTP_PORT` | Google Workspace SMTP Port | `465` (SSL) or `587` (TLS) |
| `SMTP_USER` | Google Workspace Account | `grants@teslaenergyfoundation.com` |
| `SMTP_PASS` | 16-Character Google App Password | `abcd efgh ijkl mnop` |
| `DEFAULT_REPLY_TO` | Inbound replies destination | `support@teslafoundationreward.com` |

---

## ☁️ Cloudflare DNS Records (for `teslaenergyfoundation.com`)

Because `teslaenergyfoundation.com` is configured strictly for **sending**, you do not need inbound MX records on this domain. Add these records in Cloudflare (**Proxy status set to DNS Only / Grey Cloud**):

### 1. Combined SPF Record (TXT)
* **Name / Host:** `@`
* **Content:**
  ```text
  v=spf1 include:resend.com include:_spf.google.com ~all
  ```
  *(Authorizes both Resend and Google Workspace to send on behalf of teslaenergyfoundation.com).*

### 2. DMARC Record (TXT)
* **Name / Host:** `_dmarc`
* **Content:**
  ```text
  v=DMARC1; p=quarantine; rua=mailto:dmarc@teslafoundationreward.com
  ```

### 3. Resend DKIM (CNAME)
* Generated in your Resend Dashboard &rarr; Domains &rarr; `teslaenergyfoundation.com`.
* Usually `resend._domainkey` pointing to `provided-by.resend.com`.

### 4. Google Workspace DKIM (TXT)
* Generated in your Google Workspace Admin Console (Apps &rarr; Google Workspace &rarr; Gmail &rarr; Authenticate email).
* Host: `google._domainkey`

---

## 🔐 Security & Access Control

Authentication is strictly enforced for all dispatch functions, campaigns, and logs. Initial deployment accounts can be customized or configured via environment variables (`ADMIN_EMAIL`, `ADMIN_PASSWORD`), and credentials can be rotated directly within the administrative settings panel.
