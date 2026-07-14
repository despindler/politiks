<?php

declare(strict_types=1);

namespace Politiks\App\Auth;

use JsonException;

final class GoogleJwksProvider implements JwksProvider
{
    public function __construct(
        private readonly string $url,
        private readonly string $cachePath,
        private readonly int $ttlSeconds = 300,
    ) {
    }

    public function keys(): array
    {
        $cached = $this->readFreshCache();
        if ($cached !== null) {
            return $cached;
        }
        $body = $this->fetch();
        $keys = $this->decode($body);
        $this->writeCache($body);
        return $keys;
    }

    /** @return list<array<string, mixed>>|null */
    private function readFreshCache(): ?array
    {
        if (!is_file($this->cachePath)) {
            return null;
        }
        $modified = filemtime($this->cachePath);
        if ($modified === false || $modified + $this->ttlSeconds < time()) {
            return null;
        }
        $body = file_get_contents($this->cachePath);
        if ($body === false) {
            return null;
        }
        try {
            return $this->decode($body);
        } catch (GoogleAuthException) {
            return null;
        }
    }

    private function fetch(): string
    {
        if (function_exists('curl_init')) {
            $curl = curl_init($this->url);
            if ($curl === false) {
                throw $this->unavailable();
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_MAXREDIRS => 0,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_USERAGENT => 'Politiks/1.0 Google-ID-token-verifier',
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
            if (!is_string($body) || $status !== 200 || strlen($body) > 262_144) {
                throw $this->unavailable();
            }
            return $body;
        }

        if (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOL)) {
            $context = stream_context_create([
                'http' => [
                    'method' => 'GET',
                    'timeout' => 5,
                    'ignore_errors' => true,
                    'header' => "Accept: application/json\r\nUser-Agent: Politiks/1.0 Google-ID-token-verifier\r\n",
                ],
                'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
            ]);
            $body = @file_get_contents($this->url, false, $context, 0, 262_145);
            $statusLine = $http_response_header[0] ?? '';
            if (!is_string($body) || strlen($body) > 262_144 || !str_contains($statusLine, ' 200 ')) {
                throw $this->unavailable();
            }
            return $body;
        }

        throw $this->unavailable();
    }

    /** @return list<array<string, mixed>> */
    private function decode(string $body): array
    {
        try {
            $payload = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw $this->unavailable();
        }
        if (!is_array($payload) || !isset($payload['keys']) || !is_array($payload['keys'])) {
            throw $this->unavailable();
        }
        $keys = [];
        foreach ($payload['keys'] as $key) {
            if (is_array($key)) {
                $keys[] = $key;
            }
        }
        if ($keys === []) {
            throw $this->unavailable();
        }
        return $keys;
    }

    private function writeCache(string $body): void
    {
        $directory = dirname($this->cachePath);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            return;
        }
        $temporary = $this->cachePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (@file_put_contents($temporary, $body, LOCK_EX) !== false) {
            @chmod($temporary, 0600);
            @rename($temporary, $this->cachePath);
        }
        if (is_file($temporary)) {
            @unlink($temporary);
        }
    }

    private function unavailable(): GoogleAuthException
    {
        return new GoogleAuthException(
            'GOOGLE_KEYS_UNAVAILABLE',
            'Die Google-Anmeldeschlüssel sind vorübergehend nicht verfügbar.',
            503,
        );
    }
}
