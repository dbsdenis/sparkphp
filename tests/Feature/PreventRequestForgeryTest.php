<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PreventRequestForgeryTest extends TestCase
{
    private string $basePath;
    private array $envBackup = [];
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-csrf-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/storage/sessions', 0777, true);

        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->sessionBackup = $_SESSION ?? [];

        $_ENV = [];
        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_SESSION = $this->sessionBackup;
        http_response_code(200);

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function testSafeMethodsSkipForgeryVerification(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));

        $this->assertNull($middleware->handle());
    }

    public function testMatchingTokenAndOriginPassVerification(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ORIGIN'] = 'https://sparkphp.test';
        $_POST['_csrf'] = 'known-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));

        $this->assertNull($middleware->handle());
    }

    public function testXsrfHeaderIsAcceptedAsCsrfTokenSource(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_SERVER['REQUEST_METHOD'] = 'PATCH';
        $_SERVER['HTTP_ORIGIN'] = 'https://sparkphp.test';
        $_SERVER['HTTP_X_XSRF_TOKEN'] = 'known-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));

        $this->assertNull($middleware->handle());
    }

    public function testJsonRequestsReceiveStructuredTokenMismatchResponse(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_SERVER['HTTP_ORIGIN'] = 'https://sparkphp.test';
        $_POST['_csrf'] = 'wrong-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));
        $response = $middleware->handle();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(419, $response->getStatus());
        $this->assertSame('application/json; charset=UTF-8', $response->getHeaders()['Content-Type']);
        $this->assertSame([
            'error' => 'Request forgery protection failed.',
            'reason' => 'token_mismatch',
        ], json_decode((string) $response->getBody(), true));
    }

    public function testAjaxRequestsRejectMismatchedOrigin(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $_POST['_csrf'] = 'known-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));
        $response = $middleware->handle();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(419, $response->getStatus());
        $this->assertSame([
            'error' => 'Request forgery protection failed.',
            'reason' => 'origin_mismatch',
        ], json_decode((string) $response->getBody(), true));
    }

    public function testTrustedOriginsCanBeWhitelistedExplicitly(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_ENV['CSRF_TRUSTED_ORIGINS'] = 'https://admin.sparkphp.test, https://docs.sparkphp.test';
        $_SERVER['REQUEST_METHOD'] = 'DELETE';
        $_SERVER['HTTP_ORIGIN'] = 'https://admin.sparkphp.test';
        $_POST['_csrf'] = 'known-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));

        $this->assertNull($middleware->handle());
    }

    public function testOriginCanBeRequiredExplicitly(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_ENV['CSRF_REQUIRE_ORIGIN'] = 'true';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ACCEPT'] = 'application/json';
        $_POST['_csrf'] = 'known-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));
        $response = $middleware->handle();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(419, $response->getStatus());
        $this->assertSame([
            'error' => 'Request forgery protection failed.',
            'reason' => 'missing_origin',
        ], json_decode((string) $response->getBody(), true));
    }

    public function testHtmlRequestsReceive419HtmlResponse(): void
    {
        $_ENV['APP_URL'] = 'https://sparkphp.test';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['HTTP_ORIGIN'] = 'https://sparkphp.test';
        $_POST['_csrf'] = 'wrong-token';
        $_SESSION['_csrf'] = 'known-token';

        $middleware = new PreventRequestForgery(new Request(), new Session($this->basePath));
        $response = $middleware->handle();

        $this->assertInstanceOf(Response::class, $response);
        $this->assertSame(419, $response->getStatus());
        $this->assertSame('text/html; charset=UTF-8', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('419 - Request Expired', (string) $response->getBody());
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getPathname());
            } else {
                unlink($item->getPathname());
            }
        }

        rmdir($path);
    }
}
