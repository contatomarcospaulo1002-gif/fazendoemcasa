<?php
declare(strict_types=1);

/** Cliente HTTP com cookies para testes autenticados. */
final class HttpSession
{
    private string $cookieJar;

    public function __construct(public readonly string $base)
    {
        $this->cookieJar = tempnam(sys_get_temp_dir(), 'cmsck');
    }

    /** @return array{code:int, json:array<string,mixed>|null, body:string} */
    public function request(string $path, string $method = 'GET', ?array $payload = null, array $headers = []): array
    {
        $ch = curl_init($this->base . $path);
        $h = array_merge(['Accept: application/json'], $headers);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 25,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_COOKIEJAR => $this->cookieJar,
            CURLOPT_COOKIEFILE => $this->cookieJar,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POST] = true;
            if ($payload !== null) {
                $opts[CURLOPT_POSTFIELDS] = json_encode($payload);
                $h[] = 'Content-Type: application/json';
            }
        }
        $opts[CURLOPT_HTTPHEADER] = $h;
        curl_setopt_array($ch, $opts);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($body, true);
        return ['code' => $code, 'json' => is_array($json) ? $json : null, 'body' => $body];
    }

    public function __destruct()
    {
        @unlink($this->cookieJar);
    }
}
