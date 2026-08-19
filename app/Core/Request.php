<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private array $query;
    private array $body;
    private array $files;
    private array $server;
    private array $routeParams = [];
    private ?array $json = null;

    public function __construct(?array $query = null, ?array $body = null, ?array $files = null, ?array $server = null)
    {
        $this->query = $query ?? $_GET;
        $this->body = $body ?? $_POST;
        $this->files = $files ?? $_FILES;
        $this->server = $server ?? $_SERVER;
    }

    public static function capture(): self
    {
        return new self();
    }

    public function method(): string
    {
        $method = strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));

        if ($method === 'POST' && isset($this->body['_method'])) {
            $override = strtoupper((string) $this->body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                return $override;
            }
        }

        return $method;
    }

    public function isPost(): bool
    {
        return $this->method() !== 'GET' && $this->method() !== 'HEAD';
    }

    /**
     * Application-relative path, e.g. "/documents/12/edit".
     */
    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $base = $this->basePath();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        $path = '/' . trim($path, '/');

        return $path === '/' ? '/' : rtrim($path, '/');
    }

    /**
     * Sub-directory the app is installed in (empty for domain root installs).
     */
    public function basePath(): string
    {
        $script = (string) ($this->server['SCRIPT_NAME'] ?? '');
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');

        if ($dir === '.' || $dir === '/') {
            return '';
        }

        // /public/index.php served through a rewrite => strip the /public suffix.
        if (str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }

        return rtrim($dir, '/');
    }

    public function baseUrl(): string
    {
        $configured = (string) Config::get('app.url', '');

        if ($configured !== '' && !str_contains($configured, 'yourdomain.com')) {
            return rtrim($configured, '/');
        }

        $scheme = $this->isSecure() ? 'https' : 'http';
        $host = (string) ($this->server['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . $this->basePath();
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }

        return ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * The client's address, as far as it can be trusted.
     *
     * REMOTE_ADDR is the only value the web server vouches for. A forwarded header
     * is used **only** when REMOTE_ADDR is a proxy this installation has been told
     * to trust, because anyone can send `X-Forwarded-For: 1.2.3.4` and would
     * otherwise get a fresh rate-limit bucket per request — turning the sign-in
     * limiter off for exactly the person it exists to stop.
     *
     * It matters the other way too: behind Cloudflare or a load balancer every
     * request arrives from the same REMOTE_ADDR, so without this the whole field
     * team shares one bucket and five wrong passwords lock everybody out.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
        $trusted = Config::get('security.trusted_proxies', []);

        if (!is_array($trusted) || $trusted === [] || !in_array($remote, $trusted, true)) {
            return $remote;
        }

        // Left-most entry is the original client; the rest are proxies it passed
        // through. Take the first syntactically valid address.
        $forwarded = (string) ($this->server['HTTP_X_FORWARDED_FOR'] ?? $this->server['HTTP_X_REAL_IP'] ?? '');

        foreach (explode(',', $forwarded) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }

        return $remote;
    }

    public function userAgent(): string
    {
        return substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        return isset($this->server[$key]) ? (string) $this->server[$key] : null;
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains((string) $this->header('Accept'), 'application/json')
            || str_contains((string) $this->header('Content-Type'), 'application/json');
    }

    public function input(string $key, mixed $default = null): mixed
    {
        $value = $this->body[$key] ?? $this->json()[$key] ?? $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public function raw(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->json()[$key] ?? $this->query[$key] ?? $default;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        $value = $this->query[$key] ?? $default;

        return is_string($value) ? trim($value) : $value;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function decimal(string $key, float $default = 0.0): float
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (float) $value : $default;
    }

    public function boolean(string $key): bool
    {
        $value = $this->input($key);

        return in_array($value, [true, 1, '1', 'on', 'yes', 'true'], true);
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query) || array_key_exists($key, (array) $this->json());
    }

    public function all(): array
    {
        return array_merge($this->query, $this->json() ?? [], $this->body);
    }

    public function only(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->input($key);
        }

        return $result;
    }

    public function json(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }

        $this->json = [];
        $contentType = (string) ($this->server['CONTENT_TYPE'] ?? '');

        if (str_contains($contentType, 'application/json')) {
            $raw = file_get_contents('php://input');
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    $this->json = $decoded;
                }
            }
        }

        return $this->json;
    }

    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return null;
        }

        return $file;
    }

    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    public function param(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }

    public function paramInt(string $key): int
    {
        return (int) ($this->routeParams[$key] ?? 0);
    }

    public function routeParams(): array
    {
        return $this->routeParams;
    }

    public function fullUrl(): string
    {
        return $this->baseUrl() . $this->path();
    }
}
