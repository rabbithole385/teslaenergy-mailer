<?php
/**
 * Tesla Energy Foundation — Business Mail & High-Throughput Bulk Dispatcher (6k/day)
 * Architecture inspired by OakView Mail & Mailview design system
 */

require_once __DIR__ . '/includes/functions.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$pdo = db();
$error = '';
$self = 'index.php';

// ----------------------------------------------------
// 1. AUTHENTICATION HANDLING
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    if (!csrf_check()) {
        $error = 'Security session expired. Please refresh and try again.';
    } else {
        $ident = clean($_POST['identifier'] ?? '', 120);
        $pass = (string)($_POST['password'] ?? '');

        $st = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
        $st->execute([$ident, $ident]);
        $user = $st->fetch();

        if ($user && password_verify($pass, $user['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['user_data'] = [
                'id'       => $user['id'],
                'username' => $user['username'],
                'email'    => $user['email'],
                'role'     => $user['role']
            ];
            header('Location: ' . $self);
            exit;
        }
        $error = 'Invalid credentials. Please verify your email/username and password.';
    }
}

if (isset($_GET['logout'])) {
    unset($_SESSION['user_id'], $_SESSION['user_data']);
    session_regenerate_id(true);
    header('Location: ' . $self);
    exit;
}

$authed = is_authed();
$currentUser = current_user();

