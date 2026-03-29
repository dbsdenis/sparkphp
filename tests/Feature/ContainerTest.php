<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    private array $serverBackup = [];
    private array $getBackup = [];
    private array $postBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->serverBackup = $_SERVER;
        $this->getBackup = $_GET;
        $this->postBackup = $_POST;

        $_SERVER = [];
        $_GET = [];
        $_POST = [];
        BindingUserModel::$lastResolvedValue = null;
        BindingPostModel::$lastResolvedValue = null;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;
        $_GET = $this->getBackup;
        $_POST = $this->postBackup;

        parent::tearDown();
    }

    public function testSingletonReturnsSameInstance(): void
    {
        $container = new Container();
        $container->singleton(ExampleService::class, fn() => new ExampleService('one'));

        $first = $container->make(ExampleService::class);
        $second = $container->make(ExampleService::class);

        $this->assertSame($first, $second);
    }

    public function testBindReturnsNewInstanceEachCall(): void
    {
        $container = new Container();
        $container->bind(ExampleService::class, fn() => new ExampleService('new'));

        $first = $container->make(ExampleService::class);
        $second = $container->make(ExampleService::class);

        $this->assertNotSame($first, $second);
    }

    public function testBuildAutowiresClassDependencies(): void
    {
        $container = new Container();
        $container->singleton(ExampleService::class, fn() => new ExampleService('wired'));

        $consumer = $container->make(ConsumerService::class);

        $this->assertInstanceOf(ConsumerService::class, $consumer);
        $this->assertSame('wired', $consumer->service->name);
    }

    public function testCallResolvesPrimitiveFromRequestInput(): void
    {
        $_GET['page'] = '5';

        $container = new Container();

        $result = $container->call(fn(int $page) => $page + 1);

        $this->assertSame(6, $result);
    }

    public function testCallUsesNamedExtrasForRouteParams(): void
    {
        $container = new Container();

        $result = $container->call(fn(string $id) => 'user-' . $id, ['id' => '42']);

        $this->assertSame('user-42', $result);
    }

    public function testCallRouteResolvesModelBindingFromIdParam(): void
    {
        $container = new Container();

        $userId = $container->callRoute(
            fn(BindingUserModel $user) => $user->id,
            ['id' => '42']
        );

        $this->assertSame('42', $userId);
        $this->assertSame('42', BindingUserModel::$lastResolvedValue);
    }

    public function testCallRouteSeparatesModelBindingFromServiceResolution(): void
    {
        $container = new Container();
        $container->singleton(ExampleService::class, fn() => new ExampleService('route-service'));

        $result = $container->callRoute(
            fn(BindingPostModel $post, ExampleService $service) => [$post->id, $service->name],
            ['postId' => '55']
        );

        $this->assertSame(['55', 'route-service'], $result);
        $this->assertSame('55', BindingPostModel::$lastResolvedValue);
    }

    public function testMakeThrowsForUnresolvableAbstract(): void
    {
        $container = new Container();

        $this->expectException(RuntimeException::class);
        $container->make('DefinitelyMissingClass');
    }
}

final class ExampleService
{
    public function __construct(public string $name)
    {
    }
}

final class ConsumerService
{
    public function __construct(public ExampleService $service)
    {
    }
}

final class BindingUserModel extends Model
{
    protected string $table = 'users';
    protected array $guarded = [];
    protected bool $timestamps = false;

    public static ?string $lastResolvedValue = null;

    public static function resolveRouteBinding(mixed $value): static
    {
        static::$lastResolvedValue = (string) $value;

        $model = new static();
        $model->setAttribute('id', $value);

        return $model;
    }
}

final class BindingPostModel extends Model
{
    protected string $table = 'posts';
    protected array $guarded = [];
    protected bool $timestamps = false;

    public static ?string $lastResolvedValue = null;

    public static function resolveRouteBinding(mixed $value): static
    {
        static::$lastResolvedValue = (string) $value;

        $model = new static();
        $model->setAttribute('id', $value);

        return $model;
    }
}
