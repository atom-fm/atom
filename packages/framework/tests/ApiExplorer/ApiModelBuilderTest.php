<?php

declare(strict_types=1);

namespace Atom\Tests\ApiExplorer;

use Atom\Api\Attributes\ArrayOf;
use Atom\Api\Attributes\ErrorResponse;
use Atom\Api\Attributes\ResponseOf;
use Atom\Api\ApiModelBuilder;
use Atom\Api\Responses\Created;
use Atom\Api\Responses\Conflict;
use Atom\Api\Responses\NotFound;
use Atom\Api\Responses\UnprocessableContent;
use Atom\Collections\PagedCollection;
use Atom\Database\Orm\PageResult;
use Atom\Http\HttpStatus;
use Atom\Hydrator\Attributes\Dto;
use Atom\Hydrator\Attributes\FromBody;
use Atom\Hydrator\Attributes\FromQuery;
use Atom\Router\RouteEntry;
use Atom\Router\Router;
use Atom\Validation\Rules\MaxLength;
use Atom\Validation\Rules\Required;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ApiModelBuilderTest extends TestCase
{
    public function testInfersPayloadAndTypedErrorFromReturnUnion(): void
    {
        $router = new Router();
        $router->add(RouteEntry::get(
            "/api/users/{id}",
            static fn (int $id): ApiUserResponse|NotFound => new NotFound("User not found")
        )->api());

        $endpoint = (new ApiModelBuilder())->describe($router)->endpoints[0];
        $this->assertSame(ApiUserResponse::class, $endpoint->responseType);
        $this->assertSame("id", $endpoint->responseFields[0]->name);
        $this->assertCount(1, $endpoint->errorResponses);
        $this->assertSame(HttpStatus::NotFound->value, $endpoint->errorResponses[0]->status);
        $this->assertSame("error", $endpoint->errorResponses[0]->fields[0]->name);
        $this->assertSame("string", $endpoint->errorResponses[0]->fields[0]->type);
    }

    public function testInfersCreatedStatusAndPayloadFromReturnUnion(): void
    {
        $router = new Router();
        $router->add(RouteEntry::post("/api/users", [ApiUsersController::class, "create"])->api());

        $endpoint = (new ApiModelBuilder())->describe($router)->endpoints[0];
        $this->assertSame(HttpStatus::Created->value, $endpoint->successStatus);
        $this->assertSame(ApiUserResponse::class, $endpoint->responseType);
        $this->assertSame("id", $endpoint->responseFields[0]->name);
        $this->assertSame([404, 409, 422], array_column($endpoint->errorResponses, "status"));
        foreach ($endpoint->errorResponses as $error) {
            $this->assertSame("error", $error->fields[0]->name);
        }
    }

    #[TestWith(["page"], "ORM page")]
    #[TestWith(["collection"], "paged collection")]
    public function testDescribesPaginationUsingTheDeclaredItemType(string $action): void
    {
        $router = new Router();
        $router->add(RouteEntry::get("/api/users", [ApiUsersController::class, $action])->api());

        $fields = (new ApiModelBuilder())->describe($router)->endpoints[0]->responseFields;
        $this->assertSame(["items", "page", "page_size", "total", "total_pages"], array_column($fields, "name"));
        $this->assertSame("ApiUserResponse[]", $fields[0]->type);
        $this->assertSame("id", $fields[0]->children[0]->name);
    }

    public function testDescribesPaginationItemsAsAnArrayWithoutADeclaredType(): void
    {
        $router = new Router();
        $router->add(RouteEntry::get(
            "/api/users",
            static fn (): PagedCollection => PagedCollection::fromPage([], 0, 1, 10)
        )->api());

        $fields = (new ApiModelBuilder())->describe($router)->endpoints[0]->responseFields;
        self::assertSame(["array", "int", "int", "int", "int"], array_column($fields, "type"));
        self::assertSame([], $fields[0]->children);
    }

    public function testDescribesControllerRouteAndDtoFields(): void
    {
        $router = new Router();
        $router->add(
            RouteEntry::post("/api/users/{id}", [ApiUsersController::class, "update"])
                ->api()
                ->name("users.update")
                ->title("Update user")
                ->description("Updates one user.")
        );
        $router->add(
            RouteEntry::get("/api/articles", [ApiUsersController::class, "articles"])
                ->api()
                ->name("articles.index")
        );

        $router->add(RouteEntry::get("/api/hidden", static fn() => null)
            ->api()->metadata(new \Atom\Api\ApiHidden()));
        $description = (new ApiModelBuilder())->describe($router);

        $this->assertCount(2, $description->endpoints);
        $endpoint = $description->endpoints[0];
        $this->assertSame(["POST"], $endpoint->methods);
        $this->assertSame("/api/users/{id}", $endpoint->path);
        $this->assertSame("users.update", $endpoint->name);
        $this->assertSame(ApiUsersController::class . "::update", $endpoint->handler);
        $this->assertSame(ApiUserResponse::class, $endpoint->responseType);
        $this->assertCount(3, $endpoint->responseFields);
        $this->assertSame("id", $endpoint->responseFields[0]->name);
        $this->assertSame("response", $endpoint->responseFields[0]->source);
        $this->assertSame("int", $endpoint->responseFields[0]->type);
        $this->assertTrue($endpoint->responseFields[0]->required);
        $this->assertSame("displayName", $endpoint->responseFields[1]->name);
        $this->assertSame("?string", $endpoint->responseFields[1]->type);
        $this->assertFalse($endpoint->responseFields[1]->required);

        $this->assertCount(4, $endpoint->requestFields);
        $this->assertSame("id", $endpoint->requestFields[0]->name);
        $this->assertSame("route", $endpoint->requestFields[0]->source);
        $this->assertSame("int", $endpoint->requestFields[0]->type);

        $this->assertSame("name", $endpoint->requestFields[1]->name);
        $this->assertSame("body", $endpoint->requestFields[1]->source);
        $this->assertTrue($endpoint->requestFields[1]->required);
        $this->assertSame([Required::class, MaxLength::class], $endpoint->requestFields[1]->validationRules);

        $this->assertSame("inviteCode", $endpoint->requestFields[3]->name);
        $this->assertSame("query", $endpoint->requestFields[3]->source);
        $this->assertSame("invite", $endpoint->requestFields[3]->sourceName);
        $this->assertSame("?string", $endpoint->requestFields[3]->type);
        $this->assertFalse($endpoint->requestFields[3]->required);

        $articlesEndpoint = $description->endpoints[1];
        $this->assertSame(ApiPageResponse::class, $articlesEndpoint->responseType);
        $this->assertCount(3, $articlesEndpoint->responseFields);
        $this->assertSame("items", $articlesEndpoint->responseFields[0]->name);
        $this->assertSame("ApiArticleResponse[]", $articlesEndpoint->responseFields[0]->type);
        $this->assertCount(3, $articlesEndpoint->responseFields[0]->children);
        $this->assertSame("author", $articlesEndpoint->responseFields[0]->children[2]->name);
        $this->assertSame(ApiAuthorResponse::class, $articlesEndpoint->responseFields[0]->children[2]->type);
        $this->assertCount(3, $articlesEndpoint->responseFields[0]->children[2]->children);
        $this->assertSame("name", $articlesEndpoint->responseFields[0]->children[2]->children[1]->name);
        $this->assertSame("profile", $articlesEndpoint->responseFields[0]->children[2]->children[2]->name);
        $this->assertSame([], $articlesEndpoint->responseFields[0]->children[2]->children[2]->children);
        $this->assertCount(1, $articlesEndpoint->errorResponses);
        $this->assertSame(404, $articlesEndpoint->errorResponses[0]->status);
        $this->assertSame(ApiNotFoundResponse::class, $articlesEndpoint->errorResponses[0]->type);
        $this->assertSame("Article was not found.", $articlesEndpoint->errorResponses[0]->description);
        $this->assertSame("message", $articlesEndpoint->errorResponses[0]->fields[0]->name);
    }
}

