<?php

class Request
{
    private array $input   = [];
    private array $files   = [];
    private ?string $rawBody = null;
    private ?array $acceptableContentTypes = null;

    public function __construct()
    {
        $this->parseInput();
    }

    // ─────────────────────────────────────────────
    // Input
    // ─────────────────────────────────────────────

    private function parseInput(): void
    {
        $this->files = $_FILES ?? [];

        // JSON body
        if ($this->isJson()) {
            $this->rawBody = file_get_contents('php://input');
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $this->input = $decoded;
                return;
            }
        }

        // POST body
        $this->input = array_merge($_GET ?? [], $_POST ?? []);
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->input;
        }
        return $this->input[$key] ?? $default;
    }

    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $_GET ?? [];
        }
        return $_GET[$key] ?? $default;
    }

    public function file(string $key): mixed
    {
        return $this->files[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->input);
    }

    public function all(): array
    {
        return $this->input;
    }

    public function only(array $keys): array
    {
        return array_intersect_key($this->input, array_flip($keys));
    }

    public function except(array $keys): array
    {
        return array_diff_key($this->input, array_flip($keys));
    }

    // ─────────────────────────────────────────────
    // HTTP Info
    // ─────────────────────────────────────────────

    public function method(): string
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

        // Method override
        if ($method === 'POST') {
            $override = $_POST['_method'] ?? $this->header('X-HTTP-Method-Override');
            if ($override) {
                return strtoupper($override);
            }
        }
        return $method;
    }

    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $pos = strpos($uri, '?');
        return $pos !== false ? substr($uri, 0, $pos) : $uri;
    }

    public function url(): string
    {
        return sparkRequestScheme() . '://' . sparkRequestHost() . ($_SERVER['REQUEST_URI'] ?? '/');
    }

    public function fullUrl(): string
    {
        return $this->url();
    }

    public function header(string $name, mixed $default = null): mixed
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

        // Special cases
        if ($name === 'Content-Type') {
            $key = 'CONTENT_TYPE';
        } elseif ($name === 'Content-Length') {
            $key = 'CONTENT_LENGTH';
        } elseif ($name === 'Authorization') {
            $key = 'HTTP_AUTHORIZATION';
            if (!isset($_SERVER[$key]) && function_exists('apache_request_headers')) {
                $headers = apache_request_headers();
                return $headers['Authorization'] ?? $default;
            }
        }

        return $_SERVER[$key] ?? $default;
    }

    public function headers(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['Content-Type'] = $_SERVER['CONTENT_TYPE'];
        }

        if (isset($_SERVER['CONTENT_LENGTH'])) {
            $headers['Content-Length'] = $_SERVER['CONTENT_LENGTH'];
        }

        return $headers;
    }

    public function ip(): string
    {
        return sparkRequestClientIp();
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $_COOKIE[$key] ?? $default;
    }

    public function userAgent(): string
    {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }

    // ─────────────────────────────────────────────
    // Type detection
    // ─────────────────────────────────────────────

    public function isJson(): bool
    {
        $ct = $_SERVER['CONTENT_TYPE'] ?? '';
        return str_contains($ct, 'application/json');
    }

    public function isAjax(): bool
    {
        return $this->header('X-Requested-With') === 'XMLHttpRequest';
    }

    public function acceptsJson(): bool
    {
        return $this->isAjax() || $this->accepts(['application/json', 'application/*+json']);
    }

    public function acceptsHtml(): bool
    {
        return $this->accepts(['text/html', 'application/xhtml+xml']);
    }

    public function accepts(string|array $types): bool
    {
        $types = array_values(array_filter(array_map(
            static fn(string $type): string => strtolower(trim($type)),
            (array) $types
        )));

        if ($types === []) {
            return false;
        }

        $accepted = $this->acceptableContentTypes();
        if ($accepted === []) {
            return false;
        }

        foreach ($accepted as $candidate) {
            foreach ($types as $type) {
                if ($this->mimeMatches($candidate, $type)) {
                    return true;
                }
            }
        }

        return false;
    }

    public function prefers(array $types): ?string
    {
        $types = array_values(array_filter(array_map(
            static fn(string $type): string => strtolower(trim($type)),
            $types
        )));

        if ($types === []) {
            return null;
        }

        foreach ($this->acceptableContentTypes() as $candidate) {
            foreach ($types as $type) {
                if ($this->mimeMatches($candidate, $type)) {
                    return $type;
                }
            }
        }

        return null;
    }

    public function preferredFormat(array $available = ['html', 'json']): ?string
    {
        $available = array_values(array_filter(array_map(
            static fn(string $format): string => strtolower(trim($format)),
            $available
        )));

        if ($available === []) {
            return null;
        }

        if ($this->isAjax() && in_array('json', $available, true)) {
            return 'json';
        }

        $map = [
            'html' => ['text/html', 'application/xhtml+xml'],
            'json' => ['application/json', 'application/*+json'],
            'text' => ['text/plain'],
            'xml' => ['application/xml', 'text/xml'],
        ];

        $types = [];
        foreach ($available as $format) {
            foreach ($map[$format] ?? [$format] as $mime) {
                $types[] = $mime;
            }
        }

        $preferred = $this->prefers($types);
        if ($preferred !== null) {
            foreach ($available as $format) {
                foreach ($map[$format] ?? [$format] as $mime) {
                    if ($mime === $preferred) {
                        return $format;
                    }
                }
            }
        }

        $accept = trim((string) ($this->header('Accept') ?? ''));
        if (($accept === '' || $accept === '*/*') && in_array('html', $available, true)) {
            return 'html';
        }

        return $available[0];
    }

    public function wantsJson(): bool
    {
        return $this->preferredFormat(['json', 'html']) === 'json';
    }

    public function wantsHtml(): bool
    {
        return $this->preferredFormat(['html', 'json']) === 'html';
    }

    public function isSecure(): bool
    {
        return sparkRequestScheme() === 'https';
    }

    private function acceptableContentTypes(): array
    {
        if ($this->acceptableContentTypes !== null) {
            return $this->acceptableContentTypes;
        }

        $accept = trim((string) ($this->header('Accept') ?? ''));
        if ($accept === '') {
            return $this->acceptableContentTypes = ['text/html', '*/*'];
        }

        $parsed = [];
        foreach (explode(',', $accept) as $index => $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }

            $segments = array_map('trim', explode(';', $part));
            $type = strtolower(array_shift($segments) ?? '');
            if ($type === '') {
                continue;
            }

            $quality = 1.0;
            foreach ($segments as $segment) {
                if (str_starts_with(strtolower($segment), 'q=')) {
                    $quality = (float) substr($segment, 2);
                    break;
                }
            }

            $parsed[] = [
                'type' => $type,
                'q' => $quality,
                'index' => $index,
            ];
        }

        usort($parsed, static function (array $a, array $b): int {
            if ($a['q'] === $b['q']) {
                return $a['index'] <=> $b['index'];
            }

            return $a['q'] < $b['q'] ? 1 : -1;
        });

        return $this->acceptableContentTypes = array_values(array_map(
            static fn(array $item): string => $item['type'],
            $parsed
        ));
    }

    private function mimeMatches(string $accepted, string $candidate): bool
    {
        [$acceptedType, $acceptedSubtype] = $this->splitMime($accepted);
        [$candidateType, $candidateSubtype] = $this->splitMime($candidate);

        if ($acceptedType === null || $candidateType === null) {
            return false;
        }

        if ($acceptedType !== '*' && $candidateType !== '*' && $acceptedType !== $candidateType) {
            return false;
        }

        return $this->mimeSubtypeMatches($acceptedSubtype, $candidateSubtype);
    }

    private function splitMime(string $value): array
    {
        $value = strtolower(trim($value));
        if (!str_contains($value, '/')) {
            return [null, null];
        }

        [$type, $subtype] = explode('/', $value, 2);

        return [$type, $subtype];
    }

    private function mimeSubtypeMatches(?string $accepted, ?string $candidate): bool
    {
        if ($accepted === null || $candidate === null) {
            return false;
        }

        if ($accepted === '*' || $candidate === '*' || $accepted === $candidate) {
            return true;
        }

        foreach ([[$accepted, $candidate], [$candidate, $accepted]] as [$actual, $pattern]) {
            if (str_starts_with($pattern, '*+')) {
                return str_ends_with($actual, substr($pattern, 1));
            }
        }

        return false;
    }

    public function isGet(): bool    { return $this->method() === 'GET'; }
    public function isPost(): bool   { return $this->method() === 'POST'; }
    public function isPut(): bool    { return $this->method() === 'PUT'; }
    public function isPatch(): bool  { return $this->method() === 'PATCH'; }
    public function isDelete(): bool { return $this->method() === 'DELETE'; }
}
