<?php

final class SparkChannelRegistration
{
    public function join(callable $resolver): static
    {
        ChannelRouter::$_join = $resolver;
        return $this;
    }

    public function onMessage(string $event, callable $handler): static
    {
        ChannelRouter::$_messages[$event] = $handler;
        return $this;
    }
}

final class SparkRealtimeContext
{
    private static mixed $actor = null;
    private static ?array $subscription = null;

    public static function actor(): mixed
    {
        return static::$actor;
    }

    public static function subscription(): ?array
    {
        return static::$subscription;
    }

    public static function runAs(mixed $actor, callable $callback, ?array $subscription = null): mixed
    {
        $previousActor = static::$actor;
        $previousSubscription = static::$subscription;

        static::$actor = $actor;
        static::$subscription = $subscription;

        try {
            return $callback();
        } finally {
            static::$actor = $previousActor;
            static::$subscription = $previousSubscription;
        }
    }
}

class ChannelRouter
{
    /** @internal Used by channel() registration helpers */
    public static $_join = null;
    /** @internal Used by channel() registration helpers */
    public static array $_messages = [];

    private string $basePath;
    private string $cacheFile;
    private array $channels = [];

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->cacheFile = $this->basePath . '/storage/cache/channels.php';
        $this->channels = $this->loadChannels();
    }

    public function resolve(string $name): ?array
    {
        foreach ($this->channels as $channel) {
            $params = [];

            if (!$this->matchPattern($channel['pattern'], $name, $channel['paramNames'], $params)) {
                continue;
            }

            $handlers = $this->loadHandlers($channel['file']);

            return [
                'channel' => $name,
                'template' => $channel['template'],
                'file' => $channel['file'],
                'params' => $params,
                'middlewares' => $channel['middlewares'],
                'join' => $handlers['join'],
                'messages' => $handlers['messages'],
            ];
        }

        return null;
    }

    public function list(): array
    {
        return $this->channels;
    }

    private function loadChannels(): array
    {
        $isDev = ($_ENV['APP_ENV'] ?? 'dev') === 'dev';

        if (!$isDev && is_file($this->cacheFile)) {
            return require $this->cacheFile;
        }

        return $this->buildChannels();
    }

    public function buildChannels(): array
    {
        $channelsDir = $this->basePath . '/app/channels';

        if (!is_dir($channelsDir)) {
            return [];
        }

        $channels = [];
        $this->scanDir($channelsDir, $channelsDir, [], $channels);

        usort($channels, fn(array $a, array $b): int => $a['priority'] <=> $b['priority']);

        $this->saveCache($channels);

        return $channels;
    }

    private function scanDir(string $dir, string $base, array $middlewares, array &$channels): void
    {
        $entries = scandir($dir) ?: [];

        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $fullPath = $dir . '/' . $entry;

            if (is_dir($fullPath)) {
                if (preg_match('/^\[(.+)]$/', $entry, $matches)) {
                    $names = array_values(array_filter(array_map('trim', explode('+', $matches[1]))));
                    $this->scanDir($fullPath, $base, array_values(array_unique(array_merge($middlewares, $names))), $channels);
                } else {
                    $this->scanDir($fullPath, $base, $middlewares, $channels);
                }

                continue;
            }

            if (pathinfo($entry, PATHINFO_EXTENSION) !== 'php') {
                continue;
            }

            [$segments, $paramNames, $hasParams] = $this->fileToChannelSegments($fullPath, $base);

            if ($segments === []) {
                continue;
            }

            $template = $this->segmentsToTemplate($segments);

            $channels[] = [
                'file' => $fullPath,
                'template' => $template,
                'pattern' => $this->buildPattern($segments),
                'paramNames' => $paramNames,
                'middlewares' => $middlewares,
                'priority' => $hasParams ? 10 : 1,
            ];
        }
    }

    private function fileToChannelSegments(string $file, string $base): array
    {
        $relative = ltrim(str_replace([$base, '\\'], ['', '/'], $file), '/');
        $relative = preg_replace('/\.php$/', '', $relative) ?? $relative;

        $parts = explode('/', $relative);
        $segments = [];
        $paramNames = [];
        $hasParams = false;

        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^\[.+]$/', $part)) {
                continue;
            }

            if ($part === 'index') {
                continue;
            }

            if (str_contains($part, '.[')) {
                $subParts = preg_split('/\.(?=\[)/', $part) ?: [$part];

                foreach ($subParts as $subPart) {
                    if (preg_match('/^\[(.+)]$/', $subPart, $matches)) {
                        $segments[] = ':' . $matches[1];
                        $paramNames[] = $matches[1];
                        $hasParams = true;
                    } else {
                        $segments[] = $subPart;
                    }
                }

                continue;
            }

            if (preg_match('/^\[(.+)]$/', $part, $matches)) {
                $segments[] = ':' . $matches[1];
                $paramNames[] = $matches[1];
                $hasParams = true;
                continue;
            }

            $segments[] = $part;
        }

        return [$segments, $paramNames, $hasParams];
    }

    private function segmentsToTemplate(array $segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            if (str_starts_with($segment, ':')) {
                $parts[] = '{' . substr($segment, 1) . '}';
            } else {
                $parts[] = $segment;
            }
        }

        return implode('.', $parts);
    }

    private function buildPattern(array $segments): string
    {
        $parts = [];

        foreach ($segments as $segment) {
            if (str_starts_with($segment, ':')) {
                $parts[] = '([^.]+)';
            } else {
                $parts[] = preg_quote($segment, '#');
            }
        }

        return '#^' . implode('\.', $parts) . '$#';
    }

    private function matchPattern(string $pattern, string $channel, array $paramNames, array &$params): bool
    {
        if (!preg_match($pattern, $channel, $matches)) {
            return false;
        }

        array_shift($matches);

        foreach ($matches as $index => $value) {
            $value = urldecode($value);
            $params[$index] = $value;

            if (isset($paramNames[$index])) {
                $params[$paramNames[$index]] = $value;
            }
        }

        return true;
    }

    private function loadHandlers(string $file): array
    {
        self::$_join = null;
        self::$_messages = [];

        (static function () use ($file) {
            require $file;
        })();

        $join = self::$_join;
        $messages = self::$_messages;

        self::$_join = null;
        self::$_messages = [];

        return [
            'join' => $join,
            'messages' => $messages,
        ];
    }

    private function saveCache(array $channels): void
    {
        $dir = dirname($this->cacheFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($this->cacheFile, '<?php return ' . var_export($channels, true) . ';');
    }
}

