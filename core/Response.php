<?php

class Response
{
    private const STATUS_TEXTS = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        409 => 'Conflict',
        419 => 'Request Expired',
        422 => 'Unprocessable Entity',
        429 => 'Too Many Requests',
        500 => 'Server Error',
        503 => 'Service Unavailable',
    ];

    private int $status  = 200;
    private array $headers = [];
    private mixed $body  = null;

    public function __construct(mixed $body = null, int $status = 200, array $headers = [])
    {
        $this->body    = $body;
        $this->status  = $status;
        $this->headers = $headers;
    }

    // ─────────────────────────────────────────────
    // Static factories
    // ─────────────────────────────────────────────

    public static function json(mixed $data, int $status = 200): static
    {
        return new static(
            json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json; charset=UTF-8']
        );
    }

    public static function html(string $html, int $status = 200): static
    {
        return new static($html, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $text, int $status = 200): static
    {
        return new static($text, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function redirect(string $url, int $status = 302): static
    {
        return new static('', $status, [
            'Location' => $url,
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public static function created(mixed $data): static
    {
        return static::json($data, 201);
    }

    public static function noContent(): static
    {
        return static::empty(204);
    }

    public static function notFound(string $message = 'Not Found'): static
    {
        return static::error($message, 404, 'not_found');
    }

    public static function error(
        string $message,
        int $status = 500,
        ?string $code = null,
        array $extra = []
    ): static
    {
        $payload = [
            'error' => $message,
            'status' => $status,
            'code' => $code ?? static::statusCodeAsSlug($status),
        ];

        return static::json(array_merge($payload, $extra), $status);
    }

    public static function validationError(
        array $errors,
        string $message = 'The given data was invalid.'
    ): static
    {
        return static::error($message, 422, 'validation_error', ['errors' => $errors]);
    }

    public static function empty(int $status = 204, array $headers = []): static
    {
        return new static('', $status, $headers);
    }

    public static function download(string $filePath, ?string $name = null): static
    {
        if (!is_file($filePath)) {
            throw new RuntimeException("Download file not found: {$filePath}");
        }

        $name = $name ?? basename($filePath);
        $r    = new static(null, 200, [
            'Content-Description' => 'File Transfer',
            'Content-Type'        => mime_content_type($filePath) ?: 'application/octet-stream',
            'Content-Disposition' => "attachment; filename=\"{$name}\"",
            'Content-Transfer-Encoding' => 'binary',
            'Content-Length'      => (string) filesize($filePath),
            'Cache-Control' => 'private, must-revalidate',
            'Pragma' => 'public',
            'Expires' => '0',
            'X-Accel-Buffering' => 'no',
        ]);
        $r->body = fn() => readfile($filePath);
        return $r;
    }

    public static function stream(
        callable $callback,
        int $status = 200,
        array $headers = []
    ): static
    {
        $headers = array_merge([
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ], $headers);

        return new static($callback, $status, $headers);
    }

    public static function statusText(int $status): string
    {
        return self::STATUS_TEXTS[$status] ?? 'HTTP Error';
    }

    public static function statusCodeAsSlug(int $status): string
    {
        $text = strtolower(static::statusText($status));
        $text = preg_replace('/[^a-z0-9]+/', '_', $text) ?? 'http_error';

        return trim($text, '_') ?: 'http_error';
    }

    // ─────────────────────────────────────────────
    // Instance API
    // ─────────────────────────────────────────────

    public function status(int $code): static
    {
        $this->status = $code;
        return $this;
    }

    public function header(string $name, string $value): static
    {
        $this->headers[$name] = $value;
        return $this;
    }

    public function send(mixed $content = null): void
    {
        if ($content !== null) {
            $this->body = $content;
        }

        if (class_exists('SparkInspector')) {
            SparkInspector::decorateResponse($this);
        }

        http_response_code($this->status);

        foreach ($this->headersForSend() as $name => $value) {
            header("{$name}: {$value}");
        }

        if ($this->shouldSkipBody()) {
            if (class_exists('SparkInspector')) {
                SparkInspector::finalizeResponse($this);
            }
            return;
        }

        if (is_callable($this->body)) {
            ($this->body)();
        } else {
            echo $this->body;
        }

        if (class_exists('SparkInspector')) {
            SparkInspector::finalizeResponse($this);
        }
    }

    // ─────────────────────────────────────────────
    // Smart resolver
    // ─────────────────────────────────────────────

    /**
     * Intelligently resolve the return value of a route handler into a response.
     */
    public function resolve(mixed $result, Request $request, View $view, string $route): void
    {
        // Already a Response object
        if ($result instanceof static) {
            $result->send();
            return;
        }

        // Explicit redirect
        if (is_string($result) && str_starts_with($result, 'redirect:')) {
            static::redirect(substr($result, 9))->send();
            return;
        }

        $method = strtolower($request->method());
        $status = $method === 'post' ? 201 : 200;
        $preferredFormat = $request->preferredFormat(['html', 'json']);

        // null on GET → 404
        if ($result === null && $method === 'get') {
            if ($preferredFormat === 'json') {
                static::notFound()->send();
                return;
            }

            $this->sendErrorView($view, 404, 'Not Found');
            return;
        }

        // null on non-GET → 204
        if ($result === null) {
            static::noContent()->send();
            return;
        }

        // String → HTML
        if (is_string($result)) {
            static::html($result, $status)->send();
            return;
        }

        // Array/object
        if (is_array($result) || is_object($result)) {
            if ($preferredFormat === 'json') {
                static::json($result, $status)->send();
                return;
            }

            // HTML request → look for mirror view
            $viewName  = $this->routeToViewName($route);
            $variables = $this->viewVariables($result);

            try {
                $html = $view->render($viewName, $variables);
                static::html($html, $status)->send();
            } catch (\RuntimeException) {
                // No view found → fall back to JSON for structured data
                static::json($result, $status)->send();
            }
            return;
        }

        // Boolean true → 204
        if ($result === true) {
            static::noContent()->send();
            return;
        }

        echo $result;
    }

    private function routeToViewName(string $route): string
    {
        // /users/:id         → users/show
        // /users             → users/index
        // /admin/posts/:id   → admin/posts/show
        $path = trim($route, '/');
        if ($path === '') {
            return 'index';
        }

        $segments = explode('/', $path);
        $lastIndex = count($segments) - 1;

        // If the last segment is a dynamic param (:id, :slug, etc.), replace with "show"
        if (str_starts_with($segments[$lastIndex], ':')) {
            $segments[$lastIndex] = 'show';
        }

        return implode('/', $segments);
    }

    private function viewVariables(mixed $result): array
    {
        if (is_array($result)) {
            return $result;
        }

        if (is_object($result) && method_exists($result, 'toArray')) {
            $array = $result->toArray();
            if (is_array($array)) {
                return $array;
            }
        }

        return (array) $result;
    }

    private function sendErrorView(View $view, int $status, string $fallbackMessage): void
    {
        try {
            static::html($view->render("errors/{$status}", [
                'code' => $status,
                'message' => $fallbackMessage,
            ]), $status)->send();
            return;
        } catch (\RuntimeException) {
            echo "<h1>{$status} - {$fallbackMessage}</h1>";
        }
    }

    public function getStatus(): int
    {
        return $this->status;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): mixed
    {
        return $this->body;
    }

    public function setBody(mixed $body): static
    {
        $this->body = $body;
        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return array_key_exists($name, $this->headers);
    }

    public function contentType(): string
    {
        return $this->headers['Content-Type'] ?? '';
    }

    public function isHtmlInspectable(): bool
    {
        if ($this->status === 204 || $this->hasHeader('Location')) {
            return false;
        }

        if (is_callable($this->body) || !is_string($this->body) || $this->body === '') {
            return false;
        }

        return str_contains(strtolower($this->contentType()), 'text/html');
    }

    private function shouldSkipBody(): bool
    {
        return in_array($this->status, [204, 205, 304], true);
    }

    private function headersForSend(): array
    {
        if (!$this->shouldSkipBody()) {
            return $this->headers;
        }

        $headers = $this->headers;
        unset($headers['Content-Type'], $headers['Content-Length'], $headers['Transfer-Encoding']);

        return $headers;
    }
}
