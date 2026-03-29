<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class BenchmarkRunnerTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-benchmark-runner-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/storage/benchmarks', 0777, true);
        copy(__DIR__ . '/../../VERSION', $this->basePath . '/VERSION');
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testProducesComparativeBenchmarkReportAndSavesIt(): void
    {
        $runner = new BenchmarkRunner($this->basePath);
        $report = $runner->run(2, 0);
        $savedPath = $runner->save($report, $this->basePath . '/storage/benchmarks/report.json');

        $this->assertSame(2, $report['iterations']);
        $this->assertSame(0, $report['warmup']);
        $this->assertNotEmpty($report['scenarios']);
        $this->assertSame(trim((string) file_get_contents(__DIR__ . '/../../VERSION')), $report['spark_version']);
        $this->assertSame(SparkVersion::releaseLine($report['spark_version']), $report['spark_release_line']);
        $this->assertSame('real_project_fixture', $report['profile']['name']);
        $this->assertGreaterThanOrEqual(1, $report['summary']['scenario_count']);
        $this->assertContains('autoloader.map_build', array_column($report['scenarios'], 'name'));
        $this->assertContains('view.render_warm', array_column($report['scenarios'], 'name'));
        $this->assertContains('http.request_html', array_column($report['scenarios'], 'name'));
        $this->assertContains('http.request_json', array_column($report['scenarios'], 'name'));
        $this->assertFileExists($savedPath);

        $persisted = json_decode((string) file_get_contents($savedPath), true);

        $this->assertSame($report['iterations'], $persisted['iterations']);
        $this->assertSame(count($report['scenarios']), count($persisted['scenarios']));
        $this->assertSame($report['spark_version'], $persisted['spark_version']);
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
