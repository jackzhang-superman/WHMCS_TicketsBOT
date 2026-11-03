<?php

namespace Cyberfly\AutoAccept;

use WHMCS\Database\Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Core logic for Cyberfly Auto Accept (WHMCS 8.11.2 addon).
 *
 * Accepts Paid + Pending orders via localAPI AcceptOrder.
 * Deletes stale Unpaid + Pending orders via CancelOrder then DeleteOrder
 * (WHMCS DeleteOrder refuses any status other than Cancelled or Fraud).
 */
class AutoAccept
{
    public const MODULE = 'Cyberfly_autoaccept';
    public const LOG_TABLE = 'mod_Cyberfly_autoaccept_log';
    public const PAUSE_TABLE = 'mod_Cyberfly_autoaccept_pause';
    public const MAX_PER_CRON = 100;
    /** Consecutive action errors before pause + one Telegram alert */
    public const ERROR_PAUSE_THRESHOLD = 5;
    public const SITE_LABEL = 'CyberFly';
    /** Retention: keep errors this many days */
    public const LOG_KEEP_ERROR_DAYS = 14;
    /** Retention: keep accept/delete this many days */
    public const LOG_KEEP_SUCCESS_DAYS = 7;
    /** Retention: keep skip/paused/resume markers this many days */
    public const LOG_KEEP_SKIP_DAYS = 3;
    /** Soft cap after age prune: max error rows kept per invoice+action */
    public const LOG_MAX_ERRORS_PER_KEY = 30;

    /** @var array<string, true> In-request accept lock keyed by order id */
    private static $acceptLock = [];

    /** @var array<string, true> In-request delete lock keyed by order id */
    private static $deleteLock = [];

    /** @var bool AfterCronJob must run at most once per PHP process / cron tick */
    private static $cronRan = false;

    /** @var array<string, string>|null */
    private static $settingsCache = null;
    
    public static function ensureLogTable(): void
    {
        if (Capsule::schema()->hasTable(self::LOG_TABLE)) {
            return;
        }

        Capsule::schema()->create(self::LOG_TABLE, function ($table) {
            /** @var \Illuminate\Database\Schema\Blueprint $table */
            $table->increments('id');
            $table->unsignedInteger('order_id')->default(0);
            $table->unsignedInteger('invoice_id')->default(0);
            $table->string('action', 16);
            $table->text('reason')->nullable();
            $table->text('api_result')->nullable();
            $table->dateTime('created_at');
            $table->index('order_id');
            $table->index('action');
            $table->index('created_at');
        });
    }

    public static function ensurePauseTable(): void
    {
        if (Capsule::schema()->hasTable(self::PAUSE_TABLE)) {
            return;
        }

        Capsule::schema()->create(self::PAUSE_TABLE, function ($table) {
            /** @var \Illuminate\Database\Schema\Blueprint $table */
            $table->increments('id');
            $table->unsignedInteger('invoice_id')->default(0);
            $table->unsignedInteger('order_id')->default(0);
            $table->string('action', 16); // accept | delete | __meta__
            $table->unsignedInteger('consecutive_errors')->default(0);
            $table->boolean('paused')->default(false);
            $table->boolean('alert_sent')->default(false);
            $table->text('last_error')->nullable();
            $table->dateTime('paused_at')->nullable();
            $table->dateTime('updated_at');
            $table->unique(['invoice_id', 'order_id', 'action'], 'aa_pause_uniq');
            $table->index('paused');
        });
    }


    /**
     * InvoicePaid — primary live path. $vars.invoiceid (int).
     *
     * @param array<string, mixed> $vars
     */
    public static function handleInvoicePaid(array $vars): void
    {
        $invoiceId = (int) ($vars['invoiceid'] ?? $vars['invoiceId'] ?? 0);
        if ($invoiceId <= 0) {
            return;
        }

        $order = self::findOrderByInvoiceId($invoiceId);
        if ($order === null) {
            // Recurring / non-order invoices are normal; do not flood the log.
            return;
        }

        self::acceptIfEligible((int) $order->id, $invoiceId, 'InvoicePaid');
    }

