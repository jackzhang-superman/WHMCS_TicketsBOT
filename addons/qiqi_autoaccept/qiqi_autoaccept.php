<?php
/**
 * Qiqi Auto Accept — WHMCS 8.11.2 addon module
 *
 * Auto-accepts paid pending orders immediately on payment, and optionally
 * deletes stale unpaid pending orders after a configurable age.
 *
 * @see https://developers.whmcs.com/addon-modules/
 */

use Qiqi\AutoAccept\AutoAccept;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/AutoAccept.php';

/**
 * Addon configuration shown at Setup → Addon Modules.
 *
 * @return array<string, mixed>
 */
function qiqi_autoaccept_config()
{
    return [
        'name' => 'Qiqi Auto Accept',
        'description' => 'Immediately accepts Pending orders when their invoice becomes Paid '
            . '(InvoicePaid / OrderPaid, with an AfterCronJob catch-up). Also accepts free-trial '
            . 'Pending orders (amount=0 / invoiceid=0) via AfterShoppingCartCheckout + cron. '
            . 'Optionally deletes stale Unpaid + Pending orders older than a configurable number of hours. '
            . 'Never touches Refunded, Cancelled, Collections, Fraud, or non-Pending orders. '
            . 'Zero-amount invoices that WHMCS marks Paid are treated as paid. '
            . 'Requires the WHMCS system cron (crons/cron.php). '
            . '立即在发票 Paid 时接受 Pending 订单；免费试用 Pending 亦自动接受；可选删除超时未支付的 Pending 订单。'
            . '不处理退款/取消/催收/欺诈/非 Pending 订单。',
        'author' => 'Qiqi',
        'language' => 'english',
        'version' => '1.2.0',
        'fields' => [
            'enable_autoaccept' => [
                'FriendlyName' => 'Enable auto-accept paid pending',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Accept Pending orders as soon as the related invoice status is Paid. / 发票 Paid 后立即接受 Pending 订单。',
            ],
            'enable_free_autoaccept' => [
                'FriendlyName' => 'Enable auto-accept free pending',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Accept free-trial / free product Pending orders (amount=0, no invoice) on checkout and via cron catch-up. / 自动接受免费试用 Pending 订单（金额 0、无发票）。',
            ],
            'autosetup' => [
                'FriendlyName' => 'Auto-setup products on accept',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Passed to AcceptOrder as autosetup. / 接受订单时自动开通产品。',
            ],
            'sendemail' => [
                'FriendlyName' => 'Send emails on accept',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Passed to AcceptOrder as sendemail. / 接受订单时发送自动邮件。',
            ],
            'registrar' => [
                'FriendlyName' => 'Auto-register domains on accept',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'Passed to AcceptOrder as sendregistrar (WHMCS 8.11 boolean). / 接受订单时向注册商提交域名注册。',
            ],
            'enable_delete_unpaid' => [
                'FriendlyName' => 'Enable delete unpaid pending',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => 'On each WHMCS cron tick, delete Pending orders whose invoice is still Unpaid and older than the hours below. / 定时删除超时未支付的 Pending 订单（DeleteOrder，不是仅取消）。',
            ],
            'unpaid_max_hours' => [
                'FriendlyName' => 'Unpaid pending max age hours',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '6',
                'Description' => 'Hours after tblorders.date before an Unpaid + Pending order is deleted. Default 6. / 未支付 Pending 订单保留小时数，默认 6。',
            ],
            'admin_username' => [
                'FriendlyName' => 'Admin username for localAPI',
                'Type' => 'text',
                'Size' => '30',
                'Default' => '',
                'Description' => 'Optional. Leave blank to omit (WHMCS 7.2+). / 可选，留空则不传 admin 用户名。',
            ],

            'tg_bot_token' => [
                'FriendlyName' => 'Telegram bot token (pause alerts)',
                'Type' => 'password',
                'Size' => '60',
                'Default' => '',
                'Description' => 'Optional override for THIS site only. Leave blank to use this WHMCS site\'s includes/telegram config (same bot as telegram_ticket_push). Never paste another site\'s token. / 可选覆盖；留空则用本站 includes/telegram（与工单推送同一 bot）。不要填另一站点的 token。',
            ],
            'tg_chat_id' => [
                'FriendlyName' => 'Telegram chat id (pause alerts)',
                'Type' => 'text',
                'Size' => '30',
                'Default' => '',
                'Description' => 'Optional override chat/channel id for pause alerts on THIS site. Leave blank to use includes/telegram TG_CHAT_ID. / 可选覆盖；留空用本站 TG_CHAT_ID。',
            ],
        ],
    ];
}

