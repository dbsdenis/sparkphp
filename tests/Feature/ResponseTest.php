<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    private string $tempFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        @header_remove();
        http_response_code(200);
    }

    protected function tearDown(): void
    {
        @header_remove();
        http_response_code(200);
        if ($this->tempFile !== '' && file_exists($this->tempFile)) {
            unlink($this->tempFile);
        }
        parent::tearDown();
    }

    public function testJsonFactoryBuildsResponseWithExpectedStatusAndHeader(): void
    {
        $response = Response::json(['ok' => true], 201);

        $status = $this->readPrivate($response, 'status');
        $headers = $this->readPrivate($response, 'headers');
        $body = $this->readPrivate($response, 'body');

        $this->assertSame(201, $status);
        $this->assertSame('application/json; charset=UTF-8', $headers['Content-Type']);
        $this->assertSame('{"ok":true}', $body);
    }

    public function testErrorFactoryBuildsStandardEnvelope(): void
    {
        $response = Response::error('Method Not Allowed', 405, 'method_not_allowed');

        $this->assertSame(405, $response->getStatus());
        $this->assertSame([
            'error' => 'Method Not Allowed',
            'status' => 405,
            'code' => 'method_not_allowed',
        ], json_decode((string) $response->getBody(), true));
    }

    public function testValidationErrorFactoryBuilds422Envelope(): void
    {
        $response = Response::validationError([
            'name' => 'O campo name e obrigatorio.',
        ]);

        $this->assertSame(422, $response->getStatus());
        $this->assertSame([
            'error' => 'The given data was invalid.',
            'status' => 422,
            'code' => 'validation_error',
            'errors' => [
                'name' => 'O campo name e obrigatorio.',
            ],
        ], json_decode((string) $response->getBody(), true));
    }

    public function testEmptyFactoryBuildsBodylessResponse(): void
    {
        $response = Response::empty(205, ['X-Test' => 'yes']);

        ob_start();
        $response->send();
        $output = ob_get_clean();

        $this->assertSame(205, http_response_code());
        $this->assertSame('', $output);
        $this->assertSame('yes', $response->getHeaders()['X-Test']);
    }

    public function testDownloadFactoryBuildsExpectedHeaders(): void
    {
        $this->tempFile = tempnam(sys_get_temp_dir(), 'sparkphp-download-') ?: '';
        file_put_contents($this->tempFile, 'report-content');

        $response = Response::download($this->tempFile, 'report.txt');

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('attachment; filename="report.txt"', $response->getHeaders()['Content-Disposition']);
        $this->assertSame((string) filesize($this->tempFile), $response->getHeaders()['Content-Length']);
    }

    public function testStreamFactoryExecutesCallbackWhenSent(): void
    {
        $response = Response::stream(function (): void {
            echo 'hello';
            echo ' world';
        }, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);

        ob_start();
        $response->send();
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertSame('hello world', $output);
    }

    public function testResolveReturns404MarkupForNullGetResult(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(null, new FakeRequest('GET', 'html'), new ErrorAwareFakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(404, http_response_code());
        $this->assertStringContainsString('error-errors/404', $output);
    }

    public function testResolveReturnsStandardJsonEnvelopeForNullGetWhenRequestWantsJson(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(null, new FakeRequest('GET', 'json'), new ErrorAwareFakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(404, http_response_code());
        $this->assertSame([
            'error' => 'Not Found',
            'status' => 404,
            'code' => 'not_found',
        ], json_decode($output, true));
    }

    public function testResolveReturnsJsonForArrayWhenRequestAcceptsJson(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['status' => 'ok'], new FakeRequest('GET', 'json'), new FakeView(), '/api/health');
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertSame('{"status":"ok"}', $output);
    }

    public function testResolveUses201ForPostJsonArrayResult(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['id' => 10], new FakeRequest('POST', 'json'), new FakeView(), '/api/users');
        $output = ob_get_clean();

        $this->assertSame(201, http_response_code());
        $this->assertSame('{"id":10}', $output);
    }

    public function testResolveUses201ForPostHtmlArrayResult(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['name' => 'Spark'], new FakeRequest('POST', 'html'), new FakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(201, http_response_code());
        $this->assertSame('<h1>ok</h1>', $output);
    }

    public function testResolveFallsBackToJsonWhenViewIsMissing(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(['name' => 'Spark'], new FakeRequest('POST', 'html'), new ThrowingFakeView(), '/users');
        $output = ob_get_clean();

        $this->assertSame(201, http_response_code());
        $this->assertSame('{"name":"Spark"}', $output);
    }

    public function testResolveSerializesJsonSerializableObjectsForJsonRequests(): void
    {
        $resolver = new Response();

        ob_start();
        $resolver->resolve(new ResponseModelLike(), new FakeRequest('GET', 'json'), new FakeView(), '/api/users/show');
        $output = ob_get_clean();

        $this->assertSame(200, http_response_code());
        $this->assertSame('{"id":1,"display_name":"Spark"}', $output);
    }

    public function testResolveUsesToArrayForHtmlMirrorViews(): void
    {
        $resolver = new Response();
        $view = new CapturingFakeView();

        ob_start();
        $resolver->resolve(new ResponseModelLike(), new FakeRequest('GET', 'html'), $view, '/users/show');
        ob_get_clean();

        $this->assertSame([
            'name' => 'Spark',
        ], $view->lastData);
    }

    private function readPrivate(object $target, string $property): mixed
    {
        $ref = new ReflectionClass($target);
        $prop = $ref->getProperty($property);
        $prop->setAccessible(true);
        return $prop->getValue($target);
    }
}

final class FakeRequest extends Request
{
    public function __construct(private string $fakeMethod, private string $preferredFormat)
    {
    }

    public function method(): string
    {
        return strtoupper($this->fakeMethod);
    }

    public function preferredFormat(array $available = ['html', 'json']): ?string
    {
        return in_array($this->preferredFormat, $available, true)
            ? $this->preferredFormat
            : ($available[0] ?? null);
    }

    public function acceptsJson(): bool
    {
        return in_array('json', [$this->preferredFormat], true);
    }

    public function acceptsHtml(): bool
    {
        return in_array('html', [$this->preferredFormat], true);
    }

    public function wantsJson(): bool
    {
        return $this->preferredFormat === 'json';
    }

    public function wantsHtml(): bool
    {
        return $this->preferredFormat === 'html';
    }
}

class FakeView extends View
{
    public function __construct()
    {
    }

    public function render(string $name, array $data = []): string
    {
        return '<h1>ok</h1>';
    }
}

final class ThrowingFakeView extends FakeView
{
    public function render(string $name, array $data = []): string
    {
        throw new RuntimeException('View not found');
    }
}

final class ErrorAwareFakeView extends FakeView
{
    public function render(string $name, array $data = []): string
    {
        return "<h1>error-{$name}</h1>";
    }
}

final class CapturingFakeView extends FakeView
{
    public array $lastData = [];

    public function render(string $name, array $data = []): string
    {
        $this->lastData = $data;
        return '<h1>captured</h1>';
    }
}

final class ResponseModelLike implements JsonSerializable
{
    public function toArray(): array
    {
        return ['name' => 'Spark'];
    }

    public function jsonSerialize(): mixed
    {
        return ['id' => 1, 'display_name' => 'Spark'];
    }
}
