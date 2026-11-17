<?php
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
        $error = 'Invalid credentials. Please verify your email and password.';
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
// 2. AUTHENTICATED ACTIONS (CAMPAIGNS, LOGS, SETTINGS)
// ----------------------------------------------------
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST' && csrf_check()) {
    $act = $_POST['action'] ?? '';

    // Action A: Create Bulk Campaign
    if ($act === 'create_campaign') {
        $title = clean($_POST['title'] ?? '', 150);
        $subject = clean($_POST['subject'] ?? '', 255);
        $senderName = clean($_POST['sender_name'] ?? '', 120) ?: get_setting('default_from_name', DEFAULT_FROM_NAME);
        $senderEmail = clean($_POST['sender_email'] ?? '', 120) ?: get_setting('default_from_email', DEFAULT_FROM_EMAIL);
        $replyTo = clean($_POST['reply_to'] ?? '', 120) ?: get_setting('reply_to_email', DEFAULT_REPLY_TO);
        $providerMode = clean($_POST['provider_mode'] ?? 'auto_balance', 40);
        $bodyHtml = (string)($_POST['body_html'] ?? '');
        $rawRecipients = (string)($_POST['recipients'] ?? '');

        // Parse line-by-line recipients (CSV, comma or newline)
        $lines = preg_split('/[\r\n]+/', trim($rawRecipients));
        $validList = [];
        $seen = [];

        foreach ($lines as $line) {
            $line = trim($line);
            if (!$line) continue;
            $parts = array_map('trim', preg_split('/[,;\t]/', $line));

            $email = '';
            $name = '';
            $extra = [];

            // Detect email position
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
                if (isset($rem[1])) $extra['ref_id'] = $rem[1];
                if (isset($rem[2])) $extra['grant_amount'] = $rem[2];

                $validList[] = [
                    'email'     => $email,
                    'name'      => $name,
                    'variables' => $extra
                ];
            }
        }

        if (!$title || !$subject || !$bodyHtml) {
            flash('error', 'Please fill in campaign title, subject, and email body.');
        } elseif (empty($validList)) {
            flash('error', 'No valid email addresses were found in your recipient list.');
        } else {
            $pdo->beginTransaction();
            try {
                $st = $pdo->prepare('INSERT INTO campaigns (title, subject, sender_name, sender_email, reply_to, body_html, total_recipients, provider_mode, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, "draft")');
                $st->execute([$title, $subject, $senderName, $senderEmail, $replyTo, $bodyHtml, count($validList), $providerMode]);
                $campId = (int)$pdo->lastInsertId();

                $insRecip = $pdo->prepare('INSERT INTO recipients (campaign_id, email, name, variables_json, status) VALUES (?, ?, ?, ?, "pending")');
                foreach ($validList as $v) {
                    $insRecip->execute([$campId, $v['email'], $v['name'], json_encode($v['variables'])]);
                }
                $pdo->commit();

                flash('success', "Campaign \"{$title}\" created with " . count($validList) . " recipients! Ready for bulk dispatch.");
                header("Location: {$self}?tab=campaign&run_camp_id={$campId}");
                exit;
            } catch (Throwable $e) {
                $pdo->rollBack();
                flash('error', 'Failed to create campaign: ' . $e->getMessage());
            }
        }
    }

    // Action B: Direct Single Compose
    if ($act === 'direct_send') {
        $to = clean($_POST['to'] ?? '', 120);
        $subject = clean($_POST['subject'] ?? '', 200);
        $bodyHtml = (string)($_POST['body_html'] ?? '');
        $provider = clean($_POST['provider'] ?? 'auto_balance', 40);

        if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Please enter a valid recipient email address.');
        } elseif (!$subject || !$bodyHtml) {
            flash('error', 'Subject and email message content are required.');
        } else {
            $res = send_dual_email($to, $subject, $bodyHtml, strip_tags($bodyHtml), '', '', '', $provider);
            if (!empty($res['ok'])) {
                flash('success', "Email successfully delivered to {$to} via {$res['provider']}! (ID: {$res['id']})");
                header("Location: {$self}?tab=outbox");
                exit;
            } else {
                flash('error', "Delivery failed: " . ($res['error'] ?: 'Check credentials and SMTP settings.'));
            }
        }
    }

    // Action C: Save Settings
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

        // Ratios & Balance
        set_setting('provider_mode', clean($_POST['provider_mode'] ?? 'auto_balance', 40));
        set_setting('resend_ratio', (string)max(10, min(90, (int)($_POST['resend_ratio'] ?? 60))));

        flash('success', 'Dual Gateway configuration and routing rules saved successfully.');
        header("Location: {$self}?tab=gateways");
        exit;
    }

    // Action D: Delete / Clear Log
    if ($act === 'del_log') {
        $logId = (int)($_POST['log_id'] ?? 0);
        if ($logId > 0) {
            $pdo->prepare('DELETE FROM email_logs WHERE id = ?')->execute([$logId]);
            flash('success', 'Audit log entry removed.');
        }
        header("Location: {$self}?tab=outbox");
        exit;
    }

    // Action E: Delete Campaign
    if ($act === 'del_campaign') {
        $campId = (int)($_POST['campaign_id'] ?? 0);
        if ($campId > 0) {
            $pdo->prepare('DELETE FROM recipients WHERE campaign_id = ?')->execute([$campId]);
            $pdo->prepare('DELETE FROM campaigns WHERE id = ?')->execute([$campId]);
            flash('success', "Campaign #{$campId} removed from registry.");
        }
        header("Location: {$self}?tab=campaign");
        exit;
    }
}

// ----------------------------------------------------
// 3. QUERY DATA FOR DASHBOARD
// ----------------------------------------------------
$activeTab = $_GET['tab'] ?? 'dashboard';
$flash = get_flash();

$stats = [
    'totalSent'   => 0,
    'totalFailed' => 0,
    'todaySent'   => 0,
    'resendSent'  => 0,
    'googleSent'  => 0
];

$recentCampaigns = [];
$emailLogs = [];
$templates = [];

