<?php
/**
 * Tesla Energy Foundation Mailer — API Dispatch Controller
 * Handles Asynchronous Bulk Batching, Live Testing, and System Actions
 */

require_once __DIR__ . '/includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$pdo = db();

// Require authentication for all API endpoints except public inbound webhook
if ($action !== 'inbound_webhook' && !is_authed()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit;
}

switch ($action) {

    // 1. Dispatch a Batch Chunk (for 6k bulk campaigns)
    case 'dispatch_chunk':
        @set_time_limit(0);
        $campaignId = (int)($_POST['campaign_id'] ?? 0);
        $batchSize = max(1, min((int)($_POST['batch_size'] ?? 30), 100));

        if ($campaignId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid campaign ID.']);
            exit;
        }

        // Fetch campaign details
        $campSt = $pdo->prepare('SELECT * FROM campaigns WHERE id = ?');
        $campSt->execute([$campaignId]);
        $campaign = $campSt->fetch();

        if (!$campaign) {
            echo json_encode(['success' => false, 'error' => 'Campaign record not found.']);
            exit;
        }

        // Fetch pending recipients in this batch
        $recipSt = $pdo->prepare("SELECT * FROM recipients WHERE campaign_id = ? AND status = 'pending' ORDER BY id ASC LIMIT ?");
        $recipSt->execute([$campaignId, $batchSize]);
        $recipients = $recipSt->fetchAll();

        if (empty($recipients)) {
            // Check if any remain pending
            $remSt = $pdo->prepare("SELECT COUNT(*) FROM recipients WHERE campaign_id = ? AND status = 'pending'");
            $remSt->execute([$campaignId]);
            $remaining = (int)$remSt->fetchColumn();

            if ($remaining === 0) {
                $pdo->prepare("UPDATE campaigns SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$campaignId]);
            }

            echo json_encode([
                'success'   => true,
                'completed' => true,
                'processed' => 0,
                'sent'      => 0,
                'failed'    => 0,
                'remaining' => 0,
                'message'   => 'All recipients in campaign have been processed!'
            ]);
            exit;
        }

        $dailyLimit = (int)get_setting('daily_max_limit', '6000');
        $todaySentSoFar = get_today_sent_count();

        if ($todaySentSoFar >= $dailyLimit) {
            echo json_encode([
                'success'   => false,
                'completed' => false,
                'quota_hit' => true,
                'error'     => "Daily dispatch limit of " . number_format($dailyLimit) . " emails reached for today ({$todaySentSoFar} sent). Sending stopped automatically to protect sender reputation.",
                'processed' => 0,
                'sent'      => 0,
                'failed'    => 0,
                'remaining' => count($recipients)
            ]);
            exit;
        }

        $sentCount = 0;
        $failedCount = 0;
        $batchResults = [];

        $updateRecip = $pdo->prepare('UPDATE recipients SET status = ?, provider_used = ?, message_id = ?, error_message = ?, sent_at = CURRENT_TIMESTAMP WHERE id = ?');

        foreach ($recipients as $r) {
            if ($todaySentSoFar + $sentCount >= $dailyLimit) {
                $batchResults[] = [
                    'email'    => $r['email'],
                    'status'   => 'quota_halted',
                    'error'    => 'Daily limit of 6,000 emails reached.'
                ];
                break;
            }

            $vars = [];
            if (!empty($r['variables_json'])) {
                $vars = json_decode($r['variables_json'], true) ?: [];
            }

            // Defaults for variables
            if (empty($vars['email'])) $vars['email'] = $r['email'];
            if (empty($vars['name'])) $vars['name'] = $r['name'] ?: explode('@', $r['email'])[0];
            if (empty($vars['ref_id'])) $vars['ref_id'] = generate_ref();
            if (empty($vars['grant_amount'])) $vars['grant_amount'] = '$75,000 USD';
            if (empty($vars['date'])) $vars['date'] = date('F j, Y');

            $personalizedSubject = interpolate_tags($campaign['subject'], $vars);
            $personalizedHtml = interpolate_tags($campaign['body_html'], $vars);
            $personalizedText = !empty($campaign['body_text']) ? interpolate_tags($campaign['body_text'], $vars) : '';

            $res = send_dual_email(
                $r['email'],
                $personalizedSubject,
                $personalizedHtml,
                $personalizedText,
                $campaign['sender_name'] ?: DEFAULT_FROM_NAME,
                $campaign['sender_email'] ?: DEFAULT_FROM_EMAIL,
                $campaign['reply_to'] ?: DEFAULT_REPLY_TO,
                $campaign['provider_mode'],
                $campaignId,
                $campaign['cc_address'] ?? '',
                $campaign['bcc_address'] ?? ''
            );

            if (!empty($res['ok'])) {
                $sentCount++;
                $updateRecip->execute(['sent', $res['provider'], $res['id'], null, $r['id']]);
                $batchResults[] = [
                    'email'    => $r['email'],
                    'status'   => 'sent',
                    'provider' => $res['provider'],
                    'id'       => $res['id']
                ];
            } else {
                $failedCount++;
                $updateRecip->execute(['failed', $res['provider'], null, $res['error'], $r['id']]);
                $batchResults[] = [
                    'email'    => $r['email'],
                    'status'   => 'failed',
                    'provider' => $res['provider'],
                    'error'    => $res['error']
                ];
            }

            // Micro-delay between emails (e.g. 100ms)
            usleep(120000);
        }

        // Update campaign aggregated counts
        $pdo->prepare('UPDATE campaigns SET sent_count = sent_count + ?, failed_count = failed_count + ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?')
            ->execute([$sentCount, $failedCount, $campaignId]);

        // Remaining count
        $remSt = $pdo->prepare("SELECT COUNT(*) FROM recipients WHERE campaign_id = ? AND status = 'pending'");
        $remSt->execute([$campaignId]);
        $remaining = (int)$remSt->fetchColumn();

        if ($remaining === 0) {
            $pdo->prepare("UPDATE campaigns SET status = 'completed', updated_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$campaignId]);
        }

        echo json_encode([
            'success'   => true,
            'completed' => ($remaining === 0),
            'processed' => count($recipients),
            'sent'      => $sentCount,
            'failed'    => $failedCount,
            'remaining' => $remaining,
            'results'   => $batchResults
        ]);
        break;

    // 2. Test Resend Connection
    case 'test_resend':
        $apiKey = clean($_POST['api_key'] ?? '');
        $res = test_resend_api($apiKey ?: null);
        echo json_encode($res);
        break;

    // 3. Test Google Workspace Connection
    case 'test_google':
        $host = clean($_POST['host'] ?? '');
        $port = (int)($_POST['port'] ?? 465);
        $user = clean($_POST['user'] ?? '');
        $pass = clean($_POST['pass'] ?? '');
        $res = test_google_smtp($host ?: null, $port ?: null, $user ?: null, $pass ?: null);
        echo json_encode($res);
        break;

    // 4. Live Diagnostic Email Test
    case 'test_diagnostic':
        $testTo = filter_var(clean($_POST['test_to'] ?? ''), FILTER_VALIDATE_EMAIL);
        $provider = clean($_POST['provider'] ?? 'auto_balance');

        if (!$testTo) {
            echo json_encode(['ok' => false, 'error' => 'Please provide a valid recipient email.']);
            exit;
        }

        $subject = '[DIAGNOSTIC TEST] Tesla Energy Foundation Mailer Live Check — ' . date('H:i:s');
        $html = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; border: 1px solid #e2e8f0; border-radius: 12px; overflow: hidden; background: #ffffff;">
          <div style="background: #0f1115; padding: 24px; text-align: center; border-bottom: 3px solid #e82127;">
            <h2 style="color: #ffffff; margin: 0; font-size: 20px; letter-spacing: 2px;">TESLA ENERGY FOUNDATION</h2>
            <p style="color: #94a3b8; font-size: 13px; margin: 6px 0 0;">Dual Gateway Live Diagnostic Verification</p>
          </div>
          <div style="padding: 24px; color: #334155; line-height: 1.6;">
            <p>Hello,</p>
            <p>This is a live diagnostic verification dispatched from your <strong>Tesla Energy Foundation Mailer System</strong>.</p>
            <div style="background: #f8fafc; border-left: 4px solid #e82127; padding: 12px 16px; border-radius: 4px; margin: 18px 0; font-size: 14px;">
              <strong>Sending Domain:</strong> ' . htmlspecialchars(get_setting('sending_domain', SENDING_DOMAIN)) . '<br>
              <strong>Inbound Reply Routing:</strong> ' . htmlspecialchars(get_setting('reply_to_email', DEFAULT_REPLY_TO)) . '<br>
              <strong>Requested Gateway:</strong> ' . htmlspecialchars($provider) . '<br>
              <strong>Dispatched At:</strong> ' . date('r') . '
            </div>
            <p><strong>Reply-To Test:</strong> Click <em>Reply</em> in your email client to verify that your message directs straight to <code>' . htmlspecialchars(get_setting('reply_to_email', DEFAULT_REPLY_TO)) . '</code>.</p>
          </div>
          <div style="background: #f8fafc; padding: 14px; text-align: center; font-size: 12px; color: #94a3b8; border-top: 1px solid #e2e8f0;">
            Tesla Energy Foundation &bull; teslaenergyfoundation.com
          </div>
        </div>';

        $res = send_dual_email(
            $testTo,
            $subject,
            $html,
            strip_tags($html),
            '',
            '',
            '',
            $provider
        );

        echo json_encode($res);
        break;

    // 5. Get Live Dashboard Statistics
    case 'get_stats':
        $todayCond = is_mysql() ? "DATE(created_at) = CURDATE()" : "date(created_at) = date('now')";
        $totalSent = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent'")->fetchColumn();
        $totalFailed = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'failed'")->fetchColumn();
        $todaySent = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND {$todayCond}")->fetchColumn();
        $resendSent = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'resend'")->fetchColumn();
        $googleSent = (int)$pdo->query("SELECT COUNT(*) FROM email_logs WHERE status = 'sent' AND provider = 'google_workspace'")->fetchColumn();

        $inboxStats = get_inbox_stats();
        echo json_encode([
            'success'     => true,
            'totalSent'   => $totalSent,
            'totalFailed' => $totalFailed,
            'todaySent'   => $todaySent,
            'resendSent'  => $resendSent,
            'googleSent'  => $googleSent,
            'dailyTarget' => 6000,
            'inboxTotal'  => $inboxStats['total'],
            'inboxUnread' => $inboxStats['unread']
        ]);
        break;

    // 6. Inbox Management Actions
    case 'mark_inbox_read':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) mark_inbox_read($id, true);
        echo json_encode(['success' => true]);
        break;

    case 'mark_all_inbox_read':
        mark_all_inbox_read();
        echo json_encode(['success' => true]);
        break;

    case 'delete_inbox_msg':
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) delete_inbox_message($id);
        echo json_encode(['success' => true]);
        break;

    case 'get_inbox_msg':
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        $st = $pdo->prepare('SELECT * FROM inbox_messages WHERE id = ?');
        $st->execute([$id]);
        $msg = $st->fetch();
        if ($msg) {
            mark_inbox_read($id, true);
            echo json_encode(['success' => true, 'message' => $msg]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Message not found']);
        }
        break;

    // 7. Inbound Webhook (Cloudflare Email Routing / External receiver)
    case 'inbound_webhook':
        $raw = file_get_contents('php://input');
        $data = json_decode($raw, true) ?: $_POST;

        $from = clean($data['from'] ?? $data['sender'] ?? '', 120);
        $fromName = clean($data['from_name'] ?? explode('@', $from)[0] ?? '', 120);
        $to = clean($data['to'] ?? $data['recipient'] ?? DEFAULT_REPLY_TO, 120);
        $subject = clean($data['subject'] ?? 'Inbound Reply', 255);
        $html = (string)($data['html'] ?? $data['body_html'] ?? '');
        $text = (string)($data['text'] ?? $data['body_text'] ?? strip_tags($html));
        $snippet = mb_substr(strip_tags($text ?: $html), 0, 160);
        $msgId = clean($data['message_id'] ?? ('<in-' . time() . '.' . bin2hex(random_bytes(6)) . '@' . SENDING_DOMAIN . '>'), 150);

        if ($from && filter_var($from, FILTER_VALIDATE_EMAIL)) {
            $ins = $pdo->prepare('INSERT INTO inbox_messages (message_id, from_name, from_email, to_email, subject, body_html, body_text, snippet, is_read, category) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, "replies")');
            $ins->execute([$msgId, $fromName, $from, $to, $subject, $html, $text, $snippet]);
            echo json_encode(['success' => true, 'id' => (int)$pdo->lastInsertId()]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Missing sender email']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Unknown action.']);
        break;
}
