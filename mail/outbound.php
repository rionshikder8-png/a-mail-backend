<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/mail_helpers.php';
require_once dirname(__DIR__) . '/config/mail_config.php';
require_once __DIR__ . '/domain-authentication.php';

/** Build a standards-compliant RFC 5322 message (multipart/alternative, + mixed for attachments) for a stored email. */
function build_raw_message(array $email, array $recipients, array $attachments): string
{
    $eol = "\r\n";
    $domain = (string)config('domain');
    $to = $cc = [];
    foreach ($recipients as $r) {
        if ($r['kind'] === 'to') {
            $to[] = format_address($r['name'], $r['address']);
        } elseif ($r['kind'] === 'cc') {
            $cc[] = format_address($r['name'], $r['address']);
        }
    }
    // BCC recipients are deliberately never written into headers.
    $h = [];
    $h[] = 'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000';
    $h[] = 'From: ' . format_address((string)$email['from_name'], (string)$email['from_address']);
    if ($to) {
        $h[] = 'To: ' . implode(', ', $to);
    }
    if ($cc) {
        $h[] = 'Cc: ' . implode(', ', $cc);
    }
    $h[] = 'Subject: ' . (preg_match('/[^\x20-\x7E]/', (string)$email['subject']) ? mb_encode_mimeheader(header_safe((string)$email['subject']), 'UTF-8', 'B', $eol) : header_safe((string)$email['subject']));
    $h[] = 'Message-ID: <' . header_safe((string)$email['message_id']) . '>';
    if (!empty($email['in_reply_to'])) {
        $h[] = 'In-Reply-To: <' . header_safe((string)$email['in_reply_to']) . '>';
    }
    if (!empty($email['references_hdr'])) {
        $h[] = 'References: ' . header_safe((string)$email['references_hdr']);
    }
    $h[] = 'MIME-Version: 1.0';
    $h[] = 'X-Mailer: A-Mail by Adverune';

    $text = (string)$email['body_text'] !== '' ? (string)$email['body_text'] : trim(strip_tags((string)$email['body_html']));
    $html = (string)$email['body_html'] !== '' ? (string)$email['body_html'] : '<div>' . nl2br(htmlspecialchars($text, ENT_QUOTES)) . '</div>';

    $alt = 'alt_' . bin2hex(random_bytes(8));
    $altBody = '--' . $alt . $eol . 'Content-Type: text/plain; charset=UTF-8' . $eol . 'Content-Transfer-Encoding: quoted-printable' . $eol . $eol
        . quoted_printable_encode($text) . $eol
        . '--' . $alt . $eol . 'Content-Type: text/html; charset=UTF-8' . $eol . 'Content-Transfer-Encoding: quoted-printable' . $eol . $eol
        . quoted_printable_encode($html) . $eol
        . '--' . $alt . '--' . $eol;

    if (!$attachments) {
        $ctype = 'Content-Type: multipart/alternative; boundary="' . $alt . '"';
        $body = $altBody;
    } else {
        $mix = 'mix_' . bin2hex(random_bytes(8));
        $ctype = 'Content-Type: multipart/mixed; boundary="' . $mix . '"';
        $body = '--' . $mix . $eol . 'Content-Type: multipart/alternative; boundary="' . $alt . '"' . $eol . $eol . $altBody;
        foreach ($attachments as $a) {
            $abs = attachment_abs_path($a['storage_path']);
            if (!$abs) {
                continue;
            }
            $name = header_safe($a['file_name']);
            $enc = mb_encode_mimeheader($name, 'UTF-8', 'B', $eol);
            $body .= '--' . $mix . $eol
                . 'Content-Type: ' . preg_replace('/[^\w\/.+\-]/', '', $a['mime_type']) . '; name="' . str_replace('"', '', $enc) . '"' . $eol
                . 'Content-Transfer-Encoding: base64' . $eol
                . 'Content-Disposition: attachment; filename="' . str_replace('"', '', $enc) . '"' . $eol . $eol
                . chunk_split(base64_encode((string)file_get_contents($abs)), 76, $eol);
        }
        $body .= '--' . $mix . '--' . $eol;
    }

    $headersBlock = implode($eol, $h) . $eol . $ctype;
    $dkim = dkim_sign($headersBlock, $body);
    return ($dkim !== '' ? $dkim . $eol : '') . $headersBlock . $eol . $eol . $body;
}
