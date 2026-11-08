<?php
declare(strict_types=1);

/** Tiny assertion harness: no dependencies, readable failures, non-zero exit on any failure. */
final class T
{
    public static int $pass = 0;
    public static int $fail = 0;
    public static string $base = '';

    public static function check(bool $cond, string $name, string $detail = ''): void
    {
        if ($cond) {
            self::$pass++;
            echo "  ok   $name\n";
        } else {
            self::$fail++;
            echo "  FAIL $name" . ($detail !== '' ? "\n       $detail" : '') . "\n";
        }
    }

    public static function eq(mixed $actual, mixed $expected, string $name): void
    {
        self::check($actual === $expected, $name, 'expected ' . json_encode($expected) . ', got ' . json_encode($actual));
    }

    public static function section(string $s): void
    {
        echo "\n$s\n";
    }

    public static function finish(): never
    {
        echo "\n" . self::$pass . ' passed, ' . self::$fail . " failed\n";
        exit(self::$fail > 0 ? 1 : 0);
    }
}

/** @return array{status:int,json:?array,headers:string,raw:string} */
function http(string $method, string $path, ?array $json = null, ?string $token = null, array $extra = [], ?array $multipart = null): array
{
    $ch = curl_init(T::$base . $path);
    $headers = ['Accept: application/json'];
    if ($token) {
        $headers[] = 'Authorization: Bearer ' . $token;
    }
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30];
    if ($multipart !== null) {
        $opts[CURLOPT_POSTFIELDS] = $multipart;
    } elseif ($json !== null) {
        $headers[] = 'Content-Type: application/json';
        $opts[CURLOPT_POSTFIELDS] = json_encode($json);
    }
    $opts[CURLOPT_HTTPHEADER] = array_merge($headers, $extra);
    curl_setopt_array($ch, $opts);
    $res = (string)curl_exec($ch);
    $size = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $raw = substr($res, $size);
    return ['status' => $status, 'json' => json_decode($raw, true), 'headers' => substr($res, 0, $size), 'raw' => $raw];
}