/**
 * Create the log table. Does not modify WHMCS core tables.
 *
 * @return array{status: string, description: string}
 */
function qiqi_autoaccept_activate()
{
    try {
        AutoAccept::ensureLogTable();
        AutoAccept::ensurePauseTable();
        if (function_exists('logActivity')) {
            logActivity('Qiqi Auto Accept: module activated');
        }
        return [
            'status' => 'success',
            'description' => 'Qiqi Auto Accept activated. Log table mod_qiqi_autoaccept_log is ready. '
                . 'Configure the module, save changes, and keep the WHMCS cron running. '
                . 'Do not stop the existing human/browser pending-order process until this addon is observed working in production. '
                . '已激活。请配置并保存，确认 cron 正在运行。在生产环境验证成功前，不要停掉现有人工/浏览器待审订单流程。',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => 'Unable to create mod_qiqi_autoaccept_log: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivate. The log table is intentionally kept.
 *
 * @return array{status: string, description: string}
 */
function qiqi_autoaccept_deactivate()
{
    if (function_exists('logActivity')) {
        logActivity('Qiqi Auto Accept: module deactivated (log table retained)');
    }
    return [
        'status' => 'success',
        'description' => 'Qiqi Auto Accept deactivated. Hooks will no longer run. '
            . 'The log table mod_qiqi_autoaccept_log was not dropped. '
            . '已停用；日志表已保留未删除。',
    ];
}

/**
 * Admin area page: counts + paginated log (newest first).
 *
 * @param array<string, mixed> $vars
 */
function qiqi_autoaccept_output($vars)
{
    $modulelink = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=qiqi_autoaccept');
    $page = isset($_GET['logpage']) ? (int) $_GET['logpage'] : 1;
    $h = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    // Manual resume of a paused invoice/action
    if (!empty($_POST['aa_resume_id'])) {
        $rid = (int) $_POST['aa_resume_id'];
        try {
            if (AutoAccept::resumePause($rid)) {
                echo '<div class="alert alert-success">Resumed pause #' . $rid . ' / 已恢复暂停 #' . $rid . '</div>';
            } else {
                echo '<div class="alert alert-warning">Pause #' . $rid . ' not found / 未找到</div>';
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">Resume failed: ' . $h($e->getMessage()) . '</div>';
        }
    }

    if (!empty($_POST['aa_force_prune'])) {
        try {
            $pr = AutoAccept::pruneLogs(true);
            echo '<div class="alert alert-info">Log prune: deleted=' . (int) $pr['deleted']
                . ' kept=' . (int) $pr['kept'] . '</div>';
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">Prune failed: ' . $h($e->getMessage()) . '</div>';
        }
    }

    try {
        $data = AutoAccept::adminLogPage($page, 50);
        $paused = AutoAccept::listPaused(100);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">Unable to load logs: '
            . $h($e->getMessage())
            . '</div>';
        return;
    }

    $on = static function (string $key) use ($vars): string {
        $raw = (string) ($vars[$key] ?? '');
        $yes = AutoAccept::isYes($raw !== '' ? $raw : 'off');
        return $yes ? 'ON / 开' : 'OFF / 关';
    };

    $lastError = $data['last_error'];
    $lastErrorHtml = 'None / 无';
    if ($lastError) {
        $lastErrorHtml = $h($lastError->created_at)
            . ' — order #' . $h($lastError->order_id)
            . ' invoice #' . $h($lastError->invoice_id)
            . ' — ' . $h($lastError->reason);
    }

    echo '<h2>Qiqi Auto Accept</h2>';
    echo '<p>Auto-accept paid pending orders; pause after 5 consecutive errors (one Telegram via this site\'s bot); rolling log retention.<br>';
    echo '支付后自动审单；同一发票动作连续失败 5 次则暂停并经<strong>本站</strong> Telegram bot 告警一次；日志自动滚动清理。</p>';

    echo '<div class="row" style="margin-bottom:16px;">';
    echo '  <div class="col-md-3"><div class="panel panel-success"><div class="panel-heading">Accepted today / 今日已接受</div>';
    echo '  <div class="panel-body" style="font-size:28px;">' . (int) $data['accepted_today'] . '</div></div></div>';
    echo '  <div class="col-md-3"><div class="panel panel-warning"><div class="panel-heading">Deleted today / 今日已删除</div>';
    echo '  <div class="panel-body" style="font-size:28px;">' . (int) $data['deleted_today'] . '</div></div></div>';
    echo '  <div class="col-md-3"><div class="panel panel-danger"><div class="panel-heading">Paused / 已暂停</div>';
    echo '  <div class="panel-body" style="font-size:28px;">' . count($paused) . '</div></div></div>';
    echo '  <div class="col-md-3"><div class="panel panel-danger"><div class="panel-heading">Last error / 最近错误</div>';
    echo '  <div class="panel-body">' . $lastErrorHtml . '</div></div></div>';
    echo '</div>';

    echo '<p>';
    echo 'Auto-accept: <strong>' . $h($on('enable_autoaccept')) . '</strong> &nbsp;|&nbsp; ';
    echo 'Free auto-accept: <strong>' . $h($on('enable_free_autoaccept')) . '</strong> &nbsp;|&nbsp; ';
    echo 'Auto-setup: <strong>' . $h($on('autosetup')) . '</strong> &nbsp;|&nbsp; ';
    echo 'Delete unpaid: <strong>' . $h($on('enable_delete_unpaid')) . '</strong> &nbsp;|&nbsp; ';
    echo 'Max age hours: <strong>' . $h((string) ($vars['unpaid_max_hours'] ?? '6')) . '</strong> &nbsp;|&nbsp; ';
    echo 'Log rows: <strong>' . (int) $data['total'] . '</strong> (~' . (int) $data['pages'] . ' pages)';
    echo '</p>';

    $tgTok = trim((string) ($vars['tg_bot_token'] ?? ''));
    $tgChat = trim((string) ($vars['tg_chat_id'] ?? ''));
    echo '<p class="text-muted">Pause Telegram: addon override token '
        . ($tgTok !== '' ? '<strong>SET</strong>' : '<strong>blank → site includes/telegram bot</strong>')
        . '; chat ' . ($tgChat !== '' ? '<strong>SET (' . $h($tgChat) . ')</strong>' : '<strong>blank → site TG_CHAT_ID</strong>')
        . '. CF and qiqi must use separate bots. / 告警 bot：插件覆盖或本站 telegram 配置；两站不得共用同一 bot token。</p>';

    echo '<h3>Paused invoices / 已暂停的发票动作</h3>';
    echo '<p class="text-muted">After 5 consecutive errors, retries stop until you Resume (or success path clears). One Telegram per pause. / 连续 5 次失败后暂停，点 Resume 恢复；每次暂停只告警一次。</p>';
    echo '<table class="table table-striped table-bordered"><thead><tr>';
    echo '<th>ID</th><th>Invoice</th><th>Order</th><th>Action</th><th>Errors</th><th>Paused at</th><th>Last error</th><th></th>';
    echo '</tr></thead><tbody>';
    if (count($paused) === 0) {
        echo '<tr><td colspan="8">None paused / 当前无暂停</td></tr>';
    }
    foreach ($paused as $row) {
        echo '<tr>';
        echo '<td>' . (int) $row->id . '</td>';
        echo '<td>#' . (int) $row->invoice_id . '</td>';
        echo '<td>#' . (int) $row->order_id . '</td>';
        echo '<td>' . $h($row->action) . '</td>';
        echo '<td>' . (int) $row->consecutive_errors . '</td>';
        echo '<td>' . $h($row->paused_at) . '</td>';
        echo '<td>' . $h($row->last_error) . '</td>';
        echo '<td><form method="post" action="' . $h($modulelink) . '" style="margin:0;" onsubmit="return confirm(\'Resume retries?\');">';
        echo '<input type="hidden" name="aa_resume_id" value="' . (int) $row->id . '"/>';
        echo '<button type="submit" class="btn btn-xs btn-primary">Resume / 恢复</button></form></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" action="' . $h($modulelink) . '" style="margin-bottom:16px;" onsubmit="return confirm(\'Force prune logs now?\');">';
    echo '<input type="hidden" name="aa_force_prune" value="1"/>';
    echo '<button type="submit" class="btn btn-default btn-sm">Force log prune now / 立即清理日志</button>';
    echo ' <span class="text-muted">Retention: errors 14d, accept/delete 7d, skip/paused 3d; max 30 errors/invoice. Daily on cron.</span>';
    echo '</form>';

    echo '<p class="text-muted">SQL resume example: '
        . '<code>UPDATE ' . $h(AutoAccept::PAUSE_TABLE)
        . ' SET paused=0, consecutive_errors=0, alert_sent=0, paused_at=NULL WHERE id=PAUSE_ID;</code></p>';

    echo '<h3>Activity log / 活动日志 <small>(' . (int) $data['total'] . ' rows)</small></h3>';
    echo '<table class="table table-striped table-bordered" style="width:100%;">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>Time</th><th>Order</th><th>Invoice</th><th>Action</th><th>Reason</th><th>API result</th>';
    echo '</tr></thead><tbody>';
    if (count($data['rows']) === 0) {
        echo '<tr><td colspan="7">No log entries yet.</td></tr>';
    }
    foreach ($data['rows'] as $row) {
        $action = (string) ($row->action ?? '');
        $labelClass = 'default';
        if ($action === 'accept') { $labelClass = 'success'; }
        elseif ($action === 'delete') { $labelClass = 'warning'; }
        elseif ($action === 'error' || $action === 'paused') { $labelClass = 'danger'; }
        elseif ($action === 'resume') { $labelClass = 'primary'; }
        elseif ($action === 'skip') { $labelClass = 'info'; }
        $api = (string) ($row->api_result ?? '');
        if (strlen($api) > 180) { $api = substr($api, 0, 180) . '…'; }
        echo '<tr>';
        echo '<td>' . (int) $row->id . '</td>';
        echo '<td>' . $h($row->created_at) . '</td>';
        echo '<td>' . ((int) $row->order_id > 0 ? '#' . (int) $row->order_id : '—') . '</td>';
        echo '<td>' . ((int) $row->invoice_id > 0 ? '#' . (int) $row->invoice_id : '—') . '</td>';
        echo '<td><span class="label label-' . $labelClass . '">' . $h($action) . '</span></td>';
        echo '<td>' . $h($row->reason) . '</td>';
        echo '<td><code style="white-space:pre-wrap;font-size:11px;">' . $h($api) . '</code></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    if ($data['pages'] > 1) {
        echo '<p>Page: ';
        $maxShow = min((int) $data['pages'], 40);
        for ($i = 1; $i <= $maxShow; $i++) {
            $url = $modulelink . '&logpage=' . $i;
            if ($i === (int) $data['page']) {
                echo '<strong>' . $i . '</strong> ';
            } else {
                echo '<a href="' . $h($url) . '">' . $i . '</a> ';
            }
        }
        if ((int) $data['pages'] > $maxShow) {
            echo ' … total ' . (int) $data['pages'];
        }
        echo '</p>';
    }
}

