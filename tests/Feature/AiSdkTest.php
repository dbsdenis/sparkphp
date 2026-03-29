<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class AiSdkTest extends TestCase
{
    private string $basePath;
    private array $envBackup = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->envBackup = $_ENV;
        $_ENV['AI_DRIVER'] = 'fake';

        $this->basePath = sys_get_temp_dir() . '/sparkphp-ai-' . bin2hex(random_bytes(4));
        mkdir($this->basePath . '/storage/cache/app', 0777, true);
        mkdir($this->basePath . '/storage/logs', 0777, true);
        mkdir($this->basePath . '/storage/sessions', 0777, true);
        mkdir($this->basePath . '/public', 0777, true);

        $app = new Bootstrap($this->basePath);
        $container = new Container();
        $container->singleton(AiManager::class, fn(Container $container) => new AiManager($container, $this->basePath));

        $ref = new ReflectionClass($app);
        $prop = $ref->getProperty('container');
        $prop->setAccessible(true);
        $prop->setValue($app, $container);
    }

    protected function tearDown(): void
    {
        $_ENV = $this->envBackup;
        $this->deleteDirectory($this->basePath);

        parent::tearDown();
    }

    public function testUnifiedAiSdkSupportsAllPrimaryCapabilities(): void
    {
        $client = new AiManager()->driver('fake');

        $text = $client->text('Summarize SparkPHP')
            ->system('Be concise.')
            ->temperature(0.2)
            ->generate();

        $embeddings = $client->embeddings(['Spark', 'Laravel'])->generate();
        $image = $client->image('A bright orange comet above a city')->size('512x512')->generate();
        $audio = $client->audio('SparkPHP speaking')->voice('nova')->format('wav')->generate();
        $agent = $client->agent('sales-coach')
            ->instructions('Help the team improve conversions.')
            ->tool('lookupLead', fn(array $arguments) => ['id' => $arguments['id'] ?? null], 'Look up a lead')
            ->context(['team' => 'sales'])
            ->prompt('Review the pipeline notes.')
            ->run();

        $this->assertSame('Fake response: Summarize SparkPHP', (string) $text);
        $this->assertSame('fake', $text->provider);
        $this->assertSame('spark-text', $text->model);

        $this->assertCount(2, $embeddings->vectors);
        $this->assertCount(8, $embeddings->first());
        $this->assertSame($embeddings->vectors[0], $client->embeddings('Spark')->generate()->first());

        $this->assertStringContainsString('FAKE_IMAGE:', (string) $image);
        $this->assertSame('image/png', $image->mimeType);
        $this->assertSame('512x512', $image->meta['size']);

        $this->assertStringContainsString('FAKE_AUDIO:', (string) $audio);
        $this->assertSame('audio/wav', $audio->mimeType);
        $this->assertSame('nova', $audio->meta['voice']);

        $this->assertSame('Fake agent response: Review the pipeline notes.', (string) $agent);
        $this->assertSame('sales-coach', $agent->meta['agent']);
        $this->assertSame('lookupLead', $agent->tools[0]['name']);
        $this->assertSame(['team' => 'sales'], $agent->context);
    }

    public function testAiManagerCanRegisterCustomDrivers(): void
    {
        $manager = new AiManager();
        $manager->extend('custom', fn() => new AiCustomTestProvider());

        $text = $manager->driver('custom')->text('Hello')->generate();
        $embedding = $manager->driver('custom')->embeddings('Hello')->generate();

        $this->assertSame('Custom: Hello', (string) $text);
        $this->assertSame([[1.0, 0.0]], $embedding->vectors);
    }

    public function testAiHelperResolvesManagerFromContainer(): void
    {
        $fake = (new AiFakeProvider())->textUsing(
            fn(AiTextRequest $request) => new AiTextResponse('Container says: ' . (string) $request->prompt, 'fake', $request->model)
        );

        app()->getContainer()->make(AiManager::class)->fake($fake);

        $response = ai()->text('hi')->generate();

        $this->assertSame('Container says: hi', (string) $response);
    }

    public function testAiConventionsLoadPromptsToolsAndAgentsFromAppDirectory(): void
    {
        $this->writeFile('app/ai/prompts/sales/brief.spark', "Brief for {{customer}} in {{locale}}");
        $this->writeFile('app/ai/prompts/support/system.spark', "Voce responde pelo time {{team}}.");
        $this->writeFile('app/ai/prompts/support/request.spark', "Pedido {{order_id}} do cliente {{customer.name}}");
        $this->writeFile('app/ai/tools/lookup-order.php', <<<'PHP'
<?php

return [
    'description' => 'Consulta pedido',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'id' => ['type' => 'integer'],
        ],
    ],
    'handle' => fn(array $arguments) => [
        'id' => $arguments['id'] ?? null,
        'status' => 'processing',
    ],
];
PHP
        );
        $this->writeFile('app/ai/agents/support.php', <<<'PHP'
<?php

return [
    'instructions_prompt' => 'support/system',
    'prompt_template' => 'support/request',
    'tools' => ['lookup-order'],
    'context' => ['team' => 'support'],
];
PHP
        );

        $client = ai();

        $this->assertSame('Brief for SparkPHP in pt-BR', ai_prompt('sales/brief', [
            'customer' => 'SparkPHP',
            'locale' => 'pt-BR',
        ]));
        $this->assertSame(['sales/brief', 'support/request', 'support/system'], $client->discoverPrompts());
        $this->assertSame(['support'], $client->discoverAgents());
        $this->assertSame(['lookup-order'], $client->discoverTools());
        $this->assertSame('lookup-order', ai_tool('lookup-order')->name);
        $this->assertCount(1, ai_tools(['lookup-order']));

        $text = $client->text()
            ->usingPrompt('sales/brief', ['customer' => 'Acme', 'locale' => 'en-US'])
            ->generate();

        $agent = $client->agent('support')
            ->context([
                'order_id' => 123,
                'customer' => ['name' => 'Globex'],
                'tool_arguments' => [
                    'lookup-order' => ['id' => 123],
                ],
            ])
            ->run();

        $this->assertSame('Fake response: Brief for Acme in en-US', (string) $text);
        $this->assertSame('Fake agent response: Pedido 123 do cliente Globex', (string) $agent);
        $this->assertSame('support', $agent->meta['agent']);
        $this->assertSame('lookup-order', $agent->tools[0]['name']);
        $this->assertSame(['id' => 123, 'status' => 'processing'], $agent->meta['tool_results']['lookup-order']);
        $this->assertSame('Globex', $agent->context['customer']['name']);
    }

    public function testStructuredOutputWorksForTextAndAgentBuilders(): void
    {
        $this->writeFile('app/ai/prompts/leads/extract.spark', "Extraia os campos do lead {{lead}}");
        $this->writeFile('app/ai/agents/extract-lead.php', <<<'PHP'
<?php

return [
    'prompt_template' => 'leads/extract',
    'schema' => [
        'type' => 'object',
        'properties' => [
            'name' => ['type' => 'string'],
            'email' => ['type' => 'string', 'format' => 'email'],
            'qualified' => ['type' => 'boolean'],
        ],
    ],
];
PHP
        );

        $text = ai()->text()
            ->usingPrompt('leads/extract', ['lead' => 'Alice / alice@example.com'])
            ->schema([
                'type' => 'object',
                'properties' => [
                    'summary' => ['type' => 'string'],
                    'score' => ['type' => 'integer'],
                ],
            ])
            ->generate();

        $agent = ai()->agent('extract-lead')
            ->context(['lead' => 'Alice / alice@example.com'])
            ->run();

        $this->assertIsArray($text->structured);
        $this->assertArrayHasKey('summary', $text->structured);
        $this->assertArrayHasKey('score', $text->structured);
        $this->assertJson((string) $text);

        $this->assertIsArray($agent->structured);
        $this->assertArrayHasKey('name', $agent->structured);
        $this->assertArrayHasKey('email', $agent->structured);
        $this->assertArrayHasKey('qualified', $agent->structured);
        $this->assertMatchesRegularExpression('/@example\.com$/', $agent->structured['email']);
        $this->assertJson((string) $agent);
    }

    public function testOperationsFailFastWithoutRequiredInput(): void
    {
        $client = new AiManager()->driver('fake');

        $this->expectException(AiException::class);
        $client->text()->generate();
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

    private function writeFile(string $relativePath, string $contents): void
    {
        $path = $this->basePath . '/' . ltrim($relativePath, '/');
        $directory = dirname($path);

        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        file_put_contents($path, $contents);
    }
}

final class AiCustomTestProvider implements AiProvider
{
    public function name(): string
    {
        return 'custom';
    }

    public function text(AiTextRequest $request): AiTextResponse
    {
        $prompt = is_array($request->prompt) ? implode(' ', $request->prompt) : $request->prompt;

        return new AiTextResponse('Custom: ' . $prompt, 'custom', $request->model);
    }

    public function embeddings(AiEmbeddingRequest $request): AiEmbeddingResponse
    {
        return new AiEmbeddingResponse([[1.0, 0.0]], 'custom', $request->model);
    }

    public function image(AiImageRequest $request): AiImageResponse
    {
        return new AiImageResponse('IMAGE', 'custom', $request->model);
    }

    public function audio(AiAudioRequest $request): AiAudioResponse
    {
        return new AiAudioResponse('AUDIO', 'custom', $request->model);
    }

    public function agent(AiAgentRequest $request): AiAgentResponse
    {
        return new AiAgentResponse('Agent', 'custom', $request->model);
    }
}
