# WHMCS Auto Accept 插件（CyberFly / qiqi）

两套同构的 WHMCS 8.x 插件：自动接受 **已付 + Pending** 订单（可选删除超时未付 Pending）。本目录还包含：

1. **连续 5 次错误暂停**（按发票/动作）+ **一次** Telegram 告警  
2. **日志滚动保留**（cron 每日清理）  
3. **分站独立 Telegram bot**（切勿把 CF 凭证填到 qiqi，反之亦然）

| 目录 | 站点标签 | 模块名 | 日志 / 暂停表 |
|---|---|---|---|
| `Cyberfly_autoaccept/` | CyberFly | `Cyberfly_autoaccept` | `mod_Cyberfly_autoaccept_log` / `_pause` |
| `qiqi_autoaccept/` | qiqi/77Speed | `qiqi_autoaccept` | `mod_qiqi_autoaccept_log` / `_pause` |

`qiqi_autoaccept` 另可自动接受 **免费** Pending（`enable_free_autoaccept`）。

版本：CyberFly **1.1.1** / qiqi **1.2.1**（管理页与暂停 Telegram 文案为中文）。

## 安装

1. 仅将对应目录复制到**该站** `modules/addons/`。  
2. 系统设置 → 插件模块 → 激活 → 配置 → 保存。  
3. 保持 WHMCS 系统 cron（`crons/cron.php`）运行。  
4. **不要**把某站的 `includes/telegram/config.local.php` 拷到另一站。

## 插件配置字段

| 字段 | 用途 |
|---|---|
| `enable_autoaccept` | 发票 Paid 后接受 Pending（InvoicePaid / OrderPaid / cron 补漏） |
| `enable_free_autoaccept` | *（仅 qiqi）* 接受免费 Pending（金额 0 / 无发票） |
| `autosetup` / `sendemail` / `registrar` | 传给 `AcceptOrder` |
| `enable_delete_unpaid` / `unpaid_max_hours` | cron 删除超时未付 Pending |
| `admin_username` | 可选 localAPI 管理员 |
| **`tg_bot_token`** | 可选：仅本站暂停告警 bot token。留空 → 本站 `includes/telegram`（`TG_TOKEN`，与工单推送同一 bot） |
| **`tg_chat_id`** | 可选：暂停告警 chat/频道。留空 → 本站 `TG_CHAT_ID` |

文档占位（勿提交真实值）：

```text
YOUR_TELEGRAM_BOT_TOKEN
YOUR_CHAT_ID
```

**规则：** CF 与 qiqi 必须使用**独立** Telegram bot。切勿把 CF token 填进 qiqi（或反过来）。优先留空 `tg_*`，让各站现有 `includes/telegram` 生效。

## 连续 5 次错误暂停 + 一次 Telegram

- 记在 `mod_*_autoaccept_pause`（进程重启后仍有效）。  
- 键：发票 id + 动作（`accept` / `delete`）；免费单用 `invoice_id=0` + 订单 id。  
- 同一键连续失败 **5** 次 → `paused=1`，停止重试，经该站 bot 发 **一条** HTML Telegram，`alert_sent=1`。  
- 成功会清零计数/暂停；同一故障不会刷屏。  
- 消息含站点/动作/发票/订单/连续错误次数/摘要（无密钥）。

告警文案示例含义：

「⏸ 该订单 Auto Accept 已停重试」→ 站点 / 动作 / 发票 / 订单 / 连续错误次数 / 摘要；说明**仅此发票+动作**不再自动重试，其它订单不受影响；处理完 Username Already Exists 等问题后，可在插件「已暂停」列表**对该行**点「恢复」再试。

### 恢复（Resume）

1. WHMCS → 插件模块 →（Cyberfly|Qiqi）Auto Accept → **已暂停**列表 → 对该行点 **恢复**（不是整插件暂停）  
2. 或 SQL：

```sql
UPDATE mod_Cyberfly_autoaccept_pause  -- 或 mod_qiqi_autoaccept_pause
SET paused=0, consecutive_errors=0, alert_sent=0, paused_at=NULL, last_error=NULL
WHERE id = PAUSE_ID;
```

## 日志保留

表：`mod_*_autoaccept_log`（数据库，非文件）。

| 动作 | 保留 |
|---|---|
| `error` | 14 天 |
| `accept` / `delete` | 7 天 |
| `skip` / `paused` / `resume` | 3 天 |
| 软上限 | 每发票最多 **30** 条最新 `error` |

`AfterCronJob` 每个自然日最多自动跑一次。管理页也可点 **立即清理日志**。

## Telegram 接线

默认（推荐）：

`modules/addons/.../lib/AutoAccept.php` → `ROOTDIR/includes/telegram/bootstrap.php` → `tg_send_message()`  
（= 该站 `includes/hooks/telegram_ticket_push.php` 同一栈）

覆盖：插件非空 `tg_bot_token` / `tg_chat_id` → 仅用这些值直连 Bot API。

共享 telegram 中层布局见仓库根 README。

## 安全

- 不触碰退款 hooks。  
- 不接受 Fraud / 非 Pending / 非 Paid（qiqi 免费路径除外）。  
- 暂停告警不得跨站串发。
