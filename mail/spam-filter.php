<?php
declare(strict_types=1);

/**
 * Heuristic scorer. Deliberately simple and transparent; it supplements (not replaces)
 * a real MTA-level filter such as Rspamd/SpamAssassin, whose verdict header is honoured when present.
 * Returns ['score'=>float,'is_spam'=>bool,'reasons'=>[...]].
 */
function spam_score(array $parsed, array $authSignals = []): array
{
    $score = 0.0;
    $why = [];

    // trust an upstream filter verdict if the MTA added one
    $xs = strtolower(($parsed['headers']['x-spam-flag'][0] ?? ''));
    if ($xs === 'yes') {
        return ['score' => 10.0, 'is_spam' => true, 'reasons' => ['upstream filter flagged as spam']];
    }

    $subject = (string)($parsed['subject'] ?? '');
    $text = strtolower((string)($parsed['text'] ?? '') . ' ' . strip_tags((string)($parsed['html'] ?? '')));
    $hits = ['viagra', 'cialis', 'lottery winner', 'claim your prize', 'wire transfer', 'crypto giveaway', 'make money fast', '100% free', 'act now', 'nigerian prince', 'click here to unsubscribe'];
    foreach ($hits as $w) {
        if (str_contains(strtolower($subject . ' ' . $text), $w)) {
            $score += 2.0;
            $why[] = "phrase: $w";
        }
    }
    if ($subject !== '' && mb_strlen($subject) > 8 && $subject === mb_strtoupper($subject) && preg_match('/[A-Z]{6}/', $subject)) {
        $score += 1.5;
        $why[] = 'subject in capitals';
    }
    if (substr_count($subject, '!') >= 3) {
        $score += 1.0;
        $why[] = 'excess exclamation marks';
    }
    if (preg_match_all('#https?://#i', $text) > 8) {
        $score += 1.5;
        $why[] = 'many links';
    }
    if (preg_match('#https?://\d{1,3}(\.\d{1,3}){3}#', $text)) {
        $score += 2.0;
        $why[] = 'link to a raw IP address';
    }
    if (($parsed['from']['address'] ?? '') === '') {
        $score += 3.0;
        $why[] = 'missing sender';
    }
    if (($authSignals['spf'] ?? '') === 'fail') {
        $score += 3.0;
        $why[] = 'SPF failed';
    }
    if (($authSignals['dkim'] ?? '') === 'fail') {
        $score += 2.0;
        $why[] = 'DKIM failed';
    }
    if (($authSignals['dmarc'] ?? '') === 'fail') {
        $score += 3.0;
        $why[] = 'DMARC failed';
    }
    foreach ($parsed['attachments'] ?? [] as $a) {
        $ext = strtolower(pathinfo((string)$a['file_name'], PATHINFO_EXTENSION));
        if (in_array($ext, BLOCKED_EXTENSIONS, true)) {
            $score += 4.0;
            $why[] = 'risky attachment type: .' . $ext;
        }
    }
    return ['score' => round($score, 2), 'is_spam' => $score >= 5.0, 'reasons' => $why];
}