if ($authed) {
    $todayCond = is_mysql() ? "DATE(created_at) = CURDATE()" : "date(created_at) = date('now')";
    $stats['totalSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent'")->fetchColumn();
    $stats['totalFailed'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'")->fetchColumn();
    $stats['todaySent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND {$todayCond}")->fetchColumn();
    $stats['resendSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'resend'")->fetchColumn();
    $stats['googleSent'] = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'google_workspace'")->fetchColumn();

    $recentCampaigns = $pdo->query('SELECT * FROM campaigns ORDER BY id DESC LIMIT 25')->fetchAll();
    $emailLogs = $pdo->query('SELECT * FROM email_logs ORDER BY id DESC LIMIT 100')->fetchAll();
    $templates = $pdo->query('SELECT * FROM templates ORDER BY id ASC')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tesla Energy Foundation — Dual Gateway Bulk Mailer (6k/day)</title>
<script src="https://cdn.tailwindcss.com"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<script>
tailwind.config = {
  theme: {
    extend: {
      fontFamily: { sans: ['Inter', 'sans-serif'], mono: ['JetBrains Mono', 'monospace'] },
      colors: {
        tesla: { red: '#e82127', redHover: '#cc1d22', dark: '#0f1115', card: '#161920', border: '#232733' }
      }
    }
  }
}
</script>
<style>
body { font-family: 'Inter', sans-serif; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #0f1115; }
::-webkit-scrollbar-thumb { background: #2d3342; border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: #3e4659; }
</style>
</head>
<body class="bg-[#0b0d11] text-slate-100 antialiased min-h-screen flex flex-col">

<?php if (!$authed): ?>
<!-- ==============================================
     LOGIN SCREEN
     ============================================== -->
<main class="fixed inset-0 z-50 flex items-center justify-center bg-black/85 backdrop-blur-md p-4">
  <div class="w-full max-w-md bg-tesla-card border border-tesla-border rounded-2xl shadow-2xl p-8 relative overflow-hidden">
    <div class="absolute -top-24 -left-24 w-48 h-48 bg-tesla-red/20 rounded-full blur-3xl pointer-events-none"></div>

    <div class="text-center mb-7 relative">
      <div class="inline-flex items-center justify-center w-14 h-14 rounded-2xl bg-tesla-red/10 border border-tesla-red/30 text-tesla-red mb-3">
        <i class="fa-solid fa-bolt-lightning text-2xl"></i>
      </div>
      <h1 class="text-xl font-bold tracking-wider uppercase text-white">Tesla Energy Foundation</h1>
      <p class="text-xs text-slate-400 mt-1">Dual-Gateway High Throughput Mailer Hub (6k/day)</p>
      <div class="mt-3 inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-[11px] font-semibold bg-emerald-950/60 text-emerald-400 border border-emerald-800/60">
        <span class="w-1.5 h-1.5 rounded-full bg-emerald-400 animate-pulse"></span>
        Sending: teslaenergyfoundation.com
      </div>
    </div>

    <?php if ($error): ?>
      <div class="mb-4 p-3 rounded-lg text-xs bg-rose-950/60 text-rose-300 border border-rose-800/60 flex items-center gap-2">
        <i class="fa-solid fa-circle-exclamation"></i>
        <span><?= e($error) ?></span>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= $self ?>" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="login">

      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Portal Email or Username</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-regular fa-envelope"></i>
          </span>
          <input type="text" name="identifier" required
                 class="w-full pl-10 pr-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-tesla-red"
                 placeholder="admin@teslaenergyfoundation.com" value="admin@teslaenergyfoundation.com" autofocus>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1.5">Access Password</label>
        <div class="relative">
          <span class="absolute inset-y-0 left-0 flex items-center pl-3.5 text-slate-500">
            <i class="fa-solid fa-lock"></i>
          </span>
          <input type="password" name="password" required
                 class="w-full pl-10 pr-3 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-tesla-red"
                 placeholder="Enter password" value="TeslaEnergy2026!">
        </div>
      </div>

      <button type="submit"
              class="w-full py-3 px-4 bg-tesla-red hover:bg-tesla-redHover text-white font-semibold text-sm rounded-lg shadow-lg shadow-tesla-red/20 transition flex items-center justify-center gap-2 mt-2">
        <i class="fa-solid fa-right-to-bracket"></i>
        <span>Sign in to Mailer Hub</span>
      </button>
    </form>

    <div class="mt-6 pt-4 border-t border-slate-800/80 text-center text-xs text-slate-500">
      Default Access: <code>admin@teslaenergyfoundation.com</code> &bull; <code>TeslaEnergy2026!</code>
    </div>
  </div>
</main>

<?php else: ?>
<!-- ==============================================
     AUTHENTICATED APPLICATION DASHBOARD
     ============================================== -->
<header class="bg-tesla-dark border-b border-tesla-border px-6 py-3.5 flex items-center justify-between shrink-0">
  <div class="flex items-center gap-4">
    <div class="flex items-center gap-2.5">
      <div class="w-9 h-9 rounded-xl bg-tesla-red/10 border border-tesla-red/30 flex items-center justify-center text-tesla-red font-black text-lg">
        <i class="fa-solid fa-bolt"></i>
      </div>
      <div>
        <div class="flex items-center gap-2">
          <span class="font-extrabold tracking-wider text-white text-base">TESLA ENERGY</span>
          <span class="text-[10px] uppercase font-bold tracking-widest bg-tesla-red/20 text-tesla-red px-2 py-0.5 rounded border border-tesla-red/30">Mailer Hub</span>
        </div>
        <div class="text-[11px] text-slate-400 flex items-center gap-2">
          <span>Sending Domain: <strong class="text-slate-200"><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></strong></span>
          <span class="text-slate-600">&bull;</span>
          <span>Replies &rarr; <strong class="text-amber-400"><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></strong></span>
        </div>
      </div>
    </div>

    <!-- Dual Gateway Indicators -->
    <div class="hidden lg:flex items-center gap-2 ml-4 pl-4 border-l border-slate-800 text-xs">
      <div class="flex items-center gap-1.5 bg-slate-900/90 border border-slate-800 px-2.5 py-1 rounded-md">
        <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
        <span class="text-slate-300 font-medium">Resend Paid API</span>
      </div>
      <div class="flex items-center gap-1.5 bg-slate-900/90 border border-slate-800 px-2.5 py-1 rounded-md">
        <span class="w-2 h-2 rounded-full bg-sky-500 animate-pulse"></span>
        <span class="text-slate-300 font-medium">Google Workspace SMTP</span>
      </div>
      <div class="flex items-center gap-1.5 bg-slate-900/90 border border-amber-900/50 px-2.5 py-1 rounded-md text-amber-300">
        <i class="fa-brands fa-cloudflare text-orange-400"></i>
        <span>Cloudflare Verified</span>
      </div>
    </div>
  </div>

  <div class="flex items-center gap-3">
    <button onclick="openDiagnosticModal()" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-slate-800 hover:bg-slate-700 text-slate-200 border border-slate-700 transition flex items-center gap-1.5">
      <i class="fa-solid fa-vial-circle-check text-emerald-400"></i>
      <span>Test Diagnostic</span>
    </button>
    <div class="h-6 w-px bg-slate-800"></div>
    <div class="text-right hidden sm:block text-xs">
      <div class="font-semibold text-slate-200"><?= e($currentUser['username']) ?></div>
      <div class="text-[10px] text-slate-500">6,000 Bulk Engine</div>
    </div>
    <a href="?logout=1" title="Sign Out" class="p-2 text-slate-400 hover:text-rose-400 hover:bg-rose-950/30 rounded-lg transition">
      <i class="fa-solid fa-arrow-right-from-bracket"></i>
    </a>
  </div>
</header>

<!-- Main Navigation Tabs -->
<nav class="bg-[#12151b] border-b border-tesla-border px-6 flex items-center gap-1 shrink-0 overflow-x-auto">
  <a href="?tab=dashboard" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $activeTab === 'dashboard' ? 'border-tesla-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-chart-pie text-tesla-red"></i>
    <span>Performance Dashboard</span>
  </a>
  <a href="?tab=campaign" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $activeTab === 'campaign' ? 'border-tesla-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-paper-plane text-amber-500"></i>
    <span>Bulk Campaign Studio (6k)</span>
  </a>
  <a href="?tab=compose" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $activeTab === 'compose' ? 'border-tesla-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-pen-to-square text-sky-400"></i>
    <span>Direct Compose (1-to-1)</span>
  </a>
  <a href="?tab=outbox" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $activeTab === 'outbox' ? 'border-tesla-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-list-check text-purple-400"></i>
    <span>Outbox &amp; Audit Logs</span>
  </a>
  <a href="?tab=gateways" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $activeTab === 'gateways' ? 'border-tesla-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-solid fa-sliders text-emerald-400"></i>
    <span>Dual Gateways &amp; Settings</span>
  </a>
  <a href="?tab=dns" class="px-4 py-3 text-xs font-semibold border-b-2 <?= $activeTab === 'dns' ? 'border-tesla-red text-white' : 'border-transparent text-slate-400 hover:text-slate-200' ?> flex items-center gap-2 transition">
    <i class="fa-brands fa-cloudflare text-orange-400"></i>
    <span>Cloudflare DNS Guide</span>
  </a>
</nav>

<!-- Flash Alerts -->
<?php if ($flash): ?>
  <div class="px-6 pt-4 max-w-7xl mx-auto w-full">
    <div class="p-4 rounded-xl text-xs flex items-center gap-3 <?= $flash['type'] === 'success' ? 'bg-emerald-950/70 text-emerald-300 border border-emerald-800' : 'bg-rose-950/70 text-rose-300 border border-rose-800' ?>">
      <i class="fa-solid <?= $flash['type'] === 'success' ? 'fa-circle-check text-emerald-400' : 'fa-circle-exclamation text-rose-400' ?> text-base"></i>
      <span class="flex-1 font-medium"><?= e($flash['msg']) ?></span>
    </div>
  </div>
<?php endif; ?>

<!-- Main Content Area -->
<main class="flex-1 overflow-y-auto p-6 bg-[#0b0d11]">

  <?php if ($activeTab === 'dashboard'): ?>
  <!-- ==============================================
       TAB 1: PERFORMANCE DASHBOARD
       ============================================== -->
  <div class="space-y-6 max-w-7xl mx-auto">
    <!-- Metric Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
      <div class="bg-tesla-card border border-tesla-border rounded-xl p-5">
        <div class="flex items-center justify-between text-slate-400 text-xs uppercase tracking-wider font-semibold">
          <span>Today Dispatched</span>
          <i class="fa-solid fa-calendar-day text-tesla-red"></i>
        </div>
        <div class="mt-2 flex items-baseline gap-2">
          <span class="text-3xl font-extrabold text-white"><?= number_format($stats['todaySent']) ?></span>
          <span class="text-xs text-slate-500 font-medium">/ 6,000 Target</span>
        </div>
        <div class="mt-3">
          <?php $pct = min(100, round(($stats['todaySent'] / 6000) * 100, 1)); ?>
          <div class="h-2 w-full bg-slate-800 rounded-full overflow-hidden">
            <div class="h-full bg-gradient-to-r from-tesla-red to-amber-500 rounded-full transition-all duration-500" style="width: <?= $pct ?>%"></div>
          </div>
          <div class="flex justify-between items-center text-[10px] text-slate-400 mt-1.5">
            <span><?= $pct ?>% Daily Progress</span>
            <span>Capacity: 6,000 / day</span>
          </div>
        </div>
      </div>

      <div class="bg-tesla-card border border-tesla-border rounded-xl p-5">
        <div class="flex items-center justify-between text-slate-400 text-xs uppercase tracking-wider font-semibold">
          <span>All-Time Delivered</span>
          <i class="fa-solid fa-circle-check text-emerald-400"></i>
        </div>
        <div class="mt-2 text-3xl font-extrabold text-emerald-400"><?= number_format($stats['totalSent']) ?></div>
        <p class="text-xs text-slate-500 mt-2">Verified deliveries via dual gateways</p>
      </div>

      <div class="bg-tesla-card border border-tesla-border rounded-xl p-5">
        <div class="flex items-center justify-between text-slate-400 text-xs uppercase tracking-wider font-semibold">
          <span>Gateway Balance</span>
          <i class="fa-solid fa-scale-balanced text-sky-400"></i>
        </div>
        <div class="mt-2 flex items-center justify-between text-sm">
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-emerald-400"></span>
            <span class="text-xs text-slate-300">Resend:</span>
            <strong class="text-white"><?= number_format($stats['resendSent']) ?></strong>
          </div>
          <div class="flex items-center gap-1.5">
            <span class="w-2 h-2 rounded-full bg-sky-400"></span>
            <span class="text-xs text-slate-300">Google:</span>
            <strong class="text-white"><?= number_format($stats['googleSent']) ?></strong>
          </div>
        </div>
        <div class="mt-3 text-[11px] text-slate-400 bg-slate-900/60 p-2 rounded border border-slate-800">
          Auto-balancing prevents IP/Domain rate limiting
        </div>
      </div>

      <div class="bg-tesla-card border border-tesla-border rounded-xl p-5">
        <div class="flex items-center justify-between text-slate-400 text-xs uppercase tracking-wider font-semibold">
          <span>Delivery Errors</span>
          <i class="fa-solid fa-triangle-exclamation text-rose-400"></i>
        </div>
        <div class="mt-2 text-3xl font-extrabold text-rose-400"><?= number_format($stats['totalFailed']) ?></div>
        <p class="text-xs text-slate-500 mt-2">Zero dropouts with instant failover</p>
      </div>
    </div>

    <!-- Active Routing Architecture -->
    <div class="bg-tesla-card border border-tesla-border rounded-xl p-6">
      <div class="flex items-center justify-between mb-4 pb-3 border-b border-slate-800">
        <div>
          <h2 class="text-base font-bold text-white flex items-center gap-2">
            <i class="fa-solid fa-network-wired text-tesla-red"></i>
            <span>Domain Architecture &amp; Delivery Topology</span>
          </h2>
          <p class="text-xs text-slate-400 mt-0.5">High-deliverability 6k configuration</p>
        </div>
        <a href="?tab=gateways" class="text-xs text-tesla-red hover:underline font-semibold flex items-center gap-1">
          <span>Configure Gateways</span>
          <i class="fa-solid fa-arrow-right text-[10px]"></i>
        </a>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div class="bg-slate-900/70 border border-slate-800 p-4 rounded-lg">
          <div class="text-[11px] uppercase tracking-wider font-bold text-slate-400 mb-1">Sending Domain</div>
          <div class="text-base font-bold text-white font-mono flex items-center gap-2">
            <span><?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?></span>
            <span class="text-xs bg-emerald-950 text-emerald-400 px-1.5 py-0.5 rounded border border-emerald-800">Cloudflare</span>
          </div>
          <p class="text-xs text-slate-400 mt-2">Authenticated with dual SPF (`include:resend.com include:_spf.google.com`), DKIM &amp; DMARC for 99.8% inbox delivery.</p>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 p-4 rounded-lg">
          <div class="text-[11px] uppercase tracking-wider font-bold text-amber-400 mb-1">Inbound / Reply Destination</div>
          <div class="text-base font-bold text-amber-300 font-mono flex items-center gap-2">
            <span><?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?></span>
            <span class="text-xs bg-amber-950 text-amber-400 px-1.5 py-0.5 rounded border border-amber-800">Inbound Target</span>
          </div>
          <p class="text-xs text-slate-400 mt-2">Every outbound email injects <code>Reply-To</code> so 100% of recipient inquiries direct to <strong>teslafoundationreward.com</strong>.</p>
        </div>

        <div class="bg-slate-900/70 border border-slate-800 p-4 rounded-lg">
          <div class="text-[11px] uppercase tracking-wider font-bold text-slate-400 mb-1">Throughput Allocation</div>
          <div class="text-sm font-semibold text-white space-y-1 mt-1">
            <div class="flex justify-between">
              <span class="text-slate-400">Resend Paid API:</span>
              <span class="text-emerald-400 font-bold">~4,000 / day</span>
            </div>
            <div class="flex justify-between">
              <span class="text-slate-400">Google Workspace SMTP:</span>
              <span class="text-sky-400 font-bold">~2,000 / day</span>
            </div>
          </div>
          <p class="text-xs text-slate-400 mt-2">Combines Resend API speed with Google Workspace reputation for seamless 6,000/day delivery.</p>
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($activeTab === 'campaign'): ?>
  <!-- ==============================================
       TAB 2: BULK CAMPAIGN STUDIO (6K ENGINE)
       ============================================== -->
  <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 max-w-7xl mx-auto">
    <div class="lg:col-span-2 space-y-6">

      <!-- Interactive Live Batch Dispatcher (Visible when running a campaign) -->
      <div id="live-batch-box" class="bg-slate-900 border-2 border-tesla-red/60 rounded-xl p-5 shadow-2xl <?= isset($_GET['run_camp_id']) ? '' : 'hidden' ?>">
        <div class="flex items-center justify-between pb-3 border-b border-slate-800">
          <div class="flex items-center gap-2">
            <span class="w-3 h-3 rounded-full bg-emerald-500 animate-ping"></span>
            <h3 class="text-sm font-bold text-white" id="live-camp-title">Campaign Dispatch Controller</h3>
          </div>
          <div class="flex items-center gap-2">
            <button id="btn-pause-worker" onclick="togglePause()" class="px-3 py-1 text-xs font-semibold rounded bg-amber-600 hover:bg-amber-500 text-white transition">Pause</button>
            <button id="btn-stop-worker" onclick="stopWorker()" class="px-3 py-1 text-xs font-semibold rounded bg-rose-600 hover:bg-rose-500 text-white transition">Stop</button>
          </div>
        </div>

        <div class="mt-4">
          <div class="flex justify-between text-xs text-slate-300 font-semibold mb-1">
            <span id="live-progress-stats">Preparing dispatch queue...</span>
            <span id="live-progress-pct" class="text-tesla-red font-bold">0%</span>
          </div>
          <div class="h-3 w-full bg-slate-800 rounded-full overflow-hidden p-0.5">
            <div id="live-progress-bar" class="h-full bg-gradient-to-r from-tesla-red via-amber-500 to-emerald-400 rounded-full transition-all duration-300" style="width: 0%"></div>
          </div>
        </div>

        <div class="mt-4 bg-black/60 rounded-lg p-3 font-mono text-[11px] h-36 overflow-y-auto border border-slate-800 space-y-1" id="live-event-feed">
          <div class="text-slate-500">[System] Initializing dual gateway load balancer...</div>
        </div>
      </div>

      <!-- Campaign Creation Card -->
      <div class="bg-tesla-card border border-tesla-border rounded-xl p-6">
        <h2 class="text-base font-bold text-white mb-4 pb-2 border-b border-slate-800 flex items-center justify-between">
          <span class="flex items-center gap-2">
            <i class="fa-solid fa-envelope-open-text text-tesla-red"></i>
            <span>Create Bulk Email Campaign</span>
          </span>
          <span class="text-xs text-slate-400 font-normal">Supports up to 6,000+ per batch</span>
        </h2>

        <form method="post" action="<?= $self ?>" class="space-y-4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="create_campaign">

          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Campaign Internal Title</label>
            <input type="text" name="title" required
                   class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-tesla-red"
                   placeholder="e.g. Tesla Clean Energy Grant Allocation Batch #1">
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400">Pre-Built Templates</label>
              <span class="text-[11px] text-amber-400">Auto-loads HTML message &amp; subject</span>
            </div>
            <select id="template-picker" onchange="applyTemplate(this.value)"
                    class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-slate-200 focus:outline-none focus:border-tesla-red">
              <option value="">-- Select Template --</option>
              <?php foreach ($templates as $t): ?>
                <option value="<?= $t['id'] ?>"><?= e($t['name']) ?> (<?= e($t['category']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div>
            <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Subject Line</label>
            <input type="text" id="camp-subject" name="subject" required
                   class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-tesla-red"
                   placeholder="OFFICIAL ALLOCATION: Tesla Energy Foundation Grant Notification [Ref: {{ref_id}}]">
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-semibold text-slate-400 mb-1">From Name</label>
              <input type="text" name="sender_name" value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>"
                     class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white">
            </div>
            <div>
              <label class="block text-xs font-semibold text-slate-400 mb-1">From Email (Sending)</label>
              <input type="email" name="sender_email" value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>"
                     class="w-full px-3 py-2 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white font-mono">
            </div>
            <div>
              <label class="block text-xs font-semibold text-amber-400 mb-1">Reply-To (Replies Target)</label>
              <input type="email" name="reply_to" value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>"
                     class="w-full px-3 py-2 bg-slate-900 border border-amber-600/70 rounded-lg text-xs text-amber-300 font-mono">
            </div>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400">HTML Message Body</label>
              <button type="button" onclick="toggleVisualPreview()" class="text-xs text-tesla-red hover:underline font-semibold flex items-center gap-1">
                <i class="fa-solid fa-eye"></i> Toggle Visual Preview
              </button>
            </div>
            <textarea id="camp-body-html" name="body_html" rows="11" required
                      class="w-full p-3 bg-slate-900 border border-slate-700 rounded-lg font-mono text-xs text-slate-200 focus:outline-none focus:border-tesla-red leading-relaxed"></textarea>
            <div id="visual-preview" class="w-full p-4 bg-white rounded-lg border border-slate-700 hidden text-slate-900 overflow-auto max-h-96"></div>
          </div>

          <div>
            <div class="flex items-center justify-between mb-1">
              <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400">
                Recipients (CSV or Line-By-Line)
              </label>
              <button type="button" onclick="insertSampleLeads()" class="text-xs text-sky-400 hover:text-sky-300 font-semibold">
                + Insert 25 Sample Leads
              </button>
            </div>
            <p class="text-[11px] text-slate-500 mb-1.5">Format: <code>email</code> or <code>email,name</code> or <code>email,name,ref_id,grant_amount</code></p>
            <textarea id="camp-recipients" name="recipients" rows="6" required
                      class="w-full p-3 bg-slate-900 border border-slate-700 rounded-lg font-mono text-xs text-slate-200 focus:outline-none focus:border-tesla-red"
                      placeholder="client1@example.com,John Smith,TEF-8921,$75,000&#10;client2@example.com,Sarah Connor,TEF-4412,$120,000"></textarea>
          </div>

          <div class="bg-slate-900/80 border border-slate-800 p-4 rounded-lg space-y-3">
            <div class="text-xs font-semibold uppercase tracking-wider text-slate-300">
              Gateway Dispatch Mode
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
              <label class="flex items-start gap-2.5 p-3 rounded-lg border border-slate-700 bg-slate-950 cursor-pointer hover:border-tesla-red transition">
                <input type="radio" name="provider_mode" value="auto_balance" checked class="mt-0.5 text-tesla-red">
                <div>
                  <div class="text-xs font-bold text-white">Auto-Balance (60/40)</div>
                  <div class="text-[11px] text-slate-400">Balances Resend API &amp; Google Workspace</div>
                </div>
              </label>
              <label class="flex items-start gap-2.5 p-3 rounded-lg border border-slate-700 bg-slate-950 cursor-pointer hover:border-tesla-red transition">
                <input type="radio" name="provider_mode" value="resend_only" class="mt-0.5 text-tesla-red">
                <div>
                  <div class="text-xs font-bold text-emerald-400">Resend API Only</div>
                  <div class="text-[11px] text-slate-400">High-speed HTTPS API dispatch</div>
                </div>
              </label>
              <label class="flex items-start gap-2.5 p-3 rounded-lg border border-slate-700 bg-slate-950 cursor-pointer hover:border-tesla-red transition">
                <input type="radio" name="provider_mode" value="google_only" class="mt-0.5 text-tesla-red">
                <div>
                  <div class="text-xs font-bold text-sky-400">Google Workspace Only</div>
                  <div class="text-[11px] text-slate-400">Direct via smtp.gmail.com:465</div>
                </div>
              </label>
            </div>
          </div>

          <button type="submit"
                  class="w-full py-3.5 px-6 rounded-lg bg-tesla-red hover:bg-tesla-redHover text-white font-bold text-sm shadow-xl shadow-tesla-red/20 transition flex items-center justify-center gap-2">
            <i class="fa-solid fa-rocket"></i>
            <span>Register &amp; Launch Bulk Campaign</span>
          </button>
        </form>
      </div>
    </div>

    <!-- Right Column: Personalization Tags & History -->
    <div class="space-y-6">
      <div class="bg-tesla-card border border-tesla-border rounded-xl p-5">
        <h3 class="text-sm font-bold text-white mb-2 flex items-center gap-2">
          <i class="fa-solid fa-code text-amber-400"></i>
          <span>Dynamic Personalization Tags</span>
        </h3>
        <p class="text-xs text-slate-400 mb-3">Merge tags automatically personalized per recipient:</p>
        <div class="grid grid-cols-2 gap-2 text-xs">
          <div class="bg-slate-900 p-2 rounded border border-slate-800">
            <code class="text-tesla-red font-bold font-mono">{{name}}</code>
            <div class="text-[10px] text-slate-400">Recipient Name</div>
          </div>
          <div class="bg-slate-900 p-2 rounded border border-slate-800">
            <code class="text-tesla-red font-bold font-mono">{{email}}</code>
            <div class="text-[10px] text-slate-400">Recipient Email</div>
          </div>
          <div class="bg-slate-900 p-2 rounded border border-slate-800">
            <code class="text-tesla-red font-bold font-mono">{{ref_id}}</code>
            <div class="text-[10px] text-slate-400">Unique Code (TEF-...)</div>
          </div>
          <div class="bg-slate-900 p-2 rounded border border-slate-800">
            <code class="text-tesla-red font-bold font-mono">{{grant_amount}}</code>
            <div class="text-[10px] text-slate-400">e.g. $75,000 USD</div>
          </div>
          <div class="bg-slate-900 p-2 rounded border border-slate-800 col-span-2">
            <code class="text-tesla-red font-bold font-mono">{{date}}</code>
            <div class="text-[10px] text-slate-400">Current Formatted Date</div>
          </div>
        </div>
      </div>

      <div class="bg-tesla-card border border-tesla-border rounded-xl p-5">
        <h3 class="text-sm font-bold text-white mb-3">Recent Campaigns</h3>
        <div class="space-y-2 text-xs max-h-96 overflow-y-auto">
          <?php if (empty($recentCampaigns)): ?>
            <div class="text-slate-500 text-center py-4">No campaigns registered yet.</div>
          <?php else: foreach ($recentCampaigns as $c): ?>
            <div class="p-3 rounded-lg bg-slate-900 border border-slate-800 flex items-center justify-between">
              <div>
                <div class="font-bold text-white"><?= e($c['title']) ?></div>
                <div class="text-[11px] text-slate-400">
                  Total: <?= $c['total_recipients'] ?> &bull; Sent: <span class="text-emerald-400 font-bold"><?= $c['sent_count'] ?></span> &bull; Failed: <span class="text-rose-400 font-bold"><?= $c['failed_count'] ?></span>
                </div>
              </div>
              <div class="flex items-center gap-2">
                <a href="?tab=campaign&run_camp_id=<?= $c['id'] ?>" class="px-2.5 py-1 text-[11px] font-semibold rounded bg-tesla-red hover:bg-tesla-redHover text-white">
                  Dispatch &rarr;
                </a>
              </div>
            </div>
          <?php endforeach; endif; ?>
        </div>
      </div>
    </div>
  </div>

  <?php elseif ($activeTab === 'compose'): ?>
  <!-- ==============================================
       TAB 3: DIRECT COMPOSE (1-TO-1)
       ============================================== -->
  <div class="bg-tesla-card border border-tesla-border rounded-xl p-6 max-w-4xl mx-auto">
    <div class="flex items-center justify-between pb-3 border-b border-slate-800 mb-5">
      <div>
        <h2 class="text-base font-bold text-white flex items-center gap-2">
          <i class="fa-solid fa-paper-plane text-sky-400"></i>
          <span>Direct Single Dispatch</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">Send a high-priority 1-to-1 message or diagnostic verification</p>
      </div>
      <div class="text-xs text-amber-400 bg-amber-950/40 px-3 py-1 rounded border border-amber-900/50">
        Reply-To: <?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>
      </div>
    </div>

    <form method="post" action="<?= $self ?>" class="space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="direct_send">

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">To Recipient</label>
          <input type="email" name="to" required
                 class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-tesla-red"
                 placeholder="recipient@example.com">
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Sending Gateway</label>
          <select name="provider" class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-slate-200">
            <option value="auto_balance">Auto-Balance (Resend or Google Workspace)</option>
            <option value="resend_only">Resend Paid API Only</option>
            <option value="google_only">Google Workspace SMTP Only</option>
          </select>
        </div>
      </div>

      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Subject</label>
        <input type="text" name="subject" required
               class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white focus:outline-none focus:border-tesla-red"
               placeholder="Tesla Energy Foundation — Official Grant Notification">
      </div>

      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Message Body (HTML)</label>
        <textarea name="body_html" rows="10" required
                  class="w-full p-3.5 bg-slate-900 border border-slate-700 rounded-lg font-mono text-xs text-slate-200 focus:outline-none focus:border-tesla-red leading-relaxed"></textarea>
      </div>

      <button type="submit"
              class="py-3 px-6 rounded-lg bg-tesla-red hover:bg-tesla-redHover text-white font-bold text-sm shadow-md transition flex items-center gap-2">
        <i class="fa-solid fa-paper-plane"></i>
        <span>Dispatch Single Message</span>
      </button>
    </form>
  </div>

  <?php elseif ($activeTab === 'outbox'): ?>
  <!-- ==============================================
       TAB 4: OUTBOX & AUDIT LOGS
       ============================================== -->
  <div class="bg-tesla-card border border-tesla-border rounded-xl p-6 max-w-7xl mx-auto">
    <div class="flex items-center justify-between pb-4 border-b border-slate-800">
      <div>
        <h2 class="text-base font-bold text-white flex items-center gap-2">
          <i class="fa-solid fa-list-check text-purple-400"></i>
          <span>Outbox &amp; Delivery Audit Logs</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">Full audit trail of all sent messages and failover routing</p>
      </div>
      <a href="?tab=outbox" class="px-3 py-1.5 rounded-lg bg-slate-800 text-slate-300 hover:text-white text-xs">
        <i class="fa-solid fa-rotate-right mr-1"></i> Refresh
      </a>
    </div>

    <div class="overflow-x-auto mt-4">
      <table class="w-full text-left text-xs border-collapse">
        <thead>
          <tr class="border-b border-slate-800 text-slate-400 uppercase tracking-wider text-[10px]">
            <th class="py-3 px-3">Status</th>
            <th class="py-3 px-3">Gateway</th>
            <th class="py-3 px-3">Recipient</th>
            <th class="py-3 px-3">Subject</th>
            <th class="py-3 px-3">Message ID</th>
            <th class="py-3 px-3">Timestamp</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
          <?php if (empty($emailLogs)): ?>
            <tr><td colspan="6" class="text-center py-8 text-slate-500">No outgoing emails logged yet.</td></tr>
          <?php else: foreach ($emailLogs as $log): ?>
            <tr>
              <td class="py-3 px-3">
                <?php if ($log['status'] === 'sent'): ?>
                  <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-950 text-emerald-400 border border-emerald-800">DELIVERED</span>
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
              <td class="py-3 px-3 text-slate-500 text-[11px] max-w-[120px] truncate"><?= e($log['message_id'] ?: '—') ?></td>
              <td class="py-3 px-3 text-slate-500"><?= date('M j, H:i', strtotime($log['created_at'])) ?></td>
            </tr>
          <?php endforeach; endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <?php elseif ($activeTab === 'gateways'): ?>
  <!-- ==============================================
       TAB 5: DUAL GATEWAYS & SETTINGS
       ============================================== -->
  <form method="post" action="<?= $self ?>" class="space-y-6 max-w-6xl mx-auto">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_settings">

    <!-- Domain & Identity Routing Card -->
    <div class="bg-tesla-card border border-tesla-border rounded-xl p-6">
      <h2 class="text-base font-bold text-white mb-4 pb-2 border-b border-slate-800 flex items-center gap-2">
        <i class="fa-solid fa-globe text-tesla-red"></i>
        <span>Domain Identity &amp; Reply-To Routing</span>
      </h2>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Sending Domain</label>
          <input type="text" name="sending_domain" value="<?= e(get_setting('sending_domain', SENDING_DOMAIN)) ?>" required
                 class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white font-mono">
          <p class="text-[11px] text-slate-500 mt-1">Configured on Cloudflare for SPF, DKIM, and DMARC.</p>
        </div>

        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-amber-400 mb-1">Reply-To Destination</label>
          <input type="email" name="reply_to_email" value="<?= e(get_setting('reply_to_email', DEFAULT_REPLY_TO)) ?>" required
                 class="w-full px-3.5 py-2.5 bg-slate-900 border border-amber-600/70 rounded-lg text-sm text-amber-300 font-mono">
          <p class="text-[11px] text-amber-400/80 mt-1">All recipient replies automatically flow to <strong>teslafoundationreward.com</strong>.</p>
        </div>

        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Default From Name</label>
          <input type="text" name="default_from_name" value="<?= e(get_setting('default_from_name', DEFAULT_FROM_NAME)) ?>" required
                 class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white">
        </div>

        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Default From Email</label>
          <input type="email" name="default_from_email" value="<?= e(get_setting('default_from_email', DEFAULT_FROM_EMAIL)) ?>" required
                 class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white font-mono">
        </div>
      </div>
    </div>

    <!-- Gateway 1: Resend Paid API -->
    <div class="bg-tesla-card border border-tesla-border rounded-xl p-6">
      <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-800">
        <h2 class="text-base font-bold text-white flex items-center gap-2">
          <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
          <span>Gateway 1: Resend Paid API</span>
        </h2>
        <button type="button" onclick="testResendLive()" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-emerald-950 text-emerald-400 border border-emerald-800 hover:bg-emerald-900/50 transition">
          <i class="fa-solid fa-plug mr-1"></i> Test Resend Connection
        </button>
      </div>

      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Resend API Key (re_...)</label>
        <input type="password" id="cfg-resend-key" name="resend_api_key" value="<?= e(get_setting('resend_api_key', '')) ?>"
               class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-sm text-white font-mono"
               placeholder="re_xxxxxxxxxxxxxxxxxxxxxxxxx">
      </div>
      <div id="resend-test-feedback" class="mt-2 text-xs font-mono hidden"></div>
    </div>

    <!-- Gateway 2: Google Workspace Paid SMTP -->
    <div class="bg-tesla-card border border-tesla-border rounded-xl p-6">
      <div class="flex items-center justify-between mb-4 pb-2 border-b border-slate-800">
        <h2 class="text-base font-bold text-white flex items-center gap-2">
          <span class="w-3 h-3 rounded-full bg-sky-500"></span>
          <span>Gateway 2: Google Workspace Paid SMTP</span>
        </h2>
        <button type="button" onclick="testGoogleLive()" class="px-3 py-1.5 rounded-lg text-xs font-semibold bg-sky-950 text-sky-400 border border-sky-800 hover:bg-sky-900/50 transition">
          <i class="fa-solid fa-plug mr-1"></i> Test Google SMTP Connection
        </button>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-4 gap-4">
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">SMTP Host</label>
          <input type="text" id="cfg-google-host" name="google_smtp_host" value="<?= e(get_setting('google_smtp_host', 'smtp.gmail.com')) ?>"
                 class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white font-mono">
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Port</label>
          <input type="number" id="cfg-google-port" name="google_smtp_port" value="<?= e(get_setting('google_smtp_port', '465')) ?>"
                 class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white font-mono">
        </div>
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Security</label>
          <input type="text" readonly value="SSL / TLS" class="w-full px-3.5 py-2 bg-slate-950 border border-slate-800 rounded-lg text-xs text-slate-400">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Google Workspace Account / Email</label>
          <input type="email" id="cfg-google-user" name="google_smtp_user" value="<?= e(get_setting('google_smtp_user', '')) ?>"
                 class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white font-mono"
                 placeholder="admin@teslaenergyfoundation.com">
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Google 16-Char App Password</label>
          <input type="password" id="cfg-google-pass" name="google_smtp_pass"
                 class="w-full px-3.5 py-2 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white font-mono"
                 placeholder="<?= get_setting('google_smtp_pass') ? '•••••••••••••••• (Leave blank to keep unchanged)' : 'Enter 16-character App Password' ?>">
        </div>
      </div>
      <div id="google-test-feedback" class="mt-2 text-xs font-mono hidden"></div>
    </div>

    <!-- Dual Ratio -->
    <div class="bg-tesla-card border border-tesla-border rounded-xl p-6">
      <h2 class="text-base font-bold text-white mb-3 pb-2 border-b border-slate-800 flex items-center gap-2">
        <i class="fa-solid fa-sliders text-amber-400"></i>
        <span>Dual Gateway Load Balancing Ratio</span>
      </h2>
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 items-center">
        <div>
          <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-2">Resend vs Google Balance Ratio</label>
          <div class="flex items-center gap-4">
            <input type="range" name="resend_ratio" min="10" max="90" value="<?= (int)get_setting('resend_ratio', '60') ?>"
                   oninput="document.getElementById('ratio-num').innerText = this.value + '% / ' + (100 - this.value) + '%'"
                   class="flex-1 accent-tesla-red h-2 bg-slate-800 rounded-lg">
            <span id="ratio-num" class="font-mono text-sm font-bold text-white w-28 text-right"><?= get_setting('resend_ratio', '60') ?>% / <?= 100 - (int)get_setting('resend_ratio', '60') ?>%</span>
          </div>
        </div>
        <p class="text-xs text-slate-400 leading-relaxed">
          Splitting volume protects Google Workspace from hitting its daily 2,000 threshold while maximizing deliverability.
        </p>
      </div>
    </div>

    <div class="flex justify-end">
      <button type="submit" class="px-8 py-3.5 rounded-lg bg-tesla-red hover:bg-tesla-redHover text-white font-bold text-sm shadow-xl shadow-tesla-red/20 transition flex items-center gap-2">
        <i class="fa-solid fa-floppy-disk"></i>
        <span>Save Gateway Settings</span>
      </button>
    </div>
  </form>

  <?php elseif ($activeTab === 'dns'): ?>
  <!-- ==============================================
       TAB 6: CLOUDFLARE DNS SETUP GUIDE
       ============================================== -->
  <div class="bg-tesla-card border border-tesla-border rounded-xl p-6 max-w-6xl mx-auto space-y-5">
    <div class="flex items-center justify-between pb-4 border-b border-slate-800">
      <div>
        <h2 class="text-base font-bold text-white flex items-center gap-2">
          <i class="fa-brands fa-cloudflare text-orange-400 text-xl"></i>
          <span>Cloudflare DNS Records for teslaenergyfoundation.com</span>
        </h2>
        <p class="text-xs text-slate-400 mt-0.5">
          Dual verification records for Resend and Google Workspace
        </p>
      </div>
      <a href="https://dash.cloudflare.com" target="_blank" class="px-3.5 py-1.5 rounded-lg bg-orange-600 hover:bg-orange-500 text-white text-xs font-semibold transition flex items-center gap-1.5">
        <span>Open Cloudflare</span>
        <i class="fa-solid fa-arrow-up-right-from-square text-[10px]"></i>
      </a>
    </div>

    <div class="p-4 rounded-lg bg-slate-900 border border-slate-800 text-xs text-slate-300 space-y-2">
      <div class="font-bold text-white flex items-center gap-2">
        <i class="fa-solid fa-circle-info text-sky-400"></i>
        <span>Sending-Only Domain Architecture</span>
      </div>
      <p>Because <strong>teslaenergyfoundation.com</strong> is strictly for sending, all responses are directed to <code>teslafoundationreward.com</code> via the <code>Reply-To</code> header. Add these DNS records to Cloudflare:</p>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-left text-xs border-collapse">
        <thead>
          <tr class="border-b border-slate-800 text-slate-400 uppercase tracking-wider text-[10px]">
            <th class="py-3 px-3">Type</th>
            <th class="py-3 px-3">Name / Host</th>
            <th class="py-3 px-3">Content / Value</th>
            <th class="py-3 px-3">Proxy Status</th>
            <th class="py-3 px-3">Purpose</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-slate-800/60 font-mono text-slate-300">
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 font-bold">TXT</span></td>
            <td class="py-3 px-3 font-bold text-white">@</td>
            <td class="py-3 px-3"><code class="bg-black/40 px-2 py-1 rounded text-emerald-400">v=spf1 include:resend.com include:_spf.google.com ~all</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Authorizes Resend + Google Workspace</td>
          </tr>
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 font-bold">TXT</span></td>
            <td class="py-3 px-3 font-bold text-white">_dmarc</td>
            <td class="py-3 px-3"><code class="bg-black/40 px-2 py-1 rounded text-emerald-400">v=DMARC1; p=quarantine; rua=mailto:dmarc@teslafoundationreward.com</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">DMARC domain security</td>
          </tr>
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-amber-950 text-amber-400 font-bold">CNAME</span></td>
            <td class="py-3 px-3 font-bold text-white">resend._domainkey</td>
            <td class="py-3 px-3"><code class="bg-black/40 px-2 py-1 rounded text-slate-300">provided-by.resend.com</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Resend DKIM record</td>
          </tr>
          <tr>
            <td class="py-3 px-3"><span class="px-2 py-0.5 rounded bg-sky-950 text-sky-400 font-bold">TXT</span></td>
            <td class="py-3 px-3 font-bold text-white">google._domainkey</td>
            <td class="py-3 px-3"><code class="bg-black/40 px-2 py-1 rounded text-slate-300">v=DKIM1; k=rsa; p=YOUR_GOOGLE_PUBLIC_KEY</code></td>
            <td class="py-3 px-3 text-slate-400 font-sans">DNS Only (Grey Cloud)</td>
            <td class="py-3 px-3 text-slate-400 font-sans">Google Workspace DKIM record</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

</main>

<!-- Live Diagnostic Modal -->
<div id="diag-modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/80 backdrop-blur-sm p-4 hidden">
  <div class="w-full max-w-lg bg-tesla-card border border-tesla-border rounded-xl shadow-2xl p-6">
    <div class="flex items-center justify-between pb-3 border-b border-slate-800">
      <h3 class="text-sm font-bold text-white flex items-center gap-2">
        <i class="fa-solid fa-vial text-emerald-400"></i>
        <span>Live Diagnostic Email Check</span>
      </h3>
      <button onclick="closeDiagnosticModal()" class="text-slate-400 hover:text-white">&times;</button>
    </div>
    <div class="mt-4 space-y-3">
      <p class="text-xs text-slate-400">
        Dispatches a live verification test email to verify domain authentication and reply-to routing.
      </p>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Recipient Address:</label>
        <input type="email" id="diag-to-input" class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-white" placeholder="your-email@example.com">
      </div>
      <div>
        <label class="block text-xs font-semibold uppercase tracking-wider text-slate-400 mb-1">Gateway to Test:</label>
        <select id="diag-provider-select" class="w-full px-3.5 py-2.5 bg-slate-900 border border-slate-700 rounded-lg text-xs text-slate-200">
          <option value="auto_balance">Auto-Balance (Dual Gateway)</option>
          <option value="resend_only">Resend Paid API</option>
          <option value="google_only">Google Workspace SMTP</option>
        </select>
      </div>
      <div id="diag-modal-feedback" class="p-3 rounded-lg text-xs font-mono hidden"></div>
      <div class="pt-2 flex justify-end gap-2">
        <button onclick="closeDiagnosticModal()" class="px-4 py-2 rounded-lg bg-slate-800 text-slate-300 text-xs font-semibold hover:bg-slate-700">Cancel</button>
        <button onclick="runLiveDiagnosticTest()" class="px-5 py-2 rounded-lg bg-tesla-red hover:bg-tesla-redHover text-white text-xs font-bold transition flex items-center gap-1.5">
          <i class="fa-solid fa-paper-plane"></i>
          <span>Send Live Test</span>
        </button>
      </div>
    </div>
  </div>
</div>

<script>
// Template auto-fill dictionary
const TEMPLATES = <?= json_encode($templates) ?>;

function applyTemplate(id) {
  const t = TEMPLATES.find(x => String(x.id) === String(id));
  if (t) {
    document.getElementById('camp-subject').value = t.subject;
    document.getElementById('camp-body-html').value = t.body_html;
    const v = document.getElementById('visual-preview');
    if (!v.classList.contains('hidden')) {
      v.innerHTML = t.body_html;
    }
  }
}

function toggleVisualPreview() {
  const box = document.getElementById('visual-preview');
  const txt = document.getElementById('camp-body-html');
  if (box.classList.contains('hidden')) {
    box.innerHTML = txt.value;
    box.classList.remove('hidden');
  } else {
    box.classList.add('hidden');
  }
}

function insertSampleLeads() {
  const samples = [
    'alex.morgan99@gmail.com,Alex Morgan,TEF-8921,$75,000',
    'marcus.vance@outlook.com,Marcus Vance,TEF-9310,$120,000',
    'elena.rostova@yahoo.com,Elena Rostova,TEF-4822,$75,000',
    'david.chen22@proton.me,David Chen,TEF-1109,$95,000',
    'sarah.jenkins@icloud.com,Sarah Jenkins,TEF-6031,$75,000'
  ];
  const t = document.getElementById('camp-recipients');
  t.value = (t.value.trim() ? t.value.trim() + "\n" : "") + samples.join("\n");
}

// Modal controls
function openDiagnosticModal() { document.getElementById('diag-modal').classList.remove('hidden'); }
function closeDiagnosticModal() { document.getElementById('diag-modal').classList.add('hidden'); }

// Live Diagnostics via api.php
async function runLiveDiagnosticTest() {
  const to = document.getElementById('diag-to-input').value.trim();
  const prov = document.getElementById('diag-provider-select').value;
  const fb = document.getElementById('diag-modal-feedback');

  if (!to) { alert('Please enter recipient email'); return; }
  fb.className = 'p-3 rounded-lg text-xs font-mono bg-slate-900 border border-slate-700 text-slate-300';
  fb.innerText = 'Connecting to gateway and dispatching diagnostic email...';
  fb.classList.remove('hidden');

  const fd = new FormData();
  fd.append('test_to', to);
  fd.append('provider', prov);

  try {
    const res = await fetch('api.php?action=test_diagnostic', { method: 'POST', body: fd });
    const data = await res.json();
    if (data.ok) {
      fb.className = 'p-3 rounded-lg text-xs font-mono bg-emerald-950 text-emerald-300 border border-emerald-800';
      fb.innerText = 'Success! Delivered via ' + data.provider + ' (Message ID: ' + data.id + ')';
    } else {
      fb.className = 'p-3 rounded-lg text-xs font-mono bg-rose-950 text-rose-300 border border-rose-800';
      fb.innerText = 'Failed: ' + (data.error || 'Unknown error');
    }
  } catch (e) {
    fb.className = 'p-3 rounded-lg text-xs font-mono bg-rose-950 text-rose-300 border border-rose-800';
    fb.innerText = 'Network error: ' + e.message;
  }
}

async function testResendLive() {
  const key = document.getElementById('cfg-resend-key').value.trim();
  const fb = document.getElementById('resend-test-feedback');
  fb.className = 'mt-2 text-xs font-mono text-slate-400';
  fb.innerText = 'Verifying Resend API key...';
  fb.classList.remove('hidden');

  const fd = new FormData();
  fd.append('api_key', key);
  const res = await fetch('api.php?action=test_resend', { method: 'POST', body: fd });
  const d = await res.json();
  if (d.ok) {
    fb.className = 'mt-2 text-xs font-mono text-emerald-400';
    fb.innerText = '✓ ' + d.message;
  } else {
    fb.className = 'mt-2 text-xs font-mono text-rose-400';
    fb.innerText = '✗ ' + (d.error || 'Failed');
  }
}

async function testGoogleLive() {
  const host = document.getElementById('cfg-google-host').value.trim();
  const port = document.getElementById('cfg-google-port').value.trim();
  const user = document.getElementById('cfg-google-user').value.trim();
  const pass = document.getElementById('cfg-google-pass').value.trim();
  const fb = document.getElementById('google-test-feedback');
  fb.className = 'mt-2 text-xs font-mono text-slate-400';
  fb.innerText = 'Connecting to Google Workspace SMTP stream...';
  fb.classList.remove('hidden');

  const fd = new FormData();
  fd.append('host', host);
  fd.append('port', port);
  fd.append('user', user);
  fd.append('pass', pass);
  const res = await fetch('api.php?action=test_google', { method: 'POST', body: fd });
  const d = await res.json();
  if (d.ok) {
    fb.className = 'mt-2 text-xs font-mono text-emerald-400';
    fb.innerText = '✓ ' + d.message;
  } else {
    fb.className = 'mt-2 text-xs font-mono text-rose-400';
    fb.innerText = '✗ ' + (d.error || 'Failed');
  }
}

// ----------------------------------------------------
// Interactive Bulk Batch Runner (for 6k Campaigns)
// ----------------------------------------------------
let isWorkerPaused = false;
let isWorkerStopped = false;

function togglePause() {
  isWorkerPaused = !isWorkerPaused;
  const btn = document.getElementById('btn-pause-worker');
  if (btn) btn.innerText = isWorkerPaused ? 'Resume' : 'Pause';
}

function stopWorker() {
  isWorkerStopped = true;
  logFeed('[System] Worker halted by user.');
}

function logFeed(msg) {
  const feed = document.getElementById('live-event-feed');
  if (!feed) return;
  const d = document.createElement('div');
  d.innerText = '[' + new Date().toLocaleTimeString() + '] ' + msg;
  feed.appendChild(d);
  feed.scrollTop = feed.scrollHeight;
}

<?php if (isset($_GET['run_camp_id'])): ?>
// Automatically kick off batch loop if run_camp_id is in URL
const RUN_CAMP_ID = <?= (int)$_GET['run_camp_id'] ?>;
window.addEventListener('DOMContentLoaded', () => {
  startBulkBatchLoop(RUN_CAMP_ID);
});

async function startBulkBatchLoop(campId) {
  logFeed(`Starting campaign #${campId} bulk dispatch engine...`);
  let totalProcessed = 0;

  while (!isWorkerStopped) {
    if (isWorkerPaused) {
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
        logFeed(`Error: ${data.error}`);
        break;
      }

      totalProcessed += data.processed;
      const rem = data.remaining;
      const totalAll = totalProcessed + rem;
      const pct = totalAll > 0 ? Math.round((totalProcessed / totalAll) * 100) : 100;

      document.getElementById('live-progress-bar').style.width = pct + '%';
      document.getElementById('live-progress-pct').innerText = pct + '%';
      document.getElementById('live-progress-stats').innerText = `Dispatched: ${totalProcessed} | Remaining: ${rem}`;

      if (data.results) {
        for (const item of data.results) {
          if (item.status === 'sent') {
            logFeed(`✓ [${item.provider}] Delivered to ${item.email}`);
          } else {
            logFeed(`✗ [${item.provider}] Failed ${item.email}: ${item.error}`);
          }
        }
      }

      if (data.completed || rem === 0) {
        logFeed(`✓✓ Campaign #${campId} completed 100%! All recipients dispatched.`);
        break;
      }

      // Rest 1 second between chunks
      await new Promise(r => setTimeout(r, 1000));

    } catch (err) {
      logFeed(`Chunk network error: ${err.message}. Retrying in 3s...`);
      await new Promise(r => setTimeout(r, 3000));
    }
  }
}
<?php endif; ?>
</script>
<?php endif; ?>

</body>
</html>
