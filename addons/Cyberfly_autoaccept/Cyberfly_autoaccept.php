<?php
/**
 * Cyberfly Auto Accept — WHMCS 8.11.2 addon module
 *
 * Auto-accepts paid pending orders immediately on payment, and optionally
 * deletes stale unpaid pending orders after a configurable age.
 *
 * @see https://developers.whmcs.com/addon-modules/
 */

use Cyberfly\AutoAccept\AutoAccept;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/AutoAccept.php';

/**
 * Addon configuration shown at Setup → Addon Modules.
 *
 * @return array<string, mixed>
 */
function Cyberfly_autoaccept_config()
{
    return [
        'name' => 'Cyberfly Auto Accept',
        'description' => '发票变为 Paid 时立即接受 Pending 订单（InvoicePaid / OrderPaid，并有 AfterCronJob 补漏）。可选删除超过设定小时数的未支付 Pending 订单。不处理退款/取消/催收/欺诈/非 Pending 订单。零金额且被 WHMCS 标为 Paid 的发票按已付处理。需保持 WHMCS 系统 cron（crons/cron.php）运行。',
        'author' => 'Cyberfly',
        'language' => 'english',
        'version' => '1.1.3',
        'fields' => [
            'enable_autoaccept' => [
                'FriendlyName' => '启用：自动接受已付 Pending',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '发票 Paid 后立即接受 Pending 订单。',
            ],
            'autosetup' => [
                'FriendlyName' => '接受时自动开通产品',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '传给 AcceptOrder 的 autosetup；接受订单时自动开通产品。',
            ],
            'sendemail' => [
                'FriendlyName' => '接受时发送邮件',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '传给 AcceptOrder 的 sendemail；接受订单时发送自动邮件。',
            ],
            'registrar' => [
                'FriendlyName' => '接受时自动注册域名',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '传给 AcceptOrder 的 sendregistrar（WHMCS 8.11 布尔）；接受订单时向注册商提交域名注册。',
            ],
            'enable_delete_unpaid' => [
                'FriendlyName' => '启用：删除超时未付 Pending',
                'Type' => 'yesno',
                'Default' => 'on',
                'Description' => '每次 WHMCS cron 时删除超时仍未支付的 Pending 订单（DeleteOrder，不是仅取消）。',
            ],
            'unpaid_max_hours' => [
                'FriendlyName' => '未付 Pending 最长保留小时数',
                'Type' => 'text',
                'Size' => '6',
                'Default' => '6',
                'Description' => '自 tblorders.date 起算；超时删除未付 Pending。默认 6。',
            ],
            'admin_username' => [
                'FriendlyName' => 'localAPI 管理员用户名',
                'Type' => 'text',
                'Size' => '30',
                'Default' => '',
                'Description' => '可选；留空则不传 admin 用户名（WHMCS 7.2+）。',
            ],

            'tg_bot_token' => [
                'FriendlyName' => 'Telegram Bot Token（暂停告警）',
                'Type' => 'password',
                'Size' => '60',
                'Default' => '',
                'Description' => '仅本站可选覆盖；留空则用本站 includes/telegram（与工单推送同一 bot）。不要填另一站点的 token。',
            ],
            'tg_chat_id' => [
                'FriendlyName' => 'Telegram Chat ID（暂停告警）',
                'Type' => 'text',
                'Size' => '30',
                'Default' => '',
                'Description' => '仅本站可选覆盖聊天/频道；留空用本站 TG_CHAT_ID。',
            ],
        ],
    ];
}

/**
 * Create the log table. Does not modify WHMCS core tables.
 *
 * @return array{status: string, description: string}
 */
function Cyberfly_autoaccept_activate()
{
    try {
        AutoAccept::ensureLogTable();
        AutoAccept::ensurePauseTable();
        if (function_exists('logActivity')) {
            logActivity('Cyberfly Auto Accept: module activated');
        }
        return [
            'status' => 'success',
            'description' => '已激活。日志表 mod_Cyberfly_autoaccept_log 已就绪。请配置并保存，确认 cron 正在运行。在生产环境验证成功前，不要停掉现有人工/浏览器待审订单流程。',
        ];
    } catch (\Exception $e) {
        return [
            'status' => 'error',
            'description' => '无法创建 mod_Cyberfly_autoaccept_log: ' . $e->getMessage(),
        ];
    }
}

