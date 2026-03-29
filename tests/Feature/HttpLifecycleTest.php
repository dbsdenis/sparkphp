<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class HttpLifecycleTest extends TestCase
{
    private string $basePath;
    /** @var resource|null */
    private $serverProcess = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-http-' . bin2hex(random_bytes(6));
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->serverProcess)) {
            proc_terminate($this->serverProcess);
            proc_close($this->serverProcess);
        }

        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testHtmlRequestsRenderViewsThroughTheFullHttpLifecycle(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/', [
            'Accept: text/html',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('<title>Welcome</title>', $response['body']);
        $this->assertStringContainsString('<h1>Hello SparkPHP</h1>', $response['body']);
    }

    public function testSparkInspectorInjectsToolbarIntoHtmlResponses(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/', [
            'Accept: text/html',
        ]);

        $requestId = $this->headerValue($response['headers'], 'X-Spark-Request-Id');
        $inspectorUrl = $this->headerValue($response['headers'], 'X-Spark-Inspector-Url');
        $serverTiming = $this->headerValue($response['headers'], 'Server-Timing');

        $this->assertSame(200, $response['status']);
        $this->assertStringContainsString('Spark Inspector', $response['body']);
        $this->assertStringContainsString('spark-inspector-badge-toggle', $response['body']);
        $this->assertStringContainsString('spark-inspector-toggle', $response['body']);
        $this->assertStringContainsString('spark-inspector-handle', $response['body']);
        $this->assertStringContainsString('spark-inspector-badge-restore', $response['body']);
        $this->assertNotNull($requestId);
        $this->assertSame('/_spark/requests/' . $requestId, $inspectorUrl);
        $this->assertStringContainsString('total;dur=', (string) $serverTiming);
        $this->assertStringContainsString('db;dur=', (string) $serverTiming);
        $this->assertStringContainsString('view;dur=', (string) $serverTiming);
    }

    public function testJsonRequestsAndPostResponsesWorkEndToEnd(): void
    {
        $port = $this->startServer();

        $health = $this->request($port, 'GET', '/api/health', [
            'Accept: application/json',
        ]);

        $this->assertSame(200, $health['status']);
        $this->assertSame(['ok' => true], json_decode($health['body'], true));

        $created = $this->request($port, 'POST', '/users', [
            'Accept: application/json',
            'Content-Type: application/json',
        ], json_encode(['name' => 'Denilson'], JSON_THROW_ON_ERROR));

        $this->assertSame(201, $created['status']);
        $this->assertSame(['name' => 'Denilson'], json_decode($created['body'], true));
    }

    public function testWeightedAcceptHeaderCanPreferJsonOverHtmlEndToEnd(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/', [
            'Accept: application/json;q=1, text/html;q=0.5',
        ]);

        $this->assertSame(200, $response['status']);
        $this->assertSame(['message' => 'Hello SparkPHP'], json_decode($response['body'], true));
    }

    public function testModelsAndPaginatorsSerializeByConventionInJsonRoutes(): void
    {
        $port = $this->startServer();

        $user = $this->request($port, 'GET', '/api/users/show', [
            'Accept: application/json',
        ]);

        $page = $this->request($port, 'GET', '/api/users/page?fields[users]=id,display_name', [
            'Accept: application/json',
        ]);

        $this->assertSame(200, $user['status']);
        $this->assertSame([
            'id' => 1,
            'display_name' => 'Spark',
        ], json_decode($user['body'], true));

        $pagePayload = json_decode($page['body'], true);

        $this->assertSame(200, $page['status']);
        $this->assertCount(1, $pagePayload['data']);
        $this->assertSame([
            'id' => 1,
            'display_name' => 'Spark',
        ], $pagePayload['data'][0]);
        $this->assertArrayHasKey('self', $pagePayload['links']);
        $this->assertSame(2, $pagePayload['meta']['total']);
    }

    public function testJsonApiSerializationIsOptInPerResponse(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/api/users/json_api', [
            'Accept: application/json',
        ]);

        $payload = json_decode($response['body'], true);

        $this->assertSame(200, $response['status']);
        $this->assertSame('users', $payload['data']['type']);
        $this->assertSame('1', $payload['data']['id']);
        $this->assertSame('Spark', $payload['data']['attributes']['display_name']);
    }

    public function testImplicitRouteModelBindingAndServiceInjectionWorkTogether(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/api/users/1?trace=bind-test', [
            'Accept: application/json',
        ]);

        $payload = json_decode($response['body'], true);

        $this->assertSame(200, $response['status']);
        $this->assertSame([
            'user' => [
                'id' => 1,
                'display_name' => 'Spark',
            ],
            'trace' => 'bind-test',
            'audit' => 'route-audit',
        ], $payload);
    }

    public function testAuthorizeUsesPolicyConventionAndReturns403WhenDenied(): void
    {
        $port = $this->startServer();

        $allowed = $this->request($port, 'GET', '/api/user-access/1?actor=1', [
            'Accept: application/json',
        ]);

        $denied = $this->request($port, 'GET', '/api/user-access/1?actor=2', [
            'Accept: application/json',
        ]);

        $this->assertSame(200, $allowed['status']);
        $this->assertSame([
            'id' => 1,
            'display_name' => 'Spark',
        ], json_decode($allowed['body'], true));

        $this->assertSame(403, $denied['status']);
        $this->assertSame([
            'error' => 'This action is unauthorized.',
            'status' => 403,
            'code' => 'forbidden',
        ], json_decode($denied['body'], true));
    }

    public function testSparkInspectorTracksJsonRequestsAndInternalInspectorRoutes(): void
    {
        $port = $this->startServer();

        $api = $this->request($port, 'GET', '/api/inspector', [
            'Accept: application/json',
        ]);

        $requestId = $this->headerValue($api['headers'], 'X-Spark-Request-Id');

        $this->assertSame(200, $api['status']);
        $this->assertStringNotContainsString('Spark Inspector', $api['body']);
        $this->assertNotNull($requestId);

        $history = $this->request($port, 'GET', '/_spark/requests', [
            'Accept: text/html',
        ]);

        $detailApi = $this->request($port, 'GET', '/_spark/api/requests/' . $requestId, [
            'Accept: application/json',
        ]);

        $detailPage = $this->request($port, 'GET', '/_spark/requests/' . $requestId, [
            'Accept: text/html',
        ]);

        $payload = json_decode($detailApi['body'], true);

        $this->assertSame(200, $history['status']);
        $this->assertStringContainsString('/api/inspector', $history['body']);
        $this->assertSame(200, $detailApi['status']);
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload['queries']);
        $this->assertNotEmpty($payload['logs']);
        $this->assertNotEmpty($payload['cache']);
        $this->assertNotEmpty($payload['events']);
        $this->assertNotEmpty($payload['queue']);
        $this->assertNotEmpty($payload['dumps']);
        $this->assertNotEmpty($payload['mail']);
        $this->assertSame(200, $detailPage['status']);
        $this->assertStringContainsString('Queries', $detailPage['body']);
        $this->assertStringContainsString('Logs', $detailPage['body']);
        $this->assertStringContainsString('Mail', $detailPage['body']);
    }

    public function testNotFoundAndMethodNotAllowedAreReturnedByFrontController(): void
    {
        $port = $this->startServer();

        $missing = $this->request($port, 'GET', '/missing', [
            'Accept: text/html',
        ]);

        $this->assertSame(404, $missing['status']);
        $this->assertStringContainsString('404', $missing['body']);

        $notAllowed = $this->request($port, 'POST', '/api/health', [
            'Accept: application/json',
        ]);

        $this->assertSame(405, $notAllowed['status']);
        $this->assertSame([
            'error' => 'Method Not Allowed',
            'status' => 405,
            'code' => 'method_not_allowed',
        ], json_decode($notAllowed['body'], true));
    }

    public function testAbortAndValidationUseStandardJsonErrorEnvelope(): void
    {
        $port = $this->startServer();

        $forbidden = $this->request($port, 'GET', '/api/forbidden', [
            'Accept: application/json',
        ]);

        $invalid = $this->request($port, 'POST', '/api/validate', [
            'Accept: application/json',
            'Content-Type: application/json',
        ], json_encode(['name' => 'ab'], JSON_THROW_ON_ERROR));

        $this->assertSame(403, $forbidden['status']);
        $this->assertSame([
            'error' => 'Acesso negado',
            'status' => 403,
            'code' => 'forbidden',
        ], json_decode($forbidden['body'], true));

        $this->assertSame(422, $invalid['status']);
        $this->assertSame([
            'error' => 'The given data was invalid.',
            'status' => 422,
            'code' => 'validation_error',
            'errors' => [
                'name' => 'Name deve ter no mínimo 3 caracteres.',
            ],
        ], json_decode($invalid['body'], true));
    }

    public function testInspectorRoutesReturn404OutsideDevelopment(): void
    {
        $this->writeEnv('production');
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/_spark/requests', [
            'Accept: text/html',
        ]);

        $this->assertSame(404, $response['status']);
    }

    public function testGlobalDirectoryAndInlineMiddlewareRunInPredictableOrder(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/api/order', [
            'Accept: application/json',
        ]);

        $payload = json_decode($response['body'], true);

        $this->assertSame(200, $response['status']);
        $this->assertSame([
            'mw_global',
            'mw_api',
            'mw_dir',
            'mw_inline',
            'handler',
        ], $payload['chain']);
    }

    public function testBlockingMiddlewareStopsBeforeHandlerAfterInheritedMiddlewareRuns(): void
    {
        $port = $this->startServer();

        $response = $this->request($port, 'GET', '/api/blocked', [
            'Accept: application/json',
        ]);

        $payload = json_decode($response['body'], true);

        $this->assertSame(409, $response['status']);
        $this->assertSame('mw_block', $payload['blocked_by']);
        $this->assertSame([
            'mw_global',
            'mw_api',
            'mw_block',
        ], $payload['chain']);
    }

    private function buildFixture(): void
    {
        mkdir($this->basePath, 0777, true);
        $this->copyDirectory(__DIR__ . '/../../core', $this->basePath . '/core');

        mkdir($this->basePath . '/app/events', 0777, true);
        mkdir($this->basePath . '/app/jobs', 0777, true);
        mkdir($this->basePath . '/app/middleware', 0777, true);
        mkdir($this->basePath . '/app/models', 0777, true);
        mkdir($this->basePath . '/app/policies', 0777, true);
        mkdir($this->basePath . '/app/services', 0777, true);
        mkdir($this->basePath . '/app/routes/api', 0777, true);
        mkdir($this->basePath . '/app/routes/api/[mw_dir]', 0777, true);
        mkdir($this->basePath . '/app/routes/api/users', 0777, true);
        mkdir($this->basePath . '/app/views/layouts', 0777, true);
        mkdir($this->basePath . '/app/views/errors', 0777, true);
        mkdir($this->basePath . '/public', 0777, true);
        mkdir($this->basePath . '/storage/cache/views', 0777, true);
        mkdir($this->basePath . '/storage/cache/app', 0777, true);
        mkdir($this->basePath . '/storage/inspector', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
        mkdir($this->basePath . '/storage/queue', 0777, true);
        mkdir($this->basePath . '/storage/sessions', 0777, true);
        mkdir($this->basePath . '/storage/uploads', 0777, true);

        copy(__DIR__ . '/../../public/index.php', $this->basePath . '/public/index.php');
        $this->writeEnv('dev');

        file_put_contents($this->basePath . '/app/routes/_middleware.php', <<<'PHP'
<?php
return ['mw_global'];
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/_middleware.php', <<<'PHP'
<?php
return ['mw_api'];
PHP
        );

        $databasePath = $this->basePath . '/database.sqlite';
        touch($databasePath);

        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
        $pdo->exec("INSERT INTO users (name) VALUES ('Spark')");
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Taylor', 'taylor@example.com')");

        file_put_contents($this->basePath . '/app/models/ApiUser.php', <<<'PHP'
<?php

#[Hidden('email')]
#[Rename('name', 'display_name')]
class ApiUser extends Model
{
    protected string $table = 'users';
    protected bool $timestamps = false;
}
PHP
        );

        file_put_contents($this->basePath . '/app/policies/ApiUserPolicy.php', <<<'PHP'
<?php

class ApiUserPolicy
{
    public function view(?ApiUser $actor, ApiUser $subject): bool
    {
        return (int) ($actor?->id ?? 0) === (int) $subject->id;
    }
}
PHP
        );

        file_put_contents($this->basePath . '/app/services/AuditTrail.php', <<<'PHP'
<?php

class AuditTrail
{
    public string $label = 'route-audit';
}
PHP
        );

        file_put_contents($this->basePath . '/app/routes/index.php', <<<'PHP'
<?php
get(function () {
    logger('page-hit', 'info', ['route' => '/']);
    cache(['welcome' => 'spark'], 60);
    cache('welcome');
    emit('user.created', ['id' => 1]);
    queue('SendEmailJob', ['mode' => 'queued']);
    dispatch('SendEmailJob', ['mode' => 'sync']);
    inspect(['page' => 'home']);
    measure('home-render', function () {
        usleep(1000);
    });

    try {
        mailer()->to('john@example.com')->subject('Spark Inspector')->text('Body')->send();
    } catch (Throwable $e) {
        logger('mail-failed', 'warning', ['error' => $e->getMessage()]);
    }

    return ['message' => 'Hello SparkPHP'];
});
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/health.php', <<<'PHP'
<?php
get(fn() => ['ok' => true]);
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/users/show.php', <<<'PHP'
<?php
get(fn() => ApiUser::find(1));
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/users/page.php', <<<'PHP'
<?php
get(fn() => ApiUser::query()->paginate(1));
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/users/json_api.php', <<<'PHP'
<?php
get(fn() => ApiUser::api(ApiUser::findOrFail(1), ['json_api' => true]));
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/users.[id].php', <<<'PHP'
<?php
get(function (ApiUser $user, Request $request, AuditTrail $audit) {
    return [
        'user' => $user,
        'trace' => $request->query('trace'),
        'audit' => $audit->label,
    ];
});
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/user-access.[id].php', <<<'PHP'
<?php
get(function (ApiUser $user) {
    $actor = ApiUser::findOrFail((int) query('actor'));
    authorize('view', $user, $actor);

    return $user;
});
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/inspector.php', <<<'PHP'
<?php
get(function () {
    logger('api-hit', 'info', ['route' => '/api/inspector']);
    cache(['api-key' => 'spark'], 30);
    cache('api-key');
    emit('api.called', ['ok' => true]);
    queue('SendEmailJob', ['mode' => 'api']);
    inspect(['api' => true]);
    measure('api-measure', function () {
        usleep(1000);
    });

    try {
        mailer()->to('jane@example.com')->subject('API Inspector')->text('Payload')->send();
    } catch (Throwable $e) {
        logger('api-mail-failed', 'warning', ['error' => $e->getMessage()]);
    }

    return ['ok' => true, 'count' => db('users')->count()];
});
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/forbidden.php', <<<'PHP'
<?php
get(fn() => abort(403, 'Acesso negado'));
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/validate.php', <<<'PHP'
<?php
post(function () {
    validate([
        'name' => 'required|min:3',
    ]);

    return ['ok' => true];
});
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/[mw_dir]/order.php', <<<'PHP'
<?php
get(function () {
    $log = storage_path('middleware.log');
    $chain = is_file($log) ? json_decode((string) file_get_contents($log), true) : [];
    $chain = is_array($chain) ? $chain : [];
    $chain[] = 'handler';
    file_put_contents($log, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

    return ['chain' => $chain];
})->guard('mw_inline');
PHP
        );

        file_put_contents($this->basePath . '/app/routes/api/blocked.php', <<<'PHP'
<?php
get(fn() => ['handler' => 'should-not-run'])->guard('mw_block');
PHP
        );

        file_put_contents($this->basePath . '/app/routes/users.php', <<<'PHP'
<?php
post(fn() => input());
PHP
        );

        file_put_contents($this->basePath . '/app/middleware/mw_global.php', <<<'PHP'
<?php
$log = storage_path('middleware.log');
$chain = is_file($log) ? json_decode((string) file_get_contents($log), true) : [];
$chain = is_array($chain) ? $chain : [];
$chain[] = 'mw_global';
file_put_contents($log, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

return null;
PHP
        );

        file_put_contents($this->basePath . '/app/middleware/mw_api.php', <<<'PHP'
<?php
$log = storage_path('middleware.log');
$chain = is_file($log) ? json_decode((string) file_get_contents($log), true) : [];
$chain = is_array($chain) ? $chain : [];
$chain[] = 'mw_api';
file_put_contents($log, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

return null;
PHP
        );

        file_put_contents($this->basePath . '/app/middleware/mw_dir.php', <<<'PHP'
<?php
$log = storage_path('middleware.log');
$chain = is_file($log) ? json_decode((string) file_get_contents($log), true) : [];
$chain = is_array($chain) ? $chain : [];
$chain[] = 'mw_dir';
file_put_contents($log, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

return null;
PHP
        );

        file_put_contents($this->basePath . '/app/middleware/mw_inline.php', <<<'PHP'
<?php
$log = storage_path('middleware.log');
$chain = is_file($log) ? json_decode((string) file_get_contents($log), true) : [];
$chain = is_array($chain) ? $chain : [];
$chain[] = 'mw_inline';
file_put_contents($log, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

return null;
PHP
        );

        file_put_contents($this->basePath . '/app/middleware/mw_block.php', <<<'PHP'
<?php
$log = storage_path('middleware.log');
$chain = is_file($log) ? json_decode((string) file_get_contents($log), true) : [];
$chain = is_array($chain) ? $chain : [];
$chain[] = 'mw_block';
file_put_contents($log, json_encode($chain, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

return json([
    'blocked_by' => 'mw_block',
    'chain' => $chain,
], 409);
PHP
        );

        file_put_contents($this->basePath . '/app/events/user.created.php', <<<'PHP'
<?php
return true;
PHP
        );

        file_put_contents($this->basePath . '/app/events/api.called.php', <<<'PHP'
<?php
return true;
PHP
        );

        file_put_contents($this->basePath . '/app/jobs/SendEmailJob.php', <<<'PHP'
<?php

class SendEmailJob
{
    public function __construct(private mixed $data = null)
    {
    }

    public function handle(): void
    {
    }
}
PHP
        );

        file_put_contents($this->basePath . '/app/views/layouts/main.spark', <<<'SPARK'
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

        file_put_contents($this->basePath . '/app/views/index.spark', <<<'SPARK'
@title('Welcome')
<h1>{{ $message }}</h1>
SPARK
        );

        file_put_contents($this->basePath . '/app/views/errors/404.spark', <<<'SPARK'
<h1>404 - Not Found</h1>
SPARK
        );

        file_put_contents($this->basePath . '/app/views/errors/405.spark', <<<'SPARK'
<h1>405 - Method Not Allowed</h1>
SPARK
        );
    }

    private function writeEnv(string $appEnv): void
    {
        file_put_contents($this->basePath . '/.env', <<<ENV
APP_NAME=SparkPHP Test
APP_ENV={$appEnv}
APP_KEY=test-key-1234567890
APP_LANG=pt-BR
APP_URL=http://127.0.0.1
APP_TIMEZONE=America/Sao_Paulo
SESSION=file
CACHE=file
QUEUE=file
DB=sqlite
DB_NAME={$this->basePath}/database.sqlite
LOG_LEVEL=debug
MAIL_HOST=127.0.0.1
MAIL_PORT=1
MAIL_FROM=test@example.com
MAIL_FROM_NAME=SparkPHP Test
SPARK_INSPECTOR=auto
SPARK_INSPECTOR_PREFIX=/_spark
SPARK_INSPECTOR_HISTORY=150
SPARK_INSPECTOR_MASK=false
SPARK_INSPECTOR_SLOW_MS=1
ENV
        );
    }

    private function startServer(): int
    {
        if (is_resource($this->serverProcess)) {
            return $this->detectPort();
        }

        $port = $this->findFreePort();
        $command = sprintf(
            'php -S 127.0.0.1:%d %s',
            $port,
            escapeshellarg($this->basePath . '/public/index.php')
        );

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ];

        $this->serverProcess = proc_open($command, $descriptorSpec, $pipes, $this->basePath);
        $this->assertIsResource($this->serverProcess);

        $started = false;
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            $fp = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if (is_resource($fp)) {
                fclose($fp);
                $started = true;
                break;
            }
            usleep(100000);
        }

        $this->assertTrue($started, 'Built-in PHP server did not start in time.');

        return $port;
    }

    private function detectPort(): int
    {
        throw new RuntimeException('Server port should be provided by startServer().');
    }

    private function request(int $port, string $method, string $path, array $headers = [], ?string $body = null): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'ignore_errors' => true,
            ],
        ]);

        $responseBody = file_get_contents("http://127.0.0.1:{$port}{$path}", false, $context);
        $responseHeaders = $http_response_header ?? [];

        preg_match('#HTTP/\d+\.\d+\s+(\d{3})#', $responseHeaders[0] ?? '', $matches);

        return [
            'status' => (int) ($matches[1] ?? 0),
            'body' => $responseBody === false ? '' : $responseBody,
            'headers' => $responseHeaders,
        ];
    }

    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }

    private function findFreePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, $errstr ?: 'Unable to allocate a TCP port.');

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr((string) $name, ':'), 1);
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0777, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $destination = $target . '/' . $items->getSubPathName();

            if ($item->isDir()) {
                if (!is_dir($destination)) {
                    mkdir($destination, 0777, true);
                }
                continue;
            }

            copy($item->getPathname(), $destination);
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
