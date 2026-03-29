<?php

final class PreventRequestForgery
{
    public function __construct(
        private Request $request,
        private ?Session $session = null,
    ) {
    }

    public function handle(): ?Response
    {
        if (!$this->shouldVerify()) {
            return null;
        }

        if (!$this->tokensMatch()) {
            return $this->reject('Request forgery protection failed.', 'token_mismatch');
        }

        $origin = $this->requestOrigin();
        if ($origin === null) {
            return $this->requiresOrigin()
                ? $this->reject('Request forgery protection failed.', 'missing_origin')
                : null;
        }

        if (!in_array($origin, $this->allowedOrigins(), true)) {
            return $this->reject('Request forgery protection failed.', 'origin_mismatch');
        }

        return null;
    }

    private function shouldVerify(): bool
    {
        return in_array($this->request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true);
    }

    private function tokensMatch(): bool
    {
        if (!$this->session instanceof Session) {
            return false;
        }

        return $this->session->verifyCsrf($this->requestToken());
    }

    private function requestToken(): string
    {
        return (string) (
            $this->request->input('_csrf')
            ?? $this->request->header('X-CSRF-TOKEN')
            ?? $this->request->header('X-XSRF-TOKEN')
            ?? ''
        );
    }

    private function requestOrigin(): ?string
    {
        $origin = $this->normalizeOrigin((string) ($this->request->header('Origin') ?? ''));
        if ($origin !== null) {
            return $origin;
        }

        return $this->normalizeOrigin((string) ($this->request->header('Referer') ?? ''));
    }

    private function allowedOrigins(): array
    {
        $origins = [];

        $requestOrigin = $this->normalizeOrigin($this->request->url());
        if ($requestOrigin !== null) {
            $origins[] = $requestOrigin;
        }

        $appOrigin = $this->normalizeOrigin((string) ($_ENV['APP_URL'] ?? ''));
        if ($appOrigin !== null) {
            $origins[] = $appOrigin;
        }

        $trusted = trim((string) ($_ENV['CSRF_TRUSTED_ORIGINS'] ?? ''));
        if ($trusted !== '') {
            foreach (explode(',', $trusted) as $origin) {
                $origin = $this->normalizeOrigin($origin);
                if ($origin !== null) {
                    $origins[] = $origin;
                }
            }
        }

        return array_values(array_unique($origins));
    }

    private function requiresOrigin(): bool
    {
        $setting = strtolower(trim((string) ($_ENV['CSRF_REQUIRE_ORIGIN'] ?? 'false')));

        return in_array($setting, ['1', 'true', 'on', 'yes'], true);
    }

    private function normalizeOrigin(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $port = $parts['port'] ?? null;

        $origin = $scheme . '://' . $host;

        if ($port !== null && !$this->isDefaultPort($scheme, (int) $port)) {
            $origin .= ':' . $port;
        }

        return $origin;
    }

    private function isDefaultPort(string $scheme, int $port): bool
    {
        return ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
    }

    private function reject(string $message, string $reason): Response
    {
        if ($this->request->acceptsJson()) {
            return Response::json([
                'error' => $message,
                'reason' => $reason,
            ], 419);
        }

        $basePath = null;

        try {
            $basePath = app()->getBasePath();
        } catch (\Throwable) {
            $basePath = null;
        }

        if ($basePath !== null) {
            $viewFile = $basePath . '/app/views/errors/419.spark';
            if (file_exists($viewFile)) {
                try {
                    $view = new View($basePath);

                    return Response::html($view->render('errors/419', [
                        'message' => $message,
                        'reason' => $reason,
                        'code' => 419,
                    ]), 419);
                } catch (\Throwable) {
                }
            }
        }

        return Response::html(
            '<h1>419 - Request Expired</h1><p>' . htmlspecialchars($message) . '</p>',
            419
        );
    }
}