/**
 * Deactivate. The log table is intentionally kept.
 *
 * @return array{status: string, description: string}
 */
function Cyberfly_autoaccept_deactivate()
{
    if (function_exists('logActivity')) {
        logActivity('Cyberfly Auto Accept: module deactivated (log table retained)');
    }
    return [
        'status' => 'success',
        'description' => '已停用；Hooks 不再运行。日志表 mod_Cyberfly_autoaccept_log 已保留未删除。',
    ];
}

/**
 * Admin area page: counts + paginated log (newest first).
 *
 * @param array<string, mixed> $vars
 */
function Cyberfly_autoaccept_output($vars)
{
    $modulelink = (string) ($vars['modulelink'] ?? 'addonmodules.php?module=Cyberfly_autoaccept');
    $page = isset($_GET['logpage']) ? (int) $_GET['logpage'] : 1;
    $h = static function ($value): string {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    };

    // Manual resume of a paused invoice/action
    if (!empty($_POST['aa_resume_id'])) {
        $rid = (int) $_POST['aa_resume_id'];
        try {
            if (AutoAccept::resumePause($rid)) {
                echo '<div class="alert alert-success">已恢复暂停 #' . $rid . '</div>';
            } else {
                echo '<div class="alert alert-warning">未找到暂停 #' . $rid . '</div>';
            }
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">恢复失败: ' . $h($e->getMessage()) . '</div>';
        }
    }

    if (!empty($_POST['aa_force_prune'])) {
        try {
            $pr = AutoAccept::pruneLogs(true);
            echo '<div class="alert alert-info">日志清理: 已删除=' . (int) $pr['deleted']
                . ' 保留=' . (int) $pr['kept'] . '</div>';
        } catch (\Throwable $e) {
            echo '<div class="alert alert-danger">清理失败: ' . $h($e->getMessage()) . '</div>';
        }
    }

    try {
        $data = AutoAccept::adminLogPage($page, 50);
        $paused = AutoAccept::listPaused(100);
    } catch (\Throwable $e) {
        echo '<div class="alert alert-danger">无法加载日志: '
            . $h($e->getMessage())
            . '</div>';
        return;
    }

    $on = static function (string $key) use ($vars): string {
        $raw = (string) ($vars[$key] ?? '');
        $yes = AutoAccept::isYes($raw !== '' ? $raw : 'off');
        return $yes ? '开' : '关';
    };

    $lastError = $data['last_error'];
    $lastErrorHtml = '无';
    if ($lastError) {
        $lastErrorHtml = $h($lastError->created_at)
            . ' — 订单 #' . $h($lastError->order_id)
            . ' 发票 #' . $h($lastError->invoice_id)
            . ' — ' . $h($lastError->reason);
    }

    echo '<h2>Cyberfly Auto Accept</h2>';
    echo '<p>支付后自动审单；<strong>按发票/订单+动作</strong>连续失败 5 次则仅暂停该笔重试（其它订单照常自动审单），并经<strong>本站</strong> Telegram bot 告警一次；日志自动滚动清理。</p>';

    echo '<div class="row" style="margin-bottom:16px;">';
    echo '  <div class="col-md-3"><div class="panel panel-success"><div class="panel-heading">今日已接受</div>';
    echo '  <div class="panel-body" style="font-size:28px;">' . (int) $data['accepted_today'] . '</div></div></div>';
    echo '  <div class="col-md-3"><div class="panel panel-warning"><div class="panel-heading">今日已删除</div>';
    echo '  <div class="panel-body" style="font-size:28px;">' . (int) $data['deleted_today'] . '</div></div></div>';
    echo '  <div class="col-md-3"><div class="panel panel-danger"><div class="panel-heading">已暂停</div>';
    echo '  <div class="panel-body" style="font-size:28px;">' . count($paused) . '</div></div></div>';
    echo '  <div class="col-md-3"><div class="panel panel-danger"><div class="panel-heading">最近错误</div>';
    echo '  <div class="panel-body">' . $lastErrorHtml . '</div></div></div>';
    echo '</div>';

    echo '<p>';
    echo '自动接受: <strong>' . $h($on('enable_autoaccept')) . '</strong> &nbsp;|&nbsp; ';
    echo '自动开通: <strong>' . $h($on('autosetup')) . '</strong> &nbsp;|&nbsp; ';
    echo '删除未付: <strong>' . $h($on('enable_delete_unpaid')) . '</strong> &nbsp;|&nbsp; ';
    echo '最长保留小时: <strong>' . $h((string) ($vars['unpaid_max_hours'] ?? '6')) . '</strong> &nbsp;|&nbsp; ';
    echo '日志行数: <strong>' . (int) $data['total'] . '</strong>（约 ' . (int) $data['pages'] . ' 页）';
    echo '</p>';

    $tgTok = trim((string) ($vars['tg_bot_token'] ?? ''));
    $tgChat = trim((string) ($vars['tg_chat_id'] ?? ''));
    echo '<p class="text-muted">暂停告警 Telegram：插件覆盖 token '
        . ($tgTok !== '' ? '<strong>已设置</strong>' : '<strong>留空 → 本站 includes/telegram bot</strong>')
        . '；chat ' . ($tgChat !== '' ? '<strong>已设置 (' . $h($tgChat) . ')</strong>' : '<strong>留空 → 本站 TG_CHAT_ID</strong>')
        . '。CF 与 qiqi 必须使用独立 bot，不得共用同一 bot token。</p>';

    echo '<h3>已暂停的发票动作</h3>';
    echo '<p class="text-muted">暂停范围：按发票/订单+动作。连续 5 次失败后仅该行停重试，其它订单照常自动审单；对该行点「恢复」或成功路径清除后才会继续；每次暂停只告警一次。</p>';
    echo '<table class="table table-striped table-bordered"><thead><tr>';
    echo '<th>ID</th><th>发票</th><th>订单</th><th>动作</th><th>错误次数</th><th>暂停时间</th><th>最近错误</th><th></th>';
    echo '</tr></thead><tbody>';
    if (count($paused) === 0) {
        echo '<tr><td colspan="8">当前无暂停</td></tr>';
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
        echo '<td><form method="post" action="' . $h($modulelink) . '" style="margin:0;" onsubmit="return confirm(\'确认恢复该订单重试？\');">';
        echo '<input type="hidden" name="aa_resume_id" value="' . (int) $row->id . '"/>';
        echo '<button type="submit" class="btn btn-xs btn-primary">恢复</button></form></td>';
        echo '</tr>';
    }
    echo '</tbody></table>';

    echo '<form method="post" action="' . $h($modulelink) . '" style="margin-bottom:16px;" onsubmit="return confirm(\'确认立即清理日志？\');">';
    echo '<input type="hidden" name="aa_force_prune" value="1"/>';
    echo '<button type="submit" class="btn btn-default btn-sm">立即清理日志</button>';
    echo ' <span class="text-muted">保留策略：error 14 天，accept/delete 7 天，skip/paused/resume 3 天；每发票最多 30 条 error。每日 cron 执行。</span>';
    echo '</form>';

    echo '<p class="text-muted">SQL 恢复示例: '
        . '<code>UPDATE ' . $h(AutoAccept::PAUSE_TABLE)
        . ' SET paused=0, consecutive_errors=0, alert_sent=0, paused_at=NULL WHERE id=PAUSE_ID;</code></p>';

    echo '<h3>活动日志 <small>（' . (int) $data['total'] . ' 行）</small></h3>';
    echo '<table class="table table-striped table-bordered" style="width:100%;">';
    echo '<thead><tr>';
    echo '<th>ID</th><th>时间</th><th>订单</th><th>发票</th><th>动作</th><th>原因</th><th>API 结果</th>';
    echo '</tr></thead><tbody>';
    if (count($data['rows']) === 0) {
        echo '<tr><td colspan="7">暂无日志。</td></tr>';
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
        echo '<p>页码: ';
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
            echo ' … 共 ' . (int) $data['pages'] . ' 页';
        }
        echo '</p>';
    }
}

