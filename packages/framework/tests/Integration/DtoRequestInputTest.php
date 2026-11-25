<?php

declare(strict_types=1);

namespace Atom\Tests\Integration;

use Atom\Application;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Router\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DtoRequestInputTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
        Route::clearRouter();
    }

    #[DataProvider("invalidBodyFields")]
    public function testInvalidDtoBodyFieldReturns422WithItsRequestPath(string $body, string $path): void
    {
        $response = (new DtoRequestInputApplication())->handle($this->request("/dto", $body));

        self::assertSame(422, $response->getStatus());
        self::assertSame(
            ["error" => "Invalid or missing body field: {$path}"],
            json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidBodyFields(): iterable
    {
        yield "missing constructor field" => ['{"shipping_address":{"postal_code":"42"}}', "quantity"];
        yield "invalid constructor field" => ['{"quantity":[],"shipping_address":{"postal_code":"42"}}', "quantity"];
        yield "missing nested property" => ['{"quantity":1,"shipping_address":{}}', "shipping_address.postal_code"];
        yield "invalid nested property" => ['{"quantity":1,"shipping_address":{"postal_code":[]}}', "shipping_address.postal_code"];
        yield "invalid parent shape" => ['{"quantity":1,"shipping_address":"invalid"}', "shipping_address"];
    }

    public function testInvalidNullableDtoDoesNotReachTheHandlerAsNull(): void
    {
        $response = (new DtoRequestInputApplication())->handle($this->request("/optional-dto", '{"quantity":[]}'));

        self::assertSame(422, $response->getStatus());
    }

    public function testGenuineDependencyFailureStillReturns500(): void
    {
        $response = (new DtoRequestInputApplication())->handle($this->request(
            "/broken",
            '{"quantity":1,"shipping_address":{"postal_code":"42"}}'
        ));

        self::assertSame(500, $response->getStatus());
    }

    private function request(string $path, string $body): Request
    {
        return new Request("POST", $path, body: $body, headers: [
            "Accept" => "application/json",
            "Content-Type" => "application/json",
        ]);
    }
}

final class DtoRequestInputApplication extends Application
{
    protected function bootstrap(Injector $injector): void
    {
        Route::post("/dto", static fn(OrderInput $input): array => ["quantity" => $input->quantity]);
        Route::post("/optional-dto", static fn(?OrderInput $input): array => ["quantity" => $input?->quantity]);
        Route::post("/broken", static fn(OrderInput $input, BrokenOrderService $service): array => []);
    }
}

#[Dto]
final readonly class OrderInput
{
    public function __construct(
        public int $quantity,
        #[FromBody("shipping_address")]
        public OrderAddressInput $address
    ) {
    }
}

final class OrderAddressInput
{
    #[FromBody("postal_code")]
    public string $postalCode;
}

final class BrokenOrderService
{
    public function __construct()
    {
        throw new HydrationException("Cannot hydrate service configuration.");
    }
}
