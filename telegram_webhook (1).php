<?php
// ===== 运行日志（确认 Telegram POST 命中）=====

require_once __DIR__ . '/init.php';

// 解析 Telegram 更新
$update = json_decode(file_get_contents('php://input'), true);
if (!is_array($update)) { http_response_code(200); exit; }

$msg = $update['message'] ?? null;
if (!$msg) { http_response_code(200); exit; }

// 必须是“回复某条消息”
if (empty($msg['reply_to_message']['text'])) { http_response_code(200); exit; }

// 从被回复的消息中提取 Ticket ID（依赖推送格式）
if (!preg_match('/Ticket\s+#(\d+)/i', $msg['reply_to_message']['text'], $m)) {
    http_response_code(200); exit;
}

$ticketId = (int)$m[1];
$text = trim($msg['text'] ?? '');
if ($text === '') { http_response_code(200); exit; }

// /close：仅关闭，不回复客户
if (stripos($text, '/close') === 0) {
    localAPI('CloseTicket', ['ticketid' => $ticketId]);
    http_response_code(200); echo 'OK'; exit;
}

// 默认：作为管理员回复工单
localAPI('AddTicketReply', [
    'ticketid'      => $ticketId,
    'message'       => $text,
    'adminusername' => 'Cyberfly Team',
]);

http_response_code(200);
echo 'OK';
