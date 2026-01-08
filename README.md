# WHMCS × Telegram 工单机器人

把 **WHMCS 工单**实时推送到 **Telegram 群组**，并支持在群里 **直接回复工单** / **关闭工单**（通过 Telegram Webhook → WHMCS localAPI）。

> 当前版本：**推送 + 群内回复/关闭 已可用**  
> 提醒功能（工单超时提醒）尚未启用，已加入下方「待开发计划」。

---

## 功能

- ✅ **工单推送到群组**
  - 新工单推送
  - 用户回复工单推送
- ✅ **群内直接处理工单**
  - 在群里 **回复**推送消息 → 自动写入 WHMCS 工单
  - 回复 **`/close`** → 关闭工单（不回复客户）
- ✅ 仅需两个文件，结构简单，便于运维与排查

---

## 文件结构

```bash
/whmcs-root/
├── telegram_webhook.php                  # 接收 Telegram Webhook（群内回复 /close）
└── includes/
    └── hooks/
        └── telegram_ticket_push.php      # WHMCS Hook：推送工单到 Telegram
```

> 说明：`telegram_webhook.php` 建议放在 **WHMCS 根目录**，避免主题路由/伪静态把路径重写到 404。

---

## 部署步骤

### 1) 创建 Telegram Bot 并加入群组

1. 在 Telegram 找 **@BotFather** → `/newbot` 创建机器人，拿到 **Bot Token**
2. 把机器人加入你的工单群组，并设置为 **管理员**
3. （可选）在 BotFather 打开隐私模式，便于在群里读取消息：  
   `/setprivacy` → 选择你的 bot → `Disable`

> 如果出现 “has no access to messages”，通常是隐私模式/群权限相关。

---

### 2) 获取群组 Chat ID（推荐方式：用 Webhook 日志取）

你已经在 `telegram_webhook.php` 中做了 runtime log（例如 `__tg_runtime.log`）。  
在群里发一条消息后，看日志里的：

```json
"chat":{"id":-100xxxxxxxxxxxx,"title":"YourGroup","type":"supergroup"}
```

其中 `id` 就是群组 `chat_id`（一般超级群以 `-100` 开头）。

---

### 3) 配置 token 与 chat_id

编辑 `includes/hooks/telegram_ticket_push.php`，填写：

```php
$TG_TOKEN  = 'YOUR_BOT_TOKEN';
$TG_CHATID = '-100xxxxxxxxxxxx';
```

> 注意：如果你更换了群组（新群），`chat_id` 必须更新。

---

### 4) 配置 Webhook

将 Telegram Webhook 指向你的站点：

```bash
curl -X POST "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/setWebhook" \
  -d "url=https://yourdomain.com/telegram_webhook.php"
```

检查 Webhook 状态：

```bash
curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getWebhookInfo"
```

---

## 使用方法

### 群内回复工单

1. 等待 bot 推送 “Ticket #1234 …” 的消息到群里
2. **直接回复该消息**（Telegram 的“回复”功能）
3. 输入内容发送 → 会写入 WHMCS 工单回复

### 关闭工单

- 回复推送消息并发送：`/close`  
  → WHMCS 工单会被关闭（不回复客户）

---

## 常见问题排查

### 403: bot was kicked from the group chat
- 机器人被踢出群了；重新拉进群并赋予管理员权限
- 确认 `chat_id` 是新群的 id

### 409: can't use getUpdates while webhook is active
- 你已经启用了 webhook，此时 `getUpdates` 会冲突  
  如需临时用 `getUpdates`：
  ```bash
  curl "https://api.telegram.org/bot<YOUR_BOT_TOKEN>/deleteWebhook"
  ```

### 404 Not Found（Webhook）
- 多数是站点路由/伪静态/主题把路径重写掉了
- 建议将 `telegram_webhook.php` 放在 **WHMCS 根目录** 并直接访问验证：
  `https://yourdomain.com/telegram_webhook.php`

---

## 安全建议

- 建议使用 **随机 token 参数** 或 **IP 白名单** 限制 webhook 访问（可后续增强）
- 生产环境建议避免在仓库提交真实 Token（可改为读取环境变量或独立配置文件）

---

## 待开发计划（Roadmap）

- ⏰ **工单超时提醒（未启用）**
  - 规则：工单超过 1 小时无管理员回复 → 每小时提醒一次
  - 触发方式：建议独立 cron 脚本（避免影响前台/主题路由）
- 🧾 **更丰富的推送内容**
  - 管理后台链接按钮（带自定义后台路径）
  - 显示部门、用户邮箱/昵称、最近回复摘要
- 🔐 **Webhook 安全增强**
  - secret token 验证
  - Telegram IP 段限制（可选）

---

## License

MIT