class RealtimeMetrics
{
    private string $file;

    public function __construct(string $basePath)
    {
        $dir = rtrim($basePath, '/\\') . '/storage/realtime';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->file = $dir . '/metrics.json';
    }

    public function snapshot(): array
    {
        if (!is_file($this->file)) {
            return $this->defaults();
        }

        $decoded = json_decode((string) file_get_contents($this->file), true);

        return is_array($decoded) ? array_merge($this->defaults(), $decoded) : $this->defaults();
    }

    public function increment(string $key, int $amount = 1): void
    {
        $this->mutate(function (array $metrics) use ($key, $amount): array {
            $metrics[$key] = (int) ($metrics[$key] ?? 0) + $amount;
            $metrics['updated_at'] = date(DATE_ATOM);
            return $metrics;
        });
    }

    public function set(string $key, mixed $value): void
    {
        $this->mutate(function (array $metrics) use ($key, $value): array {
            $metrics[$key] = $value;
            $metrics['updated_at'] = date(DATE_ATOM);
            return $metrics;
        });
    }

    private function mutate(callable $callback): void
    {
        $handle = fopen($this->file, 'c+');

        if ($handle === false) {
            return;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return;
            }

            $contents = stream_get_contents($handle);
            $current = json_decode($contents ?: '', true);
            $metrics = is_array($current) ? array_merge($this->defaults(), $current) : $this->defaults();
            $metrics = $callback($metrics);

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, json_encode($metrics, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function defaults(): array
    {
        return [
            'connections_open' => 0,
            'channels_active' => 0,
            'messages_received' => 0,
            'broadcasts_sent' => 0,
            'auth_denied' => 0,
            'errors' => 0,
            'updated_at' => date(DATE_ATOM),
        ];
    }
}

class RealtimeBroker
{
    private string $basePath;
    private string $storagePath;
    private string $gcMarker;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->storagePath = $this->basePath . '/storage/realtime/channels';
        $this->gcMarker = $this->basePath . '/storage/realtime/.gc';

        if (!is_dir($this->storagePath)) {
            mkdir($this->storagePath, 0755, true);
        }
    }

    public function append(string $channel, string $event, mixed $payload, array $meta = []): array
    {
        $this->garbageCollect();

        $envelope = [
            'id' => $this->nextId(),
            'channel' => $channel,
            'event' => $event,
            'payload' => $payload,
            'meta' => $meta,
            'created_at' => date(DATE_ATOM),
            'created_ts' => time(),
        ];

        file_put_contents(
            $this->channelFile($channel),
            json_encode($envelope, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );

        return $envelope;
    }

    public function readSince(string $channel, ?string $lastId = null): array
    {
        $file = $this->channelFile($channel);

        if (!is_file($file)) {
            return [];
        }

        $rows = [];
        $handle = fopen($file, 'r');

        if ($handle === false) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            $decoded = json_decode(trim($line), true);

            if (!is_array($decoded) || !isset($decoded['id'])) {
                continue;
            }

            if ($lastId !== null && strcmp((string) $decoded['id'], $lastId) <= 0) {
                continue;
            }

            $rows[] = $decoded;
        }

        fclose($handle);

        return $rows;
    }

    public function garbageCollect(): void
    {
        $ttl = max(1, (int) ($_ENV['REALTIME_GC_TTL'] ?? 300));
        $threshold = time() - $ttl;
        $lastGc = is_file($this->gcMarker) ? (int) file_get_contents($this->gcMarker) : 0;

        if ($lastGc >= (time() - 15)) {
            return;
        }

        $files = glob($this->storagePath . '/*.log') ?: [];

        foreach ($files as $file) {
            $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $kept = [];

            foreach ($lines as $line) {
                $decoded = json_decode($line, true);

                if (!is_array($decoded)) {
                    continue;
                }

                if ((int) ($decoded['created_ts'] ?? 0) < $threshold) {
                    continue;
                }

                $kept[] = $line;
            }

            file_put_contents($file, $kept === [] ? '' : implode(PHP_EOL, $kept) . PHP_EOL, LOCK_EX);
        }

        file_put_contents($this->gcMarker, (string) time(), LOCK_EX);
    }

    private function channelFile(string $channel): string
    {
        return $this->storagePath . '/' . hash('sha256', $channel) . '.log';
    }

    private function nextId(): string
    {
        return sprintf('%020d-%s', (int) floor(microtime(true) * 1000), bin2hex(random_bytes(4)));
    }
}

class RealtimeManager
{
    private string $basePath;
    private ?Container $container;
    private ?ChannelRouter $channels = null;
    private ?RealtimeBroker $broker = null;
    private ?RealtimeMetrics $metrics = null;

