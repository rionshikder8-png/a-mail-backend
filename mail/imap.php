<?php
declare(strict_types=1);

require_once __DIR__ . '/inbound.php';
require_once dirname(__DIR__) . '/config/mail_config.php';

/**
 * Poll a catch-all IMAP mailbox that receives mail for the domain and feed it into inbound_process().
 * Requires ext-imap; returns an explicit 'unavailable' result otherwise (no pretending).
 * Messages are only deleted from the source mailbox after successful local handling.
 */
function imap_poll(int $max = 50): array
{
    $c = mail_config()['imap'];
    if ($c['host'] === '') {
        return ['status' => 'unavailable', 'reason' => 'IMAP_HOST is not configured'];
    }
    if (!function_exists('imap_open')) {
        return ['status' => 'unavailable', 'reason' => 'PHP imap extension is not installed'];
    }
    $mbox = @imap_open('{' . $c['host'] . ':' . $c['port'] . '/imap/ssl/validate-cert}INBOX', $c['user'], $c['pass'], 0, 1);
    if (!$mbox) {
        return ['status' => 'error', 'reason' => 'IMAP connection failed: ' . (string)imap_last_error()];
    }
    $out = ['status' => 'ok', 'fetched' => 0, 'delivered' => 0, 'skipped' => 0];
    $ids = imap_search($mbox, 'ALL', SE_UID) ?: [];
    foreach (array_slice($ids, 0, $max) as $uid) {
        $raw = imap_fetchheader($mbox, $uid, FT_UID) . imap_body($mbox, $uid, FT_UID | FT_PEEK);
        $out['fetched']++;
        $envelope = [];
        if (preg_match_all('/^(?:Delivered-To|X-Original-To):\s*(\S+)/mi', $raw, $m)) {
            $envelope = array_map('strtolower', $m[1]);
        }
        $res = inbound_process($raw, $envelope);
        $handled = !isset($res['_error']);
        if ($handled) {
            imap_delete($mbox, (string)$uid, FT_UID);
            $out['delivered']++;
        } else {
            $out['skipped']++;
        }
    }
    imap_expunge($mbox);
    imap_close($mbox);
    return $out;
}
