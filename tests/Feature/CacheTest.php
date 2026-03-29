<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $basePath;
    private Cache $cache;

    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['CACHE'] = 'memory';
        $this->basePath = sys_get_temp_dir() . '/sparkphp-cache-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/cache/app', 0777, true);
        $this->cache = new Cache($this->basePath);
        $this->cache->flush();
    }

    protected function tearDown(): void
    {
        $this->cache->flush();
        foreach (glob($this->basePath . '/storage/cache/app/tags/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->basePath . '/storage/cache/app/tags');
        @rmdir($this->basePath . '/storage/cache/app');
        @rmdir($this->basePath . '/storage/cache');
        @rmdir($this->basePath . '/storage');
        @rmdir($this->basePath);
        parent::tearDown();
    }

    public function testSetAndGet(): void
    {
        $this->cache->set('key', 'value');
        $this->assertSame('value', $this->cache->get('key'));
    }

    public function testGetReturnsDefaultWhenMissing(): void
    {
        $this->assertSame('fallback', $this->cache->get('missing', 'fallback'));
    }

    public function testForgetRemovesKey(): void
    {
        $this->cache->set('key', 'value');
        $this->cache->forget('key');
        $this->assertNull($this->cache->get('key'));
    }

    public function testHas(): void
    {
        $this->cache->set('key', 'value');
        $this->assertTrue($this->cache->has('key'));
        $this->assertFalse($this->cache->has('missing'));
    }

    public function testIncrement(): void
    {
        $this->cache->set('counter', 5);
        $result = $this->cache->increment('counter', 3);
        $this->assertSame(8, $result);
    }

    public function testDecrement(): void
    {
        $this->cache->set('counter', 10);
        $result = $this->cache->decrement('counter', 4);
        $this->assertSame(6, $result);
    }

    public function testIncrementFromZeroWhenKeyMissing(): void
    {
        $result = $this->cache->increment('newkey');
        $this->assertSame(1, $result);
    }

    public function testRemember(): void
    {
        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;
            return 'computed';
        };

        $first = $this->cache->remember('key', 3600, $callback);
        $second = $this->cache->remember('key', 3600, $callback);

        $this->assertSame('computed', $first);
        $this->assertSame('computed', $second);
        $this->assertSame(1, $callCount); // callback called only once
    }

    public function testFlush(): void
    {
        $this->cache->set('a', 1);
        $this->cache->set('b', 2);
        $this->cache->flush();

        $this->assertNull($this->cache->get('a'));
        $this->assertNull($this->cache->get('b'));
    }

    public function testStoresArraysAndObjects(): void
    {
        $this->cache->set('arr', ['foo' => 'bar']);
        $this->assertSame(['foo' => 'bar'], $this->cache->get('arr'));
    }

    public function testTouchExtendsTtlWithoutChangingStoredValue(): void
    {
        $_ENV['CACHE'] = 'file';
        $cache = new Cache($this->basePath);

        $cache->set('token', 'abc123', 60);

        $path = $this->basePath . '/storage/cache/app/' . md5('token') . '.cache';
        $before = unserialize((string) file_get_contents($path));

        $this->assertTrue($cache->touch('token', 180));

        $after = unserialize((string) file_get_contents($path));

        $this->assertSame('abc123', $cache->get('token'));
        $this->assertSame('abc123', $after['value']);
        $this->assertSame(180, $after['ttl']);
        $this->assertGreaterThan($before['expires_at'], $after['expires_at']);
    }

    public function testTaggedCacheNamespacesKeysAndCanFlushByTag(): void
    {
        $users = $this->cache->tags(['users']);
        $posts = $this->cache->tags(['posts']);

        $users->set('list', ['alice'], 300);
        $posts->set('list', ['post-1'], 300);

        $this->assertSame(['alice'], $users->get('list'));
        $this->assertSame(['post-1'], $posts->get('list'));

        $removed = $this->cache->flushTags('users');

        $this->assertSame(1, $removed);
        $this->assertNull($users->get('list'));
        $this->assertSame(['post-1'], $posts->get('list'));
    }

    public function testFlexibleReturnsStaleValueAndRefreshesOnDeferredPass(): void
    {
        $calls = 0;

        $first = $this->cache->flexible('report', [1, 3], function () use (&$calls) {
            $calls++;
            return 'fresh-' . $calls;
        });

        sleep(2);

        $stale = $this->cache->flexible('report', [1, 3], function () use (&$calls) {
            $calls++;
            return 'fresh-' . $calls;
        });

        $this->assertSame('fresh-1', $first);
        $this->assertSame('fresh-1', $stale);
        $this->assertSame(1, $calls);

        Cache::runDeferredRefreshes();

        $this->assertSame(2, $calls);
        $this->assertSame('fresh-2', $this->cache->get('report'));
    }

    public function testMetricsTrackHitsMissesWritesAndRefreshLifecycle(): void
    {
        $this->cache->set('users', ['id' => 1], 60);
        $this->cache->get('users');
        $this->cache->get('missing');
        $this->cache->touch('users', 120);

        $metrics = $this->cache->metrics();

        $this->assertSame('memory', $metrics['driver']);
        $this->assertSame(2, $metrics['gets']);
        $this->assertSame(1, $metrics['hits']);
        $this->assertSame(1, $metrics['misses']);
        $this->assertSame(1, $metrics['sets']);
        $this->assertSame(1, $metrics['touches']);
    }
}
