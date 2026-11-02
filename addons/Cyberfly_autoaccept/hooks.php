<?php
/**
 * Cyberfly Auto Accept hooks.
 *
 * WHMCS auto-loads hooks.php from an activated addon folder.
 *
 * @see https://developers.whmcs.com/addon-modules/hooks/
 * @see https://developers.whmcs.com/hooks-reference/invoices-and-quotes/#invoicepaid
 * @see https://developers.whmcs.com/hooks-reference/shopping-cart/#orderpaid
 * @see https://developers.whmcs.com/hooks-reference/cron/#aftercronjob
 */

use cyberfly\AutoAccept\AutoAccept;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/lib/AutoAccept.php';

add_hook('InvoicePaid', 1, function ($vars) {
    try {
        AutoAccept::handleInvoicePaid(is_array($vars) ? $vars : []);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Cyberfly Auto Accept: InvoicePaid hook error: ' . $e->getMessage());
        }
    }
});

add_hook('OrderPaid', 1, function ($vars) {
    try {
        AutoAccept::handleOrderPaid(is_array($vars) ? $vars : []);
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Cyberfly Auto Accept: OrderPaid hook error: ' . $e->getMessage());
        }
    }
});

add_hook('AfterCronJob', 1, function ($vars) {
    try {
        AutoAccept::handleAfterCronJob();
    } catch (\Throwable $e) {
        if (function_exists('logActivity')) {
            logActivity('Cyberfly Auto Accept: AfterCronJob hook error: ' . $e->getMessage());
        }
    }
});
