<?php

declare(strict_types=1);

namespace Atom\Tests\Integration;

use Atom\Application;
use Atom\Config\Config;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Modules\ErrorPages\ErrorPagesOptions;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageDescriptor;
use Atom\Page\PageRouteRegistrar;
use Atom\Router\Route;
use Atom\Validation\Rules\Required;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PageActionRequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
        Route::clearRouter();
    }

    #[DataProvider("failingActions")]
    public function testPageActionFailuresReturnClientOrServerErrors(bool $enhanced, string|array $action, int $status): void
    {
        FailingPageActionDependency::$attempts = 0;
        $response = $this->submit($enhanced, ["_action" => $action]);

        self::assertSame($status, $response->getStatus());
        self::assertStringStartsWith("text/html", (string) $response->headers()->get("Content-Type"));
        self::assertStringContainsString($status === 400 ? "Bad request" : "Something went wrong", $response->getContent());
        self::assertStringNotContainsString("Private dependency failure.", $response->getContent());
        self::assertSame($action === "requiresDependency" ? 1 : 0, FailingPageActionDependency::$attempts);
    }

    /** @return iterable<string, array{bool, string|array, int}> */
    public static function failingActions(): iterable
    {
        foreach (self::requestModes() as $mode => [$enhanced]) {
            yield "{$mode} with malformed action" => [$enhanced, "setQuantity(", 400];
            yield "{$mode} with non-string action" => [$enhanced, ["setQuantity"], 400];
            yield "{$mode} with unknown action" => [$enhanced, "missingAction", 400];
            yield "{$mode} with invalid argument type" => [$enhanced, 'setQuantity("many")', 400];
            yield "{$mode} with too many arguments" => [$enhanced, "setQuantity(1, 2)", 400];
            yield "{$mode} with a failing dependency" => [$enhanced, "requiresDependency", 500];
        }
    }

    #[DataProvider("requestModes")]
    public function testInvalidFormRendersItsFieldErrorAndRetainsSubmittedData(bool $enhanced): void
    {
        $response = $this->submit($enhanced, ["_action" => "order.save", "reference" => "", "note" => "Keep this"]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('<p id="reference-error">Enter an order reference.</p>', $html);
        self::assertStringContainsString('name="note" value="Keep this"', $html);
        self::assertStringNotContainsString("Order saved.", $html);
        self::assertNull($response->headers()->get("Location"));
    }

    /** @return iterable<string, array{bool}> */
    public static function requestModes(): iterable
    {
        yield "ordinary post" => [false];
        yield "Atom-enhanced post" => [true];
    }

    /** @param array<string, string|array> $fields */
    private function submit(bool $enhanced, array $fields): Response
    {
        $app = new class extends Application {
            protected function configure(Config $config): void
            {
                $config->set(new ErrorPagesOptions(debug: false));
            }

            protected function bootstrap(Injector $injector): void
            {
                (new PageRouteRegistrar())->register($this->getRouter(), [
                    new PageDescriptor("/page-action-check", PageActionRequestFixture::class),
                ]);
            }
        };
        $headers = ["Content-Type" => "application/x-www-form-urlencoded", "Accept" => "text/html"];
        if ($enhanced) {
            $headers["X-Requested-With"] = "Atom";
        }

        return $app->handle(new Request("POST", "/page-action-check", parsedBody: $fields, headers: $headers));
    }
}

final class PageActionRequestFixture extends Page
{
    #[FormModel]
    public PageActionRequestForm $order;

    public function init(): void
    {
        $this->order = new PageActionRequestForm();
    }

    public function template(): ?string
    {
        return "PageActionRequestFixture.atom.html";
    }

    #[PageAction]
    public function setQuantity(int $quantity): string
    {
        return "Quantity: {$quantity}";
    }

    #[PageAction]
    public function requiresDependency(FailingPageActionDependency $dependency): string
    {
        return "Dependency resolved.";
    }
}

final class PageActionRequestForm extends Form
{
    #[Required("Enter an order reference.")]
    public string $reference = "";
    public string $note = "";
    public string $result = "";

    #[PageAction]
    public function save(): void
    {
        if ($this->validate()) {
            $this->result = "Order saved.";
        }
    }
}

final class FailingPageActionDependency
{
    public static int $attempts = 0;

    public function __construct()
    {
        self::$attempts++;
        throw new RuntimeException("Private dependency failure.");
    }
}
