-- ========================================================
-- Tesla Energy Foundation Mailer — Database Schema
-- Dual-Gateway Bulk Sending & Delivery Audit
-- ========================================================

-- 1. Administrators Table
CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    username VARCHAR(80) UNIQUE NOT NULL,
    email VARCHAR(120) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(30) DEFAULT 'admin',
    recovery_email VARCHAR(120),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 2. Bulk Campaigns Table
CREATE TABLE IF NOT EXISTS campaigns (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    title VARCHAR(150) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    sender_name VARCHAR(120),
    sender_email VARCHAR(120),
    reply_to VARCHAR(120),
    body_html TEXT NOT NULL,
    body_text TEXT,
    total_recipients INTEGER DEFAULT 0,
    sent_count INTEGER DEFAULT 0,
    failed_count INTEGER DEFAULT 0,
    status VARCHAR(30) DEFAULT 'draft',
    provider_mode VARCHAR(40) DEFAULT 'auto_balance',
    batch_size INTEGER DEFAULT 50,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 3. Campaign Recipients Table
CREATE TABLE IF NOT EXISTS recipients (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER NOT NULL,
    email VARCHAR(120) NOT NULL,
    name VARCHAR(120),
    variables_json TEXT,
    status VARCHAR(30) DEFAULT 'pending',
    provider_used VARCHAR(40),
    message_id VARCHAR(120),
    error_message TEXT,
    sent_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 4. Outgoing Email Logs Table (Complete Audit Trail)
CREATE TABLE IF NOT EXISTS email_logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    campaign_id INTEGER,
    provider VARCHAR(40) NOT NULL,
    message_id VARCHAR(150),
    from_address VARCHAR(150) NOT NULL,
    from_name VARCHAR(120),
    to_address VARCHAR(255) NOT NULL,
    reply_to VARCHAR(150),
    subject VARCHAR(255) NOT NULL,
    body_html TEXT,
    body_text TEXT,
    snippet TEXT,
    status VARCHAR(30) DEFAULT 'sent',
    error_message TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5. Email Templates Table
CREATE TABLE IF NOT EXISTS templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name VARCHAR(120) NOT NULL,
    category VARCHAR(60) DEFAULT 'General',
    subject VARCHAR(255) NOT NULL,
    body_html TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 6. System & Gateway Settings Table
CREATE TABLE IF NOT EXISTS settings (
    key_name VARCHAR(80) PRIMARY KEY,
    val TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Indexes for optimal performance
CREATE INDEX IF NOT EXISTS idx_logs_status ON email_logs(status);
CREATE INDEX IF NOT EXISTS idx_logs_provider ON email_logs(provider);
CREATE INDEX IF NOT EXISTS idx_logs_created ON email_logs(created_at);
CREATE INDEX IF NOT EXISTS idx_recipients_camp ON recipients(campaign_id, status);