    /**
     * OrderPaid — fires when the first invoice for a new order is marked Paid.
     * $vars.orderId / userId / invoiceId (WHMCS 8.11).
     *
     * @param array<string, mixed> $vars
     */
    public static function handleOrderPaid(array $vars): void
    {
        $orderId = (int) ($vars['orderId'] ?? $vars['orderid'] ?? 0);
        $invoiceId = (int) ($vars['invoiceId'] ?? $vars['invoiceid'] ?? 0);

        if ($orderId <= 0 && $invoiceId > 0) {
            $order = self::findOrderByInvoiceId($invoiceId);
            $orderId = $order ? (int) $order->id : 0;
        }

        if ($orderId <= 0) {
            return;
        }

        if ($invoiceId <= 0) {
            $order = self::loadOrder($orderId);
            $invoiceId = $order ? (int) $order->invoiceid : 0;
        }

        self::acceptIfEligible($orderId, $invoiceId, 'OrderPaid');
    }

    /**
     * AfterCronJob — catch-up accept of leftover Paid+Pending, then stale Unpaid+Pending delete.
     */
    public static function handleAfterCronJob(): void
    {
        if (self::$cronRan) {
            return;
        }
        self::$cronRan = true;

        try {
            if (self::isYes(self::setting('enable_autoaccept', 'on'))) {
                self::catchUpPaidPending();
            }
        } catch (\Throwable $e) {
            self::record(0, 0, 'error', 'AfterCronJob catch-up accept failed: ' . $e->getMessage(), '');
        }

        try {
            if (self::isYes(self::setting('enable_delete_unpaid', 'on'))) {
                self::deleteStaleUnpaidPending();
            }
        } catch (\Throwable $e) {
            self::record(0, 0, 'error', 'AfterCronJob stale unpaid delete failed: ' . $e->getMessage(), '');
        }

        try {
            self::maybeDailyLogMaintenance();
        } catch (\Throwable $e) {
            self::record(0, 0, 'error', 'AfterCronJob log maintenance failed: ' . $e->getMessage(), '');
        }
    }

    /**
     * Re-read order+invoice, then AcceptOrder. No tight-loop retry on failure.
     */
    public static function acceptIfEligible(int $orderId, int $invoiceId, string $source): void
    {
        if ($orderId <= 0) {
            return;
        }

        $lockKey = (string) $orderId;
        if (isset(self::$acceptLock[$lockKey])) {
            return;
        }
        self::$acceptLock[$lockKey] = true;

        if (!self::isYes(self::setting('enable_autoaccept', 'on'))) {
            return;
        }

        $order = self::loadOrder($orderId);
        if ($order === null) {
            self::record($orderId, $invoiceId, 'skip', 'missing order', '');
            return;
        }

        $invoiceId = $invoiceId > 0 ? $invoiceId : (int) $order->invoiceid;
        $invoice = $invoiceId > 0 ? self::loadInvoice($invoiceId) : null;
        if ($invoice === null) {
            self::record($orderId, $invoiceId, 'skip', 'missing invoice', '');
            return;
        }

        $refuse = self::refuseAcceptReason($order, $invoice);
        if ($refuse !== null) {
            self::record($orderId, (int) $invoice->id, 'skip', $refuse . ' (' . $source . ')', '');
            return;
        }

        if (self::isPaused((int) $invoice->id, $orderId, 'accept')) {
            return;
        }

        $autosetup = self::isYes(self::setting('autosetup', 'on'));
        $sendemail = self::isYes(self::setting('sendemail', 'on'));
        $sendregistrar = self::isYes(self::setting('registrar', 'on'));

        // WHMCS 8.11 AcceptOrder: sendregistrar is the boolean that registers domains.
        // `registrar` is a string registrar module name, not a boolean.
        $postData = [
            'orderid' => $orderId,
            'autosetup' => $autosetup,
            'sendemail' => $sendemail,
            'sendregistrar' => $sendregistrar,
        ];

        $result = self::localApi('AcceptOrder', $postData);
        $encoded = self::encodeResult($result);

        if (self::apiOk($result)) {
            $reason = 'accepted via ' . $source
                . ' (autosetup=' . self::boolWord($autosetup)
                . ', sendemail=' . self::boolWord($sendemail)
                . ', sendregistrar=' . self::boolWord($sendregistrar) . ')';
            self::record($orderId, (int) $invoice->id, 'accept', $reason, $encoded);
            self::activity('Accepted paid pending order #' . $orderId . ' (invoice #' . (int) $invoice->id . ') via ' . $source);
            self::noteActionSuccess((int) $invoice->id, $orderId, 'accept');
            return;
        }

        $message = self::apiMessage($result);
        self::record($orderId, (int) $invoice->id, 'error', 'AcceptOrder failed via ' . $source . ': ' . $message, $encoded);
        self::activity('ERROR AcceptOrder failed for order #' . $orderId . ': ' . $message);
        self::noteActionError((int) $invoice->id, $orderId, 'accept', $message);
        // No retry in this request. Next AfterCronJob tick may catch leftovers (unless paused at 5).
    }

