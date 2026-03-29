<?php

class BenchmarkRunner
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function run(int $iterations = 200, int $warmup = 15): array
    {
        $this->loadDependencies();

        $iterations = max(1, $iterations);
        $warmup = max(0, $warmup);

        $previousEnv = $_ENV;
        $fixture = $this->createFixture();

        try {
            $this->prepareBenchFixture($fixture);

            $scenarios = [
                [
                    'name' => 'autoloader.map_build',
                    'group' => 'bootstrap',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $autoloader = new Autoloader($fixture);
                        $autoloader->buildMap();
                    },
                ],
                [
                    'name' => 'autoloader.cache_load',
                    'group' => 'bootstrap',
                    'compare_to' => 'autoloader.map_build',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'prod';
                        require $fixture . '/storage/cache/classes.php';
                    },
                ],
                [
                    'name' => 'router.routes_build',
                    'group' => 'routing',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $router = new Router($fixture);
                        $router->buildRoutes();
                    },
                ],
                [
                    'name' => 'router.resolve_static',
                    'group' => 'routing',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'prod';
                        $router = new Router($fixture);
                        $router->resolve('/api/health', 'GET');
                    },
                ],
                [
                    'name' => 'router.resolve_dynamic',
                    'group' => 'routing',
                    'compare_to' => 'router.resolve_static',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'prod';
                        $router = new Router($fixture);
                        $router->resolve('/api/users/42', 'GET');
                    },
                ],
                [
                    'name' => 'view.render_cold',
                    'group' => 'views',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $compiledFile = $fixture . '/storage/cache/views/' . md5($fixture . '/app/views/index.spark') . '.php';
                        if (file_exists($compiledFile)) {
                            unlink($compiledFile);
                        }

                        $view = new View($fixture);
                        $view->render('index', ['message' => 'SparkPHP', 'items' => range(1, 10)]);
                    },
                ],
                [
                    'name' => 'view.render_warm',
                    'group' => 'views',
                    'compare_to' => 'view.render_cold',
                    'callback' => function () use ($fixture): void {
                        $_ENV['APP_ENV'] = 'dev';
                        $view = new View($fixture);
                        $view->render('index', ['message' => 'SparkPHP', 'items' => range(1, 10)]);
                    },
                ],
                [
                    'name' => 'http.request_html',
                    'group' => 'http',
                    'callback' => function () use ($fixture): void {
                        $this->simulateHttpRequest($fixture, '/', 'GET', 'text/html');
                    },
                ],
                [
                    'name' => 'http.request_json',
                    'group' => 'http',
                    'compare_to' => 'http.request_html',
                    'callback' => function () use ($fixture): void {
                        $this->simulateHttpRequest($fixture, '/api/health', 'GET', 'application/json');
                    },
                ],
                [
                    'name' => 'container.autowire',
                    'group' => 'container',
                    'callback' => function (): void {
                        $container = new Container();
                        $container->make(BenchController::class);
                    },
                ],
                [
                    'name' => 'container.singleton_hit',
                    'group' => 'container',
                    'compare_to' => 'container.autowire',
                    'callback' => function (): void {
                        $container = new Container();
                        $container->singleton(BenchLeafService::class, fn() => new BenchLeafService());
                        $container->make(BenchLeafService::class);
                        $container->make(BenchLeafService::class);
                    },
                ],
            ];

            $results = [];
            foreach ($scenarios as $scenario) {
                $results[] = $this->measureScenario(
                    $scenario['name'],
                    $scenario['callback'],
                    $iterations,
                    $warmup,
                    $scenario['group'] ?? 'core',
                    $scenario['compare_to'] ?? null
                );
            }

            return $this->buildReport($results, $iterations, $warmup, $fixture);
        } finally {
            $_ENV = $previousEnv;
            $this->deleteDirectory($fixture);
        }
    }

    public function save(array $report, string $path): string
    {
        $fullPath = $path;
        if (!str_starts_with($path, '/')) {
            $fullPath = $this->basePath . '/' . ltrim($path, '/');
        }

        $directory = dirname($fullPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $fullPath,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );

        return $fullPath;
    }

    private function buildReport(array $results, int $iterations, int $warmup, string $fixture): array
    {
        $indexed = [];
        foreach ($results as $result) {
            $indexed[$result['name']] = $result;
        }

        foreach ($results as &$result) {
            $baseline = $result['compare_to'] ?? null;
            if ($baseline === null || !isset($indexed[$baseline])) {
                unset($result['compare_to']);
                continue;
            }

            $baselineAvg = $indexed[$baseline]['avg_ns'];
            $result['comparison'] = [
                'against' => $baseline,
                'speedup' => $result['avg_ns'] > 0 ? $baselineAvg / $result['avg_ns'] : 0.0,
            ];

            unset($result['compare_to']);
        }
        unset($result);

        usort($results, static function (array $left, array $right): int {
            return [$left['group'], $left['name']] <=> [$right['group'], $right['name']];
        });

        $fastest = null;
        $slowest = null;

        foreach ($results as $result) {
            if ($fastest === null || $result['avg_ns'] < $fastest['avg_ns']) {
                $fastest = $result;
            }

            if ($slowest === null || $result['avg_ns'] > $slowest['avg_ns']) {
                $slowest = $result;
            }
        }

        $sparkVersion = SparkVersion::current($this->basePath);

        return [
            'generated_at' => date(DATE_ATOM),
            'spark_version' => $sparkVersion,
            'spark_release_line' => SparkVersion::releaseLine($sparkVersion),
            'php' => PHP_VERSION,
            'iterations' => $iterations,
            'warmup' => $warmup,
            'profile' => $this->fixtureProfile($fixture),
            'summary' => [
                'scenario_count' => count($results),
                'groups' => array_values(array_unique(array_map(
                    static fn(array $result): string => (string) ($result['group'] ?? 'core'),
                    $results
                ))),
                'fastest' => $fastest !== null ? [
                    'name' => $fastest['name'],
                    'avg_ms' => $fastest['avg_ms'],
                ] : null,
                'slowest' => $slowest !== null ? [
                    'name' => $slowest['name'],
                    'avg_ms' => $slowest['avg_ms'],
                ] : null,
            ],
            'scenarios' => $results,
        ];
    }

    private function measureScenario(
        string $name,
        callable $callback,
        int $iterations,
        int $warmup,
        string $group,
        ?string $compareTo = null
    ): array {
        for ($i = 0; $i < $warmup; $i++) {
            $callback();
        }

        $durations = [];
        $memoryDeltas = [];

        for ($i = 0; $i < $iterations; $i++) {
            gc_collect_cycles();

            $memoryBefore = memory_get_usage(true);
            $start = hrtime(true);
            $callback();
            $end = hrtime(true);
            $memoryAfter = memory_get_usage(true);

            $durations[] = $end - $start;
            $memoryDeltas[] = max(0, $memoryAfter - $memoryBefore);
        }

        sort($durations);

        $avgNs = (int) round(array_sum($durations) / count($durations));
        $medianNs = $this->percentile($durations, 50);
        $p95Ns = $this->percentile($durations, 95);
        $avgMemoryKb = array_sum($memoryDeltas) / max(1, count($memoryDeltas)) / 1024;

        return [
            'name' => $name,
            'group' => $group,
            'avg_ns' => $avgNs,
            'avg_ms' => $avgNs / 1_000_000,
            'median_ms' => $medianNs / 1_000_000,
            'p95_ms' => $p95Ns / 1_000_000,
            'ops_per_second' => $avgNs > 0 ? 1_000_000_000 / $avgNs : 0.0,
            'memory_kb' => $avgMemoryKb,
            'compare_to' => $compareTo,
        ];
    }

    private function percentile(array $sortedValues, int $percentile): int
    {
        if ($sortedValues === []) {
            return 0;
        }

        $index = (int) ceil(($percentile / 100) * count($sortedValues)) - 1;
        $index = max(0, min(count($sortedValues) - 1, $index));

        return $sortedValues[$index];
    }

    private function prepareBenchFixture(string $fixture): void
    {
        $_ENV['APP_ENV'] = 'dev';

        $autoloader = new Autoloader($fixture);
        $autoloader->buildMap();

        $router = new Router($fixture);
        $router->buildRoutes();

        $view = new View($fixture);
        $view->render('index', ['message' => 'SparkPHP', 'items' => range(1, 10)]);

        $_ENV['APP_ENV'] = 'prod';
    }

    private function createFixture(): string
    {
        $fixture = sys_get_temp_dir() . '/sparkphp-bench-' . bin2hex(random_bytes(6));

        $directories = [
            'app/models',
            'app/routes/api',
            'app/services',
            'app/views/layouts',
            'storage/cache/app',
            'storage/cache/views',
            'storage/logs',
            'storage/sessions',
            'storage/uploads',
        ];

        foreach ($directories as $directory) {
            mkdir($fixture . '/' . $directory, 0777, true);
        }

        for ($i = 1; $i <= 20; $i++) {
            file_put_contents(
                $fixture . "/app/models/BenchModel{$i}.php",
                "<?php\n\nclass BenchModel{$i}\n{\n    public function id(): int\n    {\n        return {$i};\n    }\n}\n"
            );

            file_put_contents(
                $fixture . "/app/services/BenchService{$i}.php",
                "<?php\n\nclass BenchService{$i}\n{\n    public function name(): string\n    {\n        return 'service-{$i}';\n    }\n}\n"
            );
        }

        file_put_contents($fixture . '/app/routes/index.php', <<<'PHP'
<?php
get(fn() => ['message' => 'SparkPHP', 'items' => range(1, 10)]);
PHP
        );

        file_put_contents($fixture . '/app/routes/api/health.php', <<<'PHP'
<?php
get(fn() => ['ok' => true]);
PHP
        );

        file_put_contents($fixture . '/app/routes/api/users.[id].php', <<<'PHP'
<?php
get(fn(string $id) => ['id' => $id]);
PHP
        );

        file_put_contents($fixture . '/app/views/layouts/main.spark', <<<'SPARK'
<!DOCTYPE html>
<html>
<head>
    <title>{{ $title }}</title>
</head>
<body>
    @content
</body>
</html>
SPARK
        );

        file_put_contents($fixture . '/app/views/index.spark', <<<'SPARK'
@title('Benchmark')
<section>
    <h1>{{ $message }}</h1>
    <ul>
        @foreach($items as $item)
            <li>Item {{ $item }}</li>
        @endforeach
    </ul>
</section>
SPARK
        );

        file_put_contents($fixture . '/.env', <<<'ENV'
APP_NAME=SparkPHP Benchmark
APP_ENV=dev
APP_KEY=benchmark-key-12345678901234567890
APP_URL=http://benchmark.test
APP_TIMEZONE=America/Sao_Paulo
SESSION=file
CACHE=file
QUEUE=sync
SPARK_INSPECTOR=off
ENV
        );

        return $fixture;
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

    private function loadDependencies(): void
    {
        require_once __DIR__ . '/Version.php';
        require_once __DIR__ . '/Bootstrap.php';
        require_once __DIR__ . '/Autoloader.php';
        require_once __DIR__ . '/Container.php';
        require_once __DIR__ . '/Cache.php';
        require_once __DIR__ . '/Request.php';
        require_once __DIR__ . '/Response.php';
        require_once __DIR__ . '/Router.php';
        require_once __DIR__ . '/View.php';
        require_once __DIR__ . '/helpers.php';
    }

    private function simulateHttpRequest(string $fixture, string $path, string $method, string $accept): void
    {
        $serverBackup = $_SERVER;
        $getBackup = $_GET;
        $postBackup = $_POST;
        $envBackup = $_ENV;

        $_ENV['APP_ENV'] = 'dev';
        $_SERVER['REQUEST_METHOD'] = $method;
        $_SERVER['REQUEST_URI'] = $path;
        $_SERVER['HTTP_HOST'] = 'benchmark.test';
        $_SERVER['HTTP_ACCEPT'] = $accept;
        $_GET = [];
        $_POST = [];

        try {
            $request = new Request();
            $router = new Router($fixture);
            $match = $router->resolve($request->path(), $request->method());

            if (!$match || (($match['status'] ?? 200) !== 200)) {
                throw new RuntimeException('Benchmark request scenario failed to resolve route.');
            }

            $view = new View($fixture);
            $response = new Response();

            ob_start();

            try {
                $result = ($match['handler'])();
                $response->resolve($result, $request, $view, $match['route'] ?? '');
            } finally {
                ob_end_clean();
            }
        } finally {
            $_SERVER = $serverBackup;
            $_GET = $getBackup;
            $_POST = $postBackup;
            $_ENV = $envBackup;
        }
    }

    private function fixtureProfile(string $fixture): array
    {
        return [
            'name' => 'real_project_fixture',
            'description' => 'Isolated SparkPHP project with file-based routes, views and full request scenarios.',
            'route_files' => count(glob($fixture . '/app/routes/**/*.php', GLOB_BRACE) ?: []) + count(glob($fixture . '/app/routes/*.php') ?: []),
            'model_files' => count(glob($fixture . '/app/models/*.php') ?: []),
            'service_files' => count(glob($fixture . '/app/services/*.php') ?: []),
            'view_files' => count(glob($fixture . '/app/views/**/*.spark', GLOB_BRACE) ?: []) + count(glob($fixture . '/app/views/*.spark') ?: []),
        ];
    }
}

class BenchLeafService
{
}

class BenchNestedService
{
    public function __construct(public BenchLeafService $leaf)
    {
    }
}

class BenchController
{
    public function __construct(public BenchNestedService $nested)
    {
    }
}
