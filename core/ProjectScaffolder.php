<?php

class ProjectScaffolder
{
    private const PROJECT_TEMPLATE_FILES = [
        '.env.example',
        '.gitignore',
        'composer.json',
        'composer.lock',
        'phpunit.xml',
        'spark',
        'VERSION',
        'CHANGELOG.md',
        '01-spark-template.md',
        '02-estrutura-framework.md',
        '03-core-engine.md',
        '04-identidade-filosofia.md',
    ];

    private const PROJECT_TEMPLATE_DIRECTORIES = [
        'app',
        'core',
        'database',
        'docs',
        'public',
        'tests',
    ];

    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    public function initialize(bool $force = false): array
    {
        $messages = [];

        $createdEnv = $this->ensureEnvFile();
        if ($createdEnv) {
            $messages[] = '.env created from .env.example';
        }

        $keyState = $this->ensureAppKey($force);
        if ($keyState === 'generated') {
            $messages[] = 'APP_KEY generated';
        } elseif ($keyState === 'regenerated') {
            $messages[] = 'APP_KEY regenerated';
        }

        $createdDirs = $this->ensureDirectories();
        if ($createdDirs > 0) {
            $messages[] = "{$createdDirs} project directories prepared";
        }

        if ($this->ensureDatabaseSeeder()) {
            $messages[] = 'DatabaseSeeder scaffolded';
        }

        $removedArtifacts = $this->clearRuntimeArtifacts();
        if ($removedArtifacts > 0) {
            $messages[] = "{$removedArtifacts} runtime artifacts cleared";
        }

        if ($messages === []) {
            $messages[] = 'project already initialized';
        }

        return ['messages' => $messages];
    }

    public function createProject(string $targetPath, bool $force = false, bool $initialize = true): array
    {
        $targetPath = rtrim($targetPath, '/\\');
        if ($targetPath === '') {
            throw new RuntimeException('Project target path cannot be empty.');
        }

        $targetExists = is_dir($targetPath);
        if ($targetExists && !$force && !$this->isDirectoryEmpty($targetPath)) {
            throw new RuntimeException("Target directory [{$targetPath}] is not empty. Use --force to overwrite the scaffold.");
        }

        if (!$targetExists && !mkdir($targetPath, 0755, true) && !is_dir($targetPath)) {
            throw new RuntimeException("Unable to create target directory [{$targetPath}].");
        }

        $copiedFiles = 0;
        $copiedDirectories = 0;

        foreach (self::PROJECT_TEMPLATE_FILES as $relativePath) {
            $source = $this->basePath . '/' . $relativePath;
            if (!is_file($source)) {
                continue;
            }

            $this->copyFile($source, $targetPath . '/' . $relativePath);
            $copiedFiles++;
        }

        foreach (self::PROJECT_TEMPLATE_DIRECTORIES as $relativePath) {
            $source = $this->basePath . '/' . $relativePath;
            if (!is_dir($source)) {
                continue;
            }

            $this->copyDirectory($source, $targetPath . '/' . $relativePath);
            $copiedDirectories++;
        }

        $messages = [
            "{$copiedFiles} root file(s) copied",
            "{$copiedDirectories} directory tree(s) copied",
        ];

        if (is_file($targetPath . '/spark')) {
            @chmod($targetPath . '/spark', 0755);
        }

        if ($initialize) {
            $result = (new self($targetPath))->initialize(false);
            $messages = array_merge($messages, $result['messages']);
        }

        return [
            'target' => $targetPath,
            'copied_files' => $copiedFiles,
            'copied_directories' => $copiedDirectories,
            'initialized' => $initialize,
            'messages' => $messages,
        ];
    }