    /**
     * @return array{accepted_today: int, deleted_today: int, last_error: object|null, total: int, page: int, pages: int, per_page: int, rows: array<int, object>}
     */
    public static function adminLogPage(int $page, int $perPage = 50): array
    {
        self::ensureLogTable();

        $perPage = max(1, min(100, $perPage));
        $todayStart = self::todayStartString();

        $acceptedToday = (int) Capsule::table(self::LOG_TABLE)
            ->where('action', 'accept')
            ->where('created_at', '>=', $todayStart)
            ->count();
        $deletedToday = (int) Capsule::table(self::LOG_TABLE)
            ->where('action', 'delete')
            ->where('created_at', '>=', $todayStart)
            ->count();
        $lastError = Capsule::table(self::LOG_TABLE)
            ->where('action', 'error')
            ->orderBy('id', 'desc')
            ->first();

        $total = (int) Capsule::table(self::LOG_TABLE)->count();
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $offset = ($page - 1) * $perPage;

        $rows = Capsule::table(self::LOG_TABLE)
            ->orderBy('id', 'desc')
            ->skip($offset)
            ->take($perPage)
            ->get();

        if (is_object($rows) && method_exists($rows, 'all')) {
            $rows = $rows->all();
        }

        return [
            'accepted_today' => $acceptedToday,
            'deleted_today' => $deletedToday,
            'last_error' => $lastError,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'rows' => is_array($rows) ? $rows : iterator_to_array($rows),
        ];
    }

    public static function setting(string $key, string $default = ''): string
    {
        $settings = self::loadSettings();
        if (!array_key_exists($key, $settings) || $settings[$key] === null) {
            return $default;
        }
        return (string) $settings[$key];
    }

    public static function isYes(string $value): bool
    {
        $value = strtolower(trim($value));
        return in_array($value, ['on', 'yes', 'true', '1'], true);
    }

    private static function catchUpPaidPending(): void
    {
        $orders = Capsule::table('tblorders as o')
            ->join('tblinvoices as i', 'o.invoiceid', '=', 'i.id')
            ->whereRaw('LOWER(o.status) = ?', ['pending'])
            ->whereRaw('LOWER(i.status) = ?', ['paid'])
            ->orderBy('o.id', 'asc')
            ->limit(self::MAX_PER_CRON)
            ->get(['o.id as order_id', 'i.id as invoice_id']);

        foreach ($orders as $row) {
            self::acceptIfEligible((int) $row->order_id, (int) $row->invoice_id, 'AfterCronJob');
        }
    }

    private static function deleteStaleUnpaidPending(): void
    {
        $hours = self::unpaidMaxHours();
        if ($hours <= 0) {
            self::record(0, 0, 'error', 'unpaid_max_hours is not a positive number; delete sweep skipped', self::setting('unpaid_max_hours', ''));
            return;
        }

        $cutoff = self::cutoffString($hours);

        $orders = Capsule::table('tblorders as o')
            ->join('tblinvoices as i', 'o.invoiceid', '=', 'i.id')
            ->whereRaw('LOWER(o.status) = ?', ['pending'])
            ->whereRaw('LOWER(i.status) = ?', ['unpaid'])
            ->where('o.date', '<', $cutoff)
            ->orderBy('o.id', 'asc')
            ->limit(self::MAX_PER_CRON)
            ->get(['o.id as order_id', 'i.id as invoice_id']);

        foreach ($orders as $row) {
            self::deleteIfEligible((int) $row->order_id, (int) $row->invoice_id, $hours);
        }
    }

