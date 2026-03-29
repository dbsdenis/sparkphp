<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class VersioningTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-version-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/app/config', 0777, true);
        file_put_contents($this->basePath . '/VERSION', "0.9.3\n");

        new Bootstrap($this->basePath);
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testCurrentVersionAndReleaseLineComeFromVersionFile(): void
    {
        $this->assertSame('0.9.3', SparkVersion::current($this->basePath));
        $this->assertSame('0.9.x', SparkVersion::releaseLine('0.9.3'));
    }

    public function testHelpersExposeFrameworkVersionConsistently(): void
    {
        $this->assertSame('0.9.3', spark_version());
        $this->assertSame('0.9.x', spark_release_line());
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