    public function audit(): array
    {
        require_once __DIR__ . '/Version.php';

        $missingDirectories = [];
        foreach ($this->directoryManifest() as $directory) {
            if (!is_dir($this->basePath . '/' . $directory)) {
                $missingDirectories[] = $directory;
            }
        }

        $missingFiles = [];
        foreach ($this->requiredFilesForUpgrade() as $relativePath) {
            if (!file_exists($this->basePath . '/' . $relativePath)) {
                $missingFiles[] = $relativePath;
            }
        }

        $envAudit = $this->envAudit();
        $appKeyStatus = $this->appKeyStatus();
        $ready = $missingDirectories === []
            && $missingFiles === []
            && $envAudit['missing_keys'] === []
            && in_array($appKeyStatus, ['configured', 'generated'], true);

        return [
            'base_path' => $this->basePath,
            'spark_version' => SparkVersion::current($this->basePath),
            'spark_release_line' => SparkVersion::releaseLine(SparkVersion::current($this->basePath)),
            'env_exists' => file_exists($this->basePath . '/.env'),
            'env_example_exists' => file_exists($this->basePath . '/.env.example'),
            'app_key_status' => $appKeyStatus,
            'missing_directories' => $missingDirectories,
            'missing_files' => $missingFiles,
            'missing_env_keys' => $envAudit['missing_keys'],
            'database_seeder_exists' => file_exists($this->basePath . '/database/seeds/DatabaseSeeder.php'),
            'ready' => $ready,
        ];
    }

    public function syncUpgrade(): array
    {
        $messages = [];
        $init = $this->initialize(false);
        $messages = array_merge($messages, $init['messages']);

        $envSync = $this->syncEnvFromExample();
        if ($envSync['added'] > 0) {
            $messages[] = $envSync['added'] . ' .env key(s) synced from .env.example';
        }

        if ($messages === []) {
            $messages[] = 'project already aligned with current scaffold';
        }

        return [
            'messages' => $messages,
            'synced_env_keys' => $envSync['keys'],
            'audit' => $this->audit(),
        ];
    }

    private function ensureEnvFile(): bool
    {
        $envFile = $this->basePath . '/.env';
        $exampleFile = $this->basePath . '/.env.example';

        if (file_exists($envFile) || !file_exists($exampleFile)) {
            return false;
        }

        copy($exampleFile, $envFile);
        return true;
    }

    private function ensureAppKey(bool $force): ?string
    {
        $envFile = $this->basePath . '/.env';
        if (!file_exists($envFile)) {
            return null;
        }

        $contents = (string) file_get_contents($envFile);
        $key = $this->readEnvValue($contents, 'APP_KEY');
        $needsKey = $force || $key === null || $key === '' || $key === 'change-me-to-a-random-secret-32-chars';

        if (!$needsKey) {
            return null;
        }

        $newKey = bin2hex(random_bytes(16));
        $replacement = 'APP_KEY=' . $newKey;

        if (preg_match('/^APP_KEY=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^APP_KEY=.*$/m', $replacement, $contents, 1);
        } else {
            $contents = rtrim($contents) . PHP_EOL . $replacement . PHP_EOL;
        }

        file_put_contents($envFile, $contents);

        return $key === null || $key === '' || $key === 'change-me-to-a-random-secret-32-chars'
            ? 'generated'
            : 'regenerated';
    }

    private function readEnvValue(string $contents, string $key): ?string
    {
        if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/m', $contents, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], " \t\n\r\0\x0B\"'");
    }

    private function ensureDirectories(): int
    {
        $created = 0;

        foreach ($this->directoryManifest() as $directory) {
            $path = $this->basePath . '/' . $directory;
            if (is_dir($path)) {
                continue;
            }

            mkdir($path, 0755, true);
            $created++;
        }

        return $created;
    }

