<?php

class TaggedCache
{
    private Cache $store;
    private array $tags;

    public function __construct(Cache $store, string|array $tags)
    {
        $this->store = $store;
        $this->tags = $this->normalizeTags($tags);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->store->get($this->qualify($key), $default);
    }

    public function set(string $key, mixed $value, int $ttl = 0): void
    {
        $this->store->set($this->qualify($key), $value, $ttl, [
            'tags' => $this->tags,
            'logical_key' => $key,
        ]);
    }

    public function remember(string $key, int $ttl, callable $callback): mixed
    {
        return $this->store->remember($this->qualify($key), $ttl, $callback, [
            'tags' => $this->tags,
            'logical_key' => $key,
        ]);
    }

    public function flexible(string $key, array $ttl, callable $callback): mixed
    {
        return $this->store->flexible($this->qualify($key), $ttl, $callback, [
            'tags' => $this->tags,
            'logical_key' => $key,
        ]);
    }

    public function has(string $key): bool
    {
        return $this->store->has($this->qualify($key));
    }

    public function touch(string $key, int $ttl): bool
    {
        return $this->store->touch($this->qualify($key), $ttl);
    }

    public function forget(string $key): void
    {
        $this->store->forget($this->qualify($key));
    }

    public function flush(): int
    {
        return $this->store->flushTags($this->tags);
    }

    public function increment(string $key, int $by = 1): int
    {
        return $this->store->increment($this->qualify($key), $by);
    }

    public function decrement(string $key, int $by = 1): int
    {
        return $this->store->decrement($this->qualify($key), $by);
    }

    private function qualify(string $key): string
    {
        return sprintf('tagged:%s:%s', sha1(implode('|', $this->tags)), $key);
    }

    private function normalizeTags(string|array $tags): array
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $tags = array_values(array_filter(array_map('trim', $tags), static fn(string $tag): bool => $tag !== ''));
        sort($tags);

        return array_values(array_unique($tags));
    }
}

class Cache
{
    private static array $memory = [];
    private static array $memoryTags = [];
    private static array $deferredRefreshes = [];
    private static array $deferredRefreshKeys = [];
    private static bool $shutdownRegistered = false;

    private string $basePath;
    private string $driver;
    private string $cacheDir;
    private string $tagDir;

    private array $metrics = [
        'gets' => 0,
        'hits' => 0,
        'misses' => 0,
        'stale_hits' => 0,
        'sets' => 0,
        'touches' => 0,
        'forgets' => 0,
        'flushes' => 0,
        'tag_flushes' => 0,
        'increments' => 0,
        'decrements' => 0,
        'refresh_queued' => 0,
        'refresh_executed' => 0,
    ];

    public function __construct(string $basePath)
    {
        $this->basePath = $basePath;
        $this->driver = $_ENV['CACHE'] ?? 'file';
        $this->cacheDir = $basePath . '/storage/cache/app';
        $this->tagDir = $this->cacheDir . '/tags';

        if ($this->driver === 'file' && !is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0755, true);
        }

        if ($this->driver === 'file' && !is_dir($this->tagDir)) {
            mkdir($this->tagDir, 0755, true);
        }