// ----------------------------------------------------
// 2. ACTIONS CONTROLLER (CAMPAIGNS, SETTINGS, LOGS)
// ----------------------------------------------------
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    // Action A: Create Bulk Campaign
    if ($act === 'create_campaign') {
        $title = clean($_POST['name'] ?? $_POST['title'] ?? '', 150) ?: ('Campaign ' . date('M j, H:i'));
        $subject = clean($_POST['subject'] ?? '', 255);
        $senderName = clean($_POST['sender_name'] ?? '', 120) ?: get_setting('default_from_name', DEFAULT_FROM_NAME);
        $senderEmail = clean($_POST['sender_email'] ?? '', 120) ?: get_setting('default_from_email', DEFAULT_FROM_EMAIL);
        $replyTo = clean($_POST['reply_to'] ?? '', 120) ?: get_setting('reply_to_email', DEFAULT_REPLY_TO);
        $ccAddr = clean($_POST['cc_address'] ?? '', 500);
        $bccAddr = clean($_POST['bcc_address'] ?? '', 500);
        $providerMode = clean($_POST['provider_mode'] ?? 'auto_balance', 40);
        $bodyHtml = (string)($_POST['html'] ?? $_POST['body_html'] ?? '');
        $rawRecipients = (string)($_POST['recipients'] ?? '');

        $lines = preg_split('/[\r\n]+/', trim($rawRecipients));
        $validList = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $parts = array_map('trim', preg_split('/[,;\t]/', $line));

            $email = '';
            foreach ($parts as $idx => $p) {
                if (filter_var($p, FILTER_VALIDATE_EMAIL)) {
                    $email = strtolower($p);
                    unset($parts[$idx]);
                    break;
                }
            }

            if ($email && !isset($seen[$email])) {
                $seen[$email] = true;
                $rem = array_values($parts);
                $name = $rem[0] ?? explode('@', $email)[0];
                $extra = [];
                if (isset($rem[1])) $extra['ref_id'] = $rem[1];
                if (isset($rem[2])) $extra['grant_amount'] = $rem[2];

                $validList[] = [
                    'email'     => $email,
                    'name'      => $name,
                    'variables' => $extra
                ];
            }
        }

        if (!$subject || !$bodyHtml) {
            flash('error', 'Please provide a subject line and HTML body content.');
        } elseif (empty($validList)) {
            flash('error', 'No valid recipient email addresses were found.');
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('INSERT INTO campaigns (title, subject, sender_name, sender_email, reply_to, cc_address, bcc_address, body_html, total_recipients, provider_mode, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "ready")');
                $st->execute([$title, $subject, $senderName, $senderEmail, $replyTo, $ccAddr ?: null, $bccAddr ?: null, $bodyHtml, count($validList), $providerMode]);
                $campId = (int)$pdo->lastInsertId();

                $insRecip = $pdo->prepare('INSERT INTO recipients (campaign_id, email, name, variables_json, status) VALUES (?, ?, ?, ?, "pending")');
                foreach ($validList as $v) {
                    $insRecip->execute([$campId, $v['email'], $v['name'], json_encode($v['variables'])]);
                }
                $pdo->commit();

                flash('success', "Campaign \"{$title}\" created with " . count($validList) . " recipients. Ready for dispatch!");
                header("Location: {$self}?view=campaigns&launch_id={$campId}");
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Failed to create campaign: ' . $e->getMessage());
            }
        }
    }

    // Action B: Direct Quick Dispatch
    if ($act === 'send_quick') {
        $to = clean($_POST['to'] ?? '', 120);
        $cc = clean($_POST['cc'] ?? '', 500);
        $bcc = clean($_POST['bcc'] ?? '', 500);
        $subject = clean($_POST['subject'] ?? '', 200) ?: ('Quick Test Dispatch — ' . date('H:i:s'));
        $bodyHtml = (string)($_POST['html'] ?? '');
        $provider = clean($_POST['provider'] ?? 'auto_balance', 40);

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please specify a valid recipient address.');
        } else {
            $content = $bodyHtml ?: '<div style="font-family:Arial,sans-serif;padding:24px;border:1px solid #e2e8f0;border-radius:8px;"><h2>Tesla Energy Foundation</h2><p>This is a live test dispatch from your dual-gateway mailer.</p><p><strong>Reply-To Target:</strong> ' . htmlspecialchars(get_setting('reply_to_email', DEFAULT_REPLY_TO)) . '</p></div>';
            $res = send_dual_email($to, $subject, $content, strip_tags($content), '', '', '', $provider, null, $cc, $bcc);
            if (!empty($res['ok'])) {
                flash('success', "Dispatched to {$to} via {$res['provider']}! (ID: {$res['id']})");
            } else {
                flash('error', "Delivery failed: " . ($res['error'] ?: 'Check SMTP and Resend settings.'));
            }
        }
        header("Location: {$self}?view=compose");
        exit;
    }

    // Action C: Save All Settings
    if ($act === 'save_all_settings') {
        // Identity
        set_setting('sending_domain', clean($_POST['sending_domain'] ?? '', 120) ?: SENDING_DOMAIN);
        set_setting('reply_to_email', clean($_POST['reply_to_email'] ?? '', 120) ?: DEFAULT_REPLY_TO);
        set_setting('default_from_name', clean($_POST['default_from_name'] ?? '', 120) ?: DEFAULT_FROM_NAME);
        set_setting('default_from_email', clean($_POST['default_from_email'] ?? '', 120) ?: DEFAULT_FROM_EMAIL);

        // Resend
        set_setting('resend_enabled', !empty($_POST['resend_enabled']) ? '1' : '0');
        set_setting('resend_api_key', trim((string)($_POST['resend_api_key'] ?? '')));
        set_setting('resend_daily_limit', clean($_POST['resend_daily_limit'] ?? '5000', 10));

        // Google Workspace SMTP
        set_setting('google_smtp_enabled', !empty($_POST['google_smtp_enabled']) ? '1' : '0');
        set_setting('google_smtp_host', clean($_POST['google_smtp_host'] ?? '', 120) ?: 'smtp.gmail.com');
        set_setting('google_smtp_port', clean($_POST['google_smtp_port'] ?? '', 10) ?: '465');
        set_setting('google_smtp_secure', clean($_POST['google_smtp_secure'] ?? '1', 1));
        set_setting('google_smtp_user', clean($_POST['google_smtp_user'] ?? '', 120));
        
        $newPass = trim((string)($_POST['google_smtp_pass'] ?? ''));
        if ($newPass !== '') {
            set_setting('google_smtp_pass', $newPass);
        }

        // Rules & Throttling
        set_setting('provider_mode', clean($_POST['provider_mode'] ?? 'auto_balance', 40));
        set_setting('resend_ratio', (string)max(10, min(90, (int)($_POST['resend_ratio'] ?? 60))));
        set_setting('batch_size', (string)max(5, min(100, (int)($_POST['batch_size'] ?? 50))));
        set_setting('delay_between_emails_ms', (string)max(50, min(2000, (int)($_POST['delay_between_emails_ms'] ?? 150))));
        set_setting('delay_between_batches_ms', (string)max(200, min(10000, (int)($_POST['delay_between_batches_ms'] ?? 1200))));

        flash('success', 'All system settings & gateway configurations have been saved.');
        header("Location: {$self}?view=settings");
        exit;
    }

    // Action D: Change Password
    if ($act === 'change_password') {
        $curPass = (string)($_POST['cur_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $newPass2 = (string)($_POST['new_password2'] ?? '');

        $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = ?');
        $st->execute([$currentUser['id']]);
        $hash = $st->fetchColumn();

        if (!$hash || !password_verify($curPass, $hash)) {
            flash('error', 'Current password was entered incorrectly.');
        } elseif (strlen($newPass) < 8) {
            flash('error', 'New password must be at least 8 characters.');
        } elseif ($newPass !== $newPass2) {
            flash('error', 'New passwords do not match.');
        } else {
            $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
                ->execute([password_hash($newPass, PASSWORD_DEFAULT), $currentUser['id']]);
            flash('success', 'Your account password has been updated.');
        }
        header("Location: {$self}?view=settings&tab=security");
        exit;
    }

    // Action E: Clear Logs
    if ($act === 'clear_logs') {
        $pdo->exec('DELETE FROM email_logs');
        flash('success', 'Delivery audit logs have been cleared.');
        header("Location: {$self}?view=outbox");
        exit;
    }
}

// ----------------------------------------------------
// 3. DATA PREPARATION FOR ACTIVE VIEW
// ----------------------------------------------------
$view = $_GET['view'] ?? 'overview';
$flash = get_flash();

$stats = ['totalSent' => 0, 'todaySent' => 0, 'resendSent' => 0, 'googleSent' => 0, 'totalFailed' => 0];
$templates = [];
$campaigns = [];
$emailLogs = [];

if ($authed) {
    $todayCond = is_mysql() ? "DATE(created_at) = CURDATE()" : "date(created_at) = date('now')";
    $stats['totalSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent'")->fetchColumn();
    $stats['todaySent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND {$todayCond}")->fetchColumn();
    $stats['resendSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'resend'")->fetchColumn();
    $stats['googleSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'google_workspace'")->fetchColumn();
    $stats['totalFailed'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'")->fetchColumn();

    $templates = $pdo->query('SELECT * FROM templates ORDER BY id ASC')->fetchAll();
    $campaigns = $pdo->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 50')->fetchAll();

    if ($view === 'outbox') {
        $emailLogs = $pdo->query('SELECT * FROM email_logs ORDER BY id DESC LIMIT 150')->fetchAll();
    }
}

// Check provider configurations
$resendConfigured = !empty(get_setting('resend_api_key'));
$googleConfigured = !empty(get_setting('google_smtp_user')) && !empty(get_setting('google_smtp_pass'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <meta name="robots" content="noindex, nofollow">
  <title>Tesla Energy &middot; Business Mail &amp; Dispatch</title>
  <link rel="stylesheet" href="assets/css/app.css?v=2.1">
</head>
<body>

<?php if (!$authed): ?>
<!-- ==============================================
     OAKVIEW / MAILVIEW STYLE AUTH SPLIT
     ============================================== -->
<div class="auth-wrap">
  <aside class="auth-aside">
    <div class="brand">
      <div class="brand-mark">TE</div>
      <div class="brand-text">
        <b>Tesla Energy</b>
        <span>Enterprise Dispatch</span>
      </div>
    </div>

    <div>
      <div class="lede">High-throughput mailer engineered for <span><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></span></div>
      <ul class="points">
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
          <span>Dual Gateway Architecture: Resend Paid API + Google Workspace SMTP (6,000 / day).</span>
        </li>
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
          <span>Inbound replies automatically routed to <strong><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong>.</span>
        </li>
        <li>
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M20 6 9 17l-5-5"/></svg>
          <span>Comprehensive settings for gateways, ratios, throttling, and Cloudflare DNS.</span>
        </li>
      </ul>
    </div>

    <div class="small muted">&copy; <?= date('Y') ?> Tesla Energy Foundation &bull; Internal Mail Gateway</div>
  </aside>

  <main class="auth-main">
    <div class="auth-card">
      <h1>Sign in to Mailer</h1>
      <p class="sub">Enter your authorized account credentials to access the console.</p>

      <?php if ($error): ?>
        <div class="flash flash-error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="<?= $self ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="login">

        <div class="field">
          <label for="ident">Authorized email or username</label>
          <input class="input" id="ident" name="identifier" required autofocus placeholder="name@teslaenergyfoundation.com" autocomplete="username">
        </div>

        <div class="field">
          <label for="pwd">Access password</label>
          <input class="input" id="pwd" type="password" name="password" required placeholder="••••••••••••" autocomplete="current-password">
        </div>

        <button type="submit" class="btn btn-primary btn-block btn-lg" style="margin-top:18px;">
          Sign in to Mailer
        </button>
      </form>
    </div>
  </main>
</div>

<?php else: ?>
<!-- ==============================================
     AUTHENTICATED APP SHELL (SIDEBAR + TOPBAR + CONTENT)
     ============================================== -->
<div class="shell">
  <!-- Sidebar -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <div class="brand-mark">TE</div>
      <div class="brand-text">
        <b>Tesla Energy</b>
        <span>Business Mail (6k)</span>
      </div>
    </div>

    <nav class="nav">
      <div class="nav-group-label">Workspace</div>
      <a class="nav-item <?= $view === 'overview' ? 'active' : '' ?>" href="?view=overview">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>
        </svg>
        <span>Overview</span>
      </a>
      <a class="nav-item <?= $view === 'compose' ? 'active' : '' ?>" href="?view=compose">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <rect x="2.5" y="4.5" width="19" height="15" rx="2.5"/><path d="m3 6.5 8 6a2 2 0 0 0 2 0l8-6"/>
        </svg>
        <span>Quick Send</span>
      </a>

      <div class="nav-group-label">Audience</div>
      <a class="nav-item <?= $view === 'recipients' ? 'active' : '' ?>" href="?view=recipients">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20"/><circle cx="9.5" cy="7.5" r="3.5"/><path d="M21 20v-1.5a4 4 0 0 0-3-3.87"/><path d="M16.5 4.1a3.5 3.5 0 0 1 0 6.8"/>
        </svg>
        <span>Recipients &amp; Leads</span>
      </a>

      <div class="nav-group-label">Marketing &amp; 6k Dispatch</div>
      <a class="nav-item <?= $view === 'campaigns' ? 'active' : '' ?>" href="?view=campaigns">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M21.5 2.5 10.5 13.5"/><path d="M21.5 2.5 14.5 21l-4-7.5-7.5-4 18.5-7Z"/>
        </svg>
        <span>Campaign Studio</span>
        <?php if ($stats['todaySent']): ?><span class="count"><?= $stats['todaySent'] ?></span><?php endif; ?>
      </a>
      <a class="nav-item <?= $view === 'outbox' ? 'active' : '' ?>" href="?view=outbox">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 3v18h18"/><path d="M7 15l3.5-4 3 2.5L20 7"/>
        </svg>
        <span>Outbox &amp; Logs</span>
      </a>

      <div class="nav-group-label">System</div>
      <a class="nav-item <?= $view === 'settings' ? 'active' : '' ?>" href="?view=settings">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-2.87 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 3 15a1.7 1.7 0 0 0-1.55-1H1.3a2 2 0 1 1 0-4h.15A1.7 1.7 0 0 0 3 8.9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 7 4.6a1.7 1.7 0 0 0 1-1.55V2.9a2 2 0 1 1 4 0v.15A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9v.1a1.7 1.7 0 0 0 1.55 1h.15a2 2 0 1 1 0 4h-.15a1.7 1.7 0 0 0-1.55 1Z"/>
        </svg>
        <span>Settings &amp; Integrations</span>
      </a>
      <a class="nav-item <?= $view === 'dns' ? 'active' : '' ?>" href="?view=dns">
        <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
          <circle cx="12" cy="12" r="10"/><path d="M2 12h20"/><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>
        </svg>
        <span>Cloudflare DNS</span>
      </a>
    </nav>

    <div class="sidebar-foot">
      <div class="user-chip">
        <div class="avatar">TE</div>
        <div class="u-meta">
          <b><?= e($currentUser['username']) ?></b>
          <span><?= e($currentUser['role']) ?></span>
        </div>
      </div>
      <a href="?logout=1" class="btn btn-sm btn-block" style="margin-top:12px;background:transparent;border-color:rgba(255,255,255,.16);color:#c3cede;">
        Sign out
      </a>
    </div>
  </aside>

  <!-- Main View Area -->
  <div class="main">
    <header class="topbar">
      <div>
        <h1>
          <?php
            $titles = [
              'overview'   => 'Overview &amp; Metrics',
              'campaigns'  => 'Campaign Studio',
              'compose'    => 'Quick Send',
              'recipients' => 'Audience &amp; Recipients',
              'outbox'     => 'Outbox &amp; Logs',
              'settings'   => 'Settings &amp; Integrations',
              'dns'        => 'Cloudflare DNS Records'
            ];
            echo $titles[$view] ?? 'Tesla Energy Mailer';
          ?>
        </h1>
        <div class="sub">
          Domain: <strong class="mono"><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></strong> &bull;
          Replies &rarr; <span class="badge badge-amber badge-mono"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></span>
        </div>
      </div>

      <div class="topbar-spacer"></div>

      <div class="topbar-actions">
        <a class="btn btn-sm btn-primary" href="?view=campaigns&new=1">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 4v16M4 12h16"/></svg>
          New Campaign
        </a>
        <a class="btn btn-sm" href="?view=compose">
          Quick Send
        </a>
      </div>
    </header>

    <div class="content">
      <?php if ($flash): ?>
        <div class="flash flash-<?= $flash['type'] === 'success' ? 'success' : 'error' ?>">
          <?= e($flash['msg']) ?>
        </div>
      <?php endif; ?>

      <?php if ($view === 'overview'): ?>
      <!-- ==============================================
           VIEW: OVERVIEW (STAT TILES & LIVE CHECK)
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>System Overview</h1>
          <p class="sub">Real-time status of your dual-gateway sending infrastructure and volume allocation.</p>
        </div>
      </div>

      <div class="grid grid-4 mb-3">
        <div class="card stat">
          <div class="label">Today Dispatched</div>
          <div class="value"><?= number_format($stats['todaySent']) ?></div>
          <div class="delta">of 6,000 daily capacity</div>
          <?php $pct = min(100, round(($stats['todaySent'] / 6000) * 100)); ?>
          <div class="bar"><i style="width:<?= $pct ?>%"></i></div>
        </div>

        <div class="card stat">
          <div class="label">All-time Delivered</div>
          <div class="value" style="color:var(--green);"><?= number_format($stats['totalSent']) ?></div>
          <div class="delta up">&bull; Across dual gateways</div>
        </div>

        <div class="card stat">
          <div class="label">Resend Paid API</div>
          <div class="value mono"><?= number_format($stats['resendSent']) ?></div>
          <div class="delta">&bull; ~4,000/day allocation</div>
        </div>

        <div class="card stat">
          <div class="label">Google Workspace SMTP</div>
          <div class="value mono"><?= number_format($stats['googleSent']) ?></div>
          <div class="delta">&bull; ~2,000/day user cap</div>
        </div>
      </div>

      <div class="grid grid-2 mb-3">
        <!-- Live Provider Check -->
        <div class="card">
          <div class="card-head">
            <h2>Gateway Status &amp; Connectivity</h2>
            <div class="spacer"></div>
            <a href="?view=settings" class="small">Configure &rarr;</a>
          </div>
          <div class="card-body">
            <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line-soft);">
              <div>
                <div style="font-weight:600;color:var(--ink);">Resend Paid API</div>
                <div class="small muted">High-speed HTTPS campaign delivery</div>
              </div>
              <span class="badge <?= $resendConfigured ? 'badge-green' : 'badge-amber' ?>">
                <span class="badge-dot"></span><?= $resendConfigured ? 'ready' : 'not set' ?>
              </span>
            </div>

            <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line-soft);">
              <div>
                <div style="font-weight:600;color:var(--ink);">Google Workspace SMTP</div>
                <div class="small muted">smtp.gmail.com:465 (SSL) &bull; <?= e(get_setting('google_smtp_user') ?: 'not configured') ?></div>
              </div>
              <span class="badge <?= $googleConfigured ? 'badge-green' : 'badge-amber' ?>">
                <span class="badge-dot"></span><?= $googleConfigured ? 'ready' : 'not set' ?>
              </span>
            </div>

            <div class="row between" style="padding:10px 0;">
              <div>
                <div style="font-weight:600;color:var(--ink);">Auto-Balance Load Router</div>
                <div class="small muted">Split: <?= get_setting('resend_ratio', '60') ?>% Resend / <?= 100 - (int)get_setting('resend_ratio', '60') ?>% Google with instant failover</div>
              </div>
              <span class="badge badge-green"><span class="badge-dot"></span>active</span>
            </div>

            <div class="divider"></div>

            <div class="row wrap" style="gap:8px;">
              <button class="btn btn-sm" onclick="runLiveCheck('resend')">Test Resend</button>
              <button class="btn btn-sm" onclick="runLiveCheck('google')">Test Google SMTP</button>
              <a href="?view=compose" class="btn btn-sm btn-primary">Send Test Email</a>
            </div>
            <div id="live-check-res" class="small mt-2 hide"></div>
          </div>
        </div>

        <!-- Cloudflare Email Routing Note -->
        <div class="card">
          <div class="card-head">
            <h2>Domain &amp; Routing Topology</h2>
            <div class="spacer"></div>
            <a href="?view=dns" class="small">DNS Records &rarr;</a>
          </div>
          <div class="card-body">
            <p class="small muted mb-2">
              <strong>teslaenergyfoundation.com</strong> is configured strictly as a <strong>sending domain</strong>.
              All recipient replies and bounces are captured automatically by <strong>teslafoundationreward.com</strong> via injected <span class="mono">Reply-To</span> headers.
            </p>
            <div class="alert mb-2">
              <strong>High Deliverability Notice</strong>
              SPF authorizes both Resend (`include:resend.com`) and Google Workspace (`include:_spf.google.com`) on Cloudflare with DKIM alignment.
            </div>
            <div class="row between small muted">
              <span>Sending: <strong class="mono"><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></strong></span>
              <span>Inbound: <strong class="mono text-amber"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Recent Campaigns -->
      <div class="card">
        <div class="card-head">
          <h2>Recent Dispatch Runs</h2>
          <div class="spacer"></div>
          <a class="btn btn-sm btn-primary" href="?view=campaigns&new=1">+ Create Campaign</a>
        </div>
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Campaign</th>
                <th>Subject</th>
                <th>Audience</th>
                <th>Sent</th>
                <th>Failed</th>
                <th>Mode</th>
                <th>Status</th>
                <th>Date</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($campaigns)): ?>
                <tr><td colspan="9" class="empty">No campaigns registered yet. Click &ldquo;Create Campaign&rdquo; to begin.</td></tr>
              <?php else: foreach (array_slice($campaigns, 0, 10) as $c): ?>
                <tr>
                  <td><strong><?= e($c['title']) ?></strong></td>
                  <td class="small muted truncate" style="max-width:240px;"><?= e($c['subject']) ?></td>
                  <td class="num"><?= number_format($c['total_recipients']) ?></td>
                  <td class="num" style="color:var(--green); font-weight:600;"><?= number_format($c['sent_count']) ?></td>
                  <td class="num" style="color:var(--red);"><?= number_format($c['failed_count']) ?></td>
                  <td><span class="badge badge-mono"><?= e($c['provider_mode']) ?></span></td>
                  <td>
                    <span class="badge <?= $c['status'] === 'completed' ? 'badge-green' : 'badge-red' ?>">
                      <span class="badge-dot"></span><?= e($c['status']) ?>
                    </span>
                  </td>
                  <td class="small muted"><?= date('M j, H:i', strtotime($c['created_at'])) ?></td>
                  <td>
                    <a class="btn btn-sm" href="?view=campaigns&launch_id=<?= $c['id'] ?>">Dispatch &rarr;</a>
                  </td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php elseif ($view === 'campaigns'): ?>
      <!-- ==============================================
           VIEW: CAMPAIGN STUDIO (6K BULK MAILER)
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>Campaign Studio</h1>
          <p class="sub">Compose, personalize with dynamic merge tokens, and bulk-dispatch via dual gateways.</p>
        </div>
      </div>

      <!-- Live Dispatch Queue Box (Shown if a campaign is actively launched) -->
      <div id="queue-box" class="card mb-3 <?= isset($_GET['launch_id']) ? '' : 'hide' ?>" style="border:2px solid var(--accent);">
        <div class="card-head" style="background:var(--surface-2);">
          <h2>Live Dispatch Queue</h2>
          <div class="spacer"></div>
          <button id="btn-pause-queue" onclick="toggleQueuePause()" class="btn btn-sm">Pause</button>
          <button onclick="stopQueue()" class="btn btn-sm btn-danger">Halt</button>
        </div>
        <div class="card-body">
          <div class="row between mb-1 small">
            <span id="queue-status-text">Dispatching campaign batch...</span>
            <strong id="queue-pct-text" class="mono">0%</strong>
          </div>
          <div class="progress mb-2">
            <i id="queue-progress-bar" style="width:0%;"></i>
          </div>
          <div id="queue-terminal" class="textarea mono small" style="height:150px; background:#0e0607; color:#fca5a5; border:1px solid #331518; overflow-y:auto; padding:10px;">
            <div>[System] Initializing dual gateway router...</div>
          </div>
        </div>
      </div>

      <!-- Campaign Form -->
      <form method="post" action="<?= $self ?>" id="campaignForm">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="create_campaign">

        <div class="editor-grid">
          <div class="stack">
            <!-- Basics Card -->
            <div class="card">
              <div class="card-head"><h2>Basics &amp; Headers</h2></div>
              <div class="card-body">
                <div class="field">
                  <label for="name">Campaign name</label>
                  <input class="input" id="name" name="name" required placeholder="e.g. Clean Energy Allocation Notice #1">
                </div>

                <div class="row-2">
                  <div class="field">
                    <label for="from_name">From name</label>
                    <input class="input" id="from_name" name="sender_name" value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>">
                  </div>
                  <div class="field">
                    <label for="from_email">From email</label>
                    <input class="input mono" id="from_email" name="sender_email" value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>">
                  </div>
                </div>

                <div class="row-2">
                  <div class="field">
                    <label for="subject">Subject line</label>
                    <input class="input" id="subject" name="subject" required placeholder="OFFICIAL ALLOCATION: Tesla Energy Foundation Grant [Ref: {{ref_id}}]">
                  </div>
                  <div class="field">
                    <label for="reply_to">Reply-To (Inbound replies)</label>
                    <input class="input mono" id="reply_to" name="reply_to" value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>">
                  </div>
                </div>

                <div class="row-2">
                  <div class="field">
                    <label for="cc_addr">CC <span class="muted small">(comma-separated, optional)</span></label>
                    <input class="input mono" id="cc_addr" name="cc_address" placeholder="cc1@example.com, cc2@gmail.com">
                  </div>
                  <div class="field">
                    <label for="bcc_addr">BCC <span class="muted small">(comma-separated, optional)</span></label>
                    <input class="input mono" id="bcc_addr" name="bcc_address" placeholder="bcc@example.com">
                  </div>
                </div>
              </div>
            </div>

            <!-- Audience Recipients -->
            <div class="card">
              <div class="card-head">
                <h2>Audience &amp; Recipient List</h2>
                <div class="spacer"></div>
                <button type="button" class="btn btn-sm btn-ghost" onclick="insertSampleLeads()">+ Insert 25 sample leads</button>
              </div>
              <div class="card-body">
                <div class="field">
                  <label for="recipients">Paste recipient emails (one per line, supports CSV: <span class="mono">email,name,ref_id,grant_amount</span>)</label>
                  <textarea class="textarea mono" id="recipients" name="recipients" rows="8" required oninput="updateRecipientCount()" placeholder="john.doe@example.com&#10;sarah.connor@gmail.com,Sarah Connor,TEF-8921,$75,000"></textarea>
                  <div class="hint" id="recipient-count-label">0 recipient addresses loaded &bull; Duplicates automatically filtered</div>
                </div>
              </div>
            </div>

            <!-- Content & HTML -->
            <div class="card">
              <div class="card-head">
                <h2>Email Content</h2>
                <div class="spacer"></div>
                <select class="select" id="templatePicker" style="max-width:240px;" onchange="loadTemplate(this.value)">
                  <option value="">— Load pre-built template —</option>
                  <?php foreach ($templates as $t): ?>
                    <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="card-body">
                <div class="toolbar mb-2" style="gap:6px;">
                  <span class="small muted">Insert token:</span>
                  <button type="button" class="pill" onclick="insertToken('{{name}}')">{{name}}</button>
                  <button type="button" class="pill" onclick="insertToken('{{email}}')">{{email}}</button>
                  <button type="button" class="pill" onclick="insertToken('{{ref_id}}')">{{ref_id}}</button>
                  <button type="button" class="pill" onclick="insertToken('{{grant_amount}}')">{{grant_amount}}</button>
                  <button type="button" class="pill" onclick="insertToken('{{date}}')">{{date}}</button>
                </div>
                <div class="field">
                  <label for="htmlBody">HTML body</label>
                  <textarea class="textarea mono" id="htmlBody" name="html" rows="16" required oninput="updatePreview()"></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Right Column: Settings & Launch -->
          <div class="stack">
            <!-- Gateway Strategy -->
            <div class="card">
              <div class="card-head"><h2>Delivery Gateway</h2></div>
              <div class="card-body">
                <div class="field">
                  <label>Dispatch Mode</label>
                  <div class="stack" style="gap:8px;">
                    <label class="check">
                      <input type="radio" name="provider_mode" value="auto_balance" checked>
                      <span>
                        <strong>Auto-Balance (Recommended)</strong>
                        <div class="small muted">Balances <?= get_setting('resend_ratio', '60') ?>% Resend + <?= 100 - (int)get_setting('resend_ratio', '60') ?>% Google with instant failover</div>
                      </span>
                    </label>
                    <label class="check">
                      <input type="radio" name="provider_mode" value="resend_only">
                      <span>
                        <strong>Resend Paid API Only</strong>
                        <div class="small muted">Direct HTTPS dispatch</div>
                      </span>
                    </label>
                    <label class="check">
                      <input type="radio" name="provider_mode" value="google_only">
                      <span>
                        <strong>Google Workspace SMTP Only</strong>
                        <div class="small muted">Direct via smtp.gmail.com:465</div>
                      </span>
                    </label>
                  </div>
                </div>

                <div class="divider"></div>

                <button type="submit" class="btn btn-primary btn-block btn-lg">
                  Launch 6k Campaign
                </button>
                <div class="hint text-right mt-1">Dispatches in throttled batches to prevent timeouts.</div>
              </div>
            </div>

            <!-- Live Preview Frame -->
            <div class="card">
              <div class="card-head"><h2>Visual Preview</h2></div>
              <div class="card-body tight">
                <div class="preview-frame">
                  <div class="chrome">Rendered Output</div>
                  <iframe id="htmlPreview"></iframe>
                </div>
              </div>
            </div>
          </div>
        </div>
      </form>

      <?php elseif ($view === 'compose'): ?>
      <!-- ==============================================
           VIEW: QUICK SEND (SINGLE DISPATCH / DIAGNOSTIC)
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>Quick Send</h1>
          <p class="sub">Send a single prioritized message or test delivery to any destination inbox.</p>
        </div>
      </div>

      <div class="card" style="max-width:760px;">
        <div class="card-head"><h2>Direct Dispatch</h2></div>
        <form method="post" action="<?= $self ?>" class="card-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="send_quick">

          <div class="row-2">
            <div class="field">
              <label for="q_to">Recipient email (To)</label>
              <input class="input" id="q_to" name="to" type="email" required placeholder="recipient@gmail.com">
            </div>
            <div class="field">
              <label for="q_provider">Gateway</label>
              <select class="select" id="q_provider" name="provider">
                <option value="auto_balance">Auto-Balance (Dual Gateway)</option>
                <option value="resend_only">Resend Paid API Only</option>
                <option value="google_only">Google Workspace SMTP Only</option>
              </select>
            </div>
          </div>

          <div class="row-2">
            <div class="field">
              <label for="q_cc">CC <span class="muted small">(optional, comma-separated)</span></label>
              <input class="input mono" id="q_cc" name="cc" placeholder="cc@gmail.com, another@outlook.com">
            </div>
            <div class="field">
              <label for="q_bcc">BCC <span class="muted small">(optional, comma-separated)</span></label>
              <input class="input mono" id="q_bcc" name="bcc" placeholder="bcc@example.com">
            </div>
          </div>

          <div class="field">
            <label for="q_subject">Subject</label>
            <input class="input" id="q_subject" name="subject" required value="Tesla Energy Foundation &bull; Notification Verification">
          </div>

          <div class="field">
            <label for="q_html">HTML Message Content</label>
            <textarea class="textarea mono" id="q_html" name="html" rows="10" placeholder="<p>Write or paste HTML message content here...</p>"></textarea>
          </div>

          <button type="submit" class="btn btn-primary">Dispatch Message</button>
        </form>
      </div>

      <?php elseif ($view === 'recipients'): ?>
      <!-- ==============================================
           VIEW: RECIPIENTS & AUDIENCE
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>Audience &amp; Recipients</h1>
          <p class="sub">Manage recipient pools for your 6,000/day grant and notification campaigns.</p>
        </div>
      </div>

      <div class="card">
        <div class="card-head">
          <h2>Recipient Database</h2>
          <div class="spacer"></div>
          <a class="btn btn-sm btn-primary" href="?view=campaigns&new=1">Load into Campaign Studio &rarr;</a>
        </div>
        <div class="card-body">
          <p class="small muted mb-2">
            Recipients can be pasted or imported directly in the <strong>Campaign Studio</strong> with full column mapping for custom variables (<span class="mono">{{name}}</span>, <span class="mono">{{ref_id}}</span>, <span class="mono">{{grant_amount}}</span>).
          </p>
          <div class="row">
            <a href="?view=campaigns&new=1" class="btn btn-sm">Open Campaign Studio</a>
          </div>
        </div>
      </div>

      <?php elseif ($view === 'outbox'): ?>
      <!-- ==============================================
           VIEW: OUTBOX & AUDIT LOGS
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>Outbox &amp; Logs</h1>
          <p class="sub">Complete historical record of dispatched messages with provider reference IDs.</p>
        </div>
        <div class="row">
          <form method="post" action="<?= $self ?>" onsubmit="return confirm('Clear all audit logs?');">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="clear_logs">
            <button class="btn btn-danger btn-sm" type="submit">Clear Logs</button>
          </form>
        </div>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Status</th>
                <th>Gateway</th>
                <th>To</th>
                <th>CC / BCC</th>
                <th>Subject</th>
                <th>Message ID</th>
                <th>Timestamp</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($emailLogs)): ?>
                <tr><td colspan="7" class="empty">No emails logged in outbox yet.</td></tr>
              <?php else: foreach ($emailLogs as $log): ?>
                <tr>
                  <td>
                    <span class="badge <?= $log['status'] === 'sent' ? 'badge-green' : 'badge-red' ?>">
                      <span class="badge-dot"></span><?= e($log['status']) ?>
                    </span>
                  </td>
                  <td><span class="badge badge-mono"><?= e($log['provider']) ?></span></td>
                  <td><strong><?= e($log['to_address']) ?></strong></td>
                  <td class="small muted"><?php
                    $ccbcc = [];
                    if (!empty($log['cc_address']))  $ccbcc[] = 'CC: ' . $log['cc_address'];
                    if (!empty($log['bcc_address'])) $ccbcc[] = 'BCC: ' . $log['bcc_address'];
                    echo $ccbcc ? e(implode(' | ', $ccbcc)) : '—';
                  ?></td>
                  <td class="small muted truncate" style="max-width:280px;"><?= e($log['subject']) ?></td>
                  <td class="num small muted"><?= e($log['message_id'] ?: '—') ?></td>
                  <td class="small muted"><?= date('M j, H:i:s', strtotime($log['created_at'])) ?></td>
                </tr>
              <?php endforeach; endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <?php elseif ($view === 'settings'): ?>
      <!-- ==============================================
           VIEW: SETTINGS & INTEGRATIONS (COMPLETE SETTINGS)
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>Settings &amp; Integrations</h1>
          <p class="sub">Configure delivery gateways, Google Workspace SMTP, throttling, and identity.</p>
        </div>
      </div>

      <div class="grid grid-2 mb-3">
        <!-- Provider Live Status -->
        <div class="card">
          <div class="card-head">
            <h2>Provider Status</h2>
            <div class="spacer"></div>
            <span class="small muted">Live check</span>
          </div>
          <div class="card-body">
            <div class="row between" style="padding:9px 0;border-bottom:1px solid var(--line-soft);">
              <div>
                <div style="font-weight:600;color:var(--ink);">Campaign delivery</div>
                <div class="small muted">Resend Paid API</div>
              </div>
              <span class="badge <?= $resendConfigured ? 'badge-green' : 'badge-amber' ?>">
                <span class="badge-dot"></span><?= $resendConfigured ? 'configured' : 'not set' ?>
              </span>
            </div>

            <div class="row between" style="padding:9px 0;">
              <div>
                <div style="font-weight:600;color:var(--ink);">Mailbox &amp; SMTP</div>
                <div class="small muted">Google Workspace &middot; <span class="mono"><?= e(get_setting('google_smtp_user') ?: 'not set') ?></span></div>
              </div>
              <span class="badge <?= $googleConfigured ? 'badge-green' : 'badge-amber' ?>">
                <span class="badge-dot"></span><?= $googleConfigured ? 'configured' : 'not set' ?>
              </span>
            </div>

            <div class="divider"></div>

            <div class="row wrap" style="gap:8px;">
              <button class="btn btn-sm" onclick="runLiveCheck('resend')">Test delivery connection</button>
              <button class="btn btn-sm" onclick="runLiveCheck('google')">Test mailbox connection</button>
            </div>
            <div id="settings-check-res" class="small mt-2 hide"></div>
          </div>
        </div>

        <!-- Cloudflare Email Routing Card -->
        <div class="card">
          <div class="card-head"><h2>Cloudflare Email Routing</h2></div>
          <div class="card-body">
            <p class="small muted mb-2">
              Cloudflare Email Routing is <strong>forward-only</strong> infrastructure:
              emails sent by recipients to <strong>teslaenergyfoundation.com</strong> are cleanly directed to your receiving mailbox at <strong><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong>.
            </p>
            <div class="alert">
              <strong>Inbound Architecture</strong>
              This allows <strong>teslaenergyfoundation.com</strong> to be 100% sending-only, while your incoming operations run on <strong>teslafoundationreward.com</strong>.
            </div>
          </div>
        </div>
      </div>

      <!-- Settings Form with Full Panels -->
      <form method="post" action="<?= $self ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_all_settings">

        <div class="grid grid-2 mb-3">
          <!-- Panel 1: Identity & Routing -->
          <div class="card">
            <div class="card-head"><h2>Sender Identity &amp; Routing</h2></div>
            <div class="card-body">
              <div class="field">
                <label for="s_domain">Sending Domain</label>
                <input class="input mono" id="s_domain" name="sending_domain" value="<?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?>" required>
                <div class="hint">Configured on Cloudflare for SPF, DKIM, and DMARC.</div>
              </div>

              <div class="field">
                <label for="s_reply">Inbound Reply-To Target</label>
                <input class="input mono" id="s_reply" name="reply_to_email" value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>" required>
                <div class="hint">Where recipient replies are automatically routed.</div>
              </div>

              <div class="row-2">
                <div class="field">
                  <label for="s_from_name">Default From Name</label>
                  <input class="input" id="s_from_name" name="default_from_name" value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>" required>
                </div>
                <div class="field">
                  <label for="s_from_email">Default From Email</label>
                  <input class="input mono" id="s_from_email" name="default_from_email" value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>" required>
                </div>
              </div>
            </div>
          </div>

          <!-- Panel 2: Delivery Provider (Resend Paid API) -->
          <div class="card">
            <div class="card-head"><h2>Resend Paid API Gateway</h2></div>
            <div class="card-body">
              <div class="field">
                <label class="check mb-2">
                  <input type="checkbox" name="resend_enabled" value="1" <?= get_setting('resend_enabled', '1') === '1' ? 'checked' : '' ?>>
                  <span>Enable Resend API Gateway</span>
                </label>
              </div>

              <div class="field">
                <label for="resend_key">Resend API Key (<span class="mono">re_...</span>)</label>
                <input class="input mono" id="resend_key" type="password" name="resend_api_key" value="<?= e(get_setting('resend_api_key', '')) ?>" placeholder="re_xxxxxxxxxxxxxxxxxxxxxx">
              </div>

              <div class="field">
                <label for="resend_limit">Daily Quota Limit</label>
                <input class="input mono" id="resend_limit" name="resend_daily_limit" value="<?= e(get_setting('resend_daily_limit', '5000')) ?>">
              </div>
            </div>
          </div>

          <!-- Panel 3: Google Workspace Paid SMTP -->
          <div class="card">
            <div class="card-head"><h2>Google Workspace SMTP Gateway</h2></div>
            <div class="card-body">
              <div class="field">
                <label class="check mb-2">
                  <input type="checkbox" name="google_smtp_enabled" value="1" <?= get_setting('google_smtp_enabled', '1') === '1' ? 'checked' : '' ?>>
                  <span>Enable Google Workspace SMTP Gateway</span>
                </label>
              </div>

              <div class="row-2">
                <div class="field">
                  <label for="g_host">SMTP Host</label>
                  <input class="input mono" id="g_host" name="google_smtp_host" value="<?= e(get_setting('google_smtp_host', 'smtp.gmail.com')) ?>">
                </div>
                <div class="field">
                  <label for="g_port">Port</label>
                  <input class="input mono" id="g_port" name="google_smtp_port" value="<?= e(get_setting('google_smtp_port', '465')) ?>">
                </div>
              </div>

              <div class="row-2">
                <div class="field">
                  <label for="g_user">Workspace Account Email</label>
                  <input class="input mono" id="g_user" name="google_smtp_user" value="<?= e(get_setting('google_smtp_user', '')) ?>" placeholder="grants@teslaenergyfoundation.com">
                </div>
                <div class="field">
                  <label for="g_pass">16-Character App Password</label>
                  <input class="input mono" id="g_pass" type="password" name="google_smtp_pass" placeholder="<?= get_setting('google_smtp_pass') ? '••••••••••••••••' : 'Enter App Password' ?>">
                </div>
              </div>
            </div>
          </div>

          <!-- Panel 4: Dual Balancing & Throttling Rules -->
          <div class="card">
            <div class="card-head"><h2>Balancing &amp; Rate Throttling</h2></div>
            <div class="card-body">
              <div class="field">
                <label for="p_mode">Default Provider Mode</label>
                <select class="select" id="p_mode" name="provider_mode">
                  <option value="auto_balance" <?= get_setting('provider_mode') === 'auto_balance' ? 'selected' : '' ?>>Auto-Balance (Dual Gateway)</option>
                  <option value="resend_only" <?= get_setting('provider_mode') === 'resend_only' ? 'selected' : '' ?>>Resend API Only</option>
                  <option value="google_only" <?= get_setting('provider_mode') === 'google_only' ? 'selected' : '' ?>>Google Workspace Only</option>
                  <option value="failover" <?= get_setting('provider_mode') === 'failover' ? 'selected' : '' ?>>Primary Resend with Failover</option>
                </select>
              </div>

              <div class="field">
                <label>Split Ratio: <strong id="ratio-label"><?= get_setting('resend_ratio', '60') ?>% Resend / <?= 100 - (int)get_setting('resend_ratio', '60') ?>% Google</strong></label>
                <input type="range" name="resend_ratio" min="10" max="90" value="<?= (int)get_setting('resend_ratio', '60') ?>"
                       oninput="document.getElementById('ratio-label').innerText = this.value + '% Resend / ' + (100 - this.value) + '% Google'"
                       style="width:100%; accent-color:var(--accent);">
              </div>

              <div class="row-2">
                <div class="field">
                  <label for="b_size">Batch Chunk Size</label>
                  <input class="input mono" id="b_size" name="batch_size" value="<?= e(get_setting('batch_size', '50')) ?>">
                </div>
                <div class="field">
                  <label for="e_delay">Delay between emails (ms)</label>
                  <input class="input mono" id="e_delay" name="delay_between_emails_ms" value="<?= e(get_setting('delay_between_emails_ms', '150')) ?>">
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="row between">
          <span class="small muted">Settings are active immediately across all worker dispatches.</span>
          <button type="submit" class="btn btn-primary btn-lg">Save Settings &amp; Integrations</button>
        </div>
      </form>

      <!-- Password Change Section -->
      <div class="card mt-3" style="max-width:540px;">
        <div class="card-head"><h2>Account Security</h2></div>
        <form method="post" action="<?= $self ?>" class="card-body">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="change_password">

          <div class="field">
            <label for="cur_pwd">Current password</label>
            <input class="input" id="cur_pwd" type="password" name="cur_password" required>
          </div>

          <div class="row-2">
            <div class="field">
              <label for="new_pwd">New password</label>
              <input class="input" id="new_pwd" type="password" name="new_password" required minlength="8">
            </div>
            <div class="field">
              <label for="new_pwd2">Confirm new password</label>
              <input class="input" id="new_pwd2" type="password" name="new_password2" required minlength="8">
            </div>
          </div>

          <button type="submit" class="btn btn-dark">Update Password</button>
        </form>
      </div>

      <?php elseif ($view === 'dns'): ?>
      <!-- ==============================================
           VIEW: CLOUDFLARE DNS SETUP GUIDE
           ============================================== -->
      <div class="page-head">
        <div class="titles">
          <h1>Cloudflare DNS Configuration</h1>
          <p class="sub">Essential verification records for teslaenergyfoundation.com</p>
        </div>
        <div class="row">
          <a class="btn btn-sm btn-primary" href="https://dash.cloudflare.com" target="_blank">Open Cloudflare &rarr;</a>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-body">
          <p class="small muted mb-0">
            Add these records in your Cloudflare DNS zone for <strong>teslaenergyfoundation.com</strong>.
            Ensure proxy status is set to <strong>DNS Only (Grey Cloud)</strong> for SPF and DKIM.
          </p>
        </div>
      </div>

      <div class="card">
        <div class="table-wrap">
          <table class="data">
            <thead>
              <tr>
                <th>Type</th>
                <th>Name / Host</th>
                <th>Content / Value</th>
                <th>TTL / Proxy</th>
                <th>Purpose</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td><span class="badge badge-blue badge-mono">TXT</span></td>
                <td><strong>@</strong></td>
                <td><span class="mono">v=spf1 include:resend.com include:_spf.google.com ~all</span></td>
                <td class="small muted">DNS Only</td>
                <td class="small muted">SPF: Authorizes Resend + Google Workspace</td>
              </tr>
              <tr>
                <td><span class="badge badge-blue badge-mono">TXT</span></td>
                <td><strong>_dmarc</strong></td>
                <td><span class="mono">v=DMARC1; p=quarantine; rua=mailto:dmarc@teslafoundationreward.com</span></td>
                <td class="small muted">DNS Only</td>
                <td class="small muted">DMARC: Sender protection</td>
              </tr>
              <tr>
                <td><span class="badge badge-amber badge-mono">CNAME</span></td>
                <td><strong>resend._domainkey</strong></td>
                <td><span class="mono">provided-by.resend.com</span></td>
                <td class="small muted">DNS Only</td>
                <td class="small muted">Resend DKIM record</td>
              </tr>
              <tr>
                <td><span class="badge badge-blue badge-mono">TXT</span></td>
                <td><strong>google._domainkey</strong></td>
                <td><span class="mono">v=DKIM1; k=rsa; p=YOUR_GOOGLE_KEY</span></td>
                <td class="small muted">DNS Only</td>
                <td class="small muted">Google Workspace DKIM record</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<script>
const TEMPLATES = <?= json_encode($templates) ?>;

function loadTemplate(id) {
  const t = TEMPLATES.find(x => String(x.id) === String(id));
  if (t) {
    document.getElementById('subject').value = t.subject;
    document.getElementById('htmlBody').value = t.body_html;
    updatePreview();
  }
}

function updatePreview() {
  const f = document.getElementById('htmlPreview');
  const txt = document.getElementById('htmlBody');
  if (f && txt) {
    const doc = f.contentDocument || f.contentWindow.document;
    doc.open();
    doc.write(txt.value || '<p style="color:#888;font-family:sans-serif;padding:20px;">No HTML body provided yet.</p>');
    doc.close();
  }
}

function insertToken(tok) {
  const t = document.getElementById('htmlBody');
  const start = t.selectionStart;
  const end = t.selectionEnd;
  t.value = t.value.substring(0, start) + tok + t.value.substring(end);
  t.focus();
  t.selectionStart = t.selectionEnd = start + tok.length;
  updatePreview();
}

function updateRecipientCount() {
  const val = document.getElementById('recipients').value.trim();
  const lines = val ? val.split(/\r?\n/).filter(x => x.trim().length > 0) : [];
  const lbl = document.getElementById('recipient-count-label');
  if (lbl) {
    lbl.innerText = lines.length + ' recipient addresses loaded • Duplicates automatically filtered';
  }
}

function insertSampleLeads() {
  const samples = [
    'alex.morgan@gmail.com,Alex Morgan,TEF-8921,$75,000',
    'marcus.vance@outlook.com,Marcus Vance,TEF-9310,$120,000',
    'elena.rostova@yahoo.com,Elena Rostova,TEF-4822,$75,000',
    'david.chen22@proton.me,David Chen,TEF-1109,$95,000'
  ];
  const t = document.getElementById('recipients');
  t.value = (t.value.trim() ? t.value.trim() + "\n" : "") + samples.join("\n");
  updateRecipientCount();
}

// Live Gateway Connectivity Checks
async function runLiveCheck(provider) {
  const resEl = document.getElementById('settings-check-res') || document.getElementById('live-check-res');
  if (!resEl) return;
  resEl.className = 'small mt-2 muted';
  resEl.innerText = 'Connecting to ' + provider + '...';
  resEl.classList.remove('hide');

  const action = provider === 'resend' ? 'test_resend' : 'test_google';
  const fd = new FormData();

  try {
    const res = await fetch('api.php?action=' + action, { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      resEl.className = 'small mt-2' + ' text-green';
      resEl.innerText = '✓ ' + (data.message || 'Connected successfully!');
    } else {
      resEl.className = 'small mt-2' + ' text-red';
      resEl.innerText = '✗ ' + (data.error || 'Connection failed.');
    }
  } catch (e) {
    resEl.className = 'small mt-2 text-red';
    resEl.innerText = '✗ Network error: ' + e.message;
  }
}

// Batch Dispatch Engine for Bulk Sending
let queuePaused = false;
let queueStopped = false;

function toggleQueuePause() {
  queuePaused = !queuePaused;
  const b = document.getElementById('btn-pause-queue');
  if (b) b.innerText = queuePaused ? 'Resume' : 'Pause';
}

function stopQueue() {
  queueStopped = true;
  logQueue('[User] Dispatch queue halted.');
}

function logQueue(msg) {
  const term = document.getElementById('queue-terminal');
  if (!term) return;
  const el = document.createElement('div');
  el.innerText = '[' + new Date().toLocaleTimeString() + '] ' + msg;
  term.appendChild(el);
  term.scrollTop = term.scrollHeight;
}

<?php if (isset($_GET['launch_id'])): ?>
const LAUNCH_ID = <?= (int)$_GET['launch_id'] ?>;
window.addEventListener('DOMContentLoaded', () => {
  runBatchQueue(LAUNCH_ID);
});

async function runBatchQueue(campId) {
  logQueue(`Starting batch queue for campaign #${campId}...`);
  let dispatched = 0;

  while (!queueStopped) {
    if (queuePaused) {
      await new Promise(r => setTimeout(r, 1000));
      continue;
    }

    const fd = new FormData();
    fd.append('campaign_id', campId);
    fd.append('batch_size', <?= (int)get_setting('batch_size', '50') ?>);

    try {
      const res = await fetch('api.php?action=dispatch_chunk', { method: 'POST', body: fd });
      const data = await res.json();

      if (!data.success) {
        logQueue('Error: ' + data.error);
        break;
      }

      dispatched += data.processed;
      const rem = data.remaining;
      const total = dispatched + rem;
      const pct = total > 0 ? Math.round((dispatched / total) * 100) : 100;

      document.getElementById('queue-progress-bar').style.width = pct + '%';
      document.getElementById('queue-pct-text').innerText = pct + '%';
      document.getElementById('queue-status-text').innerText = `Dispatched: ${dispatched} | Remaining: ${rem}`;

      if (data.results) {
        for (const item of data.results) {
          if (item.status === 'sent') {
            logQueue(`✓ [${item.provider}] ${item.email}`);
          } else {
            logQueue(`✗ [${item.provider}] ${item.email}: ${item.error}`);
          }
        }
      }

      if (data.completed || rem === 0) {
        logQueue(`✓✓ Complete: All ${dispatched} recipients processed successfully.`);
        break;
      }

      await new Promise(r => setTimeout(r, <?= (int)get_setting('delay_between_batches_ms', '1200') ?>));
    } catch (e) {
      logQueue('Network chunk retry: ' + e.message);
      await new Promise(r => setTimeout(r, 3000));
    }
  }
}
<?php endif; ?>

window.addEventListener('DOMContentLoaded', () => {
  if (document.getElementById('htmlPreview')) updatePreview();
});
</script>
<?php endif; ?>

</body>
</html>
