<?php
declare(strict_types=1);

require_once __DIR__ . '/smtp.php';

/**
 * Connects to the configured SMTP server and logs in, without sending any message.
 * Returns a plain-English result so a non-technical admin can see exactly what is wrong.
 *
 * @return array{ok:bool,step:string,detail:string}
 */
function smtp_selftest(): array
{
    $c = mail_config()['smtp'];
    if ($c['host'] === '') {
        return ['ok' => false, 'step' => 'configuration', 'detail' => 'SMTP_HOST is empty in .env.'];
    }
    $fp = @stream_socket_client(($c['security'] === 'ssl' ? 'ssl' : 'tcp') . "://{$c['host']}:{$c['port']}", $en, $es, 10, STREAM_CLIENT_CONNECT,
        stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]));
    if (!$fp) {
        return ['ok' => false, 'step' => 'connect', 'detail' => "Cannot reach {$c['host']}:{$c['port']} ($es). Wrong host/port, the port is blocked, or the certificate name does not match the host."];
    }
    stream_set_timeout($fp, 10);
    $read = static function () use ($fp): string {
        $r = '';
        while (($l = fgets($fp, 1024)) !== false) {
            $r .= $l;
            if (strlen($l) < 4 || $l[3] === ' ') {
                break;
            }
        }
        return $r;
    };
    $say = static function (string $cmd) use ($fp, $read): string {
        fwrite($fp, $cmd . "\r\n");
        return $read();
    };
    $greet = $read();
    if (!str_starts_with($greet, '220')) {
        fclose($fp);
        return ['ok' => false, 'step' => 'greeting', 'detail' => 'The server answered but did not say hello: ' . trim($greet)];
    }
    $say('EHLO ' . (config('domain') ?: 'localhost'));
    if ($c['security'] === 'tls') {
        if (!str_starts_with($say('STARTTLS'), '220') || !@stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
            fclose($fp);
            return ['ok' => false, 'step' => 'tls', 'detail' => 'The server would not start an encrypted connection (STARTTLS).'];
        }
        $say('EHLO ' . (config('domain') ?: 'localhost'));
    }
    if ($c['user'] !== '') {
        $res = $say('AUTH PLAIN ' . base64_encode("\0{$c['user']}\0{$c['pass']}"));
        if (!str_starts_with($res, '235')) {
            fclose($fp);
            return ['ok' => false, 'step' => 'login', 'detail' => 'The server refused the username or password (' . trim($res) . '). SMTP_USER must be the FULL email address of a mailbox you created in cPanel.'];
        }
    }
    $say('QUIT');
    fclose($fp);
    return ['ok' => true, 'step' => 'login', 'detail' => 'Connected, encrypted and logged in. The server accepts your credentials.'];
}
