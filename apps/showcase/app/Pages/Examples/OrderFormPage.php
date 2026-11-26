<?php

declare(strict_types=1);

namespace Showcase\Pages\Examples;

use Atom\Page\FormModel;
use Atom\Page\PageAction;
use Atom\Page\PageRoute;
use Atom\Security\CsrfMiddleware;
use Atom\Session\SessionInterface;
use Showcase\Orders\OrderForm;
use Showcase\Pages\AppPage;

#[PageRoute("/examples/order-form", name: "showcase.examples.order-form", middleware: CsrfMiddleware::class)]
final class OrderFormPage extends AppPage
{
    private const SESSION_KEY = "showcase.order-form";

    public string $title = "Order Form - Atom Showcase";
    public bool $saved = false;

    #[FormModel]
    public OrderForm $order;

    /** @var array{reference: string, quantity: ?int, street: string, postalCode: string, unitNumber: ?int}|null */
    public ?array $savedOrder = null;

    public function __construct(private readonly SessionInterface $session)
    {
    }

    public function init(): void
    {
        $this->savedOrder = $this->session->get(self::SESSION_KEY);
        $saved = $this->savedOrder;
        $this->order = new OrderForm();
        if ($saved !== null) {
            $this->order->reference = $saved["reference"];
            $this->order->quantity = $saved["quantity"];
            $this->order->address->street = $saved["street"];
            $this->order->address->postalCode = $saved["postalCode"];
            $this->order->address->unit->number = $saved["unitNumber"];
        }
    }

    #[PageAction]
    public function save(): void
    {
        if (!$this->order->validate()) {
            return;
        }

        $this->savedOrder = [
            "reference" => $this->order->reference,
            "quantity" => $this->order->quantity,
            "street" => $this->order->address->street,
            "postalCode" => $this->order->address->postalCode,
            "unitNumber" => $this->order->address->unit->number,
        ];
        $this->session->put(self::SESSION_KEY, $this->savedOrder);
        $this->saved = true;
    }

    #[PageAction]
    public function startOver(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->savedOrder = null;
        $this->order = new OrderForm();
    }
}