final class ApiUsersController
{
    #[ResponseOf(ApiUserResponse::class)]
    public function page(): PageResult
    {
        return new PageResult([new ApiUserResponse()], 1, 1, 10);
    }

    #[ResponseOf(ApiUserResponse::class)]
    public function collection(): PagedCollection
    {
        return PagedCollection::fromPage([new ApiUserResponse()], 1, 1, 10);
    }

    #[ResponseOf(ApiUserResponse::class)]
    public function create(): Created|NotFound|Conflict|UnprocessableContent
    {
        return new Created("/api/users/1", new ApiUserResponse());
    }

    public function update(int $id, ApiCreateUserDto $dto): ApiUserResponse
    {
        return new ApiUserResponse();
    }

    #[ResponseOf(ApiArticleResponse::class)]
    #[ErrorResponse(404, ApiNotFoundResponse::class, "Article was not found.")]
    public function articles(): ApiPageResponse
    {
        return new ApiPageResponse();
    }
}

#[Dto]
final class ApiCreateUserDto
{
    #[FromBody]
    #[Required]
    #[MaxLength(80)]
    public string $name;

    #[FromBody]
    public int $age;

    #[FromQuery("invite")]
    public ?string $inviteCode = null;
}

final class ApiUserResponse
{
    public int $id;
    public ?string $displayName = null;
    public bool $active;
}

final class ApiPageResponse
{
    #[ArrayOf]
    public array $items = [];

    public int $total;
    public int $page;
}

final class ApiArticleResponse
{
    public int $id;
    public string $title;
    public ApiAuthorResponse $author;
}

final class ApiAuthorResponse
{
    public int $id;
    public string $name;
    public ApiAuthorProfileResponse $profile;
}

final class ApiAuthorProfileResponse
{
    public ApiAuthorWebsiteResponse $website;
}

final class ApiAuthorWebsiteResponse
{
    public string $url;
}

final class ApiNotFoundResponse
{
    public string $message;
}
