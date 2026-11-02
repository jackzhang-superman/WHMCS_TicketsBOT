# WHMCS Auto Accept addons (CyberFly / qiqi)

Two sibling WHMCS 8.x addon modules that auto-accept **Paid + Pending** orders
(and optionally delete stale Unpaid + Pending). This tree also includes:

1. **5× consecutive error pause** per invoice/action + **one** Telegram alert  
2. **Rolling log retention** (cron daily prune)  
3. **Per-site Telegram bots** (never share CF credentials with qiqi or vice versa)

| Folder | Site label | Module name | Log / pause tables |
|---|---|---|---|
| `Cyberfly_autoaccept/` | CyberFly | `Cyberfly_autoaccept` | `mod_Cyberfly_autoaccept_log` / `_pause` |
| `qiqi_autoaccept/` | qiqi/77Speed | `qiqi_autoaccept` | `mod_qiqi_autoaccept_log` / `_pause` |

`qiqi_autoaccept` additionally auto-accepts **free** Pending orders (`enable_free_autoaccept`).

## Install

1. Copy one folder into `modules/addons/` on **that** WHMCS site only.  
2. Setup → Addon Modules → Activate → Configure → Save.  
3. Keep WHMCS system cron (`crons/cron.php`) running.  
4. Do **not** copy one site’s `includes/telegram/config.local.php` onto the other.

## Config fields (addon)

| Field | Purpose |
|---|---|
| `enable_autoaccept` | Accept Paid+Pending on InvoicePaid / OrderPaid / cron catch-up |
| `enable_free_autoaccept` | *(qiqi only)* Accept free Pending (amount 0 / no invoice) |
| `autosetup` / `sendemail` / `registrar` | Passed to `AcceptOrder` |
| `enable_delete_unpaid` / `unpaid_max_hours` | Cron delete of stale Unpaid+Pending |
| `admin_username` | Optional localAPI admin user |
| **`tg_bot_token`** | Optional pause-alert bot token for **this site only**. Blank → use this site’s `includes/telegram` (`TG_TOKEN`, same bot as `telegram_ticket_push`) |
| **`tg_chat_id`** | Optional pause-alert chat/channel. Blank → this site’s `TG_CHAT_ID` |

Placeholders if you document overrides elsewhere:

```text
YOUR_TELEGRAM_BOT_TOKEN
YOUR_CHAT_ID
```

**Rule:** CF and qiqi must use **separate** Telegram bots. Never paste CF’s token into qiqi’s addon settings (or the reverse). Prefer leaving `tg_*` blank so each site’s existing `includes/telegram` config is used.

## 5× error pause + one Telegram

- Tracked in `mod_*_autoaccept_pause` (durable across PHP restarts).  
- Keyed by invoice id + action (`accept` / `delete`); free orders use `invoice_id=0` + order id.  
- After **5 consecutive** failures for that key → set `paused=1`, stop retries, send **one** Telegram (HTML) via that site’s bot, set `alert_sent=1`.  
- Success clears the counter/pause. Same fault does not spam.  
- Message includes invoice/order id + error summary (no secrets).

### Resume

1. WHMCS → Addon Modules → (Cyberfly|Qiqi) Auto Accept → **Paused** table → **Resume**  
2. Or SQL:

```sql
UPDATE mod_Cyberfly_autoaccept_pause  -- or mod_qiqi_autoaccept_pause
SET paused=0, consecutive_errors=0, alert_sent=0, paused_at=NULL, last_error=NULL
WHERE id = PAUSE_ID;
```

## Log retention

Table: `mod_*_autoaccept_log` (DB, not files).

| Action | Keep |
|---|---|
| `error` | 14 days |
| `accept` / `delete` | 7 days |
| `skip` / `paused` / `resume` | 3 days |
| Soft cap | max **30** newest `error` rows per invoice |

Runs automatically once per calendar day from `AfterCronJob`. Admin UI also has **Force log prune now**.

## Telegram wiring

Default path (recommended):

`modules/addons/.../lib/AutoAccept.php` → `ROOTDIR/includes/telegram/bootstrap.php` → `tg_send_message()`  
(= same stack as `includes/hooks/telegram_ticket_push.php` on **that** WHMCS).

Override path: non-empty addon `tg_bot_token` / `tg_chat_id` → direct Bot API send with **those** values only.

See repo root README for the shared telegram mid-tier layout.

## Safety

- Does not touch refund hooks.  
- Never accepts Fraud / non-Pending / non-Paid (except qiqi free path).  
- Pause alerts must not cross-post between sites.