        $this->registerDeferredHandler();
    }

    public static function runDeferredRefreshes(): void
    {
        while ($job = array_shift(self::$deferredRefreshes)) {
            $key = $job['key'];
            unset(self::$deferredRefreshKeys[$key]);

            try {
                ($job['callback'])();
            } catch (\Throwable) {
                // Stale-while-revalidate should never break the main request.
            }
        }
    }

    public function driver(): string
    {
        return $this->driver;
    }

    public function metrics(): array
    {
        return $this->metrics + [
            'driver' => $this->driver,
        ];
    }

    public function tags(string|array $tags): TaggedCache
    {
        return new TaggedCache($this, $tags);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $resolved = $this->readItemState($key, false);
        $state = $resolved['state'];

        $this->metrics['gets']++;
        if ($state === 'fresh') {
            $this->metrics['hits']++;
        } else {
            $this->metrics['misses']++;
        }

        $entry = $resolved['item'] ?? [];

        $this->record('get', $entry['logical_key'] ?? $key, [
            'driver' => $this->driver,
            'hit' => $state === 'fresh',
            'miss' => $state !== 'fresh',
            'stale' => $state === 'stale',
            'state' => $state,
            'tags' => $entry['tags'] ?? [],
        ]);

        if ($state === 'fresh') {
            return $entry['value'];
        }

        return $default;
    }

    public function set(string $key, mixed $value, int $ttl = 0, array $options = []): void
    {
        $existing = $this->readRawEntry($key);
        $entry = $this->buildEntry($key, $value, $ttl, $options);

        $this->writeEntry($key, $entry);
        $this->syncTags($key, $existing['tags'] ?? [], $entry['tags']);

        $this->metrics['sets']++;
        $this->record('set', $entry['logical_key'], [
            'driver' => $this->driver,
            'ttl' => $ttl,
            'stale_ttl' => $entry['stale_ttl'],
            'tags' => $entry['tags'],
        ]);
    }

    public function forget(string $key): void
    {
        $existing = $this->readRawEntry($key);
        if ($existing === null) {
            $this->record('forget', $key, [
                'driver' => $this->driver,
                'removed' => false,
            ]);
            return;
        }

        $this->deleteEntry($key, $existing);

        $this->metrics['forgets']++;
        $this->record('forget', $existing['logical_key'] ?? $key, [
            'driver' => $this->driver,
            'removed' => true,
            'tags' => $existing['tags'] ?? [],
        ]);
    }

    public function flush(): void
    {
        $removed = match ($this->driver) {
            'memory' => $this->memFlush(),
            default => $this->fileFlush(),
        };

        $this->metrics['flushes']++;
        $this->record('flush', null, [
            'driver' => $this->driver,
            'removed' => $removed,
        ]);
    }

    public function flushTags(string|array $tags): int
    {
        $tags = $this->normalizeTags($tags);
        if ($tags === []) {
            return 0;
        }

        $keys = [];
        foreach ($tags as $tag) {
            foreach ($this->readTagIndex($tag) as $key) {
                $keys[$key] = true;
            }
        }

        foreach (array_keys($keys) as $key) {
            $existing = $this->readRawEntry($key);
            if ($existing !== null) {
                $this->deleteEntry($key, $existing);
            }
        }

        $removed = count($keys);
        $this->metrics['tag_flushes']++;
        $this->record('flush_tags', null, [
            'driver' => $this->driver,
            'tags' => $tags,
            'removed' => $removed,
        ]);

        return $removed;
    }

    public function has(string $key): bool
    {
        return $this->readItemState($key, false)['state'] === 'fresh';
    }

    public function increment(string $key, int $by = 1): int
    {
        $resolved = $this->readItemState($key, true);
        $entry = $resolved['item'] ?? null;
        $current = (int) (($entry['value'] ?? 0));
        $newValue = $current + $by;

        [$ttl, $options] = $this->optionsFromExistingEntry($key, $entry);
        $this->set($key, $newValue, $ttl, $options);

        $this->metrics['increments']++;
        $this->record('increment', $options['logical_key'] ?? $key, [
            'driver' => $this->driver,
            'by' => $by,
            'value' => $newValue,
            'tags' => $options['tags'] ?? [],
        ]);

        return $newValue;
    }

    public function decrement(string $key, int $by = 1): int
    {
        $value = $this->increment($key, -$by);

        $this->metrics['decrements']++;
        $this->record('decrement', $key, [
            'driver' => $this->driver,
            'by' => $by,
            'value' => $value,
        ]);

        return $value;
    }

    public function touch(string $key, int $ttl): bool
    {
        $entry = $this->readRawEntry($key);
        if ($entry === null) {
            $this->record('touch', $key, [
                'driver' => $this->driver,
                'touched' => false,
                'ttl' => $ttl,
            ]);
            return false;
        }

        $staleBuffer = max(0, (int) ($entry['stale_expires_at'] ?? 0) - (int) ($entry['expires_at'] ?? 0));
        $now = time();

        $entry['ttl'] = max(0, $ttl);
        $entry['expires_at'] = $ttl > 0 ? $now + $ttl : 0;
        $entry['stale_ttl'] = $ttl > 0 ? $ttl + $staleBuffer : 0;
        $entry['stale_expires_at'] = $ttl > 0 ? $now + $entry['stale_ttl'] : 0;
        $entry['updated_at'] = $now;

        $this->writeEntry($key, $entry);

        $this->metrics['touches']++;
        $this->record('touch', $entry['logical_key'] ?? $key, [
            'driver' => $this->driver,
            'touched' => true,
            'ttl' => $ttl,
            'stale_ttl' => $entry['stale_ttl'],
            'tags' => $entry['tags'] ?? [],
        ]);

        return true;
    }

    public function expire(string $key, int $ttl): bool
    {
        return $this->touch($key, $ttl);
    }

    public function remember(string $key, int $ttl, callable $callback, array $options = []): mixed
    {
        $value = $this->get($key);
        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl, $options);

        return $value;
    }

    public function flexible(string $key, array $ttl, callable $callback, array $options = []): mixed
    {
        [$freshTtl, $staleTtl] = $this->normalizeFlexibleTtl($ttl);
        $resolved = $this->readItemState($key, true);
        $entry = $resolved['item'] ?? null;
        $state = $resolved['state'];

        if ($state === 'fresh') {
            $this->metrics['gets']++;
            $this->metrics['hits']++;
            $this->record('flexible', $entry['logical_key'] ?? $key, [
                'driver' => $this->driver,
                'hit' => true,
                'stale' => false,
                'ttl' => $freshTtl,
                'stale_ttl' => $staleTtl,
                'tags' => $entry['tags'] ?? ($options['tags'] ?? []),
            ]);

            return $entry['value'];
        }

        if ($state === 'stale') {
            $this->metrics['gets']++;
            $this->metrics['hits']++;
            $this->metrics['stale_hits']++;

            $logicalKey = $entry['logical_key'] ?? ($options['logical_key'] ?? $key);
            $this->record('flexible', $logicalKey, [
                'driver' => $this->driver,
                'hit' => true,
                'stale' => true,
                'ttl' => $freshTtl,
                'stale_ttl' => $staleTtl,
                'tags' => $entry['tags'] ?? ($options['tags'] ?? []),
            ]);

            $this->queueDeferredRefresh($key, function () use ($key, $callback, $freshTtl, $staleTtl, $options): void {
                $value = $callback();
                $this->set($key, $value, $freshTtl, $options + ['stale_ttl' => $staleTtl]);
                $this->metrics['refresh_executed']++;
                $this->record('refresh', $options['logical_key'] ?? $key, [
                    'driver' => $this->driver,
                    'ttl' => $freshTtl,
                    'stale_ttl' => $staleTtl,
                    'tags' => $options['tags'] ?? [],
                ]);
            }, $options);

            return $entry['value'];
        }

        $this->metrics['gets']++;
        $this->metrics['misses']++;

        $value = $callback();
        $this->set($key, $value, $freshTtl, $options + ['stale_ttl' => $staleTtl]);

        $this->record('flexible', $options['logical_key'] ?? $key, [
            'driver' => $this->driver,
            'hit' => false,
            'miss' => true,
            'stale' => false,
            'ttl' => $freshTtl,
            'stale_ttl' => $staleTtl,
            'tags' => $options['tags'] ?? [],
        ]);

        return $value;
    }

    private function buildEntry(string $key, mixed $value, int $ttl, array $options): array
    {
        $ttl = max(0, $ttl);
        $staleTtl = max($ttl, (int) ($options['stale_ttl'] ?? $ttl));
        $tags = $this->normalizeTags($options['tags'] ?? []);
        $now = time();

        return [
            'value' => $value,
            'ttl' => $ttl,
            'expires_at' => $ttl > 0 ? $now + $ttl : 0,
            'stale_ttl' => $ttl > 0 ? $staleTtl : 0,
            'stale_expires_at' => $ttl > 0 ? $now + $staleTtl : 0,
            'tags' => $tags,
            'logical_key' => (string) ($options['logical_key'] ?? $key),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function readItemState(string $key, bool $allowStale): array
    {
        $entry = $this->readRawEntry($key);
        if ($entry === null) {
            return ['state' => 'missing', 'item' => null];
        }

        $now = time();
        $expiresAt = (int) ($entry['expires_at'] ?? 0);
        $staleExpiresAt = (int) ($entry['stale_expires_at'] ?? $expiresAt);

        if ($expiresAt === 0 || $expiresAt > $now) {
            return ['state' => 'fresh', 'item' => $entry];
        }

        if ($staleExpiresAt > $now) {
            return ['state' => $allowStale ? 'stale' : 'stale_hidden', 'item' => $entry];
        }

        $this->deleteEntry($key, $entry);

        return ['state' => 'expired', 'item' => $entry];
    }

    private function optionsFromExistingEntry(string $key, ?array $entry): array
    {
        if ($entry === null) {
            return [0, ['logical_key' => $key]];
        }

        $ttl = $this->remainingTtl((int) ($entry['expires_at'] ?? 0));
        $staleTtl = $this->remainingTtl((int) ($entry['stale_expires_at'] ?? 0));

        $options = [
            'logical_key' => $entry['logical_key'] ?? $key,
            'tags' => $entry['tags'] ?? [],
        ];

        if ($staleTtl > $ttl) {
            $options['stale_ttl'] = $staleTtl;
        }

        return [$ttl, $options];
    }

    private function remainingTtl(int $expiresAt): int
    {
        if ($expiresAt <= 0) {
            return 0;
        }

        return max(0, $expiresAt - time());
    }

    private function normalizeFlexibleTtl(array $ttl): array
    {
        $fresh = max(0, (int) ($ttl[0] ?? 0));
        $stale = max($fresh, (int) ($ttl[1] ?? $fresh));

        return [$fresh, $stale];
    }

    private function normalizeTags(string|array $tags): array
    {
        $tags = is_array($tags) ? $tags : [$tags];
        $tags = array_values(array_filter(array_map(static fn(mixed $tag): string => trim((string) $tag), $tags), static fn(string $tag): bool => $tag !== ''));
        sort($tags);

        return array_values(array_unique($tags));
    }

    private function queueDeferredRefresh(string $key, callable $callback, array $options = []): void
    {
        if (isset(self::$deferredRefreshKeys[$key])) {
            return;
        }

        self::$deferredRefreshKeys[$key] = true;
        self::$deferredRefreshes[] = [
            'key' => $key,
            'callback' => $callback,
        ];

        $this->metrics['refresh_queued']++;
        $this->record('refresh_queued', $options['logical_key'] ?? $key, [
            'driver' => $this->driver,
            'tags' => $options['tags'] ?? [],
        ]);
    }

    private function registerDeferredHandler(): void
    {
        if (self::$shutdownRegistered) {
            return;
        }

        register_shutdown_function(static function (): void {
            Cache::runDeferredRefreshes();
        });

        self::$shutdownRegistered = true;
    }

    private function record(string $operation, ?string $key, array $meta = []): void
    {
        if (!class_exists('SparkInspector')) {
            return;
        }

        SparkInspector::recordCache($operation, $key, $meta);
    }

    private function syncTags(string $key, array $oldTags, array $newTags): void
    {
        $oldTags = $this->normalizeTags($oldTags);
        $newTags = $this->normalizeTags($newTags);

        foreach (array_diff($oldTags, $newTags) as $tag) {
            $this->removeFromTag($tag, $key);
        }

        foreach (array_diff($newTags, $oldTags) as $tag) {
            $this->addToTag($tag, $key);
        }
    }

    private function deleteEntry(string $key, array $entry): void
    {
        match ($this->driver) {
            'memory' => $this->memForget($key),
            default => $this->fileForget($key),
        };

        $this->syncTags($key, $entry['tags'] ?? [], []);
    }

    private function readRawEntry(string $key): ?array
    {
        $raw = match ($this->driver) {
            'memory' => $this->memReadRaw($key),
            default => $this->fileReadRaw($key),
        };

        return $this->normalizeEntry($key, $raw);
    }

    private function writeEntry(string $key, array $entry): void
    {
        match ($this->driver) {
            'memory' => $this->memWrite($key, $entry),
            default => $this->fileWrite($key, $entry),
        };
    }

    private function normalizeEntry(string $key, mixed $raw): ?array
    {
        if (!is_array($raw) || !array_key_exists('value', $raw)) {
            return null;
        }

        $ttl = max(0, (int) ($raw['ttl'] ?? 0));
        $expiresAt = max(0, (int) ($raw['expires_at'] ?? 0));
        $staleTtl = max($ttl, (int) ($raw['stale_ttl'] ?? $ttl));
        $staleExpiresAt = max($expiresAt, (int) ($raw['stale_expires_at'] ?? $expiresAt));

        if ($ttl === 0) {
            $staleTtl = 0;
            $staleExpiresAt = 0;
        }

        return [
            'value' => $raw['value'],
            'ttl' => $ttl,
            'expires_at' => $expiresAt,
            'stale_ttl' => $staleTtl,
            'stale_expires_at' => $staleExpiresAt,
            'tags' => $this->normalizeTags($raw['tags'] ?? []),
            'logical_key' => (string) ($raw['logical_key'] ?? $key),
            'created_at' => (int) ($raw['created_at'] ?? time()),
            'updated_at' => (int) ($raw['updated_at'] ?? time()),
        ];
    }

    private function addToTag(string $tag, string $key): void
    {
        match ($this->driver) {
            'memory' => self::$memoryTags[$tag][$key] = true,
            default => $this->fileAddToTag($tag, $key),
        };
    }

    private function removeFromTag(string $tag, string $key): void
    {
        if ($this->driver === 'memory') {
            unset(self::$memoryTags[$tag][$key]);
        } else {
            $this->fileRemoveFromTag($tag, $key);
        }

        if ($this->driver === 'memory' && empty(self::$memoryTags[$tag])) {
            unset(self::$memoryTags[$tag]);
        }
    }

    private function readTagIndex(string $tag): array
    {
        return match ($this->driver) {
            'memory' => array_keys(self::$memoryTags[$tag] ?? []),
            default => $this->fileReadTagIndex($tag),
        };
    }

    private function filePath(string $key): string
    {
        return $this->cacheDir . '/' . md5($key) . '.cache';
    }

    private function tagPath(string $tag): string
    {
        return $this->tagDir . '/' . md5($tag) . '.tag';
    }

    private function fileReadRaw(string $key): mixed
    {
        $path = $this->filePath($key);
        if (!file_exists($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        return unserialize($contents);
    }

    private function fileWrite(string $key, array $entry): void
    {
        file_put_contents($this->filePath($key), serialize($entry), LOCK_EX);
    }

    private function fileForget(string $key): void
    {
        $path = $this->filePath($key);
        if (file_exists($path)) {
            unlink($path);
        }
    }

    private function fileFlush(): int
    {
        $removed = 0;

        foreach (glob($this->cacheDir . '/*.cache') ?: [] as $file) {
            unlink($file);
            $removed++;
        }

        foreach (glob($this->tagDir . '/*.tag') ?: [] as $file) {
            unlink($file);
        }

        return $removed;
    }

    private function fileReadTagIndex(string $tag): array
    {
        $path = $this->tagPath($tag);
        if (!file_exists($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $decoded), static fn(string $key): bool => $key !== '')));
    }

    private function fileWriteTagIndex(string $tag, array $keys): void
    {
        file_put_contents($this->tagPath($tag), json_encode(array_values($keys), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
    }

    private function fileAddToTag(string $tag, string $key): void
    {
        $keys = $this->fileReadTagIndex($tag);
        if (!in_array($key, $keys, true)) {
            $keys[] = $key;
            $this->fileWriteTagIndex($tag, $keys);
        }
    }

    private function fileRemoveFromTag(string $tag, string $key): void
    {
        $keys = array_values(array_filter(
            $this->fileReadTagIndex($tag),
            static fn(string $existing): bool => $existing !== $key
        ));

        $path = $this->tagPath($tag);
        if ($keys === []) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }

        $this->fileWriteTagIndex($tag, $keys);
    }

    private function memReadRaw(string $key): mixed
    {
        return self::$memory[$key] ?? null;
    }

    private function memWrite(string $key, array $entry): void
    {
        self::$memory[$key] = $entry;
    }

    private function memForget(string $key): void
    {
        unset(self::$memory[$key]);
    }

    private function memFlush(): int
    {
        $count = count(self::$memory);
        self::$memory = [];
        self::$memoryTags = [];

        return $count;
    }
}
