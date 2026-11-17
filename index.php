<?php
/**
 * Tesla Energy Foundation — Enterprise Mailer Hub
 * High-Volume Dual-Gateway Bulk Sending Engine (Resend API + Google Workspace SMTP)
 */

require_once __DIR__ . '/includes/functions.php';

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

$pdo = db();
$error = '';
$self = 'index.php';

// ----------------------------------------------------
// 1. AUTHENTICATION CONTROLLER
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
        $error = 'Invalid credentials. Please verify your access details.';
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
// 2. DISPATCH & MANAGEMENT ACTIONS
// ----------------------------------------------------
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    // Create & Register a Campaign for Dispatch
    if ($act === 'create_campaign') {
        $title = clean($_POST['title'] ?? '', 150) ?: ('Dispatch Run ' . date('Y-m-d H:i'));
        $subject = clean($_POST['subject'] ?? '', 255);
        $senderName = clean($_POST['sender_name'] ?? '', 120) ?: get_setting('default_from_name', DEFAULT_FROM_NAME);
        $senderEmail = clean($_POST['sender_email'] ?? '', 120) ?: get_setting('default_from_email', DEFAULT_FROM_EMAIL);
        $replyTo = clean($_POST['reply_to'] ?? '', 120) ?: get_setting('reply_to_email', DEFAULT_REPLY_TO);
        $providerMode = clean($_POST['provider_mode'] ?? 'auto_balance', 40);
        $bodyHtml = (string)($_POST['body_html'] ?? '');
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
            flash('error', 'Subject line and HTML message body are required.');
        } elseif (empty($validList)) {
            flash('error', 'No valid email addresses found in the recipient list.');
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('INSERT INTO campaigns (title, subject, sender_name, sender_email, reply_to, body_html, total_recipients, provider_mode, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "ready")');
                $st->execute([$title, $subject, $senderName, $senderEmail, $replyTo, $bodyHtml, count($validList), $providerMode]);
                $campId = (int)$pdo->lastInsertId();

                $insRecip = $pdo->prepare('INSERT INTO recipients (campaign_id, email, name, variables_json, status) VALUES (?, ?, ?, ?, "pending")');
                foreach ($validList as $v) {
                    $insRecip->execute([$campId, $v['email'], $v['name'], json_encode($v['variables'])]);
                }
                $pdo->commit();

                header("Location: {$self}?view=mailer&launch_id={$campId}");
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Failed to initialize campaign: ' . $e->getMessage());
            }
        }
    }

    // Direct Test Send (1-to-1)
    if ($act === 'test_send') {
        $to = clean($_POST['test_to'] ?? '', 120);
        $subject = clean($_POST['test_subject'] ?? '', 200) ?: ('[Test] Verification Notice — ' . date('H:i:s'));
        $bodyHtml = (string)($_POST['test_body'] ?? '');
        $provider = clean($_POST['test_provider'] ?? 'auto_balance', 40);

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid recipient email address.');
        } else {
            $content = $bodyHtml ?: '<div style="font-family:Arial,sans-serif;padding:20px;border:1px solid #e2e8f0;border-radius:8px;"><h2>Tesla Energy Foundation</h2><p>This is a live test dispatch from your dual-gateway mailer.</p><p><strong>Reply-To:</strong> ' . htmlspecialchars(get_setting('reply_to_email', DEFAULT_REPLY_TO)) . '</p></div>';
            $res = send_dual_email($to, $subject, $content, strip_tags($content), '', '', '', $provider);
            if (!empty($res['ok'])) {
                flash('success', "Live test delivered to {$to} via {$res['provider']}! (ID: {$res['id']})");
            } else {
                flash('error', "Delivery failed: " . ($res['error'] ?: 'Check SMTP / API credentials.'));
            }
        }
        header("Location: {$self}?view=" . ($_GET['view'] ?? 'mailer'));
        exit;
    }

    // Save Settings
    if ($act === 'save_settings') {
        set_setting('sending_domain', clean($_POST['sending_domain'] ?? '', 120) ?: SENDING_DOMAIN);
        set_setting('reply_to_email', clean($_POST['reply_to_email'] ?? '', 120) ?: DEFAULT_REPLY_TO);
        set_setting('default_from_name', clean($_POST['default_from_name'] ?? '', 120) ?: DEFAULT_FROM_NAME);
        set_setting('default_from_email', clean($_POST['default_from_email'] ?? '', 120) ?: DEFAULT_FROM_EMAIL);

        // Resend
        set_setting('resend_api_key', trim((string)($_POST['resend_api_key'] ?? '')));

        // Google Workspace SMTP
        set_setting('google_smtp_host', clean($_POST['google_smtp_host'] ?? '', 120) ?: 'smtp.gmail.com');
        set_setting('google_smtp_port', clean($_POST['google_smtp_port'] ?? '', 10) ?: '465');
        set_setting('google_smtp_user', clean($_POST['google_smtp_user'] ?? '', 120));
        
        $newPass = trim((string)($_POST['google_smtp_pass'] ?? ''));
        if ($newPass !== '') {
            set_setting('google_smtp_pass', $newPass);
        }

        // Ratios
        set_setting('provider_mode', clean($_POST['provider_mode'] ?? 'auto_balance', 40));
        set_setting('resend_ratio', (string)max(10, min(90, (int)($_POST['resend_ratio'] ?? 60))));

        flash('success', 'Mailer configuration updated successfully.');
        header("Location: {$self}?view=settings");
        exit;
    }

    // Clear Logs
    if ($act === 'clear_logs') {
        $pdo->exec('DELETE FROM email_logs');
        flash('success', 'Outbox log cleared.');
        header("Location: {$self}?view=outbox");
        exit;
    }
}

