<?php

declare(strict_types=1);

namespace Atom\Tests\Session;

use Atom\Application;
use Atom\Config\Config;
use Atom\Di\Bindings;
use Atom\Di\Injector;
use Atom\Di\ServiceProviderInterface;
use Atom\Di\ServiceProviderRegistry;
use Atom\Http\Request;
use Atom\Http\CookieJar;
use Atom\Identity\AuthenticateMiddleware;
use Atom\Identity\AuthenticatorInterface;
use Atom\Identity\IdentityInterface;
use Atom\Identity\IdentityProviderInterface;
use Atom\Module\ModuleRegistry;
use Atom\Modules\Accounts\Accounts;
use Atom\Router\Route;
use Atom\Scheduler\ClockInterface;
use Atom\Security\CsrfTokenManagerInterface;
use Atom\Session\ArraySession;
use Atom\Session\FlashBag;
use Atom\Session\NativeSession;
use Atom\Session\SessionInterface;
use Atom\Session\SessionOptions;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
        Route::clearRouter();
    }

    public function testArraySessionStoresPullsAndInvalidatesValues(): void
    {
        $session = new ArraySession(["name" => "Atom"], "initial-id");

        $this->assertFalse($session->isStarted());
        $this->assertSame("Atom", $session->get("name"));
        $this->assertTrue($session->isStarted());

        $session->put("nullable", null);
        $this->assertTrue($session->has("nullable"));
        $this->assertNull($session->get("nullable", "fallback"));
        $this->assertSame("Atom", $session->pull("name"));
        $this->assertFalse($session->has("name"));

        $session->put("user_id", 42);
        $session->invalidate();

        $this->assertSame([], $session->all());
        $this->assertNotSame("initial-id", $session->id());
    }

    public function testFlashDataLivesForTheNextSessionRequest(): void
    {
        $session = new ArraySession(id: "flash-session");
        $flash = new FlashBag($session);

        $flash->put("notice", "Saved");
        $flash->put("nullable", null);
        $this->assertSame("Saved", $flash->get("notice"));
        $this->assertNull($flash->get("nullable", "fallback"));
        $session->save();

        $next = new FlashBag($session);
        $this->assertSame("Saved", $next->get("notice"));
        $this->assertTrue($next->has("nullable"));
        $next->keep("notice");
        $this->assertNull($next->pull("nullable", "fallback"));
        $session->save();

        $kept = new FlashBag($session);
        $this->assertSame("Saved", $kept->get("notice"));
        $session->save();

        $expired = new FlashBag($session);
        $this->assertFalse($expired->has("notice"));

        $expired->now("current", "Only now");
        $session->save();
        $this->assertFalse((new FlashBag($session))->has("current"));
    }

    public function testSessionOptionsHydrateFromEnvironmentConfiguration(): void
    {
        $options = Config::fromEnv([
            "SESSION_NAME" => "BLOGSESSID",
            "SESSION_LIFETIME" => "3600",
            "SESSION_IDLE_TIMEOUT" => "1800",
            "SESSION_SECURE" => "true",
            "SESSION_HTTP_ONLY" => "false",
            "SESSION_SAME_SITE" => "Strict",
        ])->options(SessionOptions::class);

        $this->assertSame("BLOGSESSID", $options->name);
        $this->assertSame(3600, $options->lifetime);
        $this->assertSame(1800, $options->idleTimeout);
        $this->assertTrue($options->secure);
        $this->assertFalse($options->httpOnly);
        $this->assertSame("Strict", $options->sameSite);
    }

    public function testApplicationClosesAResolvedSessionAfterHandlingTheRequest(): void
    {
        $app = new SessionApplication();

        $response = $app->handle(new Request("GET", "/session"));

        $this->assertSame("stored", $response->getContent());
        $this->assertFalse($app->session->isStarted());
        $this->assertSame(42, $app->session->get("user_id"));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    #[TestWith(["GET", "/test/protected", "text/html"])]
    #[TestWith(["POST", "/account/logout", "text/html"])]
    #[TestWith(["POST", "/account/logout", "application/json"])]
    public function testProtectedRequestReturnsUnauthorizedAtTheIdleTimeout(string $method, string $path, string $accept): void
    {
        $directory = sys_get_temp_dir() . "/atom-session-" . bin2hex(random_bytes(8));
        mkdir($directory);
        ini_set("session.save_path", $directory);

        try {
            $clock = new IdleSessionClock(new DateTimeImmutable("2026-11-23T09:00:00Z"));
            $app = new IdleSessionApplication($clock);
            $login = $app->handle(new Request("POST", "/test/login"));
            $this->assertSame(200, $login->getStatus());
            $cookie = explode(";", (string) $login->headers()->get("Set-Cookie"), 2)[0];
            $this->assertStringStartsWith("ATOMIDLETEST=", $cookie);
            $protected = new Request("GET", "/test/protected", headers: ["Cookie" => $cookie]);

            $clock->time = $clock->time->modify("+29 seconds");
            $this->assertSame(200, $app->handle($protected)->getStatus());
            $clock->time = $clock->time->modify("+29 seconds");
            $app->handle(new Request("GET", "/test/public", headers: ["Cookie" => $cookie]));
            $clock->time = $clock->time->modify("+1 second");

            $expired = $app->handle(new Request(
                $method,
                $path,
                parsedBody: [CsrfTokenManagerInterface::FIELD_NAME => $login->getContent()],
                headers: ["Cookie" => $cookie, "Accept" => $accept]
            ));
            $this->assertSame(401, $expired->getStatus());
            $this->assertSame("authentication_required", $expired->headers()->get("X-Atom-Error-Code"));
            $this->assertSame("no-store", $expired->headers()->get("Cache-Control"));
            $this->assertNull($expired->headers()->get("Location"));
            if ($accept === "application/json") {
                $this->assertSame("application/json", $expired->headers()->get("Content-Type"));
                $body = json_decode($expired->getContent(), true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame("authentication_required", $body["error"]["code"]);
            } else {
                $this->assertSame("text/plain; charset=utf-8", $expired->headers()->get("Content-Type"));
                $this->assertSame("Authentication required.", $expired->getContent());
            }
            $replacement = explode(";", (string) $expired->headers()->get("Set-Cookie"), 2)[0];
            $this->assertStringStartsWith("ATOMIDLETEST=", $replacement);
            $this->assertNotSame($cookie, $replacement);
            $this->assertSame(1, $app->protectedCalls);
        } finally {
            foreach (glob($directory . "/sess_*") ?: [] as $file) {
                unlink($file);
            }
            rmdir($directory);
        }
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testNativeSessionPersistsDataUsingTheRequestCookie(): void
    {
        $options = new SessionOptions(name: "ATOMTESTSID", sameSite: "Strict");
        $firstCookies = new CookieJar();
        $first = new NativeSession($options, new Request("GET", "/"), $firstCookies);
        $first->put("user_id", 42);
        $id = $first->id();
        $this->assertCount(1, $firstCookies->all());
        $first->save();

        $secondCookies = new CookieJar();
        $second = new NativeSession($options, new Request(
            "GET",
            "/",
            headers: ["Cookie" => "theme=dark; ATOMTESTSID=" . rawurlencode($id)]
        ), $secondCookies);

        $this->assertSame($id, $second->id());
        $this->assertSame(42, $second->get("user_id"));
        $this->assertTrue($secondCookies->isEmpty());
        $second->invalidate();
        $this->assertCount(1, $secondCookies->all());
        $second->save();
    }
}

final class IdleSessionApplication extends Application
{
    public int $protectedCalls = 0;

    public function __construct(private IdleSessionClock $clock)
    {
        parent::__construct();
    }

    protected function configure(Config $config): void
    {
        $config->set(new SessionOptions(name: "ATOMIDLETEST", idleTimeout: 30));
    }

    protected function modules(ModuleRegistry $modules): void
    {
        $modules->add(Accounts::module(), "/account");
    }

    protected function bootstrap(Injector $injector): void
    {
        $this->getBindings()->value(ClockInterface::class, $this->clock);
        $this->getBindings()->type(IdentityProviderInterface::class, IdleSessionIdentities::class);
        Route::post("/test/login", static function (AuthenticatorInterface $auth, CsrfTokenManagerInterface $csrf): string {
            $auth->login(new IdleSessionIdentity());
            return $csrf->token();
        });
        Route::get("/test/public", static fn(): string => "public");
        Route::get("/test/protected", function (): string {
            $this->protectedCalls++;
            return "protected";
        })->middleware(AuthenticateMiddleware::class);
    }
}

final class IdleSessionClock implements ClockInterface
{
    public function __construct(public DateTimeImmutable $time)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}

final class IdleSessionIdentity implements IdentityInterface
{
    public function identifier(): int
    {
        return 42;
    }
}

final class IdleSessionIdentities implements IdentityProviderInterface
{
    public function findByIdentifier(string|int $identifier): ?IdentityInterface
    {
        return $identifier === 42 ? new IdleSessionIdentity() : null;
    }

    public function findByLogin(string $login): ?IdentityInterface
    {
        return null;
    }

    public function validateCredentials(IdentityInterface $identity, string $password): bool
    {
        return false;
    }
}

final class SessionApplication extends Application
{
    public ArraySession $session;

    public function __construct()
    {
        $this->session = new ArraySession(id: "application-session");
        parent::__construct();
    }

    protected function services(ServiceProviderRegistry $providers): void
    {
        $providers->add(new ArraySessionServices($this->session));
    }

    protected function bootstrap(Injector $injector): void
    {
        Route::get("/session", function (SessionInterface $session): string {
            $session->put("user_id", 42);
            return "stored";
        });
    }
}

final readonly class ArraySessionServices implements ServiceProviderInterface
{
    public function __construct(private ArraySession $session)
    {
    }

    public function register(Bindings $bindings): void
    {
        $bindings->bind(SessionInterface::class)
            ->toFactory(fn() => $this->session)
            ->scoped();
    }
}
