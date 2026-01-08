<?php
// telegram_ticket_push.php

// 钩子：工单开启通知
add_hook('TicketOpen', 1, function ($vars) {
    // 确保获取正确的工单数据
    $ticketId = $vars['ticketid'] ?? 'N/A';
    $subject = $vars['subject'] ?? 'N/A';
    $message = $vars['message'] ?? 'N/A';
    $userId = $vars['userid'] ?? 'N/A';
    
    // 构建发送到 Telegram 的消息内容
    $msg = "🎫 *新工单*\n\nTicket #{$ticketId}\n用户：{$userId}\n主题：{$subject}\n\n{$message}";
    
    // 调用函数发送消息到 Telegram
    sendTelegramMessage($msg);
});

// 钩子：工单用户回复通知
add_hook('TicketUserReply', 1, function ($vars) {
    // 确保获取正确的工单数据
    $ticketId = $vars['ticketid'] ?? 'N/A';
    $subject = $vars['subject'] ?? 'N/A';
    $message = $vars['message'] ?? 'N/A';
    $userId = $vars['userid'] ?? 'N/A';
    
    // 构建发送到 Telegram 的消息内容
    $msg = "📩 *用户回复工单*\n\nTicket #{$ticketId}\n用户：{$userId}\n主题：{$subject}\n\n{$message}";
    
    // 调用函数发送消息到 Telegram
    sendTelegramMessage($msg);
});

// 发送 Telegram 消息的函数
function sendTelegramMessage($text) {
    // 配置 Telegram Bot Token 和 Chat ID
    $TG_TOKEN = '8224905722:AAEDazc_2VMmFdJwJ9iSiW6PI6GyERJ2_Lg';
    $TG_CHATID = '-1003517119517'; // 替换为你实际的群组 chat_id
    
    // Telegram API 请求 URL
    $url = "https://api.telegram.org/bot{$TG_TOKEN}/sendMessage";
    
    // 设置发送消息的参数
    $data = [
        'chat_id' => $TG_CHATID,
        'text'    => $text,
        'parse_mode' => 'Markdown', // 允许Markdown格式
    ];
    
    // 初始化 cURL 请求
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $data,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
    ]);
    
    // 执行 cURL 请求并获取响应
    $response = curl_exec($ch);
    
    // 如果发送失败，记录 cURL 错误
    if ($response === false) {
        file_put_contents('/tmp/telegram_curl_error.log', date('c') . ' CURL ERROR: ' . curl_error($ch) . "\n", FILE_APPEND);
    } else {
        file_put_contents('/tmp/telegram_curl_response.log', date('c') . ' Response: ' . $response . "\n", FILE_APPEND);
    }
    
    curl_close($ch); // 关闭 cURL
}