// ----------------------------------------------------
// 3. VIEW CONTROLLER & DATA BINDING
// ----------------------------------------------------
$view = $_GET['view'] ?? 'mailer';
$flash = get_flash();

$stats = ['totalSent' => 0, 'todaySent' => 0, 'resendSent' => 0, 'googleSent' => 0, 'totalFailed' => 0];
$templates = [];
$emailLogs = [];

if ($authed) {
    $todayCond = is_mysql() ? "DATE(created_at) = CURDATE()" : "date(created_at) = date('now')";
    $stats['totalSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent'")->fetchColumn();
    $stats['todaySent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND {$todayCond}")->fetchColumn();
    $stats['resendSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'resend'")->fetchColumn();
    $stats['googleSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'google_workspace'")->fetchColumn();
    $stats['totalFailed'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'")->fetchColumn();

    $templates = $pdo->query('SELECT * FROM templates ORDER BY id ASC')->fetchAll();
    if ($view === 'outbox') {
        $emailLogs = $pdo->query('SELECT * FROM email_logs ORDER BY id DESC LIMIT 150')->fetchAll();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Tesla Energy Foundation &bull; Mailer</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: {
        sans: ['"Plus Jakarta Sans"', 'sans-serif'],
        mono: ['"JetBrains Mono"', 'monospace']
      },
      colors: {
        brand: {
          red: '#e82127',
          redHover: '#cc1d22',
          dark: '#0c0e14',
          panel: '#121620',
          border: '#1f2637'
        }
      }
    }
  }
}
</script>
<style>
body { font-family: 'Plus Jakarta Sans', sans-serif; background: #0c0e14; color: #f1f5f9; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #0c0e14; }
::-webkit-scrollbar-thumb { background: #262f44; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #384563; }
.card { background: #121620; border: 1px solid #1f2637; border-radius: 14px; }
.input-field { background: #080a0f; border: 1px solid #232b3e; color: #ffffff; border-radius: 8px; font-size: 13px; }
.input-field:focus { border-color: #e82127; outline: none; }
</style>
</head>
<body class="min-h-screen flex flex-col antialiased">

<?php if (!$authed): ?>
<!-- ==============================================
     CLASSY CORPORATE LOGIN
     ============================================== -->
<main class="fixed inset-0 z-50 flex items-center justify-center bg-[#07080c] p-4">
  <div class="w-full max-w-[400px] card p-8 sm:p-10 relative overflow-hidden shadow-2xl">
    <div class="text-center mb-7">
      <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-[#181d2a] border border-[#262f44] text-brand-red mb-3">
        <i class="fa-solid fa-bolt text-xl"></i>
      </div>
      <h1 class="text-base font-extrabold tracking-[0.2em] uppercase text-white">Tesla Energy</h1>
      <p class="text-[11px] uppercase tracking-wider text-slate-400 mt-1 font-semibold">Mailer Distribution Portal</p>
    </div>

    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg text-xs bg-rose-950/40 text-rose-300 border border-rose-900/60 flex items-center gap-2">
        <i class="fa-solid fa-circle-exclamation text-rose-400"></i>
        <span><?= e($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $self ?>" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">

      <div>
        <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Authorized Identity</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-regular fa-envelope text-xs"></i>
          </span>
          <input type="text" name="identifier" required
                 class="w-full pl-10 pr-3.5 py-2.5 input-field"
                 placeholder="name@teslaenergyfoundation.com" autofocus autocomplete="username">
        </div>
      </div>

      <div>
        <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Access Credential</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-lock text-xs"></i>
          </span>
          <input type="password" name="password" required
                 class="w-full pl-10 pr-3.5 py-2.5 input-field"
                 placeholder="••••••••••••" autocomplete="current-password">
        </div>
      </div>

      <button type="submit"
              class="w-full py-3 px-4 bg-brand-red hover:bg-brand-redHover text-white font-semibold text-xs tracking-wider uppercase rounded-lg shadow-lg shadow-brand-red/20 transition flex items-center justify-center gap-2 mt-2">
        <i class="fa-solid fa-shield-halved text-xs"></i>
        <span>Sign in to Mailer</span>
      </button>
    </form>

    <div class="mt-7 pt-4 border-t border-[#1a202e] text-center text-[10px] text-slate-500 tracking-wide uppercase">
      Authorized Access Only &bull; Tesla Energy Infrastructure
    </div>
  </div>
</main>

<?php else: ?>
<!-- ==============================================
     AUTHENTICATED MAILER ENVIRONMENT
     ============================================== -->
<header class="bg-[#0f121a] border-b border-brand-border px-6 py-3.5 flex items-center justify-between shrink-0">
  <div class="flex items-center gap-5">
    <div class="flex items-center gap-3">
      <div class="w-9 h-9 rounded-xl bg-brand-red/10 border border-brand-red/30 flex items-center justify-center text-brand-red font-black text-lg">
        <i class="fa-solid fa-bolt"></i>
      </div>
      <div>
        <div class="flex items-center gap-2">
          <span class="font-extrabold tracking-wider text-white text-sm">TESLA ENERGY</span>
          <span class="text-[10px] uppercase font-bold tracking-widest bg-brand-red/20 text-brand-red px-2 py-0.5 rounded border border-brand-red/30">Bulk Mailer</span>
        </div>
        <div class="text-[11px] text-slate-400 flex items-center gap-2">
          <span>Sender: <strong class="text-slate-200"><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></strong></span>
          <span class="text-slate-600">&bull;</span>
          <span>Replies &rarr; <strong class="text-amber-400"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong></span>
        </div>
      </div>
    </div>

    <!-- Active Service Badges -->
    <div class="hidden md:flex items-center gap-2 text-xs">
      <div class="flex items-center gap-1.5 bg-[#080a0f] border border-brand-border px-2.5 py-1 rounded-md">
        <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
        <span class="text-slate-300 font-medium">Resend Paid API</span>
      </div>
      <div class="flex items-center gap-1.5 bg-[#080a0f] border border-brand-border px-2.5 py-1 rounded-md">
        <span class="w-2 h-2 rounded-full bg-sky-400 animate-pulse"></span>
        <span class="text-slate-300 font-medium">Google Workspace SMTP</span>
      </div>
    </div>
  </div>

  <!-- Top Actions -->
  <div class="flex items-center gap-3 text-xs">
    <div class="text-right hidden sm:block">
      <div class="text-slate-400">Today Dispatched: <strong class="text-white"><?= number_format($stats['todaySent']) ?></strong> / 6,000</div>
    </div>
    <div class="h-6 w-px bg-brand-border"></div>
    <a href="?logout=1" title="Sign Out" class="p-2 text-slate-400 hover:text-rose-400 transition">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </a>
  </div>
</header>

<!-- Main Navigation Bar -->
<nav class="bg-[#0a0d13] border-b border-brand-border px-6 flex items-center gap-2 shrink-0">
  <a href="?view=mailer" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $view === 'mailer' ? 'border-brand-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-paper-plane text-brand-red"></i>
    <span>Bulk Mailer (Send 6k)</span>
  </a>

  <a href="?view=outbox" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $view === 'outbox' ? 'border-brand-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-list-check text-purple-400"></i>
    <span>Outbox &amp; Logs</span>
    <?php if ($stats['totalSent']): ?><span class="ml-1 text-[10px] bg-slate-800 text-slate-300 px-1.5 py-0.5 rounded"><?= $stats['totalSent'] ?></span><?php endif; ?>
  </a>

  <a href="?view=settings" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $view === 'settings' ? 'border-brand-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-sliders text-emerald-400"></i>
    <span>Gateways &amp; SMTP Settings</span>
  </a>

  <a href="?view=dns" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $view === 'dns' ? 'border-brand-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-brands fa-cloudflare text-orange-400"></i>
    <span>Cloudflare DNS Guide</span>
  </a>
</nav>

<!-- Flash Messages -->
<?php if ($flash): ?>
  <div class="px-6 pt-4 max-w-7xl mx-auto w-full">
    <div class="p-3.5 rounded-xl text-xs flex items-center gap-2.5 <?= $flash['type'] === 'success' ? 'bg-emerald-950/70 text-emerald-300 border border-emerald-800' : 'bg-rose-950/70 text-rose-300 border border-rose-800' ?>">
      <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?>"></i>
      <span><?= e($flash['msg']) ?></span>
    </div>
  </div>
<?php endif; ?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-6 bg-[#07090e]">

  <?php if ($view === 'mailer'): ?>
  <!-- ==============================================
       VIEW 1: BULK MAILER CONSOLE (PRIMARY SCREEN)
       ============================================== -->
  <div class="max-w-7xl mx-auto space-y-6">

    <!-- Active Dispatch Monitor (Visible when a campaign is running or launched) -->
    <div id="live-dispatch-console" class="card p-5 border-brand-red/50 <?= isset($_GET['launch_id']) ? '' : 'hidden' ?>">
      <div class="flex items-center justify-between pb-3 border-b border-brand-border">
        <div class="flex items-center gap-2.5">
          <span class="w-3 h-3 rounded-full bg-emerald-500 animate-ping"></span>
          <h3 class="text-xs font-bold text-white uppercase tracking-wider">Active Mailer Dispatch Queue</h3>
        </div>
        <div class="flex items-center gap-2">
          <button id="btn-pause-queue" onclick="toggleQueuePause()" class="px-3 py-1 text-xs font-semibold rounded bg-amber-600 hover:bg-amber-500 text-white transition">Pause</button>
          <button id="btn-stop-queue" onclick="stopQueue()" class="px-3 py-1 text-xs font-semibold rounded bg-rose-600 hover:bg-rose-500 text-white transition">Stop</button>
        </div>
      </div>

      <div class="mt-4">
        <div class="flex justify-between text-xs text-slate-300 font-semibold mb-1">
          <span id="queue-status-text">Dispatching batch...</span>
          <span id="queue-pct-text" class="text-brand-red font-bold">0%</span>
        </div>
        <div class="h-2.5 w-full bg-[#080a0f] rounded-full overflow-hidden p-0.5 border border-brand-border">
          <div id="queue-progress-bar" class="h-full bg-gradient-to-r from-brand-red to-emerald-400 rounded-full transition-all duration-300" style="width: 0%"></div>
        </div>
      </div>

      <!-- Live Terminal Output -->
      <div class="mt-4 bg-[#050608] rounded-lg p-3 font-mono text-[11px] h-36 overflow-y-auto border border-brand-border space-y-1 text-slate-300" id="queue-terminal">
        <div class="text-slate-500">[System] Initializing dual gateway mailer...</div>
      </div>
    </div>

    <!-- The Mailer Form Grid -->
    <form method="post" action="<?= $self ?>" id="bulk-mailer-form" class="grid grid-cols-1 lg:grid-cols-3 gap-6">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_campaign">

      <!-- Left 2 Columns: Email Compose & Content -->
      <div class="lg:col-span-2 space-y-5">
        <div class="card p-6 space-y-4">
          <div class="flex items-center justify-between pb-3 border-b border-brand-border">
            <h2 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
              <i class="fa-solid fa-envelope text-brand-red"></i>
              <span>Email Composition &amp; Headers</span>
            </h2>
            <div class="flex items-center gap-2">
              <select id="template-select" onchange="loadTemplate(this.value)" class="input-field px-3 py-1.5 text-xs">
                <option value="">-- Load Pre-built Template --</option>
                <?php foreach ($templates as $t): ?>
                  <option value="<?= $t['id'] ?>"><?= e($t['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- Campaign Label & Subject -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div class="sm:col-span-1">
              <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Campaign Label</label>
              <input type="text" name="title" class="w-full px-3 py-2 input-field" placeholder="e.g. Allocation Notice #1">
            </div>
            <div class="sm:col-span-2">
              <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Subject Line</label>
              <input type="text" id="mailer-subject" name="subject" required class="w-full px-3 py-2 input-field"
                     placeholder="OFFICIAL ALLOCATION: Tesla Energy Foundation Grant Notification [Ref: {{ref_id}}]">
            </div>
          </div>

          <!-- Senders and Reply-To -->
          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-[11px] font-semibold text-slate-400 mb-1">From Name</label>
              <input type="text" name="sender_name" value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>" class="w-full px-3 py-2 input-field">
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-slate-400 mb-1">From Address</label>
              <input type="email" name="sender_email" value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>" class="w-full px-3 py-2 input-field font-mono">
            </div>
            <div>
              <label class="block text-[11px] font-semibold text-amber-400 mb-1">Reply-To (Target)</label>
              <input type="email" name="reply_to" value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>" class="w-full px-3 py-2 input-field font-mono text-amber-300">
            </div>
          </div>

          <!-- HTML Message Body -->
          <div>
            <div class="flex items-center justify-between mb-1.5">
              <div class="flex items-center gap-1.5 text-xs text-slate-400">
                <span class="font-semibold text-white">Merge Tokens:</span>
                <button type="button" onclick="insertToken('{{name}}')" class="px-1.5 py-0.5 rounded bg-[#080a0f] border border-brand-border text-[11px] text-brand-red font-mono">{{name}}</button>
                <button type="button" onclick="insertToken('{{email}}')" class="px-1.5 py-0.5 rounded bg-[#080a0f] border border-brand-border text-[11px] text-brand-red font-mono">{{email}}</button>
                <button type="button" onclick="insertToken('{{ref_id}}')" class="px-1.5 py-0.5 rounded bg-[#080a0f] border border-brand-border text-[11px] text-brand-red font-mono">{{ref_id}}</button>
                <button type="button" onclick="insertToken('{{grant_amount}}')" class="px-1.5 py-0.5 rounded bg-[#080a0f] border border-brand-border text-[11px] text-brand-red font-mono">{{grant_amount}}</button>
                <button type="button" onclick="insertToken('{{date}}')" class="px-1.5 py-0.5 rounded bg-[#080a0f] border border-brand-border text-[11px] text-brand-red font-mono">{{date}}</button>
              </div>
              <button type="button" onclick="toggleBodyPreview()" class="text-xs text-brand-red hover:underline font-semibold flex items-center gap-1">
                <i class="fa-solid fa-eye"></i> Toggle Preview
              </button>
            </div>

            <textarea id="mailer-html-body" name="body_html" rows="14" required
                      class="w-full p-3 input-field font-mono text-xs leading-relaxed"
                      placeholder="Paste your HTML email template here..."></textarea>
            <div id="mailer-live-preview" class="w-full p-4 bg-white rounded-lg border border-brand-border hidden text-slate-900 overflow-auto max-h-96"></div>
          </div>
        </div>
      </div>

      <!-- Right Column: Recipient Box, Gateway Selection & Launch -->
      <div class="space-y-5">
        <div class="card p-6 space-y-4">
          <div class="flex items-center justify-between pb-3 border-b border-brand-border">
            <h2 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
              <i class="fa-solid fa-users text-amber-400"></i>
              <span>Recipient Email List</span>
            </h2>
            <button type="button" onclick="insertSampleRecipients()" class="text-[11px] text-sky-400 hover:underline">
              + Sample Leads
            </button>
          </div>

          <p class="text-[11px] text-slate-400">Paste recipient list (one per line). Supports up to 6,000+ per batch.</p>
          <textarea id="mailer-recipients" name="recipients" rows="9" required
                    oninput="countRecipients()"
                    class="w-full p-3 input-field font-mono text-xs"
                    placeholder="email@example.com&#10;name@domain.com,John Smith&#10;user@company.com,Sarah,TEF-8921,$75,000"></textarea>

          <div class="flex justify-between items-center text-xs text-slate-400 pt-1">
            <span id="recipient-counter" class="font-bold text-white">0 recipients loaded</span>
            <button type="button" onclick="document.getElementById('mailer-recipients').value=''; countRecipients();" class="text-slate-500 hover:text-slate-300 text-[11px]">Clear</button>
          </div>

          <!-- Dispatch Gateway Selector -->
          <div class="pt-2 border-t border-brand-border space-y-2">
            <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">Dispatch Gateway</label>
            <div class="space-y-1.5">
              <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-brand-border bg-[#080a0f] cursor-pointer hover:border-brand-red transition">
                <input type="radio" name="provider_mode" value="auto_balance" checked class="mt-0.5 text-brand-red">
                <div>
                  <div class="text-xs font-bold text-white">Auto-Balance (60/40)</div>
                  <div class="text-[10px] text-slate-400">Balances Resend API &amp; Google Workspace</div>
                </div>
              </label>
              <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-brand-border bg-[#080a0f] cursor-pointer hover:border-brand-red transition">
                <input type="radio" name="provider_mode" value="resend_only" class="mt-0.5 text-brand-red">
                <div>
                  <div class="text-xs font-bold text-emerald-400">Resend API Only</div>
                  <div class="text-[10px] text-slate-400">Direct HTTPS API (High Speed)</div>
                </div>
              </label>
              <label class="flex items-start gap-2.5 p-2.5 rounded-lg border border-brand-border bg-[#080a0f] cursor-pointer hover:border-brand-red transition">
                <input type="radio" name="provider_mode" value="google_only" class="mt-0.5 text-brand-red">
                <div>
                  <div class="text-xs font-bold text-sky-400">Google Workspace Only</div>
                  <div class="text-[10px] text-slate-400">smtp.gmail.com:465 SSL</div>
                </div>
              </label>
            </div>
          </div>

          <!-- Main Launch Button -->
          <div class="pt-3">
            <button type="submit"
                    class="w-full py-3.5 px-4 bg-brand-red hover:bg-brand-redHover text-white font-bold text-xs uppercase tracking-wider rounded-lg shadow-xl shadow-brand-red/20 transition flex items-center justify-center gap-2">
              <i class="fa-solid fa-paper-plane"></i>
              <span>Start Bulk Dispatch</span>
            </button>
          </div>
        </div>

        <!-- Quick 1-to-1 Live Test Send -->
        <div class="card p-5 space-y-3">
          <h3 class="text-xs font-bold uppercase tracking-wider text-slate-300 flex items-center gap-2">
            <i class="fa-solid fa-vial-circle-check text-emerald-400"></i>
            <span>Send Quick Test Email</span>
          </h3>
          <div class="space-y-2">
            <input type="email" id="quick-test-email" placeholder="test-recipient@example.com" class="w-full px-3 py-2 input-field text-xs">
            <button type="button" onclick="sendQuickTest()" class="w-full py-2 bg-[#1b2233] hover:bg-[#232c42] text-slate-200 text-xs font-semibold rounded-lg transition flex items-center justify-center gap-1.5">
              <span>Send Single Test</span>
            </button>
            <div id="quick-test-feedback" class="text-[11px] font-mono hidden"></div>
          </div>
        </div>
      </div>
    </form>
  </div>

  <?php elseif ($view === 'outbox'): ?>
  <!-- ==============================================
       VIEW 2: OUTBOX & AUDIT LOGS
       ============================================== -->
  <div class="card p-6 max-w-7xl mx-auto space-y-4">
    <div class="flex items-center justify-between pb-3 border-b border-brand-border">
      <div>
        <h2 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
          <i class="fa-solid fa-list-check text-purple-400"></i>
          <span>Delivery Audit Logs</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">Historical record of sent messages with provider message IDs</p>
      </div>

      <div class="flex items-center gap-2">
        <form method="post" action="<?= $self ?>" onsubmit="return confirm('Clear all outbox records?');">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="clear_logs">
          <button type="submit" class="px-3 py-1.5 rounded-lg bg-[#1b2233] hover:bg-rose-950/50 hover:text-rose-400 text-slate-300 text-xs transition">
            Clear Logs
          </button>
        </form>
        <a href="?view=outbox" class="px-3 py-1.5 rounded-lg bg-[#1b2233] hover:bg-[#232c42] text-slate-300 text-xs transition">
          <i class="fa-solid fa-rotate-right mr-1"></i> Refresh
        </a>
      </div>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs border-collapse font-mono">
        <thead>
          <tr class="border-b border-brand-border text-slate-400 uppercase tracking-wider text-[10px] font-sans">
            <th class="py-3 px-3">Status</th>
            <th class="py-3 px-3">Gateway</th>
            <th class="py-3 px-3">Recipient</th>
            <th class="py-3 px-3">Subject</th>
            <th class="py-3 px-3">Message ID</th>
            <th class="py-3 px-3">Timestamp</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-brand-border/60 text-slate-300">
          <?php if (empty($emailLogs)): ?>
            <tr><td colspan="6" class="text-center py-10 text-slate-500 font-sans">No emails logged in outbox yet.</td></tr>
          <?php else: foreach ($emailLogs as $log): ?>
            <tr>
              <td class="py-3 px-3">
                <?php if ($log['status'] === 'sent'): ?>
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-950 text-emerald-400 border border-emerald-800">SENT</span>
                <?php else: ?>
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-950 text-rose-400 border border-rose-800" title="<?= e($log['error_message']) ?>">FAILED</span>
                <?php endif; ?>
              </td>
              <td class="py-3 px-3">
                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $log['provider'] === 'resend' ? 'bg-emerald-950 text-emerald-300' : 'bg-sky-950 text-sky-300' ?>">
                  <?= e($log['provider']) ?>
                </span>
              </td>
              <td class="py-3 px-3 font-semibold text-white"><?= e($log['to_address']) ?></td>
              <td class="py-3 px-3 font-sans text-slate-300 max-w-xs truncate"><?= e($log['subject']) ?></td>
              <td class="py-3 px-3 text-slate-500 text-[11px] max-w-[140px] truncate"><?= e($log['message_id'] ?: '—') ?></td>
              <td class="py-3 px-3 text-slate-500"><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($view === 'settings'): ?>
  <!-- ==============================================
       VIEW 3: GATEWAYS & SMTP SETTINGS
       ============================================== -->
  <form method="post" action="<?= $self ?>" class="card p-6 max-w-5xl mx-auto space-y-6">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">

    <div class="flex items-center justify-between pb-3 border-b border-brand-border">
      <div>
        <h2 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
          <i class="fa-solid fa-sliders text-emerald-400"></i>
          <span>Dual Gateway &amp; SMTP Configuration</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">Configure Resend API and Google Workspace SMTP for bulk 6k sending</p>
      </div>
      <button type="submit" class="px-5 py-2 rounded-lg bg-brand-red hover:bg-brand-redHover text-white text-xs font-bold transition">
        Save Changes
      </button>
    </div>

    <!-- Domain & Routing -->
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
      <div>
        <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Sending Domain</label>
        <input type="text" name="sending_domain" value="<?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?>" required class="w-full px-3 py-2 input-field font-mono">
        <p class="text-[11px] text-slate-500 mt-1">Managed on Cloudflare for SPF, DKIM, and DMARC.</p>
      </div>
      <div>
        <label class="block text-[11px] font-semibold uppercase tracking-wider text-amber-400 mb-1">Inbound Reply-To Target</label>
        <input type="email" name="reply_to_email" value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>" required class="w-full px-3 py-2 input-field font-mono text-amber-300">
        <p class="text-[11px] text-amber-400/80 mt-1">All replies from recipients direct to <strong>teslafoundationreward.com</strong>.</p>
      </div>
    </div>

    <!-- Resend Paid API -->
    <div class="p-5 rounded-xl bg-[#080a0f] border border-brand-border space-y-3">
      <div class="flex items-center justify-between">
        <span class="text-xs font-bold uppercase tracking-wider text-emerald-400 flex items-center gap-2">
          <span class="w-2.5 h-2.5 rounded-full bg-emerald-400"></span>
          <span>Gateway 1: Resend Paid API</span>
        </span>
        <button type="button" onclick="testResendKey()" class="text-xs text-emerald-400 hover:underline font-semibold">
          Test Connection
        </button>
      </div>
      <div>
        <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400 mb-1">Resend API Key (re_...)</label>
        <input type="password" id="cfg-resend-key" name="resend_api_key" value="<?= e(get_setting('resend_api_key', '')) ?>" class="w-full px-3 py-2 input-field font-mono" placeholder="re_xxxxxxxxxxxxxxxxxxxx">
      </div>
      <div id="resend-feedback" class="text-xs font-mono hidden"></div>
    </div>

    <!-- Google Workspace Paid SMTP -->
    <div class="p-5 rounded-xl bg-[#080a0f] border border-brand-border space-y-3">
      <div class="flex items-center justify-between">
        <span class="text-xs font-bold uppercase tracking-wider text-sky-400 flex items-center gap-2">
          <span class="w-2.5 h-2.5 rounded-full bg-sky-400"></span>
          <span>Gateway 2: Google Workspace SMTP (Paid)</span>
        </span>
        <button type="button" onclick="testGoogleSmtp()" class="text-xs text-sky-400 hover:underline font-semibold">
          Test Connection
        </button>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-4 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-[11px] font-semibold text-slate-400 mb-1">Host</label>
          <input type="text" id="cfg-google-host" name="google_smtp_host" value="<?= e(get_setting('google_smtp_host', 'smtp.gmail.com')) ?>" class="w-full px-3 py-2 input-field font-mono text-xs">
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-slate-400 mb-1">Port</label>
          <input type="number" id="cfg-google-port" name="google_smtp_port" value="<?= e(get_setting('google_smtp_port', '465')) ?>" class="w-full px-3 py-2 input-field font-mono text-xs">
        </div>
        <div>
          <label class="block text-[11px] font-semibold text-slate-400 mb-1">Security</label>
          <input type="text" readonly value="SSL / TLS" class="w-full px-3 py-2 input-field text-slate-500 text-xs">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-[11px] font-semibold text-slate-400 mb-1">Google Workspace Account</label>
          <input type="email" id="cfg-google-user" name="google_smtp_user" value="<?= e(get_setting('google_smtp_user', '')) ?>" class="w-full px-3 py-2 input-field font-mono text-xs" placeholder="grants@teslaenergyfoundation.com">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-[11px] font-semibold text-slate-400 mb-1">16-Char Google App Password</label>
          <input type="password" id="cfg-google-pass" name="google_smtp_pass" class="w-full px-3 py-2 input-field font-mono text-xs" placeholder="<?= get_setting('google_smtp_pass') ? '•••••••••••••••• (Leave blank to keep unchanged)' : 'Enter 16-character App Password' ?>">
        </div>
      </div>
      <div id="google-feedback" class="text-xs font-mono hidden"></div>
    </div>

    <!-- Dual Ratio Slider -->
    <div class="p-5 rounded-xl bg-[#080a0f] border border-brand-border space-y-2">
      <label class="block text-[11px] font-semibold uppercase tracking-wider text-slate-400">Load Balancing Split Ratio</label>
      <div class="flex items-center gap-4">
        <input type="range" name="resend_ratio" min="10" max="90" value="<?= (int)get_setting('resend_ratio', '60') ?>"
               oninput="document.getElementById('ratio-label').innerText = this.value + '% Resend / ' + (100 - this.value) + '% Google'"
               class="flex-1 accent-brand-red h-2 bg-[#1b2233] rounded-lg">
        <span id="ratio-label" class="font-mono text-xs font-bold text-white w-36 text-right"><?= get_setting('resend_ratio', '60') ?>% Resend / <?= 100 - (int)get_setting('resend_ratio', '60') ?>% Google</span>
      </div>
    </div>
  </form>

  <?php elseif ($view === 'dns'): ?>
  <!-- ==============================================
       VIEW 4: CLOUDFLARE DNS GUIDE
       ============================================== -->
  <div class="card p-6 max-w-5xl mx-auto space-y-5">
    <div class="flex items-center justify-between pb-3 border-b border-brand-border">
      <div>
        <h2 class="text-xs font-bold uppercase tracking-wider text-white flex items-center gap-2">
          <i class="fa-brands fa-cloudflare text-orange-400"></i>
          <span>Cloudflare DNS Records for teslaenergyfoundation.com</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">Authorizes both Resend and Google Workspace while routing all replies to teslafoundationreward.com</p>
      </div>
      <a href="https://dash.cloudflare.com" target="_blank" class="px-3 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-500 text-white text-xs font-semibold transition">
        Open Cloudflare &rarr;
      </a>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs border-collapse font-mono">
        <thead>
          <tr class="border-b border-brand-border text-slate-400 uppercase tracking-wider text-[10px] font-sans">
            <th class="py-3 px-3">Type</th>
            <th class="py-3 px-3">Name / Host</th>
            <th class="py-3 px-3">Content / Value</th>
            <th class="py-3 px-3">Proxy Status</th>
            <th class="py-3 px-3">Purpose</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-brand-border/60 text-slate-300">
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 font-bold">TXT</span></td>
            <td class="py-3 px-3 font-bold text-white">@</td>
            <td class="py-3 px-3"><code class="bg-[#080a0f] px-2 py-1 rounded text-emerald-400">v=spf1 include:resend.com include:_spf.google.com ~all</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Authorizes Resend + Google Workspace</td>
          </tr>
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 font-bold">TXT</span></td>
            <td class="py-3 px-3 font-bold text-white">_dmarc</td>
            <td class="py-3 px-3"><code class="bg-[#080a0f] px-2 py-1 rounded text-emerald-400">v=DMARC1; p=quarantine; rua=mailto:dmarc@teslafoundationreward.com</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Protects domain from spoofing</td>
          </tr>
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-amber-950 text-amber-400 font-bold">CNAME</span></td>
            <td class="py-3 px-3 font-bold text-white">resend._domainkey</td>
            <td class="py-3 px-3"><code class="bg-[#080a0f] px-2 py-1 rounded text-slate-300">provided-by.resend.com</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Resend DKIM record</td>
          </tr>
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 font-bold">TXT</span></td>
            <td class="py-3 px-3 font-bold text-white">google._domainkey</td>
            <td class="py-3 px-3"><code class="bg-[#080a0f] px-2 py-1 rounded text-slate-300">v=DKIM1; k=rsa; p=YOUR_KEY</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Google Workspace DKIM record</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</main>

<script>
const TEMPLATES = <?= json_encode($templates) ?>;

function loadTemplate(id) {
  const t = TEMPLATES.find(x => String(x.id) === String(id));
  if (t) {
    document.getElementById('mailer-subject').value = t.subject;
    document.getElementById('mailer-html-body').value = t.body_html;
    const prev = document.getElementById('mailer-live-preview');
    if (!prev.classList.contains('hidden')) {
      prev.innerHTML = t.body_html;
    }
  }
}

function toggleBodyPreview() {
  const p = document.getElementById('mailer-live-preview');
  const t = document.getElementById('mailer-html-body');
  if (p.classList.contains('hidden')) {
    p.innerHTML = t.value;
    p.classList.remove('hidden');
  } else {
    p.classList.add('hidden');
  }
}

function insertToken(tok) {
  const t = document.getElementById('mailer-html-body');
  const start = t.selectionStart;
  const end = t.selectionEnd;
  t.value = t.value.substring(0, start) + tok + t.value.substring(end);
  t.focus();
  t.selectionStart = t.selectionEnd = start + tok.length;
}

function countRecipients() {
  const val = document.getElementById('mailer-recipients').value.trim();
  const lines = val ? val.split(/\r?\n/).filter(x => x.trim().length > 0) : [];
  document.getElementById('recipient-counter').innerText = lines.length + ' recipients loaded';
}

function insertSampleRecipients() {
  const samples = [
    'alex.morgan@gmail.com,Alex Morgan,TEF-8921,$75,000',
    'marcus.vance@outlook.com,Marcus Vance,TEF-9310,$120,000',
    'elena.rostova@yahoo.com,Elena Rostova,TEF-4822,$75,000',
    'david.chen22@proton.me,David Chen,TEF-1109,$95,000'
  ];
  const t = document.getElementById('mailer-recipients');
  t.value = (t.value.trim() ? t.value.trim() + "\n" : "") + samples.join("\n");
  countRecipients();
}

// Quick Test Dispatch
async function sendQuickTest() {
  const to = document.getElementById('quick-test-email').value.trim();
  const fb = document.getElementById('quick-test-feedback');
  if (!to) { alert('Please enter test recipient email'); return; }

  fb.className = 'text-[11px] font-mono text-slate-400 block';
  fb.innerText = 'Sending test via dual gateways...';

  const fd = new FormData();
  fd.append('test_to', to);
  fd.append('provider', 'auto_balance');

  try {
    const res = await fetch('api.php?action=test_diagnostic', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      fb.className = 'text-[11px] font-mono text-emerald-400 block';
      fb.innerText = '✓ Delivered via ' + data.provider + ' (ID: ' + data.id + ')';
    } else {
      fb.className = 'text-[11px] font-mono text-rose-400 block';
      fb.innerText = '✗ Failed: ' + (data.error || 'Unknown error');
    }
  } catch (e) {
    fb.className = 'text-[11px] font-mono text-rose-400 block';
    fb.innerText = '✗ Error: ' + e.message;
  }
}

// Settings Test Connections
async function testResendKey() {
  const key = document.getElementById('cfg-resend-key').value.trim();
  const fb = document.getElementById('resend-feedback');
  fb.className = 'text-xs font-mono text-slate-400 block';
  fb.innerText = 'Verifying Resend API...';

  const fd = new FormData();
  fd.append('api_key', key);
  const res = await fetch('api.php?action=test_resend', { method: 'POST', body: fd });
  const d = await res.json();
  if (d.ok) {
    fb.className = 'text-xs font-mono text-emerald-400 block';
    fb.innerText = '✓ ' + d.message;
  } else {
    fb.className = 'text-xs font-mono text-rose-400 block';
    fb.innerText = '✗ ' + (d.error || 'Verification failed');
  }
}

async function testGoogleSmtp() {
  const host = document.getElementById('cfg-google-host').value.trim();
  const port = document.getElementById('cfg-google-port').value.trim();
  const user = document.getElementById('cfg-google-user').value.trim();
  const pass = document.getElementById('cfg-google-pass').value.trim();
  const fb = document.getElementById('google-feedback');
  fb.className = 'text-xs font-mono text-slate-400 block';
  fb.innerText = 'Testing Google Workspace SMTP connection...';

  const fd = new FormData();
  fd.append('host', host);
  fd.append('port', port);
  fd.append('user', user);
  fd.append('pass', pass);
  const res = await fetch('api.php?action=test_google', { method: 'POST', body: fd });
  const d = await res.json();
  if (d.ok) {
    fb.className = 'text-xs font-mono text-emerald-400 block';
    fb.innerText = '✓ ' + d.message;
  } else {
    fb.className = 'text-xs font-mono text-rose-400 block';
    fb.innerText = '✗ ' + (d.error || 'Connection failed');
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
  logQueue('[User] Dispatch queue stopped.');
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
  logQueue(`Starting batch dispatcher for run #${campId}...`);
  let dispatched = 0;

  while (!queueStopped) {
    if (queuePaused) {
      await new Promise(r => setTimeout(r, 1000));
      continue;
    }

    const fd = new FormData();
    fd.append('campaign_id', campId);
    fd.append('batch_size', 25);

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
      document.getElementById('queue-status-text').innerText = `Sent: ${dispatched} | Remaining: ${rem}`;

      if (data.results) {
        for (const item of data.results) {
          if (item.status === 'sent') {
            logQueue(`✓ [${item.provider}] Delivered to ${item.email}`);
          } else {
            logQueue(`✗ [${item.provider}] Failed ${item.email}: ${item.error}`);
          }
        }
      }

      if (data.completed || rem === 0) {
        logQueue(`✓✓ Completed: All ${dispatched} emails dispatched successfully.`);
        break;
      }

      await new Promise(r => setTimeout(r, 1000));
    } catch (e) {
      logQueue('Network error: ' + e.message + '. Retrying in 3s...');
      await new Promise(r => setTimeout(r, 3000));
    }
  }
}
<?php endif; ?>
</script>
<?php endif; ?>

</body>
</html>