    private function ensureDatabaseSeeder(): bool
    {
        $file = $this->basePath . '/database/seeds/DatabaseSeeder.php';
        if (file_exists($file)) {
            return false;
        }

        $dir = dirname($file);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($file, <<<'PHP'
<?php

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // $this->call(UserSeeder::class);
    }
}
PHP
        );

        return true;
    }

    private function clearRuntimeArtifacts(): int
    {
        $patterns = [
            'storage/cache/app/*.cache',
            'storage/cache/views/*.php',
            'storage/cache/phpunit/*',
            'storage/logs/*.log',
            'storage/inspector/*.json',
            'storage/queue/*.json',
            'storage/sessions/*',
        ];

        $files = [
            'storage/cache/env.php',
            'storage/cache/classes.php',
            'storage/cache/routes.php',
            'storage/inspector/index.json',
            'storage/migrations.json',
        ];

        $removed = 0;

        foreach ($files as $relativePath) {
            $path = $this->basePath . '/' . $relativePath;
            if (is_file($path) && unlink($path)) {
                $removed++;
            }
        }

        foreach ($patterns as $pattern) {
            $matches = glob($this->basePath . '/' . $pattern) ?: [];
            foreach ($matches as $path) {
                if (!is_file($path)) {
                    continue;
                }

                if (basename($path) === '.gitignore') {
                    continue;
                }

                if (unlink($path)) {
                    $removed++;
                }
            }
        }

        return $removed;
    }

    private function directoryManifest(): array
    {
        return [
            'app/ai/agents',
            'app/ai/prompts',
            'app/ai/tools',
            'app/events',
            'app/models',
            'app/services',
            'database/migrations',
            'database/seeds',
            'public/css',
            'public/images',
            'public/js',
            'storage/cache/app',
            'storage/cache/phpunit',
            'storage/cache/views',
            'storage/benchmarks',
            'storage/inspector',
            'storage/logs',
            'storage/queue',
            'storage/sessions',
            'storage/uploads',
        ];
    }

    private function requiredFilesForUpgrade(): array
    {
        return [
            '.env.example',
            'composer.json',
            'spark',
            'VERSION',
            'CHANGELOG.md',
            'public/index.php',
            'database/seeds/DatabaseSeeder.php',
            'docs/README.md',
        ];
    }

    private function envAudit(): array
    {
        $exampleEntries = $this->parseEnvEntries($this->basePath . '/.env.example');
        $envEntries = $this->parseEnvEntries($this->basePath . '/.env');

        $missingKeys = array_values(array_diff(array_keys($exampleEntries), array_keys($envEntries)));
        sort($missingKeys);

        return [
            'example_keys' => array_keys($exampleEntries),
            'env_keys' => array_keys($envEntries),
            'missing_keys' => $missingKeys,
        ];
    }

    private function syncEnvFromExample(): array
    {
        $envFile = $this->basePath . '/.env';
        $exampleFile = $this->basePath . '/.env.example';

        if (!file_exists($envFile) || !file_exists($exampleFile)) {
            return ['added' => 0, 'keys' => []];
        }

        $exampleEntries = $this->parseEnvEntries($exampleFile);
        $envEntries = $this->parseEnvEntries($envFile);
        $missingKeys = array_values(array_diff(array_keys($exampleEntries), array_keys($envEntries)));

        if ($missingKeys === []) {
            return ['added' => 0, 'keys' => []];
        }

        $contents = rtrim((string) file_get_contents($envFile));
        $append = PHP_EOL . PHP_EOL . '# Synced by SparkPHP upgrade on ' . date('Y-m-d H:i:s') . PHP_EOL;

        foreach ($missingKeys as $key) {
            $append .= $exampleEntries[$key] . PHP_EOL;
        }

        file_put_contents($envFile, $contents . $append);

        return ['added' => count($missingKeys), 'keys' => $missingKeys];
    }

    private function appKeyStatus(): string
    {
        $envFile = $this->basePath . '/.env';
        if (!file_exists($envFile)) {
            return 'missing';
        }

        $contents = (string) file_get_contents($envFile);
        $key = $this->readEnvValue($contents, 'APP_KEY');

        if ($key === null || $key === '') {
            return 'missing';
        }

        if ($key === 'change-me-to-a-random-secret-32-chars') {
            return 'placeholder';
        }

        return 'configured';
    }

    private function parseEnvEntries(string $path): array
    {
        if (!file_exists($path)) {
            return [];
        }

        $entries = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key] = explode('=', $line, 2);
            $entries[trim($key)] = rtrim($line);
        }

        return $entries;
    }

    private function isDirectoryEmpty(string $path): bool
    {
        $items = array_diff(scandir($path) ?: [], ['.', '..']);

        return $items === [];
    }

    private function copyDirectory(string $source, string $destination): void
    {
        if (!is_dir($destination) && !mkdir($destination, 0755, true) && !is_dir($destination)) {
            throw new RuntimeException("Unable to create directory [{$destination}].");
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $target = $destination . DIRECTORY_SEPARATOR . $iterator->getSubPathName();
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0755, true) && !is_dir($target)) {
                    throw new RuntimeException("Unable to create directory [{$target}].");
                }

                continue;
            }

            $this->copyFile($item->getPathname(), $target);
        }
    }

    private function copyFile(string $source, string $destination): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Unable to create directory [{$directory}].");
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException("Unable to copy [{$source}] to [{$destination}].");
        }
    }
}
