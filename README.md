🧩 WHMCS Telegram Ticket Bot Integration

让 WHMCS 工单系统与 Telegram 群组实时同步。
自动推送新工单与用户回复至 Telegram 群组，并支持群内直接回复工单（甚至关闭工单）。

🚀 功能简介

✅ 实时推送

新工单自动推送到 Telegram 群。

用户回复后，群内会收到同步消息。

💬 群内互动

群成员可直接在 Telegram 回复消息以回复工单。

发送 /close 可关闭工单。

⏰ 超时提醒

工单超过 1 小时未回复，系统自动提醒。

🧠 全自动运行

基于 WHMCS Hook，无需额外任务计划或守护进程。

🧱 项目结构
/www/wwwroot/www.cyberfly.org/
├── telegram_webhook.php          # 接收 Telegram Webhook (群内回复 /close)
└── includes/
    └── hooks/
        └── telegram_ticket_push.php  # WHMCS Hook: 推送工单 + 定时提醒

⚙️ 配置方法

在 Telegram 创建一个机器人：

@BotFather → /newbot → 获取 TOKEN


将机器人加入群组并设为管理员（确保它能“读取消息”）。

获取群组 ID：

发送任意消息到群组；

访问：

https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates


找到 "chat":{"id":-XXXXXXXXXX} 即为群组 ID。

修改配置：

// includes/hooks/telegram_ticket_push.php
$TG_TOKEN  = 'YOUR_TELEGRAM_BOT_TOKEN';
$TG_CHATID = '-XXXXXXXXXX';


设置 Webhook：

curl -F "url=https://yourdomain.com/telegram_webhook.php" \
     https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook

🧰 兼容性

✅ WHMCS 版本：8.x+

✅ PHP 版本：7.4 - 8.3

✅ Telegram Bot API v6+

🧩 工作流程

当 WHMCS 产生新工单或用户回复时 →
telegram_ticket_push.php 会触发并调用 Telegram Bot API 发送推送。

当管理员在群内回复消息时 →
telegram_webhook.php 会解析 Telegram 的 Webhook POST →
自动通过 localAPI() 在 WHMCS 内创建回复。

🧠 注意事项

确保 telegram_webhook.php 可被公网访问（非 404）。

Webhook 文件 不应受 WHMCS 主题路由或 CDN 缓存影响。

若需要重新绑定 Webhook：

curl https://api.telegram.org/bot<YOUR_TOKEN>/deleteWebhook
curl -F "url=https://yourdomain.com/telegram_webhook.php" \
     https://api.telegram.org/bot<YOUR_TOKEN>/setWebhook
