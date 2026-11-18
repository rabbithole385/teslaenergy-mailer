<?php
/**
 * Tesla Energy Foundation â€” Business Mail & High-Throughput Bulk Dispatcher (6k/day)
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
    $pass = (string) ($_POST['password'] ?? '');

    $st = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $st->execute([$ident, $ident]);
    $user = $st->fetch();

    if ($user && password_verify($pass, $user['password_hash'])) {
      session_regenerate_id(true);
      $_SESSION['user_id'] = $user['id'];
      $_SESSION['user_data'] = [
        'id' => $user['id'],
        'username' => $user['username'],
        'email' => $user['email'],
        'role' => $user['role']
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
    $bodyHtml = (string) ($_POST['html'] ?? $_POST['body_html'] ?? '');
    $rawRecipients = (string) ($_POST['recipients'] ?? '');

    $lines = preg_split('/[\r\n]+/', trim($rawRecipients));
    $validList = [];
    $seen = [];

    foreach ($lines as $line) {
      $line = trim($line);
      if (!$line)
        continue;
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
        if (isset($rem[1]))
          $extra['ref_id'] = $rem[1];
        if (isset($rem[2]))
          $extra['grant_amount'] = $rem[2];

        $validList[] = [
          'email' => $email,
          'name' => $name,
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
        $campId = (int) $pdo->lastInsertId();

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
    $to = clean($_POST['to'] ?? '', 50000);
    $cc = clean($_POST['cc'] ?? '', 50000);
    $bcc = clean($_POST['bcc'] ?? '', 50000);
    $subject = clean($_POST['subject'] ?? '', 200) ?: ('Quick Test Dispatch — ' . date('H:i:s'));
    $bodyHtml = (string) ($_POST['html'] ?? '');
    $provider = clean($_POST['provider'] ?? 'auto_balance', 40);

    $validTo = parse_email_list($to);
    $validCC = parse_email_list($cc);
    $validBCC = parse_email_list($bcc);
    $allTargets = array_unique(array_merge($validTo, $validCC, $validBCC));

    if (empty($allTargets)) {
      flash('error', 'Please specify at least one recipient address (To, CC, or BCC).');
    } elseif (is_daily_quota_reached()) {
      flash('error', 'Daily sending limit of 6,000 emails reached (' . number_format(get_today_sent_count()) . ' / 6,000). Sending has been halted automatically until tomorrow to preserve domain reputation.');
    } else {
      $replyToTarget = get_setting('reply_to_email', DEFAULT_REPLY_TO) ?: 'service@teslaenergyfoundation.com';
      $content = $bodyHtml ?: '<div style="font-family:Arial,sans-serif;padding:24px;border:1px solid #e2e8f0;border-radius:8px;"><h2>Tesla Energy Foundation</h2><p>This is an executive dispatch from your Tesla Energy mailer.</p><p><strong>Reply-To Target:</strong> ' . htmlspecialchars($replyToTarget) . '</p></div>';

      if (count($validTo) > 1 && empty($validCC) && empty($validBCC)) {
        // Multi-recipient dispatch (individual personalized delivery to each recipient in To)
        $dispatched = 0;
        $failed = 0;
        $lastErr = '';
        foreach ($validTo as $singleTo) {
          if (is_daily_quota_reached()) {
            flash('warning', "Daily 6,000 email limit reached during batch. Sent {$dispatched} of " . count($validTo) . " messages.");
            break;
          }
          $res = send_dual_email($singleTo, $subject, $content, strip_tags($content), '', '', $replyToTarget, $provider, null, '', '');
          if (!empty($res['ok'])) {
            $dispatched++;
          } else {
            $failed++;
            $lastErr = $res['error'] ?? 'Delivery failed';
          }
        }
        if ($dispatched > 0) {
          flash('success', "Successfully dispatched {$dispatched} email" . ($dispatched > 1 ? 's' : '') . "! Today's counter updated (+{$dispatched})." . ($failed > 0 ? " ({$failed} failed: {$lastErr})" : ''));
        } else {
          flash('error', "Delivery failed for all recipients: {$lastErr}");
        }
      } else {
        // Standard dispatch or BCC/CC dispatch (all recipients logged into counter)
        $targetTo = !empty($validTo) ? $validTo[0] : (!empty($validCC) ? $validCC[0] : (!empty($validBCC) ? $validBCC[0] : DEFAULT_FROM_EMAIL));
        $res = send_dual_email($targetTo, $subject, $content, strip_tags($content), '', '', $replyToTarget, $provider, null, $cc, $bcc);
        if (!empty($res['ok'])) {
          $countTotal = count($allTargets);
          $destSummary = $countTotal > 1 ? ("{$countTotal} recipients (Batch)") : $targetTo;
          flash('success', "Dispatched to {$destSummary} successfully! Counter updated (+{$countTotal}). (ID: {$res['id']})");
        } else {
          flash('error', "Delivery failed: " . ($res['error'] ?: 'Check SMTP and Resend settings.'));
        }
      }
    }
    header("Location: {$self}?view=compose");
    exit;
  }

  // Action B2: Simulate Inbound Test Reply (to verify Inbox receiving)
  if ($act === 'simulate_inbound_reply') {
    $testSender = clean($_POST['test_sender'] ?? '', 120);
    $testName = clean($_POST['test_name'] ?? 'Beneficiary Response', 120);
    $testSubj = clean($_POST['test_subject'] ?? 'Re: Tesla Clean Energy Grant Allocation Inquiry', 255);
    $testBody = clean($_POST['test_body'] ?? 'Hello Tesla Energy Support, I received your grant notification and am writing to submit my verification documents.', 2000);

    if (empty($testSender) || !filter_var($testSender, FILTER_VALIDATE_EMAIL)) {
      $testSender = 'beneficiary.' . rand(100, 999) . '@gmail.com';
    }

    $snippet = mb_substr($testBody, 0, 160);
    $msgId = '<reply-' . time() . '.' . bin2hex(random_bytes(4)) . '@' . SENDING_DOMAIN . '>';
    $ins = $pdo->prepare('INSERT INTO inbox_messages (message_id, from_name, from_email, to_email, subject, body_html, body_text, snippet, is_read, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "replies")');
    $ins->execute([$msgId, $testName, $testSender, 'service@teslaenergyfoundation.com', $testSubj, nl2br(htmlspecialchars($testBody)), $testBody, $snippet]);
    flash('success', "Inbound reply received from {$testName} ({$testSender})! Logged in Inbox.");
    header("Location: {$self}?view=inbox");
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
    set_setting('resend_api_key', trim((string) ($_POST['resend_api_key'] ?? '')));
    set_setting('resend_daily_limit', clean($_POST['resend_daily_limit'] ?? '5000', 10));

    // Google Workspace SMTP
    set_setting('google_smtp_enabled', !empty($_POST['google_smtp_enabled']) ? '1' : '0');
    set_setting('google_smtp_host', clean($_POST['google_smtp_host'] ?? '', 120) ?: 'smtp.gmail.com');
    set_setting('google_smtp_port', clean($_POST['google_smtp_port'] ?? '', 10) ?: '465');
    set_setting('google_smtp_secure', clean($_POST['google_smtp_secure'] ?? '1', 1));
    set_setting('google_smtp_user', clean($_POST['google_smtp_user'] ?? '', 120));

    $newPass = trim((string) ($_POST['google_smtp_pass'] ?? ''));
    if ($newPass !== '') {
      set_setting('google_smtp_pass', $newPass);
    }

    // Rules & Throttling
    set_setting('provider_mode', clean($_POST['provider_mode'] ?? 'auto_balance', 40));
    set_setting('resend_ratio', (string) max(10, min(90, (int) ($_POST['resend_ratio'] ?? 60))));
    set_setting('batch_size', (string) max(5, min(100, (int) ($_POST['batch_size'] ?? 50))));
    set_setting('delay_between_emails_ms', (string) max(50, min(2000, (int) ($_POST['delay_between_emails_ms'] ?? 150))));
    set_setting('delay_between_batches_ms', (string) max(200, min(10000, (int) ($_POST['delay_between_batches_ms'] ?? 1200))));

    flash('success', 'All system settings & gateway configurations have been saved.');
    header("Location: {$self}?view=settings");
    exit;
  }

  // Action D: Change Password
  if ($act === 'change_password') {
    $curPass = (string) ($_POST['cur_password'] ?? '');
    $newPass = (string) ($_POST['new_password'] ?? '');
    $newPass2 = (string) ($_POST['new_password2'] ?? '');

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

  // Action F: Clear Inbox
  if ($act === 'clear_inbox') {
    $pdo->exec('DELETE FROM inbox_messages');
    flash('success', 'Inbox inquiries have been cleared.');
    header("Location: {$self}?view=inbox");
    exit;
  }
}

// ----------------------------------------------------
// 2B. INBOX ACTIONS CONTROLLER (GET)
// ----------------------------------------------------
$view = $_GET['view'] ?? 'overview';

if ($authed && $view === 'inbox' && isset($_GET['action'])) {
  $inboxAct = $_GET['action'];
  if ($inboxAct === 'mark_all_read') {
    mark_all_inbox_read();
    flash('success', 'All inbox messages marked as read.');
    header("Location: {$self}?view=inbox");
    exit;
  }
  if ($inboxAct === 'mark_read' && !empty($_GET['id'])) {
    mark_inbox_read((int) $_GET['id'], true);
    header("Location: {$self}?view=inbox");
    exit;
  }
  if ($inboxAct === 'delete' && !empty($_GET['id'])) {
    delete_inbox_message((int) $_GET['id']);
    flash('success', 'Message removed from inbox.');
    header("Location: {$self}?view=inbox");
    exit;
  }
}

// ----------------------------------------------------
// 3. DATA PREPARATION FOR ACTIVE VIEW
// ----------------------------------------------------
$flash = get_flash();

$stats = ['totalSent' => 0, 'todaySent' => 0, 'resendSent' => 0, 'googleSent' => 0, 'totalFailed' => 0];
$inboxStats = ['total' => 0, 'unread' => 0, 'starred' => 0];
$templates = [];
$campaigns = [];
$emailLogs = [];
$inboxMessages = [];
$activeMsg = null;

if ($authed) {
  $inboxStats = get_inbox_stats();
  $todayCond = is_mysql() ? "DATE(created_at) = CURDATE()" : "date(created_at) = date('now')";
  $stats['totalSent'] = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent'")->fetchColumn();
  $stats['todaySent'] = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND {$todayCond}")->fetchColumn();
  $stats['resendSent'] = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'resend'")->fetchColumn();
  $stats['googleSent'] = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'google_workspace'")->fetchColumn();
  $stats['totalFailed'] = (int) $pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'")->fetchColumn();

  $templates = $pdo->query('SELECT * FROM templates ORDER BY id ASC')->fetchAll();
  $campaigns = $pdo->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 50')->fetchAll();

  if ($view === 'outbox' || $view === 'sent') {
    $emailLogs = $pdo->query('SELECT * FROM email_logs ORDER BY id DESC LIMIT 150')->fetchAll();
  }

  if ($view === 'inbox' || $view === 'replies') {
    $filter = clean($_GET['filter'] ?? ($view === 'replies' ? 'replies' : 'all'));
    if ($filter === 'unread') {
      $inboxMessages = $pdo->query('SELECT * FROM inbox_messages WHERE is_read = 0 ORDER BY id DESC')->fetchAll();
    } elseif ($filter === 'replies') {
      $inboxMessages = $pdo->query("SELECT * FROM inbox_messages WHERE category = 'replies' OR subject LIKE 'Re:%' ORDER BY id DESC")->fetchAll();
    } elseif ($filter === 'grants') {
      $inboxMessages = $pdo->query("SELECT * FROM inbox_messages WHERE category = 'grants' ORDER BY id DESC")->fetchAll();
    } else {
      $inboxMessages = $pdo->query('SELECT * FROM inbox_messages ORDER BY id DESC LIMIT 100')->fetchAll();
    }

    if (!empty($_GET['read_id'])) {
      $rId = (int) $_GET['read_id'];
      $st = $pdo->prepare('SELECT * FROM inbox_messages WHERE id = ?');
      $st->execute([$rId]);
      $activeMsg = $st->fetch();
      if ($activeMsg) {
        mark_inbox_read($rId, true);
        $inboxStats = get_inbox_stats(); // Refresh unread count
      }
    }
  }
}

// Check provider configurations (Primary & Secondary Gateways)
$resendConfigured = !empty(get_setting('resend_api_key'));
$googleUser = get_setting('google_smtp_user', DEFAULT_FROM_EMAIL);
$googleConfigured = !empty($googleUser);
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
          <div class="lede">High-throughput mailer engineered for
            <span><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></span>
          </div>
          <ul class="points">
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
              <span>Dual Gateway Architecture: Resend Paid API + Google Workspace SMTP (6,000 / day).</span>
            </li>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
              <span>Inbound replies automatically routed to
                <strong><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong>.</span>
            </li>
            <li>
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                <path d="M20 6 9 17l-5-5" />
              </svg>
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
          <input class="input" id="ident" name="identifier" required value="<?= e($_POST['identifier'] ?? 'service@teslaenergyfoundation.com') ?>" autocomplete="username">
        </div>

        <div class="field">
          <label for="pwd">Access password</label>
          <input class="input" id="pwd" type="password" name="password" required autofocus placeholder="Enter access password" autocomplete="current-password">
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
          <div class="nav-group-label">Mailbox</div>
          <a class="nav-item <?= $view === 'inbox' ? 'active' : '' ?>" href="?view=inbox">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
              <path
                d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
            </svg>
            <span>Inbox</span>
            <?php if (!empty($inboxStats['unread'])): ?><span
                class="count"><?= $inboxStats['unread'] ?></span><?php endif; ?>
          </a>

          <a class="nav-item <?= ($view === 'sent' || $view === 'outbox') ? 'active' : '' ?>" href="?view=sent">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <line x1="22" y1="2" x2="11" y2="13"></line>
              <polygon points="22 2 15 22 11 13 2 9 22 2"></polygon>
            </svg>
            <span>Sent Mail</span>
            <?php if (!empty($stats['totalSent'])): ?><span class="badge badge-mono small"
                style="margin-left:auto;"><?= number_format($stats['totalSent']) ?></span><?php endif; ?>
          </a>

          <a class="nav-item <?= $view === 'replies' ? 'active' : '' ?>" href="?view=replies">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <polyline points="9 17 4 12 9 7" />
              <path d="M20 18v-2a4 4 0 0 0-4-4H4" />
            </svg>
            <span>Replies</span>
            <?php if (!empty($inboxStats['total'])): ?><span class="badge badge-mono small"
                style="margin-left:auto;"><?= number_format($inboxStats['total']) ?></span><?php endif; ?>
          </a>

          <a class="nav-item <?= $view === 'compose' ? 'active' : '' ?>" href="?view=compose">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M12 20h9" />
              <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
            </svg>
            <span>Compose / Send</span>
          </a>

          <div class="nav-group-label">Dispatch &amp; Campaigns</div>
          <a class="nav-item <?= $view === 'overview' ? 'active' : '' ?>" href="?view=overview">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <rect x="3" y="3" width="7" height="7" rx="1.5" />
              <rect x="14" y="3" width="7" height="7" rx="1.5" />
              <rect x="3" y="14" width="7" height="7" rx="1.5" />
              <rect x="14" y="14" width="7" height="7" rx="1.5" />
            </svg>
            <span>Overview &amp; Metrics</span>
          </a>
          <a class="nav-item <?= $view === 'campaigns' ? 'active' : '' ?>" href="?view=campaigns">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M21.5 2.5 10.5 13.5" />
              <path d="M21.5 2.5 14.5 21l-4-7.5-7.5-4 18.5-7Z" />
            </svg>
            <span>Campaign Studio (6k)</span>
            <?php if ($stats['todaySent']): ?><span class="count"><?= $stats['todaySent'] ?></span><?php endif; ?>
          </a>
          <a class="nav-item <?= $view === 'recipients' ? 'active' : '' ?>" href="?view=recipients">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <path d="M16 20v-1.5a4 4 0 0 0-4-4H7a4 4 0 0 0-4 4V20" />
              <circle cx="9.5" cy="7.5" r="3.5" />
              <path d="M21 20v-1.5a4 4 0 0 0-3-3.87" />
              <path d="M16.5 4.1a3.5 3.5 0 0 1 0 6.8" />
            </svg>
            <span>Recipients &amp; Leads</span>
          </a>

          <div class="nav-group-label">System</div>
          <a class="nav-item <?= $view === 'settings' ? 'active' : '' ?>" href="?view=settings">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="3" />
              <path
                d="M19.4 15a1.7 1.7 0 0 0 .34 1.87l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.7 1.7 0 0 0-2.87 1.2V21a2 2 0 1 1-4 0v-.1A1.7 1.7 0 0 0 7 19.4a1.7 1.7 0 0 0-1.87.34l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.7 1.7 0 0 0 3 15a1.7 1.7 0 0 0-1.55-1H1.3a2 2 0 1 1 0-4h.15A1.7 1.7 0 0 0 3 8.9a1.7 1.7 0 0 0-.34-1.87l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.7 1.7 0 0 0 7 4.6a1.7 1.7 0 0 0 1-1.55V2.9a2 2 0 1 1 4 0v.15A1.7 1.7 0 0 0 15 4.6a1.7 1.7 0 0 0 1.87-.34l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.7 1.7 0 0 0 19.4 9v.1a1.7 1.7 0 0 0 1.55 1h.15a2 2 0 1 1 0 4h-.15a1.7 1.7 0 0 0-1.55 1Z" />
            </svg>
            <span>Settings &amp; Gateways</span>
          </a>
          <a class="nav-item <?= $view === 'dns' ? 'active' : '' ?>" href="?view=dns">
            <svg class="nav-ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
              stroke-linecap="round" stroke-linejoin="round">
              <circle cx="12" cy="12" r="10" />
              <path d="M2 12h20" />
              <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" />
            </svg>
            <span>Cloudflare DNS</span>
          </a>
        </nav>

        <!-- SIDEBAR CIRCULAR COUNTER -->
        <?php
          $sentTodayCount = (int)($stats['todaySent'] ?? 0);
          $dailyCapPct = min(100, max(0, round(($sentTodayCount / 6000) * 100)));
        ?>
        <div class="nav-circular-counter">
          <svg viewBox="0 0 36 36" class="circular-chart">
            <path class="circle-bg" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
            <path class="circle-bar" stroke="#e82127" stroke-dasharray="<?= $dailyCapPct ?>, 100" d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831"/>
            <text x="18" y="20.5" class="circle-pct" text-anchor="middle" font-size="7.5" font-weight="700" fill="#ffffff"><?= $dailyCapPct ?>%</text>
          </svg>
          <div class="circular-meta">
            <span class="circular-title">Daily 6k Quota</span>
            <span class="circular-val"><?= number_format($sentTodayCount) ?> <span style="font-size:10px; color:#94a3b8; font-weight:normal;">/ 6k</span></span>
            <span class="circular-sub"><?= number_format(max(0, 6000 - $sentTodayCount)) ?> remaining</span>
          </div>
        </div>

        <!-- SIDEBAR MAIL COUNTER METER -->
        <div class="sidebar-meter-box">
          <div class="row between small">
            <span
              style="color:#94a3b8; font-weight:600; text-transform:uppercase; font-size:10px; letter-spacing:0.5px;">Mail
              Capacity</span>
            <strong class="mono" style="color:#ffffff; font-size:11.5px;"><?= number_format($stats['todaySent']) ?> /
              6,000</strong>
          </div>
          <div class="sidebar-meter-bar">
            <i style="width:<?= min(100, max(6, round(($stats['todaySent'] / 6000) * 100))) ?>%;"></i>
          </div>
          <div class="row between small" style="color:#64748b; font-size:10.5px;">
            <span><?= round(($stats['todaySent'] / 6000) * 100) ?>% Dispatched</span>
            <span style="color:#10b981; font-weight:600;">Gateways Active</span>
          </div>
        </div>

        <div class="sidebar-foot">
          <div class="user-chip">
            <div class="avatar">TE</div>
            <div class="u-meta">
              <b><?= e($currentUser['username']) ?></b>
              <span><?= e($currentUser['role']) ?></span>
            </div>
          </div>
          <a href="?logout=1" class="btn btn-sm btn-block"
            style="margin-top:12px;background:transparent;border-color:rgba(255,255,255,.16);color:#c3cede;">
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
                'overview' => 'Overview &amp; Metrics',
                'inbox' => 'Inbox &amp; Messages',
                'sent' => 'Sent Mail',
                'replies' => 'Inbound Customer Replies',
                'compose' => 'Compose / Quick Send',
                'campaigns' => 'Campaign Studio (6k Bulk)',
                'recipients' => 'Audience &amp; Recipients',
                'outbox' => 'Outbox &amp; Logs',
                'settings' => 'Settings &amp; Gateways',
                'dns' => 'Cloudflare DNS Records'
              ];
              echo $titles[$view] ?? 'Tesla Energy Mailer';
              ?>
            </h1>
            <div class="sub">
              Domain: <strong class="mono"><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></strong> &bull;
              Replies &rarr; <span
                class="badge badge-amber badge-mono"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></span>
            </div>
          </div>

          <div class="topbar-spacer"></div>

          <div class="topbar-actions">
            <a class="btn btn-sm btn-primary" href="?view=compose">
              <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                style="width:14px; height:14px; margin-right:4px;">
                <path d="M12 20h9" />
                <path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z" />
              </svg>
              Compose
            </a>
            <a class="btn btn-sm" href="?view=campaigns&new=1">
              + 6k Campaign
            </a>
          </div>
        </header>

        <!-- GLOBAL MAIL COUNTER BAR STRIP -->
        <div class="mail-counter-strip">
          <div class="counter-item">
            <span class="dot green"></span>
            <span class="lbl">Mail Capacity:</span>
            <strong class="val"><?= number_format($stats['todaySent']) ?></strong>
            <span class="sub">/ 6,000 daily limit</span>
          </div>
          <div class="counter-bar-wrap">
            <div class="counter-progress-bar">
              <i style="width:<?= min(100, max(5, round(($stats['todaySent'] / 6000) * 100))) ?>%;"></i>
            </div>
          </div>
          <div class="counter-item">
            <span class="dot red"></span>
            <span class="lbl">Inbox Unread:</span>
            <strong class="val" style="color:var(--accent);"><?= number_format($inboxStats['unread']) ?></strong>
          </div>
          <div class="counter-item">
            <span class="lbl">Total Inbound:</span>
            <strong class="val"><?= number_format($inboxStats['total']) ?></strong>
          </div>
          <div class="counter-item hide-mobile">
            <span class="lbl">All-time Delivered:</span>
            <strong class="val text-green"><?= number_format($stats['totalSent']) ?></strong>
          </div>
        </div>

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
                <div class="label">Primary Gateway</div>
                <div class="value mono"><?= number_format($stats['resendSent']) ?></div>
                <div class="delta">&bull; ~4,000/day allocation</div>
              </div>

              <div class="card stat">
                <div class="label">Secondary Gateway</div>
                <div class="value mono"><?= number_format($stats['googleSent']) ?></div>
                <div class="delta">&bull; ~2,000/day allocation</div>
              </div>
            </div>

            <!-- INBOUND MESSAGE COUNTER BAR CARD -->
            <div class="card mb-3" style="border-left:4px solid var(--accent);">
              <div class="card-body">
                <div class="row between mb-2">
                  <div class="row" style="gap:12px; align-items:center;">
                    <div class="avatar" style="width:38px; height:38px; font-size:15px; border-radius:10px;">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        style="width:18px; height:18px;">
                        <polyline points="22 12 16 12 14 15 10 15 8 12 2 12" />
                        <path
                          d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
                      </svg>
                    </div>
                    <div>
                      <div style="font-size:15.5px; font-weight:700; color:var(--ink);">Inbound Desk &amp; Message Activity
                      </div>
                      <div class="small muted">Live reply router &bull; Inbound target: <strong
                          class="mono text-amber"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong></div>
                    </div>
                  </div>
                  <div class="row" style="gap:14px; align-items:center;">
                    <div class="text-right">
                      <div class="small muted">Inbox Status</div>
                      <div style="font-size:14px; font-weight:700; color:var(--accent);"><?= $inboxStats['unread'] ?> Unread
                        Inquiries</div>
                    </div>
                    <a href="?view=inbox" class="btn btn-sm btn-primary">Open Inbox (<?= $inboxStats['unread'] ?>)
                      &rarr;</a>
                  </div>
                </div>
                <?php $inboxPct = $inboxStats['total'] > 0 ? min(100, max(15, round((($inboxStats['total'] - $inboxStats['unread']) / $inboxStats['total']) * 100))) : 100; ?>
                <div class="bar mt-2" style="height:6px; background:var(--line-soft); border-radius:3px; overflow:hidden;">
                  <i
                    style="display:block; height:100%; width:<?= $inboxPct ?>%; background:linear-gradient(90deg, var(--accent), #ff5459); border-radius:3px;"></i>
                </div>
                <div class="row between mt-2 small muted">
                  <span><strong><?= $inboxStats['total'] ?></strong> total inquiries received &bull;
                    <strong><?= $inboxStats['total'] - $inboxStats['unread'] ?></strong> processed</span>
                  <span>Response &amp; Delivery Health: <strong class="text-green"><?= $inboxPct ?>%
                      Resolved</strong></span>
                </div>
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
                      <div style="font-weight:600;color:var(--ink);">Primary Delivery Gateway</div>
                      <div class="small muted">High-speed HTTPS campaign delivery</div>
                    </div>
                    <span class="badge <?= $resendConfigured ? 'badge-green' : 'badge-amber' ?>">
                      <span class="badge-dot"></span><?= $resendConfigured ? 'ready' : 'not set' ?>
                    </span>
                  </div>

                  <div class="row between" style="padding:10px 0;border-bottom:1px solid var(--line-soft);">
                    <div>
                      <div style="font-weight:600;color:var(--ink);">Secondary Delivery Gateway</div>
                      <div class="small muted">High-volume TLS Relay &bull; <?= e($googleUser) ?></div>
                    </div>
                    <span class="badge badge-green">
                      <span class="badge-dot"></span>active
                    </span>
                  </div>

                  <div class="row between" style="padding:10px 0;">
                    <div>
                      <div style="font-weight:600;color:var(--ink);">Auto-Balance Load Router</div>
                      <div class="small muted">Split: <?= get_setting('resend_ratio', '60') ?>% Resend /
                        <?= 100 - (int) get_setting('resend_ratio', '60') ?>% Google with instant failover
                      </div>
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
                    <strong>teslaenergyfoundation.com</strong> is configured for outbound dispatch and incoming replies.
                    All recipient replies are routed directly to
                    <strong>service@teslaenergyfoundation.com</strong> via injected <span class="mono">Reply-To</span> headers.
                  </p>
                  <div class="alert mb-2">
                    <strong>High Deliverability Notice</strong>
                    SPF authorizes both Resend (`include:resend.com`) and Google Workspace (`include:_spf.google.com`) on
                    Cloudflare with DKIM alignment.
                  </div>
                  <div class="row between small muted">
                    <span>Sending: <strong
                        class="mono"><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></strong></span>
                    <span>Inbound: <strong
                        class="mono text-amber"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong></span>
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
                      <tr>
                        <td colspan="9" class="empty">No campaigns registered yet. Click &ldquo;Create Campaign&rdquo; to
                          begin.</td>
                      </tr>
                    <?php else:
                      foreach (array_slice($campaigns, 0, 10) as $c): ?>
                        <tr>
                          <td><strong><?= e($c['title']) ?></strong></td>
                          <td class="small muted truncate" style="max-width:240px;"><?= e($c['subject']) ?></td>
                          <td class="num"><?= number_format($c['total_recipients']) ?></td>
                          <td class="num" style="color:var(--green); font-weight:600;"><?= number_format($c['sent_count']) ?>
                          </td>
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

          <?php elseif ($view === 'inbox' || $view === 'replies'): ?>
            <!-- ==============================================
           VIEW: INBOX & INQUIRIES (WITH MESSAGE COUNTER BAR)
           ============================================== -->
            <div class="page-head">
              <div class="titles">
                <h1><?= $view === 'replies' ? 'Beneficiary Replies' : 'Inbox &amp; Inquiries' ?></h1>
                <p class="sub">
                  <?= $view === 'replies' ? 'Direct inbound replies from grant recipients routed via' : 'Inbound beneficiary responses and queries routed via' ?>
                  <strong class="mono"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong>.
                </p>
              </div>
              <div class="row" style="gap:8px;">
                <button type="button" class="btn btn-sm" onclick="document.getElementById('test-inbound-box').classList.toggle('hide');">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    style="width:14px; height:14px; margin-right:4px;">
                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" />
                    <polyline points="7 10 12 15 17 10" />
                    <line x1="12" y1="15" x2="12" y2="3" />
                  </svg>
                  + Simulate Test Reply
                </button>
                <a class="btn btn-sm" href="?view=inbox&action=mark_all_read">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    style="width:14px; height:14px; margin-right:4px;">
                    <polyline points="20 6 9 17 4 12" />
                  </svg>
                  Mark All Read
                </a>
                <a class="btn btn-sm btn-primary" href="?view=compose">
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                    style="width:14px; height:14px; margin-right:4px;">
                    <path d="M12 4v16M4 12h16" />
                  </svg>
                  Compose Reply
                </a>
              </div>
            </div>

            <!-- TEST INBOUND REPLY SIMULATION BOX -->
            <div id="test-inbound-box" class="card mb-3 hide" style="border: 2px dashed var(--accent); background:rgba(232,33,39,0.04);">
              <div class="card-head">
                <h2>Simulate Inbound Beneficiary Reply</h2>
                <div class="spacer"></div>
                <button type="button" class="btn btn-sm btn-ghost" onclick="document.getElementById('test-inbound-box').classList.add('hide');">&times; Close</button>
              </div>
              <form method="post" action="<?= $self ?>" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="simulate_inbound_reply">
                <div class="row-3">
                  <div class="field">
                    <label>Sender Name</label>
                    <input class="input" name="test_name" value="Marcus Vance" required>
                  </div>
                  <div class="field">
                    <label>Sender Email</label>
                    <input class="input" name="test_sender" type="email" value="marcus.vance@gmail.com" required>
                  </div>
                  <div class="field">
                    <label>Subject</label>
                    <input class="input" name="test_subject" value="Re: Clean Energy Allocation Notice #TE-9821" required>
                  </div>
                </div>
                <div class="field">
                  <label>Message Content</label>
                  <textarea class="textarea" name="test_body" rows="3" required>Hello Tesla Energy Foundation, I received your clean energy grant notification and would like to confirm my allocation documents.</textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm">Receive Inbound Test Message</button>
              </form>
            </div>

            <!-- EXECUTIVE MESSAGE COUNTER BAR -->
            <div class="card mb-3" style="border-left:4px solid var(--accent);">
              <div class="card-body">
                <div class="row between mb-2">
                  <div>
                    <div class="small text-muted font-semibold uppercase" style="letter-spacing:0.5px;">Live Inbound &amp; Dispatch Activity &bull; Message Counter Bar</div>
                    <div style="font-size:18px; font-weight:700; color:var(--ink); margin-top:2px;">
                      <span style="color:var(--accent);"><?= number_format($inboxStats['unread']) ?> Unread Inquiries</span>
                      <span class="muted" style="font-weight:400;">&bull; <?= number_format($inboxStats['total']) ?> Total Replies</span>
                      <span class="muted" style="font-weight:400;">&bull; <strong class="mono" style="color:#10b981;"><?= number_format($stats['todaySent']) ?> / 6,000</strong> Dispatched Today</span>
                    </div>
                  </div>
                  <div class="row" style="gap:16px; align-items:center;">
                    <div class="text-right">
                      <div class="small muted">Inbound Router</div>
                      <div class="mono small font-semibold" style="color:var(--ink);">
                        <?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>
                      </div>
                    </div>
                    <div class="text-right">
                      <div class="small muted">Resolution Rate</div>
                      <div class="small font-semibold text-green">
                        <?= $inboxStats['total'] > 0 ? round((($inboxStats['total'] - $inboxStats['unread']) / $inboxStats['total']) * 100) : 100 ?>%
                        Active
                      </div>
                    </div>
                  </div>
                </div>

                <!-- The Visual Message Counter Progress Bar -->
                <?php
                $resolutionPct = $inboxStats['total'] > 0 ? min(100, max(15, round((($inboxStats['total'] - $inboxStats['unread']) / $inboxStats['total']) * 100))) : 100;
                ?>
                <div class="bar"
                  style="height:7px; background:var(--line-soft); border-radius:4px; overflow:hidden; position:relative;">
                  <i
                    style="display:block; height:100%; width:<?= $resolutionPct ?>%; background:linear-gradient(90deg, var(--accent), #ff5459); border-radius:4px; transition:width 0.4s ease;"></i>
                </div>

                <div class="row between mt-2 small muted">
                  <span>Volume Indicator: <strong><?= $inboxStats['total'] - $inboxStats['unread'] ?> processed</strong> /
                    <?= $inboxStats['total'] ?> total inquiries</span>
                  <span>Capacity Health: <span class="badge badge-green" style="font-size:11px; padding:1px 6px;"><span
                        class="badge-dot"></span>100% operational</span></span>
                </div>
              </div>
            </div>

            <!-- Active Message Preview (if opened) -->
            <?php if ($activeMsg): ?>
              <div class="card mb-3" style="border:1.5px solid var(--accent); background:var(--surface);">
                <div class="card-head" style="background:var(--surface-2);">
                  <div>
                    <span class="badge badge-mono small"
                      style="margin-right:8px;"><?= e(strtoupper($activeMsg['category'])) ?></span>
                    <strong style="font-size:15px;"><?= e($activeMsg['subject']) ?></strong>
                  </div>
                  <div class="spacer"></div>
                  <div class="row" style="gap:6px;">
                    <a href="?view=compose&to=<?= urlencode($activeMsg['from_email']) ?>&subject=<?= urlencode('Re: ' . preg_replace('/^Re:\s*/i', '', $activeMsg['subject'])) ?>"
                      class="btn btn-sm btn-primary">
                      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                        style="width:13px; height:13px; margin-right:4px;">
                        <polyline points="9 17 4 12 9 7" />
                        <path d="M20 18v-2a4 4 0 0 0-4-4H4" />
                      </svg>
                      Reply
                    </a>
                    <a href="?view=inbox" class="btn btn-sm btn-ghost">&times; Close</a>
                  </div>
                </div>
                <div class="card-body">
                  <div class="row between mb-3 pb-2" style="border-bottom:1px solid var(--line-soft); font-size:13px;">
                    <div>
                      <div><strong>From:</strong> <?= e($activeMsg['from_name']) ?> &lt;<span
                          class="mono text-muted"><?= e($activeMsg['from_email']) ?></span>&gt;</div>
                      <div class="small muted mt-1"><strong>To:</strong> <?= e($activeMsg['to_email']) ?></div>
                    </div>
                    <div class="text-right small muted">
                      <div><?= date('F j, Y &bull; H:i', strtotime($activeMsg['created_at'])) ?></div>
                      <div class="mono small mt-1"><?= e($activeMsg['message_id']) ?></div>
                    </div>
                  </div>

                  <div style="font-size:14.5px; line-height:1.7; color:var(--ink); padding:10px 0;">
                    <?php if (!empty($activeMsg['body_html'])): ?>
                      <?= $activeMsg['body_html'] ?>
                    <?php else: ?>
                      <pre
                        style="font-family:var(--sans); white-space:pre-wrap;"><?= e($activeMsg['body_text'] ?: $activeMsg['snippet']) ?></pre>
                    <?php endif; ?>
                  </div>

                  <div class="row between mt-3 pt-2" style="border-top:1px solid var(--line-soft);">
                    <div class="small muted">Routed automatically to inbound recipient inbox.</div>
                    <div class="row" style="gap:8px;">
                      <a href="?view=compose&to=<?= urlencode($activeMsg['from_email']) ?>&subject=<?= urlencode('Re: ' . preg_replace('/^Re:\s*/i', '', $activeMsg['subject'])) ?>"
                        class="btn btn-sm btn-primary">Reply to <?= e($activeMsg['from_name']) ?></a>
                      <a href="?view=inbox&action=delete&id=<?= $activeMsg['id'] ?>" class="btn btn-sm btn-danger"
                        onclick="return confirm('Delete this message?');">Delete</a>
                    </div>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <!-- Inbox List Table -->
            <div class="card">
              <div class="card-head">
                <div class="row" style="gap:8px; align-items:center;">
                  <h2>Messages &amp; Inquiries</h2>
                  <div class="row ml-2" style="gap:4px;">
                    <?php $curFilter = $_GET['filter'] ?? 'all'; ?>
                    <a href="?view=inbox&filter=all" class="pill <?= $curFilter === 'all' ? 'active' : '' ?>">All
                      (<?= $inboxStats['total'] ?>)</a>
                    <a href="?view=inbox&filter=unread" class="pill <?= $curFilter === 'unread' ? 'active' : '' ?>">Unread
                      (<?= $inboxStats['unread'] ?>)</a>
                    <a href="?view=inbox&filter=replies"
                      class="pill <?= $curFilter === 'replies' ? 'active' : '' ?>">Replies</a>
                    <a href="?view=inbox&filter=grants" class="pill <?= $curFilter === 'grants' ? 'active' : '' ?>">Grant
                      Inquiries</a>
                  </div>
                </div>
                <div class="spacer"></div>
                <div class="row" style="gap:8px;">
                  <a href="?view=inbox" class="btn btn-sm btn-ghost">Refresh</a>
                  <form method="post" action="<?= $self ?>" onsubmit="return confirm('Clear all inbox messages?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="clear_inbox">
                    <button type="submit" class="btn btn-sm btn-ghost" style="color:var(--muted);">&times; Clear</button>
                  </form>
                </div>
              </div>

              <div class="table-wrap">
                <table class="data">
                  <thead>
                    <tr>
                      <th style="width:40px;"></th>
                      <th>Sender</th>
                      <th>Subject &amp; Snippet</th>
                      <th>Category</th>
                      <th>Received</th>
                      <th style="text-align:right;">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($inboxMessages)): ?>
                      <tr>
                        <td colspan="6" class="empty">No inquiries or messages match this filter.</td>
                      </tr>
                    <?php else:
                      foreach ($inboxMessages as $m): ?>
                        <tr style="<?= $m['is_read'] ? '' : 'background:rgba(232,33,39,0.03);' ?>">
                          <td style="text-align:center;">
                            <?php if (!$m['is_read']): ?>
                              <span
                                style="display:inline-block; width:8px; height:8px; border-radius:50%; background:var(--accent);"
                                title="Unread"></span>
                            <?php else: ?>
                              <span
                                style="display:inline-block; width:6px; height:6px; border-radius:50%; background:var(--line);"
                                title="Read"></span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <div style="font-weight:<?= $m['is_read'] ? '500' : '700' ?>; color:var(--ink);">
                              <?= e($m['from_name']) ?>
                            </div>
                            <div class="small muted truncate" style="max-width:180px;"><?= e($m['from_email']) ?></div>
                          </td>
                          <td>
                            <a href="?view=inbox&read_id=<?= $m['id'] ?>" style="text-decoration:none; color:inherit;">
                              <div style="font-weight:<?= $m['is_read'] ? '500' : '700' ?>; color:var(--ink);">
                                <?= e($m['subject']) ?>
                              </div>
                              <div class="small muted truncate" style="max-width:380px;"><?= e($m['snippet']) ?></div>
                            </a>
                          </td>
                          <td>
                            <span class="badge badge-mono small"><?= e($m['category']) ?></span>
                          </td>
                          <td class="small muted" style="white-space:nowrap;">
                            <?= date('M j, H:i', strtotime($m['created_at'])) ?>
                          </td>
                          <td style="text-align:right; white-space:nowrap;">
                            <a href="?view=inbox&read_id=<?= $m['id'] ?>" class="btn btn-sm">Read &rarr;</a>
                            <a href="?view=compose&to=<?= urlencode($m['from_email']) ?>&subject=<?= urlencode('Re: ' . preg_replace('/^Re:\s*/i', '', $m['subject'])) ?>"
                              class="btn btn-sm btn-ghost" title="Reply">Reply</a>
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

            <?php if (is_daily_quota_reached()): ?>
              <div class="card mb-3" style="border: 2px solid #ef4444; background: rgba(239, 68, 68, 0.1);">
                <div class="card-body" style="display:flex; align-items:center; gap:16px;">
                  <span style="font-size:2rem;">🛑</span>
                  <div>
                    <h3 style="margin:0 0 4px 0; color:#ef4444;">Daily Sending Cap Reached (6,000 / 6,000)</h3>
                    <p style="margin:0; font-size:13px; color:var(--text-muted);">Today's volume quota of 6,000 sent emails
                      has been reached. Outbound bulk dispatch is locked until tomorrow 00:00 UTC to maintain domain health
                      and sender reputation.</p>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <!-- Live Dispatch Queue Box (Shown if a campaign is actively launched) -->
            <div id="queue-box" class="card mb-3 <?= isset($_GET['launch_id']) ? '' : 'hide' ?>"
              style="border:2px solid var(--accent);">
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
                <div id="queue-terminal" class="textarea mono small"
                  style="height:150px; background:#0e0607; color:#fca5a5; border:1px solid #331518; overflow-y:auto; padding:10px;">
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
                    <div class="card-head">
                      <h2>Basics &amp; Headers</h2>
                    </div>
                    <div class="card-body">
                      <div class="field">
                        <label for="name">Campaign name</label>
                        <input class="input" id="name" name="name" required
                          placeholder="e.g. Clean Energy Allocation Notice #1">
                      </div>

                      <div class="row-2">
                        <div class="field">
                          <label for="from_name">From name</label>
                          <input class="input" id="from_name" name="sender_name"
                            value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>">
                        </div>
                        <div class="field">
                          <label for="from_email">From email</label>
                          <input class="input mono" id="from_email" name="sender_email"
                            value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>">
                        </div>
                      </div>

                      <div class="row-2">
                        <div class="field">
                          <label for="subject">Subject line</label>
                          <input class="input" id="subject" name="subject" required
                            placeholder="OFFICIAL ALLOCATION: Tesla Energy Foundation Grant [Ref: {{ref_id}}]">
                        </div>
                        <div class="field">
                          <label for="reply_to">Reply-To (Inbound replies)</label>
                          <input class="input mono" id="reply_to" name="reply_to"
                            value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>">
                        </div>
                      </div>

                      <div class="row-2">
                        <div class="field">
                          <label for="cc_addr">CC <span class="muted small">(comma-separated, optional)</span></label>
                          <input class="input mono" id="cc_addr" name="cc_address"
                            placeholder="cc1@example.com, cc2@gmail.com">
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
                      <button type="button" class="btn btn-sm btn-ghost" onclick="insertSampleLeads()">+ Insert 25 sample
                        leads</button>
                    </div>
                    <div class="card-body">
                      <div class="field">
                        <label for="recipients">Paste recipient emails (one per line, supports CSV: <span
                            class="mono">email,name,ref_id,grant_amount</span>)</label>
                        <textarea class="textarea mono" id="recipients" name="recipients" rows="8"
                          oninput="updateRecipientCount()"
                          placeholder="john.doe@example.com&#10;sarah.connor@gmail.com,Sarah Connor,TEF-8921,$75,000"></textarea>
                        <div class="hint" id="recipient-count-label">0 recipient addresses loaded &bull; Duplicates
                          automatically filtered</div>
                      </div>
                    </div>
                  </div>

                  <!-- Content & HTML -->
                  <div class="card">
                    <div class="card-head">
                      <h2>Email Content</h2>
                      <div class="spacer"></div>
                      <select class="select" id="templatePicker" style="max-width:240px;"
                        onchange="loadTemplate(this.value)">
                        <option value="">â€” Load pre-built template â€”</option>
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
                        <button type="button" class="pill"
                          onclick="insertToken('{{grant_amount}}')">{{grant_amount}}</button>
                        <button type="button" class="pill" onclick="insertToken('{{date}}')">{{date}}</button>
                      </div>
                      <div class="field">
                        <label for="htmlBody">HTML body</label>
                        <textarea class="textarea mono" id="htmlBody" name="html" rows="16" required
                          oninput="updatePreview()"></textarea>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Right Column: Settings & Launch -->
                <div class="stack">
                  <!-- Gateway Strategy -->
                  <div class="card">
                    <div class="card-head">
                      <h2>Delivery Gateway</h2>
                    </div>
                    <div class="card-body">
                      <div class="field">
                        <label>Dispatch Mode</label>
                        <div class="stack" style="gap:8px;">
                          <label class="check">
                            <input type="radio" name="provider_mode" value="auto_balance" checked>
                            <span>
                              <strong>Auto-Balance (Recommended)</strong>
                              <div class="small muted">Balances <?= get_setting('resend_ratio', '60') ?>% Resend +
                                <?= 100 - (int) get_setting('resend_ratio', '60') ?>% Google with instant failover
                              </div>
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
                    <div class="card-head">
                      <h2>Visual Preview</h2>
                    </div>
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
                <h1>Compose &amp; Direct Dispatch</h1>
                <p class="sub">Send single or multi-recipient messages with automatic 6,000/day volume tracking.</p>
              </div>
            </div>

            <!-- COMPOSE DISPATCH METER & COUNTER -->
            <div class="card mb-3" style="max-width:760px; border-left:4px solid var(--accent); background:linear-gradient(135deg, rgba(232, 33, 39, 0.08) 0%, var(--surface-2) 100%);">
              <div class="card-body" style="padding:16px 20px;">
                <div class="row between" style="align-items:center; flex-wrap:wrap; gap:16px;">
                  <div style="display:flex; align-items:center; gap:14px;">
                    <div style="width:42px; height:42px; border-radius:10px; background:rgba(232, 33, 39, 0.15); display:flex; align-items:center; justify-content:center; color:var(--accent); font-size:20px;">
                      ✉️
                    </div>
                    <div>
                      <div style="font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:0.5px; color:#94a3b8;">Compose Dispatch Meter</div>
                      <div style="font-size:18px; font-weight:700; font-family:var(--font-mono); color:#ffffff;">
                        <?= number_format($stats['todaySent']) ?> <span style="font-size:13px; color:#94a3b8; font-weight:normal;">/ 6,000 sent today</span>
                      </div>
                    </div>
                  </div>
                  <div style="display:flex; align-items:center; gap:18px;">
                    <div style="text-align:right;">
                      <div style="font-size:11px; color:#94a3b8; font-weight:600;">Daily Capacity</div>
                      <div class="mono" style="font-size:15px; font-weight:700; color:#10b981;"><?= number_format(max(0, 6000 - $stats['todaySent'])) ?> available</div>
                    </div>
                    <div style="min-width:130px;">
                      <div style="display:flex; justify-content:space-between; font-size:11px; color:#94a3b8; margin-bottom:4px;">
                        <span><?= round(($stats['todaySent'] / 6000) * 100) ?>% Used</span>
                        <span>6k Cap</span>
                      </div>
                      <div class="counter-progress-bar" style="height:7px;">
                        <i style="width:<?= min(100, max(4, round(($stats['todaySent'] / 6000) * 100))) ?>%;"></i>
                      </div>
                    </div>
                  </div>
                </div>
                <div class="mt-2 pt-2 row between small" style="border-top:1px solid rgba(255,255,255,0.06); color:#94a3b8; font-size:11.5px;">
                  <span><strong>Sender:</strong> <span class="mono text-amber">service@teslaenergyfoundation.com</span></span>
                  <span><strong>Reply-To:</strong> <span class="mono text-green">service@teslaenergyfoundation.com</span></span>
                </div>
              </div>
            </div>

            <?php if (is_daily_quota_reached()): ?>
              <div class="card mb-3" style="border: 2px solid #ef4444; background: rgba(239, 68, 68, 0.1); max-width:760px;">
                <div class="card-body" style="display:flex; align-items:center; gap:16px;">
                  <span style="font-size:2rem;">🛑</span>
                  <div>
                    <h3 style="margin:0 0 4px 0; color:#ef4444;">Daily Sending Limit Reached (6,000 / 6,000)</h3>
                    <p style="margin:0; font-size:13px; color:var(--text-muted);">Today's volume quota of 6,000 sent emails
                      has been reached. Dispatches are paused until tomorrow to preserve domain reputation.</p>
                  </div>
                </div>
              </div>
            <?php endif; ?>

            <div class="card" style="max-width:760px;">
              <div class="card-head">
                <h2>Direct Dispatch</h2>
              </div>
              <form method="post" action="<?= $self ?>" class="card-body">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="send_quick">

                <div class="row-2">
                  <div class="field">
                    <label for="q_to">Recipient email(s) (To) <span class="muted small">(supports multiple comma-separated emails, or leave blank if using CC/BCC)</span></label>
                    <input class="input mono" id="q_to" name="to" type="text"
                      placeholder="recipient1@gmail.com, recipient2@yahoo.com (or blank if CC/BCC)"
                      value="<?= e($_GET['to'] ?? '') ?>">
                    <div class="hint">Sending to multiple recipients counts each delivered address accurately against your 6,000 daily limit.</div>
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
                  <input class="input" id="q_subject" name="subject" required
                    value="<?= e($_GET['subject'] ?? 'Tesla Energy Foundation • Notification Verification') ?>">
                </div>

                <div class="field">
                  <label for="q_html">HTML Message Content</label>
                  <textarea class="textarea mono" id="q_html" name="html" rows="10"
                    placeholder="<p>Write or paste HTML message content here...</p>"></textarea>
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
                  Recipients can be pasted or imported directly in the <strong>Campaign Studio</strong> with full column
                  mapping for custom variables (<span class="mono">{{name}}</span>, <span class="mono">{{ref_id}}</span>,
                  <span class="mono">{{grant_amount}}</span>).
                </p>
                <div class="row">
                  <a href="?view=campaigns&new=1" class="btn btn-sm">Open Campaign Studio</a>
                </div>
              </div>
            </div>

          <?php elseif ($view === 'outbox' || $view === 'sent'): ?>
            <!-- ==============================================
           VIEW: SENT MAIL & OUTBOX LOGS
           ============================================== -->
            <div class="page-head">
              <div class="titles">
                <h1><?= $view === 'sent' ? 'Sent Mail' : 'Outbox &amp; Logs' ?></h1>
                <p class="sub">
                  <?= $view === 'sent' ? 'All successfully dispatched outgoing messages with verified delivery confirmation IDs.' : 'Complete historical record of dispatched messages with provider reference IDs.' ?>
                </p>
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
                      <th>To</th>
                      <th>CC / BCC</th>
                      <th>Subject</th>
                      <th>Message ID</th>
                      <th>Timestamp</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($emailLogs)): ?>
                      <tr>
                        <td colspan="6" class="empty">No emails logged in outbox yet.</td>
                      </tr>
                    <?php else:
                      foreach ($emailLogs as $log): ?>
                        <tr>
                          <td>
                            <span class="badge <?= $log['status'] === 'sent' ? 'badge-green' : 'badge-red' ?>">
                              <span class="badge-dot"></span><?= $log['status'] === 'sent' ? 'delivered' : 'failed' ?>
                            </span>
                          </td>
                          <td><strong><?= e($log['to_address']) ?></strong></td>
                          <td class="small muted"><?php
                          $ccbcc = [];
                          if (!empty($log['cc_address']))
                            $ccbcc[] = 'CC: ' . $log['cc_address'];
                          if (!empty($log['bcc_address']))
                            $ccbcc[] = 'BCC: ' . $log['bcc_address'];
                          echo $ccbcc ? e(implode(' | ', $ccbcc)) : 'â€”';
                          ?></td>
                          <td class="small muted truncate" style="max-width:280px;"><?= e($log['subject']) ?></td>
                          <td class="num small muted"><?= e($log['message_id'] ?: 'â€”') ?></td>
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
                      <div class="small muted">Primary Gateway</div>
                    </div>
                    <span class="badge <?= $resendConfigured ? 'badge-green' : 'badge-amber' ?>">
                      <span class="badge-dot"></span><?= $resendConfigured ? 'configured' : 'not set' ?>
                    </span>
                  </div>

                  <div class="row between" style="padding:9px 0;">
                    <div>
                      <div style="font-weight:600;color:var(--ink);">Mailbox &amp; Secondary Gateway</div>
                      <div class="small muted">TLS Failover Relay &middot; <span class="mono"><?= e($googleUser) ?></span>
                      </div>
                    </div>
                    <span class="badge badge-green">
                      <span class="badge-dot"></span>active
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
                <div class="card-head">
                  <h2>Cloudflare Email Routing</h2>
                </div>
                <div class="card-body">
                  <p class="small muted mb-2">
                    Cloudflare Email Routing is <strong>forward-only</strong> infrastructure:
                    emails sent by recipients to <strong>teslaenergyfoundation.com</strong> are cleanly directed to your
                    receiving mailbox at <strong><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong>.
                  </p>
                  <div class="alert">
                    <strong>Unified Email Architecture</strong>
                    Dispatches and inbound recipient replies are unified on <strong>service@teslaenergyfoundation.com</strong>.
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
                  <div class="card-head">
                    <h2>Sender Identity &amp; Routing</h2>
                  </div>
                  <div class="card-body">
                    <div class="field">
                      <label for="s_domain">Sending Domain</label>
                      <input class="input mono" id="s_domain" name="sending_domain"
                        value="<?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?>" required>
                      <div class="hint">Configured on Cloudflare for SPF, DKIM, and DMARC.</div>
                    </div>

                    <div class="field">
                      <label for="s_reply">Inbound Reply-To Target</label>
                      <input class="input mono" id="s_reply" name="reply_to_email"
                        value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>" required>
                      <div class="hint">Where recipient replies are automatically routed.</div>
                    </div>

                    <div class="row-2">
                      <div class="field">
                        <label for="s_from_name">Default From Name</label>
                        <input class="input" id="s_from_name" name="default_from_name"
                          value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>" required>
                      </div>
                      <div class="field">
                        <label for="s_from_email">Default From Email</label>
                        <input class="input mono" id="s_from_email" name="default_from_email"
                          value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>" required>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Panel 2: Delivery Provider (Resend Paid API) -->
                <div class="card">
                  <div class="card-head">
                    <h2>Resend Paid API Gateway</h2>
                  </div>
                  <div class="card-body">
                    <div class="field">
                      <label class="check mb-2">
                        <input type="checkbox" name="resend_enabled" value="1" <?= get_setting('resend_enabled', '1') === '1' ? 'checked' : '' ?>>
                        <span>Enable Resend API Gateway</span>
                      </label>
                    </div>

                    <div class="field">
                      <label for="resend_key">Resend API Key (<span class="mono">re_...</span>)</label>
                      <input class="input mono" id="resend_key" type="password" name="resend_api_key"
                        value="<?= e(get_setting('resend_api_key', '')) ?>" placeholder="re_xxxxxxxxxxxxxxxxxxxxxx">
                    </div>

                    <div class="field">
                      <label for="resend_limit">Daily Quota Limit</label>
                      <input class="input mono" id="resend_limit" name="resend_daily_limit"
                        value="<?= e(get_setting('resend_daily_limit', '5000')) ?>">
                    </div>
                  </div>
                </div>

                <!-- Panel 3: Google Workspace Paid SMTP -->
                <div class="card">
                  <div class="card-head">
                    <h2>Google Workspace SMTP Gateway</h2>
                  </div>
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
                        <input class="input mono" id="g_host" name="google_smtp_host"
                          value="<?= e(get_setting('google_smtp_host', 'smtp.gmail.com')) ?>">
                      </div>
                      <div class="field">
                        <label for="g_port">Port</label>
                        <input class="input mono" id="g_port" name="google_smtp_port"
                          value="<?= e(get_setting('google_smtp_port', '465')) ?>">
                      </div>
                    </div>

                    <div class="row-2">
                      <div class="field">
                        <label for="g_user">Workspace Account Email</label>
                        <input class="input mono" id="g_user" name="google_smtp_user"
                          value="<?= e(get_setting('google_smtp_user', '')) ?>"
                          placeholder="service@teslaenergyfoundation.com">
                      </div>
                      <div class="field">
                        <label for="g_pass">16-Character App Password</label>
                        <input class="input mono" id="g_pass" type="password" name="google_smtp_pass"
                          placeholder="<?= get_setting('google_smtp_pass') ? 'â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢â€¢' : 'Enter App Password' ?>">
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Panel 4: Dual Balancing & Throttling Rules -->
                <div class="card">
                  <div class="card-head">
                    <h2>Balancing &amp; Rate Throttling</h2>
                  </div>
                  <div class="card-body">
                    <div class="field">
                      <label for="p_mode">Default Provider Mode</label>
                      <select class="select" id="p_mode" name="provider_mode">
                        <option value="auto_balance" <?= get_setting('provider_mode') === 'auto_balance' ? 'selected' : '' ?>>
                          Auto-Balance (Dual Gateway)</option>
                        <option value="resend_only" <?= get_setting('provider_mode') === 'resend_only' ? 'selected' : '' ?>>
                          Resend API Only</option>
                        <option value="google_only" <?= get_setting('provider_mode') === 'google_only' ? 'selected' : '' ?>>
                          Google Workspace Only</option>
                        <option value="failover" <?= get_setting('provider_mode') === 'failover' ? 'selected' : '' ?>>Primary
                          Resend with Failover</option>
                      </select>
                    </div>

                    <div class="field">
                      <label>Split Ratio: <strong id="ratio-label"><?= get_setting('resend_ratio', '60') ?>% Resend /
                          <?= 100 - (int) get_setting('resend_ratio', '60') ?>% Google</strong></label>
                      <input type="range" name="resend_ratio" min="10" max="90"
                        value="<?= (int) get_setting('resend_ratio', '60') ?>"
                        oninput="document.getElementById('ratio-label').innerText = this.value + '% Resend / ' + (100 - this.value) + '% Google'"
                        style="width:100%; accent-color:var(--accent);">
                    </div>

                    <div class="row-2">
                      <div class="field">
                        <label for="b_size">Batch Chunk Size</label>
                        <input class="input mono" id="b_size" name="batch_size"
                          value="<?= e(get_setting('batch_size', '50')) ?>">
                      </div>
                      <div class="field">
                        <label for="e_delay">Delay between emails (ms)</label>
                        <input class="input mono" id="e_delay" name="delay_between_emails_ms"
                          value="<?= e(get_setting('delay_between_emails_ms', '150')) ?>">
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
              <div class="card-head">
                <h2>Account Security</h2>
              </div>
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

              <div class="row-2">
                <div class="field">
                  <label for="g_user">Workspace Account Email</label>
                  <input class="input mono" id="g_user" name="google_smtp_user" value="<?= e(get_setting('google_smtp_user', 'service@teslaenergyfoundation.com')) ?>">
                </div>
                <div class="field">
                  <label for="g_pass">16-Character App Password</label>
                  <input class="input mono" id="g_pass" type="password" name="google_smtp_pass" placeholder="<?= get_setting('google_smtp_pass') ? '••••••••••••••••' : 'Enter App Password' ?>">
                </div>
              </div>
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
                <a class="btn btn-sm btn-primary" href="https://dash.cloudflare.com" target="_blank">Open Cloudflare
                  &rarr;</a>
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
          lbl.innerText = lines.length + ' recipient addresses loaded â€¢ Duplicates automatically filtered';
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
            resEl.innerText = 'âœ“ ' + (data.message || 'Connected successfully!');
          } else {
            resEl.className = 'small mt-2' + ' text-red';
            resEl.innerText = 'âœ— ' + (data.error || 'Connection failed.');
          }
        } catch (e) {
          resEl.className = 'small mt-2 text-red';
          resEl.innerText = 'âœ— Network error: ' + e.message;
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
        const LAUNCH_ID = <?= (int) $_GET['launch_id'] ?>;
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
            fd.append('batch_size', <?= (int) get_setting('batch_size', '50') ?>);

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
                    logQueue(`âœ“ [${item.provider}] ${item.email}`);
                  } else {
                    logQueue(`âœ— [${item.provider}] ${item.email}: ${item.error}`);
                  }
                }
              }

              if (data.completed || rem === 0) {
                logQueue(`âœ“âœ“ Complete: All ${dispatched} recipients processed successfully.`);
                break;
              }

              await new Promise(r => setTimeout(r, <?= (int) get_setting('delay_between_batches_ms', '1200') ?>));
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