    public function __construct(string $basePath, ?Container $container = null)
    {
        $this->basePath = rtrim($basePath, '/\\');
        $this->container = $container;
    }

    public function channels(): ChannelRouter
    {
        return $this->channels ??= new ChannelRouter($this->basePath);
    }

    public function broker(): RealtimeBroker
    {
        return $this->broker ??= new RealtimeBroker($this->basePath);
    }

    public function metrics(): RealtimeMetrics
    {
        return $this->metrics ??= new RealtimeMetrics($this->basePath);
    }

    public function prefix(): string
    {
        return rtrim($_ENV['REALTIME_PREFIX'] ?? '/_realtime', '/');
    }

    public function isRealtimePath(string $path): bool
    {
        $prefix = $this->prefix();
        return $path === $prefix || str_starts_with($path, $prefix . '/');
    }

    public function broadcast(string $channel, string $event, mixed $payload, array $meta = []): array
    {
        $envelope = $this->broker()->append($channel, $event, $payload, $meta);
        $this->metrics()->increment('broadcasts_sent');
        return $envelope;
    }

    public function sseUrl(string $channel): string
    {
        return url(trim($this->prefix(), '/') . '/stream?channel=' . rawurlencode($channel));
    }

    public function authToken(string $channel, array $context = []): array|string
    {
        $authorization = $this->authorize($channel, auth(), runMiddleware: true);

        if (($authorization['allowed'] ?? false) !== true) {
            throw new RuntimeException('Realtime channel authorization failed.');
        }

        $token = $this->signToken([
            'channel' => $channel,
            'user_id' => $this->normalizeActorId(auth()),
            'member' => $authorization['member'] ?? null,
            'context' => $context,
            'exp' => time() + max(1, (int) ($_ENV['REALTIME_TOKEN_TTL'] ?? 30)),
            'nonce' => bin2hex(random_bytes(8)),
        ]);

        if (($context['raw'] ?? false) === true) {
            return $token;
        }

        return [
            'token' => $token,
            'channel' => $channel,
            'expires_in' => max(1, (int) ($_ENV['REALTIME_TOKEN_TTL'] ?? 30)),
            'ws_url' => $this->webSocketUrl(),
            'member' => $authorization['member'] ?? null,
        ];
    }

