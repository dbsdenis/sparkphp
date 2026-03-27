<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class SparkCliDatabaseTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        parent::setUp();

        Database::reset();

        $this->basePath = sys_get_temp_dir() . '/sparkphp-cli-' . bin2hex(random_bytes(6));
        mkdir($this->basePath, 0777, true);

        $this->copyDirectory(__DIR__ . '/../../core', $this->basePath . '/core');
        copy(__DIR__ . '/../../spark', $this->basePath . '/spark');
        chmod($this->basePath . '/spark', 0755);

        file_put_contents($this->basePath . '/.env.example', <<<'ENV'
APP_NAME=SparkPHP
APP_ENV=dev
APP_KEY=change-me-to-a-random-secret-32-chars
DB=sqlite
DB_NAME=
ENV
        );

        $scaffolder = new ProjectScaffolder($this->basePath);
        $scaffolder->initialize();

        $databasePath = $this->basePath . '/database.sqlite';
        $env = <<<'ENV'
APP_NAME=SparkPHP
APP_ENV=dev
APP_KEY=test-key-1234567890123456789012
DB=sqlite
DB_NAME=%s
ENV;
        file_put_contents($this->basePath . '/.env', sprintf($env, $databasePath));
    }

    protected function tearDown(): void
    {
        Database::reset();
        $this->deleteDirectory($this->basePath);
        parent::tearDown();
    }

    public function testMakeCommandsGenerateClassBasedMigrationAndSeeder(): void
    {
        $makeMigration = $this->runSpark(['make:migration', 'create_posts_table']);
        $makeSeeder = $this->runSpark(['make:seeder', 'UserSeeder']);

        $migrationFiles = glob($this->basePath . '/database/migrations/*_create_posts_table.php') ?: [];

        $this->assertSame(0, $makeMigration['exit_code'], $makeMigration['output']);
        $this->assertSame(0, $makeSeeder['exit_code'], $makeSeeder['output']);
        $this->assertCount(1, $migrationFiles);
        $this->assertStringContainsString('extends Migration', (string) file_get_contents($migrationFiles[0]));
        $this->assertFileExists($this->basePath . '/database/seeds/UserSeeder.php');
        $this->assertStringContainsString('extends Seeder', (string) file_get_contents($this->basePath . '/database/seeds/UserSeeder.php'));
    }

    public function testMigrateSeedStatusRollbackAndFreshWorkEndToEnd(): void
    {
        file_put_contents($this->basePath . '/database/migrations/20260327010101_create_users_table.php', <<<'PHP'
<?php

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
PHP
        );

        file_put_contents($this->basePath . '/database/migrations/20260327010102_create_posts_table.php', <<<'PHP'
<?php

class CreatePostsTable extends Migration
{
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
}
PHP
        );

        file_put_contents($this->basePath . '/database/seeds/DatabaseSeeder.php', <<<'PHP'
<?php

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(UserSeeder::class);
    }
}
PHP
        );

        file_put_contents($this->basePath . '/database/seeds/UserSeeder.php', <<<'PHP'
<?php

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $count = (int) (db()->raw('SELECT COUNT(*) AS aggregate_count FROM users')[0]->aggregate_count ?? 0);

        db()->statement(
            'INSERT INTO users (name, email, created_at, updated_at) VALUES (?, ?, ?, ?)',
            ['Spark', 'spark' . ($count + 1) . '@example.com', '2026-03-27 00:00:00', '2026-03-27 00:00:00']
        );
    }
}
PHP
        );

        $migrate = $this->runSpark(['migrate', '--seed']);
        $statusAfterMigrate = $this->runSpark(['migrate:status']);
        $seedSingle = $this->runSpark(['seed', 'UserSeeder']);

        $pdo = $this->sqlitePdo();
        $userCountAfterSeed = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

        $rollback = $this->runSpark(['migrate:rollback', '1']);
        $statusAfterRollback = $this->runSpark(['migrate:status']);

        $fresh = $this->runSpark(['db:fresh', '--seed']);
        $userCountAfterFresh = (int) $this->sqlitePdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $migrationCount = (int) $this->sqlitePdo()->query('SELECT COUNT(*) FROM spark_migrations')->fetchColumn();

        $this->assertSame(0, $migrate['exit_code'], $migrate['output']);
        $this->assertStringContainsString('migration(s) complete', $migrate['output']);
        $this->assertStringContainsString('CreateUsersTable', $statusAfterMigrate['output']);
        $this->assertStringContainsString('CreatePostsTable', $statusAfterMigrate['output']);
        $this->assertSame(0, $seedSingle['exit_code'], $seedSingle['output']);
        $this->assertSame(2, $userCountAfterSeed);
        $this->assertSame(0, $rollback['exit_code'], $rollback['output']);
        $this->assertStringContainsString('Pending', $statusAfterRollback['output']);
        $this->assertSame(0, $fresh['exit_code'], $fresh['output']);
        $this->assertSame(1, $userCountAfterFresh);
        $this->assertSame(2, $migrationCount);
    }

    public function testLegacyMigrationsFailWithHelpfulMessage(): void
    {
        file_put_contents($this->basePath . '/database/migrations/20260327020202_legacy_users.php', <<<'PHP'
<?php

up(function () {
    db()->statement('CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT)');
});

down(function () {
    db()->statement('DROP TABLE IF EXISTS users');
});
PHP
        );

        $result = $this->runSpark(['migrate']);

        $this->assertNotSame(0, $result['exit_code']);
        $this->assertStringContainsString('Legacy migration format detected', $result['output']);
    }

    /**
     * @dataProvider externalDriverProvider
     */
    public function testExternalDriversCanRunMigrationsWhenConfigured(string $driver, array $config): void
    {
        foreach ($config as $key => $value) {
            if ($value === '') {
                $this->markTestSkipped("External {$driver} test is not configured.");
            }
        }

        file_put_contents($this->basePath . '/.env', sprintf(
            "APP_NAME=SparkPHP\nAPP_ENV=dev\nAPP_KEY=test-key-1234567890123456789012\nDB=%s\nDB_HOST=%s\nDB_PORT=%s\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\n",
            $driver,
            $config['host'],
            $config['port'],
            $config['database'],
            $config['user'],
            $config['pass']
        ));

        file_put_contents($this->basePath . '/database/migrations/20260327030303_create_users_table.php', <<<'PHP'
<?php

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
}
PHP
        );

        $pdo = $this->externalPdo($driver, $config);
        $this->dropExternalTables($driver, $pdo, ['spark_migrations', 'users']);

        $migrate = $this->runSpark(['migrate']);
        $status = $this->runSpark(['migrate:status']);

        $this->assertSame(0, $migrate['exit_code'], $migrate['output']);
        $this->assertStringContainsString('CreateUsersTable', $status['output']);

        $this->dropExternalTables($driver, $pdo, ['spark_migrations', 'users']);
    }

    public static function externalDriverProvider(): array
    {
        return [
            'mysql' => [
                'mysql',
                [
                    'host' => (string) getenv('SPARK_TEST_MYSQL_HOST'),
                    'port' => (string) getenv('SPARK_TEST_MYSQL_PORT'),
                    'database' => (string) getenv('SPARK_TEST_MYSQL_DATABASE'),
                    'user' => (string) getenv('SPARK_TEST_MYSQL_USER'),
                    'pass' => (string) getenv('SPARK_TEST_MYSQL_PASS'),
                ],
            ],
            'pgsql' => [
                'pgsql',
                [
                    'host' => (string) getenv('SPARK_TEST_PGSQL_HOST'),
                    'port' => (string) getenv('SPARK_TEST_PGSQL_PORT'),
                    'database' => (string) getenv('SPARK_TEST_PGSQL_DATABASE'),
                    'user' => (string) getenv('SPARK_TEST_PGSQL_USER'),
                    'pass' => (string) getenv('SPARK_TEST_PGSQL_PASS'),
                ],
            ],
        ];
    }

    private function sqlitePdo(): PDO
    {
        return new PDO('sqlite:' . $this->basePath . '/database.sqlite');
    }

    private function externalPdo(string $driver, array $config): PDO
    {
        $dsn = match ($driver) {
            'mysql' => sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%s;dbname=%s',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            default => throw new InvalidArgumentException("Unsupported driver [{$driver}]"),
        };

        return new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function dropExternalTables(string $driver, PDO $pdo, array $tables): void
    {
        foreach ($tables as $table) {
            if ($driver === 'mysql') {
                $pdo->exec("DROP TABLE IF EXISTS `{$table}`");
                continue;
            }

            $pdo->exec("DROP TABLE IF EXISTS \"{$table}\" CASCADE");
        }
    }

    private function runSpark(array $args): array
    {
        $command = array_merge(['php', 'spark'], $args);
        $descriptors = [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptors, $pipes, $this->basePath);
        if (!is_resource($process)) {
            $this->fail('Unable to start spark process.');
        }

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

    private function copyDirectory(string $source, string $destination): void
    {
        mkdir($destination, 0777, true);

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($items as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $items->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target)) {
                    mkdir($target, 0777, true);
                }
                continue;
            }

            copy($item->getPathname(), $target);
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