    /**
     * Delete a stale Unpaid + Pending order. Never deletes Paid/Refunded/non-Pending.
     *
     * WHMCS DeleteOrder only accepts Cancelled or Fraud
     * (https://developers.whmcs.com/api-reference/deleteorder/).
     * CancelOrder is used only as the required precondition, then DeleteOrder removes the order.
     */
    private static function deleteIfEligible(int $orderId, int $invoiceId, float $hours): void
    {
        if ($orderId <= 0) {
            return;
        }

        $lockKey = (string) $orderId;
        if (isset(self::$deleteLock[$lockKey]) || isset(self::$acceptLock[$lockKey])) {
            return;
        }
        self::$deleteLock[$lockKey] = true;

        $order = self::loadOrder($orderId);
        if ($order === null) {
            self::record($orderId, $invoiceId, 'skip', 'missing order (delete)', '');
            return;
        }

        $invoiceId = $invoiceId > 0 ? $invoiceId : (int) $order->invoiceid;
        $invoice = $invoiceId > 0 ? self::loadInvoice($invoiceId) : null;
        if ($invoice === null) {
            self::record($orderId, $invoiceId, 'skip', 'missing invoice (delete)', '');
            return;
        }

        $invoiceStatus = self::normStatus($invoice->status ?? '');
        $orderStatus = self::normStatus($order->status ?? '');

        // Payment may have landed between the sweep query and now.
        if ($invoiceStatus === 'paid' && $orderStatus === 'pending') {
            unset(self::$deleteLock[$lockKey]);
            self::acceptIfEligible($orderId, (int) $invoice->id, 'AfterCronJob-race');
            return;
        }

        if (in_array($invoiceStatus, ['paid', 'refunded', 'cancelled', 'collections'], true)) {
            self::record($orderId, (int) $invoice->id, 'skip', 'never delete invoice status ' . ($invoice->status ?? ''), '');
            return;
        }

        if ($orderStatus !== 'pending') {
            self::record($orderId, (int) $invoice->id, 'skip', 'never delete non-Pending order status ' . ($order->status ?? ''), '');
            return;
        }

        if ($invoiceStatus !== 'unpaid') {
            self::record($orderId, (int) $invoice->id, 'skip', 'delete requires Unpaid invoice, got ' . ($invoice->status ?? ''), '');
            return;
        }

        if (self::isFraud($order)) {
            self::record($orderId, (int) $invoice->id, 'skip', 'fraud flagged; never delete', '');
            return;
        }

        if (!self::orderOlderThanHours($order, $hours)) {
            self::record($orderId, (int) $invoice->id, 'skip', 'order not older than unpaid_max_hours', '');
            return;
        }

        if (self::isPaused((int) $invoice->id, $orderId, 'delete')) {
            return;
        }

        $cancelResult = self::localApi('CancelOrder', [
            'orderid' => $orderId,
            'noemail' => true,
        ]);
        if (!self::apiOk($cancelResult)) {
            $message = self::apiMessage($cancelResult);
            self::record($orderId, (int) $invoice->id, 'error', 'CancelOrder (precondition for DeleteOrder) failed: ' . $message, self::encodeResult($cancelResult));
            self::activity('ERROR CancelOrder failed for unpaid pending order #' . $orderId . ': ' . $message);
            self::noteActionError((int) $invoice->id, $orderId, 'delete', $message);
            return;
        }

        $deleteResult = self::localApi('DeleteOrder', ['orderid' => $orderId]);
        $encoded = self::encodeResult([
            'cancel' => $cancelResult,
            'delete' => $deleteResult,
        ]);

        if (self::apiOk($deleteResult)) {
            $reason = 'deleted stale unpaid pending order older than ' . $hours . 'h';
            self::record($orderId, (int) $invoice->id, 'delete', $reason, $encoded);
            self::activity('Deleted stale unpaid pending order #' . $orderId . ' (invoice #' . (int) $invoice->id . ')');
            self::noteActionSuccess((int) $invoice->id, $orderId, 'delete');
            return;
        }

        $message = self::apiMessage($deleteResult);
        self::record($orderId, (int) $invoice->id, 'error', 'DeleteOrder failed after CancelOrder: ' . $message, $encoded);
        self::activity('ERROR DeleteOrder failed for order #' . $orderId . ': ' . $message);
        self::noteActionError((int) $invoice->id, $orderId, 'delete', $message);
    }

    /**
     * @param object $order
     * @param object $invoice
     */
    private static function refuseAcceptReason($order, $invoice): ?string
    {
        if (self::isFraud($order)) {
            return 'fraud flagged / status Fraud; never accept';
        }

        $orderStatus = self::normStatus($order->status ?? '');
        if ($orderStatus !== 'pending') {
            return 'order status is not Pending (' . ($order->status ?? '') . ')';
        }

        $invoiceStatus = self::normStatus($invoice->status ?? '');
        if ($invoiceStatus !== 'paid') {
            return 'invoice is not Paid (' . ($invoice->status ?? '') . ')';
        }

        return null;
    }

    /**
     * @param object $order
     */
    private static function isFraud($order): bool
    {
        $status = self::normStatus($order->status ?? '');
        if ($status === 'fraud') {
            return true;
        }

        // Status Fraud is the official WHMCS flag. Do not treat a passed
        // fraud-module result (fraudmodule populated, status still Pending) as fraud.
        return false;
    }

    /**
     * @param object $order
     */
    private static function orderOlderThanHours($order, float $hours): bool
    {
        $date = (string) ($order->date ?? '');
        if ($date === '') {
            return false;
        }
        $ts = strtotime($date);
        if ($ts === false) {
            return false;
        }
        return $ts < (time() - (int) round($hours * 3600));
    }