    public function handleHttp(Request $request): bool
    {
        if (!$this->isRealtimePath($request->path())) {
            return false;
        }

        $relative = trim(substr($request->path(), strlen($this->prefix())), '/');

        if ($request->method() === 'POST' && $relative === 'auth') {
            $this->handleAuthRequest($request);
            return true;
        }

        if ($request->method() === 'GET' && $relative === 'stream') {
            $this->handleStreamRequest($request);
            return true;
        }

        if ($request->method() === 'GET' && $relative === 'metrics') {
            Response::json($this->metrics()->snapshot())->send();
            return true;
        }

        Response::notFound('Realtime endpoint not found.')->send();
        return true;
    }

    public function parseToken(string $token): ?array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signatureEncoded] = $parts;
        $payloadJson = $this->base64UrlDecode($payloadEncoded);
        $signature = $this->base64UrlDecode($signatureEncoded);

        if ($payloadJson === false || $signature === false) {
            return null;
        }

        $expected = hash_hmac('sha256', $payloadJson, $this->tokenKey(), true);

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $payload = json_decode($payloadJson, true);

        if (!is_array($payload)) {
            return null;
        }

        if ((int) ($payload['exp'] ?? 0) < time()) {
            return null;
        }

        return $payload;
    }

    public function actorFromToken(array $payload): mixed
    {
        $userId = $payload['user_id'] ?? null;

        if ($userId === null) {
            return null;
        }

        if (class_exists('User')) {
            return User::find($userId);
        }

        return (object) ['id' => $userId];
    }

    public function authorize(string $channel, mixed $actor = null, bool $runMiddleware = true): array
    {
        $resolved = $this->channels()->resolve($channel);

        if ($resolved === null) {
            return [
                'allowed' => false,
                'status' => 404,
                'error' => 'Realtime channel not found.',
            ];
        }

        if ($runMiddleware && $resolved['middlewares'] !== []) {
            $early = (new Middleware($this->basePath, $resolved['middlewares']))->run();

            if ($early !== null) {
                return [
                    'allowed' => false,
                    'status' => 403,
                    'response' => $early,
                ];
            }
        }

        if ($resolved['join'] === null) {
            return [
                'allowed' => true,
                'resolved' => $resolved,
                'member' => null,
            ];
        }

        $callback = function () use ($resolved, $channel) {
            return $this->callChannelHandler($resolved['join'], $resolved['params'], [
                'channel' => $channel,
                'subscription' => SparkRealtimeContext::subscription(),
            ]);
        };

        $result = SparkRealtimeContext::runAs($actor, $callback, [
            'channel' => $channel,
        ]);

        if ($result instanceof Response) {
            return [
                'allowed' => false,
                'status' => 403,
                'response' => $result,
            ];
        }

        if ($result === false) {
            return [
                'allowed' => false,
                'status' => 403,
                'error' => 'Realtime channel authorization failed.',
            ];
        }

        return [
            'allowed' => true,
            'resolved' => $resolved,
            'member' => is_array($result) ? $result : null,
        ];
    }

    public function dispatchMessage(string $channel, string $event, mixed $payload, array $tokenPayload): mixed
    {
        $resolved = $this->channels()->resolve($channel);

        if ($resolved === null) {
            throw new RuntimeException("Realtime channel not found: {$channel}");
        }

        $handler = $resolved['messages'][$event] ?? null;

        if ($handler === null) {
            throw new RuntimeException("Realtime event handler not found for [{$channel}] event [{$event}].");
        }

        $actor = $this->actorFromToken($tokenPayload);

        $this->metrics()->increment('messages_received');

        return SparkRealtimeContext::runAs($actor, function () use ($handler, $resolved, $channel, $event, $payload, $tokenPayload) {
            return $this->callChannelHandler($handler, $resolved['params'], [
                'payload' => $payload,
                'channel' => $channel,
                'event' => $event,
                'subscription' => $tokenPayload,
            ]);
        }, $tokenPayload);
    }

    public function serveWebSocket(array $args): void
    {
        $host = '0.0.0.0';
        $port = (int) ($_ENV['REALTIME_WS_PORT'] ?? 8081);

        foreach ($args as $arg) {
            if (str_starts_with($arg, '--host=')) {
                $host = substr($arg, 7);
            }

            if (str_starts_with($arg, '--port=')) {
                $port = (int) substr($arg, 7);
            }
        }

        $server = new RealtimeWebSocketServer($this, $host, $port);
        $server->run();
    }

    private function handleAuthRequest(Request $request): void
    {
        $channel = trim((string) ($request->input('channel') ?? $request->query('channel') ?? ''));

        if ($channel === '') {
            Response::validationError([
                'channel' => 'The channel field is required.',
            ])->send();
            return;
        }

        $authorization = $this->authorize($channel, auth(), runMiddleware: true);

        if (($authorization['allowed'] ?? false) !== true) {
            $this->metrics()->increment('auth_denied');
            $this->sendAuthorizationFailure($authorization);
            return;
        }

        Response::json($this->authToken($channel))->send();
    }

    private function handleStreamRequest(Request $request): void
    {
        $channel = trim((string) ($request->query('channel') ?? ''));

        if ($channel === '') {
            Response::validationError([
                'channel' => 'The channel query parameter is required.',
            ])->send();
            return;
        }

        $authorization = $this->authorize($channel, auth(), runMiddleware: true);

        if (($authorization['allowed'] ?? false) !== true) {
            $this->metrics()->increment('auth_denied');
            $this->sendAuthorizationFailure($authorization);
            return;
        }

        $broker = $this->broker();
        $once = filter_var($request->query('once', false), FILTER_VALIDATE_BOOLEAN);
        $lastId = (string) ($request->header('Last-Event-ID') ?? $request->query('last_event_id') ?? '');
        $lastId = $lastId !== '' ? $lastId : null;

        Response::stream(function () use ($broker, $channel, $lastId, $once) {
            ignore_user_abort(true);
            $cursor = $lastId;
            $lastHeartbeat = microtime(true);

            while (true) {
                $events = $broker->readSince($channel, $cursor);

                foreach ($events as $event) {
                    $cursor = (string) ($event['id'] ?? $cursor);
                    $data = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                    echo 'id: ' . $event['id'] . "\n";
                    echo 'event: ' . ($event['event'] ?? 'message') . "\n";
                    echo 'data: ' . $data . "\n\n";
                    $this->flushStream();
                }

                if ($once) {
                    echo ":heartbeat\n\n";
                    $this->flushStream();
                    break;
                }

                if ((microtime(true) - $lastHeartbeat) >= 15) {
                    echo ":heartbeat\n\n";
                    $this->flushStream();
                    $lastHeartbeat = microtime(true);
                }

                if (connection_aborted()) {
                    break;
                }

                usleep(250000);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ])->send();
    }

    private function sendAuthorizationFailure(array $authorization): void
    {
        $response = $authorization['response'] ?? null;

        if ($response instanceof Response) {
            $response->send();
            return;
        }

        if ($response !== null) {
            $resolver = new Response();
            $request = $this->container?->make(Request::class) ?? new Request();
            $view = new View($this->basePath);
            $resolver->resolve($response, $request, $view, $this->prefix());
            return;
        }

        Response::error($authorization['error'] ?? 'Forbidden', (int) ($authorization['status'] ?? 403), 'forbidden')->send();
    }

    private function signToken(array $payload): string
    {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $signature = hash_hmac('sha256', $payloadJson, $this->tokenKey(), true);

        return $this->base64UrlEncode($payloadJson) . '.' . $this->base64UrlEncode($signature);
    }

    private function tokenKey(): string
    {
        return (string) ($_ENV['APP_KEY'] ?? 'spark-realtime-default-key');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string|false
    {
        $padding = strlen($value) % 4;

        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return base64_decode(strtr($value, '-_', '+/'), true);
    }

    private function callChannelHandler(callable $handler, array $params, array $extras = []): mixed
    {
        if ($this->container instanceof Container) {
            return $this->container->callRoute($handler, $params, $extras);
        }

        return $handler(...array_values(array_merge($extras, $params)));
    }

    private function normalizeActorId(mixed $actor): mixed
    {
        return is_object($actor) ? ($actor->id ?? null) : $actor;
    }

    private function flushStream(): void
    {
        if (PHP_SAPI === 'cli') {
            return;
        }

        if (function_exists('ob_get_level') && ob_get_level() > 0) {
            @ob_flush();
        }

        flush();
    }

    private function webSocketUrl(): string
    {
        $appUrl = $_ENV['APP_URL'] ?? 'http://localhost:8000';
        $parts = parse_url($appUrl) ?: [];
        $scheme = ($parts['scheme'] ?? 'http') === 'https' ? 'wss' : 'ws';
        $host = $parts['host'] ?? 'localhost';
        $port = (int) ($_ENV['REALTIME_WS_PORT'] ?? 8081);

        return sprintf('%s://%s:%d%s/ws', $scheme, $host, $port, $this->prefix());
    }
}

class RealtimeWebSocketServer
{
    private RealtimeManager $manager;
    private string $host;
    private int $port;
    /** @var resource */
    private $server;
    /** @var array<int, array<string, mixed>> */
    private array $clients = [];

    public function __construct(RealtimeManager $manager, string $host, int $port)
    {
        $this->manager = $manager;
        $this->host = $host;
        $this->port = $port;
    }

    public function run(): void
    {
        $context = stream_context_create();
        $server = stream_socket_server("tcp://{$this->host}:{$this->port}", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);

        if ($server === false) {
            throw new RuntimeException("Realtime WebSocket server failed to start: {$errstr} ({$errno})");
        }

        $this->server = $server;
        stream_set_blocking($this->server, false);

        echo "\n";
        out(color('  ⚡ Realtime worker started', 'cyan') . color(" [{$this->host}:{$this->port}]", 'green') . color(' — Ctrl+C to stop.', 'dim'));
        echo "\n";

        while (true) {
            $read = [$this->server];
            $write = [];
            $except = [];

            foreach ($this->clients as $client) {
                $read[] = $client['socket'];
            }

            @stream_select($read, $write, $except, 0, 250000);

            foreach ($read as $socket) {
                if ($socket === $this->server) {
                    $this->acceptClient();
                    continue;
                }

                $this->readClient($socket);
            }

            $this->fanOut();
            $this->maintainHeartbeats();
            $this->updateMetrics();
        }
    }

    private function acceptClient(): void
    {
        $client = @stream_socket_accept($this->server, 0);

        if ($client === false) {
            return;
        }

        stream_set_blocking($client, false);

        $id = (int) $client;
        $this->clients[$id] = [
            'socket' => $client,
            'handshake' => false,
            'buffer' => '',
            'subscriptions' => [],
            'last_ping' => time(),
            'last_pong' => time(),
        ];

        $this->manager->metrics()->increment('connections_open');
    }

    private function readClient($socket): void
    {
        $id = (int) $socket;
        $chunk = @fread($socket, 8192);

        if ($chunk === '' || $chunk === false) {
            if (feof($socket)) {
                $this->disconnect($id);
            }

            return;
        }

        $this->clients[$id]['buffer'] .= $chunk;

        if (!$this->clients[$id]['handshake']) {
            $this->performHandshake($id);
            return;
        }

        while (($frame = $this->extractFrame($this->clients[$id]['buffer'])) !== null) {
            $this->handleFrame($id, $frame);
        }
    }

    private function performHandshake(int $id): void
    {
        $buffer = $this->clients[$id]['buffer'];

        if (!str_contains($buffer, "\r\n\r\n")) {
            return;
        }

        [$headersBlock, $rest] = explode("\r\n\r\n", $buffer, 2);
        $lines = explode("\r\n", $headersBlock);
        $requestLine = array_shift($lines);
        $headers = [];

        foreach ($lines as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }

            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }

        $key = $headers['sec-websocket-key'] ?? null;

        if ($requestLine === null || $key === null) {
            $this->disconnect($id);
            return;
        }

        $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        $response = "HTTP/1.1 101 Switching Protocols\r\n"
            . "Upgrade: websocket\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: {$accept}\r\n\r\n";

        fwrite($this->clients[$id]['socket'], $response);
        $this->clients[$id]['handshake'] = true;
        $this->clients[$id]['buffer'] = $rest;
    }

    private function handleFrame(int $id, array $frame): void
    {
        $opcode = $frame['opcode'];

        if ($opcode === 0x8) {
            $this->disconnect($id);
            return;
        }

        if ($opcode === 0xA) {
            $this->clients[$id]['last_pong'] = time();
            return;
        }

        if ($opcode === 0x9) {
            $this->sendFrame($this->clients[$id]['socket'], $frame['payload'], 0xA);
            return;
        }

        if ($opcode !== 0x1) {
            return;
        }

        $payload = json_decode($frame['payload'], true);

        if (!is_array($payload)) {
            $this->sendJson($id, ['type' => 'error', 'message' => 'Invalid realtime payload.']);
            return;
        }

        $type = $payload['type'] ?? null;

        if ($type === 'subscribe') {
            $this->handleSubscribe($id, $payload);
            return;
        }

        if ($type === 'message') {
            $this->handleClientMessage($id, $payload);
            return;
        }
    }

    private function handleSubscribe(int $id, array $payload): void
    {
        $tokenPayload = $this->manager->parseToken((string) ($payload['token'] ?? ''));

        if ($tokenPayload === null) {
            $this->manager->metrics()->increment('auth_denied');
            $this->sendJson($id, ['type' => 'error', 'message' => 'Invalid realtime token.']);
            return;
        }

        $channel = (string) ($tokenPayload['channel'] ?? '');
        $actor = $this->manager->actorFromToken($tokenPayload);
        $authorization = $this->manager->authorize($channel, $actor, runMiddleware: false);

        if (($authorization['allowed'] ?? false) !== true) {
            $this->manager->metrics()->increment('auth_denied');
            $this->sendJson($id, ['type' => 'error', 'message' => $authorization['error'] ?? 'Realtime authorization failed.']);
            return;
        }

        $this->clients[$id]['subscriptions'][$channel] = [
            'token' => $tokenPayload,
            'last_id' => null,
            'member' => $authorization['member'] ?? null,
        ];

        $this->sendJson($id, [
            'type' => 'subscribed',
            'channel' => $channel,
            'member' => $authorization['member'] ?? null,
        ]);
    }

    private function handleClientMessage(int $id, array $payload): void
    {
        $tokenPayload = $this->manager->parseToken((string) ($payload['token'] ?? ''));

        if ($tokenPayload === null) {
            $this->manager->metrics()->increment('auth_denied');
            $this->sendJson($id, ['type' => 'error', 'message' => 'Invalid realtime token.']);
            return;
        }

        $channel = (string) ($tokenPayload['channel'] ?? '');
        $event = (string) ($payload['event'] ?? '');
        $body = $payload['payload'] ?? null;

        if ($channel === '' || $event === '') {
            $this->sendJson($id, ['type' => 'error', 'message' => 'Realtime message requires channel and event.']);
            return;
        }

        try {
            $result = $this->manager->dispatchMessage($channel, $event, $body, $tokenPayload);

            $this->sendJson($id, [
                'type' => 'ack',
                'channel' => $channel,
                'event' => $event,
                'result' => $result,
            ]);
        } catch (Throwable $e) {
            $this->manager->metrics()->increment('errors');
            $this->sendJson($id, ['type' => 'error', 'message' => $e->getMessage()]);
        }
    }

    private function fanOut(): void
    {
        foreach ($this->clients as $id => $client) {
            foreach ($client['subscriptions'] as $channel => $subscription) {
                $events = $this->manager->broker()->readSince($channel, $subscription['last_id']);

                foreach ($events as $event) {
                    $this->clients[$id]['subscriptions'][$channel]['last_id'] = $event['id'];
                    $this->sendJson($id, [
                        'type' => 'event',
                        'channel' => $channel,
                        'envelope' => $event,
                    ]);
                }
            }
        }
    }

    private function maintainHeartbeats(): void
    {
        foreach (array_keys($this->clients) as $id) {
            $now = time();

            if (($now - (int) $this->clients[$id]['last_pong']) > 60) {
                $this->disconnect($id);
                continue;
            }

            if (($now - (int) $this->clients[$id]['last_ping']) >= 30) {
                $this->sendFrame($this->clients[$id]['socket'], 'ping', 0x9);
                $this->clients[$id]['last_ping'] = $now;
            }
        }
    }

    private function updateMetrics(): void
    {
        $channels = [];

        foreach ($this->clients as $client) {
            foreach (array_keys($client['subscriptions']) as $channel) {
                $channels[$channel] = true;
            }
        }

        $this->manager->metrics()->set('connections_open', count($this->clients));
        $this->manager->metrics()->set('channels_active', count($channels));
    }

    private function disconnect(int $id): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        fclose($this->clients[$id]['socket']);
        unset($this->clients[$id]);
    }

    private function sendJson(int $id, array $payload): void
    {
        if (!isset($this->clients[$id])) {
            return;
        }

        $this->sendFrame(
            $this->clients[$id]['socket'],
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            0x1
        );
    }

    private function sendFrame($socket, string $payload, int $opcode): void
    {
        $length = strlen($payload);
        $header = chr(0x80 | ($opcode & 0x0F));

        if ($length <= 125) {
            $header .= chr($length);
        } elseif ($length <= 65535) {
            $header .= chr(126) . pack('n', $length);
        } else {
            $header .= chr(127) . pack('NN', 0, $length);
        }

        fwrite($socket, $header . $payload);
    }

    private function extractFrame(string &$buffer): ?array
    {
        $length = strlen($buffer);

        if ($length < 2) {
            return null;
        }

        $first = ord($buffer[0]);
        $second = ord($buffer[1]);
        $masked = ($second & 0x80) === 0x80;
        $payloadLength = $second & 0x7F;
        $offset = 2;

        if ($payloadLength === 126) {
            if ($length < 4) {
                return null;
            }

            $payloadLength = unpack('n', substr($buffer, 2, 2))[1];
            $offset = 4;
        } elseif ($payloadLength === 127) {
            if ($length < 10) {
                return null;
            }

            $parts = unpack('N2', substr($buffer, 2, 8));
            $payloadLength = ($parts[1] << 32) | $parts[2];
            $offset = 10;
        }

        $maskingKeyLength = $masked ? 4 : 0;

        if ($length < ($offset + $maskingKeyLength + $payloadLength)) {
            return null;
        }

        $mask = $masked ? substr($buffer, $offset, 4) : '';
        $offset += $maskingKeyLength;
        $payload = substr($buffer, $offset, $payloadLength);
        $buffer = substr($buffer, $offset + $payloadLength);

        if ($masked) {
            $unmasked = '';

            for ($i = 0; $i < $payloadLength; $i++) {
                $unmasked .= $payload[$i] ^ $mask[$i % 4];
            }

            $payload = $unmasked;
        }

        return [
            'fin' => ($first & 0x80) === 0x80,
            'opcode' => $first & 0x0F,
            'payload' => $payload,
        ];
    }
}
