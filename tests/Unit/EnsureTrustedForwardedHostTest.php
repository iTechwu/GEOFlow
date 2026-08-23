<?php

namespace Tests\Unit;

use App\Http\Middleware\EnsureTrustedForwardedHost;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EnsureTrustedForwardedHostTest extends TestCase
{
    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalEnvironment = app()->environment();
        app()->detectEnvironment(fn (): string => 'production');
        config([
            'app.url' => 'https://geo.example.test',
            'geoflow.additional_trusted_hosts' => ['127.0.0.1'],
        ]);
    }

    protected function tearDown(): void
    {
        app()->detectEnvironment(fn (): string => $this->originalEnvironment);
        parent::tearDown();
    }

    public function test_allows_an_exact_additional_trusted_host(): void
    {
        $request = Request::create('http://127.0.0.1:18080/mcp', 'POST');

        $response = app(EnsureTrustedForwardedHost::class)->handle(
            $request,
            fn (): Response => new Response('accepted'),
        );

        $this->assertSame('accepted', $response->getContent());
    }

    public function test_rejects_a_host_that_is_not_explicitly_trusted(): void
    {
        $this->expectException(HttpException::class);
        $this->expectExceptionMessage('Invalid Host header.');

        app(EnsureTrustedForwardedHost::class)->handle(
            Request::create('http://127.0.0.2:18080/mcp', 'POST'),
            fn (): Response => new Response('unexpected'),
        );
    }
}
