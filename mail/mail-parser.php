<?php
declare(strict_types=1);

/**
 * RFC 5322 / MIME parser. Pure PHP (no ext-mailparse needed).
 * parse_raw_mail() returns headers, text/html bodies and attachments.
 */

function mime_decode_header(string $v): string
{
    $v = preg_replace("/\r?\n[ \t]+/", ' ', $v) ?? $v;
    if (function_exists('iconv_mime_decode')) {
        $d = @iconv_mime_decode($v, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        if ($d !== false) {
            return trim($d);
        }
    }
    return trim(mb_decode_mimeheader($v));
}

function mime_split(string $raw): array
{
    $raw = str_replace("\r\n", "\n", $raw);
    $pos = strpos($raw, "\n\n");
    if ($pos === false) {
        return [$raw, ''];
    }
    return [substr($raw, 0, $pos), substr($raw, $pos + 2)];
}

function mime_headers(string $block): array
{
    $block = preg_replace("/\n[ \t]+/", ' ', $block) ?? $block;
    $out = [];
    foreach (explode("\n", $block) as $line) {
        if (!str_contains($line, ':')) {
            continue;
        }
        [$k, $v] = explode(':', $line, 2);
        $k = strtolower(trim($k));
        $out[$k][] = trim($v);
    }
    return $out;
}

function mime_param(string $header, string $name): ?string
{
    if (preg_match('/;\s*' . preg_quote($name, '/') . '\*?=(?:"([^"]*)"|([^;\s]*))/i', $header, $m)) {
        $v = $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        return mime_decode_header($v);
    }
    return null;
}

function mime_decode_body(string $body, string $encoding, string $charset): string
{
    $enc = strtolower(trim($encoding));
    if ($enc === 'base64') {
        $body = base64_decode(preg_replace('/\s+/', '', $body) ?? '', false) ?: '';
    } elseif ($enc === 'quoted-printable') {
        $body = quoted_printable_decode($body);
    }
    $charset = strtolower($charset ?: 'utf-8');
    if ($charset !== 'utf-8' && $charset !== 'us-ascii') {
        $conv = @mb_convert_encoding($body, 'UTF-8', $charset);
        if (is_string($conv)) {
            $body = $conv;
        }
    }
    return $body;
}

/** Parse "Name <a@b.c>, d@e.f" into [{name,address}]. */
function parse_address_list(string $v): array
{
    $v = mime_decode_header($v);
    $out = [];
    // split on commas not inside quotes/angle brackets
    $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $v) ?: [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') {
            continue;
        }
        if (preg_match('/^(.*?)<([^>]+)>$/', $p, $m)) {
            $name = trim($m[1], " \t\"'");
            $addr = strtolower(trim($m[2]));
        } else {
            $name = '';
            $addr = strtolower(trim($p, " <>"));
        }
        if (filter_var($addr, FILTER_VALIDATE_EMAIL)) {
            $out[] = ['name' => $name, 'address' => $addr];
        }
    }
    return $out;
}

function parse_mime_part(string $raw, array &$result, int $depth = 0): void
{
    if ($depth > 8) {
        return;
    }
    [$hb, $body] = mime_split($raw);
    $h = mime_headers($hb);
    $ctype = $h['content-type'][0] ?? 'text/plain';
    $mime = strtolower(trim(explode(';', $ctype)[0]));
    $enc = $h['content-transfer-encoding'][0] ?? '7bit';
    $dispo = strtolower($h['content-disposition'][0] ?? '');

    if (str_starts_with($mime, 'multipart/')) {
        $boundary = mime_param($ctype, 'boundary');
        if (!$boundary) {
            return;
        }
        $chunks = preg_split('/\n--' . preg_quote($boundary, '/') . '(?:--)?[ \t]*(?:\n|$)/', "\n" . $body) ?: [];
        array_shift($chunks);
        foreach ($chunks as $c) {
            if (trim($c) !== '') {
                parse_mime_part($c, $result, $depth + 1);
            }
        }
        return;
    }

    $filename = mime_param($dispo, 'filename') ?? mime_param($ctype, 'name');
    $isAttachment = str_starts_with($dispo, 'attachment') || ($filename !== null && !in_array($mime, ['text/plain', 'text/html'], true))
        || ($filename !== null && str_starts_with($dispo, 'inline') && !str_starts_with($mime, 'text/'));

    if ($isAttachment || (!str_starts_with($mime, 'text/') && $mime !== 'message/rfc822')) {
        $data = mime_decode_body($body, $enc, 'binary');
        if (strtolower(trim($enc)) !== 'base64' && strtolower(trim($enc)) !== 'quoted-printable') {
            $data = $body;
        }
        $result['attachments'][] = [
            'file_name' => $filename ?: 'attachment-' . (count($result['attachments']) + 1),
            'mime_type' => $mime,
            'data' => $data,
            'content_id' => trim($h['content-id'][0] ?? '', '<> '),
        ];
        return;
    }

    $text = mime_decode_body($body, $enc, mime_param($ctype, 'charset') ?? 'utf-8');
    if ($mime === 'text/html') {
        $result['html'] .= $text;
    } elseif ($mime === 'text/plain') {
        $result['text'] .= $text;
    }
}

function parse_raw_mail(string $raw): array
{
    [$hb, ] = mime_split($raw);
    $h = mime_headers($hb);
    $first = static fn(string $k): string => $h[$k][0] ?? '';

    $from = parse_address_list($first('from'))[0] ?? ['name' => '', 'address' => ''];
    $result = ['html' => '', 'text' => '', 'attachments' => []];
    parse_mime_part($raw, $result);

    $date = null;
    if ($first('date') !== '') {
        $ts = strtotime($first('date'));
        $date = $ts ? gmdate('Y-m-d H:i:s', $ts) : null;
    }

    return [
        'message_id' => trim($first('message-id'), '<> '),
        'in_reply_to' => trim($first('in-reply-to'), '<> '),
        'references' => $first('references'),
        'from' => $from,
        'to' => parse_address_list($first('to')),
        'cc' => parse_address_list($first('cc')),
        'subject' => mime_decode_header($first('subject')),
        'date' => $date,
        'html' => $result['html'],
        'text' => $result['text'],
        'attachments' => $result['attachments'],
        'headers' => $h,
        'size' => strlen($raw),
    ];
}

/** Build a safe plain-text preview for list rows. */
function make_snippet(string $text, string $html = ''): string
{
    if (trim($text) === '' && $html !== '') {
        $html = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html) ?? $html;
        $text = html_entity_decode(strip_tags(preg_replace('#<br\s*/?>|</p>|</div>#i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    return mb_substr($text, 0, 200);
}
