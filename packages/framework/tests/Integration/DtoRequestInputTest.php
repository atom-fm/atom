<?php

declare(strict_types=1);

namespace Atom\Tests\Integration;

use Atom\Application;
use Atom\Di\Injector;
use Atom\Dispatcher\ResponseEmitterInterface;
use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Exception\HydrationException;
use Atom\Router\Route;
use Atom\Validation\Rules\Pattern;
use Atom\Validation\ValidateNested;
use Atom\Validation\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DtoRequestInputTest extends TestCase
{
    protected function tearDown(): void
    {
        Application::$app = null;
        Route::clearRouter();
    }

    #[DataProvider("timedErrorResponses")]
    public function testErrorResponsesIncludeTimingThroughApplicationEntryPoints(
        bool $emit,
        string $path,
        string $requestBody,
        int $status,
        string $message
    ): void {
        $app = new DtoRequestInputApplication();
        $app->initialize();
        $emitter = new class implements ResponseEmitterInterface {
            public ?Response $response = null;

            public function emit(Response $response): void
            {
                $this->response = $response;
            }
        };
        $app->getBindings()->value(ResponseEmitterInterface::class, $emitter);
        $request = $this->request($path, $requestBody);

        $response = $emit ? $app->run($request) : $app->handle($request);

        self::assertSame($status, $response->getStatus());
        self::assertSame("application/json", $response->headers()->get("Content-Type"));
        $error = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)["error"];
        self::assertSame($message, is_array($error) ? $error["title"] : $error);
        self::assertSame($emit ? $response : null, $emitter->response);
        foreach (["X-Atom-Time", "X-Atom-Time-Request-Dispatch"] as $header) {
            self::assertMatchesRegularExpression('/^\d+\.\d{2}ms$/D', (string) $response->headers()->get($header), $header);
        }
    }

    /** @return iterable<string, array{bool, string, string, int, string}> */
    public static function timedErrorResponses(): iterable
    {
        foreach (["handle" => false, "run" => true] as $entryPoint => $emit) {
            yield "{$entryPoint} malformed JSON" => [$emit, "/dto", '{"quantity":', 400, "Bad request"];
            yield "{$entryPoint} invalid DTO" => [
                $emit, "/dto", '{"quantity":[]}', 422, "Invalid or missing body field: quantity",
            ];
            yield "{$entryPoint} dependency failure" => [
                $emit, "/broken", '{"quantity":1,"shipping_address":{"postal_code":"42"}}', 500, "Something went wrong",
            ];
        }
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
        self::assertSame(
            ["error" => "Invalid or missing body field: quantity"],
            json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testNestedDtoRuleReturns422WithADottedPropertyPath(): void
    {
        $response = (new DtoRequestInputApplication())->handle($this->request(
            "/validated-dto",
            '{"quantity":1,"shipping_address":{"postal_code":"invalid"}}'
        ));

        self::assertSame(422, $response->getStatus());
        self::assertSame(
            ["errors" => ["address.postalCode" => ["Enter a numeric postal code."]]],
            json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR)
        );
    }

    public function testValidNestedDtoPassesValidation(): void
    {
        $response = (new DtoRequestInputApplication())->handle($this->request(
            "/validated-dto",
            '{"quantity":1,"shipping_address":{"postal_code":"42"}}'
        ));

        self::assertSame(200, $response->getStatus());
        self::assertSame(["quantity" => 1], json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR));
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
        Route::post("/validated-dto", static function (OrderInput $input, Response $response): Response {
            $validation = Validator::for()->validate($input);

            return $validation->failed()
                ? $response->status(422)->json(["errors" => $validation->messages()])
                : $response->json(["quantity" => $input->quantity]);
        });
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
        #[ValidateNested]
        public OrderAddressInput $address
    ) {
    }
}

final class OrderAddressInput
{
    #[FromBody("postal_code")]
    #[Pattern('/^\d+$/D', "Enter a numeric postal code.")]
    public string $postalCode;
}

final class BrokenOrderService
{
    public function __construct()
    {
        throw new HydrationException("Cannot hydrate service configuration.", fieldPath: "quantity", source: "body");
    }
}
