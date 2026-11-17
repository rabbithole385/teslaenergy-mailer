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

## 🚀 Railway Deployment Guide

### Step 1: Deploy to Railway
1. Push this folder to GitHub or deploy via Railway CLI:
   ```bash
   railway up
   ```
2. The project contains a pre-configured `Dockerfile`, `railway.json`, and `/health.php` endpoint.

### Step 2: Attach a Persistent Volume on Railway
1. In your Railway Project Dashboard &rarr; Click your service &rarr; Go to **Volumes**.
2. Click **Add Volume**.
3. Set the Mount Path to:
   ```
   /data
   ```
   *(This ensures your SQLite database, campaigns, recipient lists, and audit logs persist across rebuilds).*

### Step 3: Configure Railway Environment Variables (Optional)
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

## 🔐 Default Access Credentials

* **URL:** `http://localhost:8080` or `https://your-railway-app.up.railway.app`
* **Email / Username:** `admin@teslaenergyfoundation.com` or `admin`
* **Default Password:** `TeslaEnergy2026!`

*(You can update this password directly in the portal).*
