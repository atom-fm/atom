<?php

declare(strict_types=1);

namespace Atom\Tests\Integration;

use Atom\Application;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Module\ModuleRegistry;
use Atom\Components\Components;
use Atom\Integration\View\ComponentModule;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\JsonPageStateSerializer;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageDescriptor;
use Atom\Page\PageRouteRegistrar;
use Atom\Page\State;
use Atom\Router\Route;
use Atom\Validation\Rules\Min;
use Atom\Validation\Rules\Required;
use Atom\Validation\ValidateNested;
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
            "reference" => "ORDER-1",
            "address" => [
                "number" => "42",
                "street" => "Main Street",
                "postalCode" => "71000",
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

    public function testNestedFormRulesRenderErrorsBesideTheMatchingFields(): void
    {
        $response = $this->submit([
            "reference" => "ORDER-1",
            "address" => ["number" => "0", "postalCode" => "71000", "unit" => ["number" => "0"]],
        ]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString('aria-describedby="address-number--error"', $html);
        self::assertStringContainsString('aria-describedby="address-unit--number--error"', $html);
        self::assertStringContainsString("Enter a positive street number.", $html);
        self::assertStringContainsString("Enter a positive unit number.", $html);
        self::assertStringNotContainsString("Saved:", $html);
    }

    public function testSaveValidatesOmittedRequiredFieldsOnANewForm(): void
    {
        $response = $this->submit(["address" => ["street" => "Main Street"]]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString('aria-describedby="reference-error"', $html);
        self::assertStringContainsString('aria-describedby="address-postalCode--error"', $html);
        self::assertStringContainsString("Enter an order reference.", $html);
        self::assertStringContainsString("Enter a postal code.", $html);
        self::assertStringContainsString('name="address[street]" value="Main Street"', $html);
        self::assertStringNotContainsString("Saved:", $html);
    }

    public function testActionWithoutValidationAcceptsOmittedRequiredFields(): void
    {
        $response = $this->submit(["address" => ["street" => "Main Street"]], "order.preview");
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString("Preview: Main Street", $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    public function testNewFormAcceptsOmittedOptionalFields(): void
    {
        $response = $this->submit(["reference" => "ORDER-1", "address" => ["postalCode" => "71000"]]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString("Saved: 2 / 1 /  / 7 / ", $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    public function testPartialEditKeepsOmittedFieldsFromStateAcrossForms(): void
    {
        $page = new HydratedOrderPage();
        $page->draft = [
            "reference" => "ORDER-1",
            "quantity" => 8,
            "street" => "Main Street",
            "postalCode" => "71000",
            "note" => "Leave at reception",
        ];
        $response = $this->submit([
            "_state" => (new JsonPageStateSerializer())->serialize($page),
            "address" => ["number" => "42"],
        ]);
        $html = $response->getContent();

        self::assertSame(200, $response->getStatus(), $html);
        self::assertStringContainsString("Saved: 8 / 42 / Main Street / 7 / ", $html);
        self::assertStringContainsString('name="reference" value="ORDER-1"', $html);
        self::assertStringContainsString('name="address[postalCode]" value="71000"', $html);
        self::assertStringContainsString('name="note" value="Leave at reception"', $html);
        self::assertStringNotContainsString('aria-invalid="true"', $html);
    }

    private function submit(array $fields, string $action = "order.save"): Response
    {
        $app = new class extends Application {
            protected function modules(ModuleRegistry $modules): void
            {
                $modules->add(new ComponentModule(
                    Components::definitions(),
                    Components::resourceDirectory(),
                    Components::DEFAULT_RESOURCE_PATH
                ));
            }

            protected function bootstrap(Injector $injector): void
            {
                (new PageRouteRegistrar())->register($this->getRouter(), [
                    new PageDescriptor("/order", HydratedOrderPage::class),
                ]);
            }
        };

        return $app->handle(new Request("POST", "/order", parsedBody: ["_action" => $action, ...$fields]));
    }
}

final class HydratedOrderPage extends Page
{
    #[State]
    public array $draft = [];

    #[FormModel]
    public HydratedOrderForm $order;

    #[FormModel]
    public HydratedOrderNoteForm $delivery;

    public function template(): ?string
    {
        return "HydratedOrderPage.atom.html";
    }

    public function init(): void
    {
        $this->order = new HydratedOrderForm();
        $this->delivery = new HydratedOrderNoteForm();
        if ($this->draft !== []) {
            $this->order->reference = $this->draft["reference"];
            $this->order->quantity = $this->draft["quantity"];
            $this->order->address->street = $this->draft["street"];
            $this->order->address->postalCode = $this->draft["postalCode"];
            $this->delivery->note = $this->draft["note"];
        }
    }
}

final class HydratedOrderForm extends Form
{
    #[Required("Enter an order reference.")]
    public string $reference;
    public int $quantity = 2;
    public int|bool $rush = false;
    #[ValidateNested]
    public HydratedOrderAddress $address;
    public string $result = "";

    public function __construct()
    {
        $this->address = new HydratedOrderAddress(7);
    }

    #[PageAction]
    public function save(): void
    {
        if (!$this->validate()) {
            return;
        }

        $unit = $this->address->unit?->number;
        $this->result = "Saved: {$this->quantity} / {$this->address->number} / {$this->address->street} / {$this->address->zone()} / {$unit}";
    }

    #[PageAction]
    public function preview(): void
    {
        $this->result = "Preview: {$this->address->street}";
    }
}

final class HydratedOrderNoteForm extends Form
{
    public string $note = "";

    #[PageAction]
    public function save(): void
    {
    }
}

final class HydratedOrderAddress
{
    #[Min(1, "Enter a positive street number.")]
    public int $number = 1;
    public string $street = "";
    #[Required("Enter a postal code.")]
    public string $postalCode;
    #[ValidateNested]
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
    #[Min(1, "Enter a positive unit number.")]
    public int $number = 0;
}
