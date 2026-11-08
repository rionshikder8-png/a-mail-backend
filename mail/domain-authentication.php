<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/mail_config.php';

/** DKIM relaxed/relaxed signing (RFC 6376) with rsa-sha256. Returns the DKIM-Signature header line, or '' if no key. */
function dkim_sign(string $headersBlock, string $body): string
{
    $cfg = mail_config()['dkim'];
    if ($cfg['key_path'] === '' || !is_readable($cfg['key_path'])) {
        return '';
    }
    $key = openssl_pkey_get_private((string)file_get_contents($cfg['key_path']));
    if (!$key) {
        return '';
    }
    $canonBody = dkim_canon_body($body);
    $bh = base64_encode(hash('sha256', $canonBody, true));
    $signHeaders = ['from', 'to', 'subject', 'date', 'message-id', 'mime-version', 'content-type'];

    $parsed = [];
    foreach (explode("\r\n", preg_replace("/\r\n[ \t]+/", ' ', $headersBlock) ?? '') as $line) {
        if (str_contains($line, ':')) {
            [$k, $v] = explode(':', $line, 2);
            $parsed[strtolower(trim($k))] = trim($v);
        }
    }
    $present = array_values(array_filter($signHeaders, static fn($h) => isset($parsed[$h])));
    $sigHeader = 'v=1; a=rsa-sha256; c=relaxed/relaxed; d=' . config('domain') . '; s=' . $cfg['selector']
        . '; t=' . time() . '; h=' . implode(':', $present) . '; bh=' . $bh . '; b=';

    $toSign = '';
    foreach ($present as $h) {
        $toSign .= $h . ':' . preg_replace('/\s+/', ' ', $parsed[$h]) . "\r\n";
    }
    $toSign .= 'dkim-signature:' . preg_replace('/\s+/', ' ', $sigHeader);

    if (!openssl_sign($toSign, $sig, $key, OPENSSL_ALGO_SHA256)) {
        return '';
    }
    return 'DKIM-Signature: ' . $sigHeader . rtrim(chunk_split(base64_encode($sig), 72, "\r\n\t"));
}

function dkim_canon_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $lines = explode("\n", $body);
    foreach ($lines as &$l) {
        $l = rtrim(preg_replace('/[ \t]+/', ' ', $l) ?? $l);
    }
    unset($l);
    $out = rtrim(implode("\r\n", $lines), "\r\n");
    return $out === '' ? '' : $out . "\r\n";
}

/** Live DNS checks used by the admin Domains page. Returns what was actually observed. */
function domain_dns_check(string $domain, string $selector): array
{
    $res = ['mx' => [], 'spf' => null, 'dkim' => null, 'dmarc' => null];
    $mx = @dns_get_record($domain, DNS_MX) ?: [];
    foreach ($mx as $r) {
        $res['mx'][] = $r['target'] . ' (pri ' . $r['pri'] . ')';
    }
    foreach (@dns_get_record($domain, DNS_TXT) ?: [] as $r) {
        if (stripos($r['txt'] ?? '', 'v=spf1') === 0) {
            $res['spf'] = $r['txt'];
        }
    }
    foreach (@dns_get_record($selector . '._domainkey.' . $domain, DNS_TXT) ?: [] as $r) {
        if (stripos($r['txt'] ?? '', 'v=DKIM1') === 0) {
            $res['dkim'] = mb_substr($r['txt'], 0, 80) . '…';
        }
    }
    foreach (@dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [] as $r) {
        if (stripos($r['txt'] ?? '', 'v=DMARC1') === 0) {
            $res['dmarc'] = $r['txt'];
        }
    }
    return $res;
}

/** Evaluate Authentication-Results style signals on inbound mail (only what the MTA recorded). */
function inbound_auth_signals(array $headers): array
{
    $ar = strtolower(implode(' ', $headers['authentication-results'] ?? []));
    return [
        'spf' => preg_match('/spf=(pass|fail|softfail|neutral|none)/', $ar, $m) ? $m[1] : 'unknown',
        'dkim' => preg_match('/dkim=(pass|fail|none)/', $ar, $m) ? $m[1] : 'unknown',
        'dmarc' => preg_match('/dmarc=(pass|fail|none)/', $ar, $m) ? $m[1] : 'unknown',
    ];
}
