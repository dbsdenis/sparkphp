<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class RealtimeTest extends TestCase
{
    private static array $fixturePaths = [];
    private string $basePath;
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];
    private array $cookieBackup = [];
    private array $sessionBackup = [];
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        Database::reset();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;
        $this->cookieBackup = $_COOKIE;
        $this->sessionBackup = $_SESSION ?? [];
        $this->envBackup = $_ENV;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        $_COOKIE = [];
        $_SESSION = [];

        $this->basePath = sys_get_temp_dir() . '/sparkphp-realtime-' . bin2hex(random_bytes(6));
        self::$fixturePaths[] = $this->basePath;
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;
        $_COOKIE = $this->cookieBackup;
        $_SESSION = $this->sessionBackup;
        $_ENV = $this->envBackup;

        parent::tearDown();
    }

    public static function tearDownAfterClass(): void
    {
        foreach (array_unique(self::$fixturePaths) as $path) {
            self::deleteDirectoryStatic($path);
        }
    }

    public function testRealtimeClassesStayLazyUntilExplicitlyUsed(): void
    {
        $this->assertFalse(class_exists('RealtimeManager', false));

        $app = new Bootstrap($this->basePath);
        $app->boot();

        $this->assertFalse(class_exists('RealtimeManager', false));
    }

    public function testChannelRouterResolvesDynamicProtectedChannels(): void
    {
        require_once __DIR__ . '/../../core/Realtime.php';

        $router = new ChannelRouter($this->basePath);
        $resolved = $router->resolve('chat.42');

        $this->assertIsArray($resolved);
        $this->assertSame('chat.42', $resolved['channel']);
        $this->assertSame('chat.{roomId}', $resolved['template']);
        $this->assertSame('42', $resolved['params']['roomId']);
        $this->assertSame(['auth'], $resolved['middlewares']);
        $this->assertIsCallable($resolved['join']);
        $this->assertArrayHasKey('message.send', $resolved['messages']);
    }

    public function testBrokerAppendsAndReadsSinceWithoutRewritingFullLog(): void
    {
        require_once __DIR__ . '/../../core/Realtime.php';

        $broker = new RealtimeBroker($this->basePath);
        $first = $broker->append('chat.42', 'message.sent', ['body' => 'one']);
        usleep(1000);
        $second = $broker->append('chat.42', 'message.sent', ['body' => 'two']);

        $all = $broker->readSince('chat.42');
        $afterFirst = $broker->readSince('chat.42', $first['id']);

        $this->assertCount(2, $all);
        $this->assertSame('one', $all[0]['payload']['body']);
        $this->assertCount(1, $afterFirst);
        $this->assertSame($second['id'], $afterFirst[0]['id']);
        $this->assertSame('two', $afterFirst[0]['payload']['body']);
    }

    public function testMakeChannelGeneratesStubAndHelpListsCommand(): void
    {
        $make = $this->runSpark(['make:channel', 'chat.[roomId]']);
        $help = $this->runSpark(['help', 'make:channel']);

        $this->assertSame(0, $make['exit_code'], $make['output']);
        $this->assertSame(0, $help['exit_code'], $help['output']);
        $this->assertFileExists($this->basePath . '/app/channels/chat.[roomId].php');
        $this->assertStringContainsString('channel()', (string) file_get_contents($this->basePath . '/app/channels/chat.[roomId].php'));
        $this->assertStringContainsString('make:channel', $help['output']);
    }

    public function testRealtimeAuthEndpointAndSseStreamWorkEndToEnd(): void
    {
        $login = $this->dispatchRequest('POST', '/login', ['user_id' => 1], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertSame(201, $login['status']);

        $auth = $this->dispatchRequest('POST', '/_realtime/auth', ['channel' => 'chat.42'], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $tokenPayload = json_decode($auth['body'], true);

        $this->assertSame(200, $auth['status']);
        $this->assertSame('chat.42', $tokenPayload['channel']);
        $this->assertArrayHasKey('token', $tokenPayload);

        $emit = $this->dispatchRequest('POST', '/emit', [
            'room' => '42',
            'body' => 'hello world',
        ], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertSame(201, $emit['status']);
        require_once __DIR__ . '/../../core/Realtime.php';
        $events = (new RealtimeBroker($this->basePath))->readSince('chat.42');

        $this->assertCount(1, $events);
        $this->assertSame('hello world', $events[0]['payload']['body']);

        $stream = $this->dispatchRequest('GET', '/_realtime/stream?channel=chat.42&once=1', [], [
            'HTTP_ACCEPT' => 'text/event-stream',
        ]);

        $this->assertSame(200, $stream['status']);
        $this->assertStringContainsString('event: message.sent', $stream['body']);
        $this->assertStringContainsString('"body":"hello world"', $stream['body']);
        $this->assertStringContainsString(':heartbeat', $stream['body']);

        preg_match('/id:\s*(.+)\n/', $stream['body'], $matches);
        $lastId = $matches[1] ?? null;

        $this->assertNotNull($lastId);

        $replay = $this->dispatchRequest('GET', '/_realtime/stream?channel=chat.42&once=1&last_event_id=' . rawurlencode($lastId), [], [
            'HTTP_ACCEPT' => 'text/event-stream',
        ]);

        $this->assertSame(200, $replay['status']);
        $this->assertStringNotContainsString('event: message.sent', $replay['body']);
        $this->assertStringContainsString(':heartbeat', $replay['body']);
    }

    public function testProtectedRealtimeChannelsRejectUnauthenticatedAuthRequests(): void
    {
        $auth = $this->dispatchRequest('POST', '/_realtime/auth', ['channel' => 'chat.42'], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $this->assertSame(401, $auth['status']);
        $this->assertSame([
            'error' => 'Unauthenticated.',
        ], json_decode($auth['body'], true));
    }

    public function testRealtimeTokenAndDispatchMessageProduceBroadcastEnvelope(): void
    {
        $this->dispatchRequest('POST', '/login', ['user_id' => 1], [
            'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $app = new Bootstrap($this->basePath);
        $app->boot();

        $payload = realtime()->authToken('chat.42');
        $token = (string) $payload['token'];
        $parsed = realtime()->parseToken($token);
        $result = realtime()->dispatchMessage('chat.42', 'message.send', [
            'body' => 'from dispatch',
        ], $parsed);
        $events = realtime()->broker()->readSince('chat.42');

        $this->assertSame('chat.42', $parsed['channel']);
        $this->assertSame(['stored' => true], $result);
        $this->assertNotEmpty($events);
        $this->assertSame('message.sent', $events[0]['event']);
        $this->assertSame('from dispatch', $events[0]['payload']['body']);
        $this->assertStringContainsString('from dispatch', (string) file_get_contents($this->basePath . '/storage/messages.log'));
    }

    private function buildFixture(): void
    {
        mkdir($this->basePath, 0777, true);
        $this->copyDirectory(__DIR__ . '/../../core', $this->basePath . '/core');
        $this->copyDirectory(__DIR__ . '/../../public', $this->basePath . '/public');
        copy(__DIR__ . '/../../spark', $this->basePath . '/spark');
        copy(__DIR__ . '/../../VERSION', $this->basePath . '/VERSION');
        chmod($this->basePath . '/spark', 0755);

        mkdir($this->basePath . '/app/channels/[auth]', 0777, true);
        mkdir($this->basePath . '/app/middleware', 0777, true);
        mkdir($this->basePath . '/app/models', 0777, true);
        mkdir($this->basePath . '/app/routes', 0777, true);
        mkdir($this->basePath . '/app/views/errors', 0777, true);
        mkdir($this->basePath . '/storage/cache/app', 0777, true);
        mkdir($this->basePath . '/storage/cache/views', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
        mkdir($this->basePath . '/storage/queue', 0777, true);
        mkdir($this->basePath . '/storage/realtime', 0777, true);
        mkdir($this->basePath . '/storage/sessions', 0777, true);
        mkdir($this->basePath . '/storage/uploads', 0777, true);

        $databasePath = $this->basePath . '/database.sqlite';
        touch($databasePath);

        $pdo = new PDO('sqlite:' . $databasePath);
        $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT, email TEXT)');
        $pdo->exec("INSERT INTO users (name, email) VALUES ('Spark', 'spark@example.com')");

        file_put_contents($this->basePath . '/.env', <<<ENV
APP_NAME=SparkPHP Realtime Test
APP_ENV=dev
APP_KEY=test-realtime-key-1234567890
APP_URL=http://localhost:8000
APP_TIMEZONE=America/Sao_Paulo
SESSION=file
CACHE=file
QUEUE=file
DB=sqlite
DB_NAME={$databasePath}
LOG_LEVEL=debug
SPARK_INSPECTOR=off
REALTIME_PREFIX=/_realtime
REALTIME_WS_PORT=8081
REALTIME_TOKEN_TTL=30
REALTIME_GC_TTL=300
ENV
        );

        file_put_contents($this->basePath . '/app/models/User.php', <<<'PHP'
<?php

class User extends Model
{
    protected string $table = 'users';
    protected bool $timestamps = false;
}
PHP
        );

        file_put_contents($this->basePath . '/app/middleware/auth.php', <<<'PHP'
<?php

if (!auth()) {
    return json(['error' => 'Unauthenticated.'], 401);
}

return null;
PHP
        );

        file_put_contents($this->basePath . '/app/routes/login.php', <<<'PHP'
<?php

post(function () {
    $user = User::findOrFail((int) input('user_id', 1));
    login($user);

    return ['id' => auth()->id];
});
PHP
        );

        file_put_contents($this->basePath . '/app/routes/emit.php', <<<'PHP'
<?php

post(function () {
    $room = (string) input('room', '');
    $body = (string) input('body', '');

    realtime()->broadcast("chat.{$room}", 'message.sent', [
        'body' => $body,
        'user_id' => auth()->id,
    ]);

    return ['ok' => true];
})->guard('auth');
PHP
        );

        file_put_contents($this->basePath . '/app/channels/[auth]/chat.[roomId].php', <<<'PHP'
<?php

channel()
    ->join(function ($roomId) {
        if (!auth() || (string) $roomId !== '42') {
            return false;
        }

        return [
            'id' => auth()->id,
            'room' => $roomId,
        ];
    })
    ->onMessage('message.send', function (array $payload, $roomId) {
        file_put_contents(
            storage_path('messages.log'),
            json_encode([
                'room' => $roomId,
                'body' => $payload['body'] ?? null,
                'user_id' => auth()->id,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        realtime()->broadcast("chat.{$roomId}", 'message.sent', [
            'body' => $payload['body'] ?? null,
            'user_id' => auth()->id,
        ]);

        return ['stored' => true];
    });
PHP
        );

        file_put_contents($this->basePath . '/app/views/errors/404.spark', <<<'SPARK'
<h1>404</h1>
SPARK
        );
    }

    private function dispatchRequest(string $method, string $uri, array $data = [], array $server = []): array
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        header_remove();
        http_response_code(200);

        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $queryString = parse_url($uri, PHP_URL_QUERY) ?? '';

        parse_str($queryString, $query);

        $_SERVER = array_merge([
            'REQUEST_METHOD' => strtoupper($method),
            'REQUEST_URI' => $uri,
            'REMOTE_ADDR' => '127.0.0.1',
            'HTTP_HOST' => 'localhost:8000',
        ], $server);
        $_GET = $query;
        $_POST = strtoupper($method) === 'POST' ? $data : [];
        $_COOKIE = $_COOKIE;

        $app = new Bootstrap($this->basePath);
        $app->boot();

        ob_start();
        $app->run();
        $body = ob_get_clean();
        $status = http_response_code();
        $headers = headers_list();

        header_remove();

        return [
            'status' => $status,
            'headers' => $headers,
            'body' => $body,
            'path' => $path,
        ];
    }

    private function runSpark(array $args): array
    {
        $command = array_merge(['php', 'spark'], $args);
        $escaped = implode(' ', array_map('escapeshellarg', $command));

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($escaped, $descriptorSpec, $pipes, $this->basePath);

        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start spark command.');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'output' => trim($stdout . "\n" . $stderr),
        ];
    }

    private function copyDirectory(string $source, string $target): void
    {
        mkdir($target, 0777, true);

        $items = scandir($source) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $from = $source . '/' . $item;
            $to = $target . '/' . $item;

            if (is_dir($from)) {
                $this->copyDirectory($from, $to);
                continue;
            }

            copy($from, $to);
        }
    }

    private function deleteDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . '/' . $item;

            if (is_dir($target)) {
                $this->deleteDirectory($target);
            } else {
                unlink($target);
            }
        }

        rmdir($path);
    }

    private static function deleteDirectoryStatic(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = scandir($path) ?: [];

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $target = $path . '/' . $item;

            if (is_dir($target)) {
                self::deleteDirectoryStatic($target);
            } else {
                unlink($target);
            }
        }

        rmdir($path);
    }
}
