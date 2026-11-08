<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/mail_config.php';

/**
 * Minimal real SMTP client over PHP streams: implicit TLS or STARTTLS, AUTH LOGIN/PLAIN,
 * dot-stuffing, and proper reply-code handling. It speaks to the relay in SMTP_HOST.
 * Nothing is simulated: if SMTP_HOST is empty, smtp_send() returns an explicit failure.
 */
final class SmtpClient
{
    /** @var resource|null */
    private $fp = null;
    private string $lastReply = '';

    public function __construct(private array $cfg, private int $timeout = 20) {}

    /** @return array{ok:bool,code:string,response:string} */
    public function send(string $mailFrom, array $rcpts, string $rawMessage): array
    {
        try {
            $this->connect();
            $this->cmd('EHLO ' . $this->ehloName(), [250]);
            if ($this->cfg['security'] === 'tls') {
                $this->cmd('STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('TLS negotiation failed');
                }
                $this->cmd('EHLO ' . $this->ehloName(), [250]);
            }
            if ($this->cfg['user'] !== '') {
                $this->cmd('AUTH LOGIN', [334]);
                $this->cmd(base64_encode($this->cfg['user']), [334]);
                $this->cmd(base64_encode($this->cfg['pass']), [235]);
            }
            $this->cmd('MAIL FROM:<' . $this->clean($mailFrom) . '>', [250]);
            foreach ($rcpts as $r) {
                $this->cmd('RCPT TO:<' . $this->clean($r) . '>', [250, 251]);
            }
            $this->cmd('DATA', [354]);
            $this->write($this->dotStuff($rawMessage) . "\r\n.\r\n");
            $this->expect([250]);
            $code = substr($this->lastReply, 0, 3);
            $this->quit();
            return ['ok' => true, 'code' => $code, 'response' => trim($this->lastReply)];
        } catch (Throwable $e) {
            $code = preg_match('/^(\d{3})/', $this->lastReply, $m) ? $m[1] : '000';
            $this->quit();
            return ['ok' => false, 'code' => $code, 'response' => mb_substr($e->getMessage() . ' ' . trim($this->lastReply), 0, 480)];
        }
    }

    private function connect(): void
    {
        $scheme = $this->cfg['security'] === 'ssl' ? 'ssl' : 'tcp';
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $fp = @stream_socket_client("$scheme://{$this->cfg['host']}:{$this->cfg['port']}", $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) {
            throw new RuntimeException("Cannot connect to SMTP relay: $errstr ($errno)");
        }
        stream_set_timeout($fp, $this->timeout);
        $this->fp = $fp;
        $this->expect([220]);
    }

    private function cmd(string $line, array $okCodes): void
    {
        $this->write($line . "\r\n");
        $this->expect($okCodes);
    }

    private function write(string $data): void
    {
        if (@fwrite($this->fp, $data) === false) {
            throw new RuntimeException('Connection lost while writing');
        }
    }

    private function expect(array $okCodes): void
    {
        $reply = '';
        while (($line = fgets($this->fp, 1024)) !== false) {
            $reply .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }
        $this->lastReply = $reply;
        $code = (int)substr($reply, 0, 3);
        if (!in_array($code, $okCodes, true)) {
            throw new RuntimeException('SMTP error');
        }
    }

    private function quit(): void
    {
        if (is_resource($this->fp)) {
            @fwrite($this->fp, "QUIT\r\n");
            @fclose($this->fp);
        }
        $this->fp = null;
    }

    private function dotStuff(string $m): string
    {
        $m = str_replace(["\r\n", "\r"], "\n", $m);
        $m = str_replace("\n", "\r\n", $m);
        return preg_replace('/^\./m', '..', $m) ?? $m;
    }

    private function clean(string $a): string
    {
        return preg_replace('/[\r\n<>\s]+/', '', $a) ?? '';
    }

    private function ehloName(): string
    {
        return preg_replace('/[^a-z0-9.\-]/i', '', (string)config('domain')) ?: 'localhost';
    }
}

/** Public entry point used by the delivery queue. */
function smtp_send(string $mailFrom, array $rcpts, string $rawMessage): array
{
    if (!smtp_configured()) {
        return ['ok' => false, 'code' => '000', 'response' => 'SMTP relay is not configured (SMTP_HOST is empty).'];
    }
    return (new SmtpClient(mail_config()['smtp']))->send($mailFrom, $rcpts, $rawMessage);
}
