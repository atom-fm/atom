<?php

declare(strict_types=1);

namespace Atom\Tests\Integration;

use Atom\Application;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Module\ModuleRegistry;
use Atom\Modules\Components\Components;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageDescriptor;
use Atom\Page\PageRouteRegistrar;
use Atom\Router\Route;
use PHPUnit\Framework\TestCase;

final class PageFormHydrationTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
        Route::clearRouter();
    }

    public function testInvalidFieldsRenderTogetherAndSkipTheAction(): void
    {
        $response = $this->submit([
            "quantity" => "many",
            "rush" => ["unexpected"],
            "address" => ["number" => "unknown", "street" => "Main Street"],
        ]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString('aria-describedby="quantity-error"', $html);
        self::assertStringContainsString('aria-describedby="rush-error"', $html);
        self::assertStringContainsString('aria-describedby="address-number--error"', $html);
        self::assertStringContainsString('value="Main Street"', $html);
        self::assertStringContainsString('value="2"', $html);
        self::assertStringNotContainsString("Saved:", $html);
    }

    public function testNestedFieldsHydrateIntoTheirExistingObjects(): void
    {
        $response = $this->submit([
            "quantity" => "3",
            "address" => [
                "number" => "42",
                "street" => "Main Street",
                "zone" => "999",
                "unit" => ["number" => "5"],
            ],
        ]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString("Saved: 3 / 42 / Main Street / 7 / 5", $html);
        self::assertStringContainsString('name="address[number]" value="42"', $html);
        self::assertStringContainsString('name="address[unit][number]" value="5"', $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    public function testInvalidNestedShapeReportsAnErrorOnTheParentPath(): void
    {
        $response = $this->submit(["address" => "Main Street"]);

        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('<p id="address-error">The field has an invalid value.</p>', $response->getContent());
        self::assertStringNotContainsString("Saved:", $response->getContent());
    }

    private function submit(array $fields): Response
    {
        $app = new class extends Application {
            protected function modules(ModuleRegistry $modules): void
            {
                $modules->add(Components::module());
            }

            protected function bootstrap(Injector $injector): void
            {
                (new PageRouteRegistrar())->register($this->getRouter(), [
                    new PageDescriptor("/order", HydratedOrderPage::class),
                ]);
            }
        };

        return $app->handle(new Request("POST", "/order", parsedBody: ["_action" => "order.save", ...$fields]));
    }
}

final class HydratedOrderPage extends Page
{
    #[FormModel]
    public HydratedOrderForm $order;

    public function template(): ?string
    {
        return "HydratedOrderPage.atom.html";
    }

    public function init(): void
    {
        $this->order = new HydratedOrderForm();
    }
}

final class HydratedOrderForm extends Form
{
    public int $quantity = 2;
    public int|bool $rush = false;
    public HydratedOrderAddress $address;
    public string $result = "";

    public function __construct()
    {
        $this->address = new HydratedOrderAddress(7);
    }

    #[PageAction]
    public function save(): void
    {
        $unit = $this->address->unit?->number;
        $this->result = "Saved: {$this->quantity} / {$this->address->number} / {$this->address->street} / {$this->address->zone()} / {$unit}";
    }
}

final class HydratedOrderAddress
{
    public int $number = 1;
    public string $street = "";
    public ?HydratedOrderUnit $unit = null;

    public function __construct(private int $zone)
    {
    }

    public function zone(): int
    {
        return $this->zone;
    }
}

final class HydratedOrderUnit
{
    public int $number = 0;
}
