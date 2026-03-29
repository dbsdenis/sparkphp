<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SessionSecurityTest extends TestCase
{
    private string $basePath;
    private array $envBackup = [];
    private array $serverBackup = [];
    private array $sessionBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-session-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/storage/sessions', 0777, true);

        $this->envBackup = $_ENV;
        $this->serverBackup = $_SERVER;
        $this->sessionBackup = $_SESSION ?? [];

        $_ENV = [];
        $_SERVER = [];
        $_SESSION = [];

        $this->resetNativeSession();
    }

    protected function tearDown(): void
    {
        $this->resetNativeSession();

        $_ENV = $this->envBackup;
        $_SERVER = $this->serverBackup;
        $_SESSION = $this->sessionBackup;

        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function testAutoSecureCookiesFollowTrustedProxyHttpsHeaders(): void
    {
        $_ENV['SESSION_SECURE'] = 'auto';
        $_ENV['TRUSTED_PROXIES'] = '127.0.0.1';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
        $_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';

        $session = new Session($this->basePath);
        $session->start();

        $params = session_get_cookie_params();

        $this->assertTrue($params['secure']);
        $this->assertTrue($params['httponly']);
        $this->assertSame('Lax', $params['samesite']);
    }

    public function testSameSiteNoneForcesSecureCookieEvenWhenDisabledExplicitly(): void
    {
        $_ENV['SESSION_SECURE'] = 'false';
        $_ENV['SESSION_SAME_SITE'] = 'None';

        $session = new Session($this->basePath);
        $session->start();

        $params = session_get_cookie_params();

        $this->assertTrue($params['secure']);
        $this->assertSame('None', $params['samesite']);
    }

    public function testHttpOnlyCanBeDisabledExplicitlyWhenNeeded(): void
    {
        $_ENV['SESSION_HTTP_ONLY'] = 'false';

        $session = new Session($this->basePath);

        $this->assertFalse($this->invokePrivate($session, 'resolveHttpOnlyFlag'));
    }

    public function testInvalidSameSiteValueFallsBackToLax(): void
    {
        $_ENV['SESSION_SAME_SITE'] = 'invalid';

        $session = new Session($this->basePath);

        $this->assertSame('Lax', $this->invokePrivate($session, 'resolveSameSite'));
    }

    private function invokePrivate(object $target, string $method): mixed
    {
        $ref = new ReflectionMethod($target, $method);
        $ref->setAccessible(true);

        return $ref->invoke($target);
    }

    private function resetNativeSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_unset();
            session_destroy();
        }
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