    private static function unpaidMaxHours(): float
    {
        $raw = trim(self::setting('unpaid_max_hours', '6'));
        if ($raw === '' || !is_numeric($raw)) {
            return 6.0;
        }
        return (float) $raw;
    }

    /**
     * @return object|null
     */
    private static function loadOrder(int $orderId)
    {
        if ($orderId <= 0) {
            return null;
        }
        return Capsule::table('tblorders')->where('id', $orderId)->first();
    }

    /**
     * @return object|null
     */
    private static function loadInvoice(int $invoiceId)
    {
        if ($invoiceId <= 0) {
            return null;
        }
        return Capsule::table('tblinvoices')->where('id', $invoiceId)->first();
    }

    /**
     * @return object|null
     */
    private static function findOrderByInvoiceId(int $invoiceId)
    {
        if ($invoiceId <= 0) {
            return null;
        }
        return Capsule::table('tblorders')->where('invoiceid', $invoiceId)->orderBy('id', 'desc')->first();
    }

    /**
     * @param array<string, mixed> $postData
     * @return array<string, mixed>
     */
    private static function localApi(string $command, array $postData): array
    {
        if (!function_exists('localAPI')) {
            return ['result' => 'error', 'message' => 'localAPI is not available'];
        }

        $admin = trim(self::setting('admin_username', ''));

        try {
            if ($admin !== '') {
                $result = localAPI($command, $postData, $admin);
            } else {
                $result = localAPI($command, $postData);
            }
        } catch (\Throwable $e) {
            return ['result' => 'error', 'message' => $e->getMessage()];
        }

        if (!is_array($result)) {
            return ['result' => 'error', 'message' => 'localAPI returned a non-array response'];
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function apiOk(array $result): bool
    {
        return strtolower((string) ($result['result'] ?? '')) === 'success';
    }

    /**
     * @param array<string, mixed> $result
     */
    private static function apiMessage(array $result): string
    {
        $message = (string) ($result['message'] ?? $result['error'] ?? '');
        if ($message === '') {
            $message = self::encodeResult($result);
        }
        return $message;
    }

    /**
     * @param mixed $result
     */
    private static function encodeResult($result): string
    {
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return $json !== false ? $json : '';
    }

    private static function record(int $orderId, int $invoiceId, string $action, string $reason, string $apiResult): void
    {
        try {
            self::ensureLogTable();
            Capsule::table(self::LOG_TABLE)->insert([
                'order_id' => $orderId,
                'invoice_id' => $invoiceId,
                'action' => $action,
                'reason' => $reason,
                'api_result' => $apiResult,
                'created_at' => self::nowString(),
            ]);
        } catch (\Throwable $e) {
            self::activity('ERROR writing ' . self::LOG_TABLE . ': ' . $e->getMessage() . ' [' . $action . ' order #' . $orderId . ']');
        }
    }

    private static function activity(string $message): void
    {
        if (function_exists('logActivity')) {
            logActivity('Cyberfly Auto Accept: ' . $message);
        }
    }

    /**
     * @return array<string, string>
     */
    private static function loadSettings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $out = [];
        try {
            $rows = Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->get(['setting', 'value']);
            foreach ($rows as $row) {
                $out[(string) $row->setting] = (string) $row->value;
            }
        } catch (\Throwable $e) {
            self::activity('ERROR loading settings: ' . $e->getMessage());
        }

        self::$settingsCache = $out;
        return $out;
    }

    private static function normStatus($status): string
    {
        return strtolower(trim((string) $status));
    }

    private static function boolWord(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }

    private static function nowString(): string
    {
        if (class_exists('\\WHMCS\\Carbon')) {
            return \WHMCS\Carbon::now()->toDateTimeString();
        }
        return date('Y-m-d H:i:s');
    }

    private static function todayStartString(): string
    {
        if (class_exists('\\WHMCS\\Carbon')) {
            return \WHMCS\Carbon::now()->startOfDay()->toDateTimeString();
        }
        return date('Y-m-d 00:00:00');
    }

    private static function cutoffString(float $hours): string
    {
        $seconds = (int) round($hours * 3600);
        if (class_exists('\\WHMCS\\Carbon')) {
            return \WHMCS\Carbon::now()->subSeconds($seconds)->toDateTimeString();
        }
        return date('Y-m-d H:i:s', time() - $seconds);
    }

    /**
     * Whether retries are paused for this invoice/order + action.
     * Invoice id is preferred when > 0; free orders use order_id with invoice_id=0.
     */
    public static function isPaused(int $invoiceId, int $orderId, string $action): bool
    {
        try {
            self::ensurePauseTable();
            $q = Capsule::table(self::PAUSE_TABLE)
                ->where('action', $action)
                ->where('paused', 1);
            if ($invoiceId > 0) {
                $q->where('invoice_id', $invoiceId);
            } else {
                $q->where('invoice_id', 0)->where('order_id', $orderId);
            }
            $row = $q->first();
            return $row !== null;
        } catch (\Throwable $e) {
            self::activity('ERROR isPaused: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Reset consecutive counter after a successful action.
     */
    public static function noteActionSuccess(int $invoiceId, int $orderId, string $action): void
    {
        try {
            self::ensurePauseTable();
            $now = self::nowString();
            $existing = self::findPauseRow($invoiceId, $orderId, $action);
            if ($existing === null) {
                return;
            }
            Capsule::table(self::PAUSE_TABLE)->where('id', (int) $existing->id)->update([
                'consecutive_errors' => 0,
                'paused' => 0,
                'alert_sent' => 0,
                'last_error' => null,
                'paused_at' => null,
                'updated_at' => $now,
            ]);
        } catch (\Throwable $e) {
            self::activity('ERROR noteActionSuccess: ' . $e->getMessage());
        }
    }

    /**
     * Increment consecutive errors; at threshold pause and send one Telegram.
     */
    public static function noteActionError(int $invoiceId, int $orderId, string $action, string $message): void
    {
        try {
            self::ensurePauseTable();
            $now = self::nowString();
            $summary = self::safeErrorSummary($message);
            $existing = self::findPauseRow($invoiceId, $orderId, $action);

            if ($existing === null) {
                $id = Capsule::table(self::PAUSE_TABLE)->insertGetId([
                    'invoice_id' => $invoiceId,
                    'order_id' => $orderId,
                    'action' => $action,
                    'consecutive_errors' => 1,
                    'paused' => 0,
                    'alert_sent' => 0,
                    'last_error' => $summary,
                    'paused_at' => null,
                    'updated_at' => $now,
                ]);
                $count = 1;
                $alerted = false;
            } else {
                if ((int) $existing->paused === 1) {
                    Capsule::table(self::PAUSE_TABLE)->where('id', (int) $existing->id)->update([
                        'last_error' => $summary,
                        'updated_at' => $now,
                    ]);
                    return;
                }
                $count = (int) $existing->consecutive_errors + 1;
                $alerted = (int) $existing->alert_sent === 1;
                Capsule::table(self::PAUSE_TABLE)->where('id', (int) $existing->id)->update([
                    'consecutive_errors' => $count,
                    'last_error' => $summary,
                    'order_id' => $orderId > 0 ? $orderId : (int) $existing->order_id,
                    'updated_at' => $now,
                ]);
                $id = (int) $existing->id;
            }

            if ($count >= self::ERROR_PAUSE_THRESHOLD) {
                Capsule::table(self::PAUSE_TABLE)->where('id', $id)->update([
                    'paused' => 1,
                    'paused_at' => $now,
                    'updated_at' => $now,
                ]);
                self::record(
                    $orderId,
                    $invoiceId,
                    'paused',
                    '已暂停 ' . $action . '（连续 ' . $count . ' 次错误）: ' . $summary,
                    ''
                );
                self::activity(
                    'PAUSED ' . $action . ' for invoice #' . $invoiceId
                    . ' order #' . $orderId . ' after ' . $count . ' consecutive errors'
                );
                if (!$alerted) {
                    self::sendPauseTelegram($invoiceId, $orderId, $action, $count, $summary);
                    Capsule::table(self::PAUSE_TABLE)->where('id', $id)->update([
                        'alert_sent' => 1,
                        'updated_at' => self::nowString(),
                    ]);
                }
            }
        } catch (\Throwable $e) {
            self::activity('ERROR noteActionError: ' . $e->getMessage());
        }
    }

    /**
     * Manual resume: clear pause so retries are allowed again.
     */
    public static function resumePause(int $pauseId): bool
    {
        self::ensurePauseTable();
        $row = Capsule::table(self::PAUSE_TABLE)->where('id', $pauseId)->first();
        if ($row === null) {
            return false;
        }
        Capsule::table(self::PAUSE_TABLE)->where('id', $pauseId)->update([
            'paused' => 0,
            'consecutive_errors' => 0,
            'alert_sent' => 0,
            'paused_at' => null,
            'last_error' => null,
            'updated_at' => self::nowString(),
        ]);
        self::record(
            (int) $row->order_id,
            (int) $row->invoice_id,
            'resume',
            '手动恢复 ' . (string) $row->action . '（暂停 id ' . $pauseId . '）',
            ''
        );
        self::activity(
            'RESUMED ' . (string) $row->action
            . ' for invoice #' . (int) $row->invoice_id
            . ' order #' . (int) $row->order_id
        );
        return true;
    }

    /**
     * @return array<int, object>
     */
    public static function listPaused(int $limit = 100): array
    {
        self::ensurePauseTable();
        $rows = Capsule::table(self::PAUSE_TABLE)
            ->where('paused', 1)
            ->where('action', '!=', '__meta__')
            ->orderBy('paused_at', 'desc')
            ->limit(max(1, min(500, $limit)))
            ->get();
        if (is_object($rows) && method_exists($rows, 'all')) {
            $rows = $rows->all();
        }
        return is_array($rows) ? $rows : iterator_to_array($rows);
    }

    /**
     * Age-based + per-key error cap. Runs at most once per calendar day unless $force.
     *
     * @return array{deleted:int, kept:int, forced:bool}
     */
    public static function pruneLogs(bool $force = false): array
    {
        self::ensureLogTable();
        self::ensurePauseTable();

        $today = date('Y-m-d');
        if (!$force) {
            $meta = Capsule::table(self::PAUSE_TABLE)
                ->where('action', '__meta__')
                ->where('invoice_id', 0)
                ->where('order_id', 0)
                ->first();
            if ($meta && strpos((string) ($meta->last_error ?? ''), 'pruned:' . $today) === 0) {
                return ['deleted' => 0, 'kept' => (int) Capsule::table(self::LOG_TABLE)->count(), 'forced' => false];
            }
        }

        $deleted = 0;
        $errorCut = self::cutoffString((float) self::LOG_KEEP_ERROR_DAYS * 24);
        $successCut = self::cutoffString((float) self::LOG_KEEP_SUCCESS_DAYS * 24);
        $skipCut = self::cutoffString((float) self::LOG_KEEP_SKIP_DAYS * 24);

        $deleted += (int) Capsule::table(self::LOG_TABLE)
            ->where('action', 'error')
            ->where('created_at', '<', $errorCut)
            ->delete();
        $deleted += (int) Capsule::table(self::LOG_TABLE)
            ->whereIn('action', ['accept', 'delete'])
            ->where('created_at', '<', $successCut)
            ->delete();
        $deleted += (int) Capsule::table(self::LOG_TABLE)
            ->whereIn('action', ['skip', 'paused', 'resume'])
            ->where('created_at', '<', $skipCut)
            ->delete();

        $keys = Capsule::table(self::LOG_TABLE)
            ->select('invoice_id', 'action')
            ->where('action', 'error')
            ->groupBy('invoice_id', 'action')
            ->havingRaw('COUNT(*) > ?', [self::LOG_MAX_ERRORS_PER_KEY])
            ->get();
        if (is_object($keys) && method_exists($keys, 'all')) {
            $keys = $keys->all();
        }
        foreach ($keys as $key) {
            $keepIds = Capsule::table(self::LOG_TABLE)
                ->where('action', 'error')
                ->where('invoice_id', (int) $key->invoice_id)
                ->orderBy('id', 'desc')
                ->limit(self::LOG_MAX_ERRORS_PER_KEY)
                ->pluck('id');
            if (is_object($keepIds) && method_exists($keepIds, 'all')) {
                $keepIds = $keepIds->all();
            }
            $keepIds = array_map('intval', is_array($keepIds) ? $keepIds : iterator_to_array($keepIds));
            if ($keepIds === []) {
                continue;
            }
            $deleted += (int) Capsule::table(self::LOG_TABLE)
                ->where('action', 'error')
                ->where('invoice_id', (int) $key->invoice_id)
                ->whereNotIn('id', $keepIds)
                ->delete();
        }

        $kept = (int) Capsule::table(self::LOG_TABLE)->count();
        $metaMsg = 'pruned:' . $today . ' deleted=' . $deleted . ' kept=' . $kept;
        $meta = Capsule::table(self::PAUSE_TABLE)
            ->where('action', '__meta__')
            ->where('invoice_id', 0)
            ->where('order_id', 0)
            ->first();
        if ($meta) {
            Capsule::table(self::PAUSE_TABLE)->where('id', (int) $meta->id)->update([
                'last_error' => $metaMsg,
                'updated_at' => self::nowString(),
            ]);
        } else {
            Capsule::table(self::PAUSE_TABLE)->insert([
                'invoice_id' => 0,
                'order_id' => 0,
                'action' => '__meta__',
                'consecutive_errors' => 0,
                'paused' => 0,
                'alert_sent' => 0,
                'last_error' => $metaMsg,
                'paused_at' => null,
                'updated_at' => self::nowString(),
            ]);
        }

        self::record(0, 0, 'skip', 'log prune: deleted=' . $deleted . ' kept=' . $kept, $metaMsg);
        return ['deleted' => $deleted, 'kept' => $kept, 'forced' => $force];
    }

    private static function maybeDailyLogMaintenance(): void
    {
        self::pruneLogs(false);
    }

    /**
     * @return object|null
     */
    private static function findPauseRow(int $invoiceId, int $orderId, string $action)
    {
        $q = Capsule::table(self::PAUSE_TABLE)->where('action', $action);
        if ($invoiceId > 0) {
            $q->where('invoice_id', $invoiceId);
        } else {
            $q->where('invoice_id', 0)->where('order_id', $orderId);
        }
        return $q->first();
    }

    private static function safeErrorSummary(string $message): string
    {
        $message = trim(preg_replace('/\s+/', ' ', strip_tags($message)) ?? '');
        $message = preg_replace('/(password|token|secret|api[_-]?key)\s*[:=]\s*\S+/i', '$1=***', $message) ?? $message;
        if (function_exists('mb_substr')) {
            return mb_substr($message, 0, 400, 'UTF-8');
        }
        return substr($message, 0, 400);
    }


    private static function sendPauseTelegram(
        int $invoiceId,
        int $orderId,
        string $action,
        int $count,
        string $summary
    ): void {
        try {
            $overrideToken = trim(self::setting('tg_bot_token', ''));
            $overrideChat = trim(self::setting('tg_chat_id', ''));

            $token = $overrideToken;
            $chatId = $overrideChat;
            $source = 'addon settings';

            if ($token === '' || $chatId === '') {
                $root = defined('ROOTDIR') ? ROOTDIR : dirname(__DIR__, 4);
                $boot = rtrim((string) $root, '/') . '/includes/telegram/bootstrap.php';
                if (!is_file($boot)) {
                    self::activity('Telegram bootstrap missing and addon tg_* empty; pause alert not sent');
                    return;
                }
                require_once $boot;
                if ($token === '') {
                    $token = defined('TG_TOKEN') ? trim((string) TG_TOKEN) : '';
                }
                if ($chatId === '') {
                    $chatId = defined('TG_CHAT_ID') ? trim((string) TG_CHAT_ID) : '';
                }
                $source = 'site includes/telegram (+ addon overrides if set)';
            }

            if ($token === '' || $chatId === '') {
                self::activity('Pause alert skipped: empty tg token/chat (' . $source . ')');
                return;
            }

            $esc = static function ($t) {
                return htmlspecialchars((string) $t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            };
            $siteLabel = self::SITE_LABEL;
            $text = "⏸ <b>该订单 Auto Accept 已停重试</b>\n"
                . "站点: <b>" . $esc($siteLabel) . "</b>\n"
                . "发票 #<code>" . (int) $invoiceId . "</code> / 订单 #<code>" . (int) $orderId . "</code>\n"
                . "动作: <code>" . $esc($action) . "</code>\n"
                . "连续错误次数: <b>" . (int) $count . "</b>\n"
                . "摘要: " . $esc($summary) . "\n\n"
                . "仅此发票+动作不再自动重试；其它订单不受影响。"
                . "处理完 Username Already Exists 等问题后，可在插件「已暂停」列表对该行点「恢复」再试。";

            // Prefer site helper when no token override (uses that site's bot only).
            if ($overrideToken === '' && function_exists('tg_send_message')) {
                $ok = tg_send_message($text, null, $chatId !== '' ? $chatId : null);
                if ($ok === false) {
                    self::activity('Telegram pause alert send failed for invoice #' . $invoiceId);
                }
                return;
            }

            // Direct send with explicit token/chat from THIS addon's settings (never another site).
            $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
            $params = [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'HTML',
                'disable_web_page_preview' => true,
            ];
            $timeout = 8;
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $params,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_TIMEOUT => $timeout,
            ]);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($response === false || $errno || $http !== 200) {
                self::activity('Telegram pause alert HTTP send failed for invoice #' . $invoiceId . ' http=' . $http);
            }
        } catch (\Throwable $e) {
            self::activity('Telegram pause alert exception: ' . $e->getMessage());
        }
    }



}
