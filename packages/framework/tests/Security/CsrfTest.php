<?php

declare(strict_types=1);

namespace Atom\Tests\Security;

use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\RequestHandlerInterface;
use Atom\Http\Response;
use Atom\Security\CsrfMiddleware;
use Atom\Security\CsrfTokenManager;
use Atom\Security\CsrfTokenManagerInterface;
use Atom\Security\SecurityServices;
use Atom\Security\SecurityHeadersMiddleware;
use Atom\Session\ArraySession;
use Atom\Session\SessionInterface;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    public function testSecurityServicesRegisterRequestScopedCsrfDependencies(): void
    {
        $bindings = Bindings::create();
        $bindings->value(SessionInterface::class, new ArraySession(id: "services-session"));
        (new SecurityServices())->register($bindings);
        $injector = new Injector($bindings);
        $context = new InjectionContext();

        $tokens = $injector->get(CsrfTokenManagerInterface::class, $context);
        $middleware = $injector->get(CsrfMiddleware::class, $context);

        $this->assertInstanceOf(CsrfTokenManager::class, $tokens);
        $this->assertInstanceOf(CsrfMiddleware::class, $middleware);
        $this->assertSame($tokens, $injector->get(CsrfTokenManagerInterface::class, $context));
        $this->assertArrayHasKey(SecurityHeadersMiddleware::class, $bindings->providers());
    }

    public function testTokenIsStableUntilRefreshedOrCleared(): void
    {
        $session = new ArraySession(id: "csrf-session");
        $tokens = new CsrfTokenManager($session);

        $first = $tokens->token();

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first);
        $this->assertSame($first, $tokens->token());
        $this->assertTrue($tokens->validate($first));
        $this->assertFalse($tokens->validate(null));
        $this->assertFalse($tokens->validate(str_repeat("0", 64)));

        $second = $tokens->refresh();
        $this->assertNotSame($first, $second);
        $this->assertTrue($tokens->validate($second));

        $tokens->clear();
        $this->assertFalse($tokens->validate($second));
    }

    public function testMiddlewareSkipsSafeMethodsWithoutStartingSession(): void
    {
        $session = new ArraySession(id: "safe-session");
        $middleware = new CsrfMiddleware(new CsrfTokenManager($session));
        $handler = new CsrfTestHandler();

        foreach (["GET", "HEAD", "OPTIONS"] as $method) {
            $response = $middleware->process(new Request($method, "/protected"), $handler);
            $this->assertSame("accepted", $response->getContent());
        }

        $this->assertFalse($session->isStarted());
        $this->assertSame(3, $handler->calls);
    }

    public function testMiddlewareAcceptsFormAndHeaderTokens(): void
    {
        $session = new ArraySession(id: "protected-session");
        $tokens = new CsrfTokenManager($session);
        $token = $tokens->token();
        $middleware = new CsrfMiddleware($tokens);
        $handler = new CsrfTestHandler();

        $formResponse = $middleware->process(new Request(
            "POST",
            "/protected",
            parsedBody: ["_token" => $token]
        ), $handler);
        $headerResponse = $middleware->process(new Request(
            "DELETE",
            "/protected",
            headers: ["X-CSRF-Token" => $token]
        ), $handler);

        $this->assertSame("accepted", $formResponse->getContent());
        $this->assertSame("accepted", $headerResponse->getContent());
        $this->assertSame(2, $handler->calls);
    }

    public function testMiddlewareRejectsMissingOrInvalidToken(): void
    {
        $session = new ArraySession(id: "rejected-session");
        $tokens = new CsrfTokenManager($session);
        $tokens->token();
        $middleware = new CsrfMiddleware($tokens);
        $handler = new CsrfTestHandler();

        $response = $middleware->process(new Request(
            "POST",
            "/protected",
            parsedBody: ["_token" => "invalid"]
        ), $handler);

        $this->assertSame(403, $response->getStatus());
        $this->assertSame("Invalid CSRF token.", $response->getContent());
        $this->assertSame("no-store", $response->headers()->get("Cache-Control"));
        $this->assertSame(0, $handler->calls);
    }

}

final class CsrfTestHandler implements RequestHandlerInterface
{
    public int $calls = 0;

    public function handle(Request $request): Response
    {
        $this->calls++;
        return (new Response())->content("accepted");
    }
}
