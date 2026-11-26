<?php

declare(strict_types=1);

namespace Atom\Tests\Validation;

use Atom\Tests\Validation\Types\CreateArticleDto;
use Atom\Validation\Rules\Required;
use Atom\Validation\Schema;
use Atom\Validation\ValidateNested;
use Atom\Validation\Validation;
use Atom\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidationTest extends TestCase
{
    public function testFluentSchemaValidation(): void
    {
        $schema = Schema::make()
            ->field("title")->required()->maxLength(10)
            ->field("body")->required()->minLength(5)
            ->field("status")->required()->in(["draft", "published"])
            ->field("categoryId")->numeric()->min(1)
            ->schema();

        $result = $schema->validate([
            "title" => "This title is too long",
            "body" => "Body",
            "status" => "archived",
            "categoryId" => 0,
        ]);

        $this->assertTrue($result->failed());
        $this->assertTrue($result->hasErrorsFor("title"));
        $this->assertSame("max_length", $result->errorsFor("title")[0]->code);
        $this->assertSame("min_length", $result->errorsFor("body")[0]->code);
        $this->assertSame("in", $result->errorsFor("status")[0]->code);
        $this->assertSame("min", $result->errorsFor("categoryId")[0]->code);
    }

    public function testAttributeValidation(): void
    {
        $dto = new CreateArticleDto();
        $dto->title = "";
        $dto->body = "Article body";
        $dto->status = "archived";
        $dto->categoryId = "0";

        $result = Validator::for(CreateArticleDto::class)->validate($dto);

        $this->assertTrue($result->failed());
        $this->assertSame("required", $result->errorsFor("title")[0]->code);
        $this->assertSame("in", $result->errorsFor("status")[0]->code);
        $this->assertSame("min", $result->errorsFor("categoryId")[0]->code);
    }

    public function testAttributeValidationCanInferClassFromObject(): void
    {
        $dto = new CreateArticleDto();
        $dto->title = "Valid title";
        $dto->body = "Long enough body";
        $dto->status = "published";
        $dto->categoryId = 1;

        $result = Validator::for()->validate($dto);

        $this->assertTrue($result->passed());
    }

    public function testNestedValidationOnlyFollowsMarkedPropertiesAndStopsAtCycles(): void
    {
        $root = new NestedValidationNode();
        $root->label = "Root";
        $child = new NestedValidationNode();
        $root->child = $child;
        $root->alternate = $child;
        $root->unmarked = $child;
        $child->child = $root;

        $result = Validator::for()->validate($root);

        self::assertSame(["child.label", "alternate.label"], array_keys($result->messages()));
        self::assertSame("required", $result->errorsFor("child.label")[0]->code);
    }

    public function testNestedValidationChecksPresenceOnlyWhenRequired(): void
    {
        $parent = new NestedValidationParent();
        $validator = Validator::for();

        self::assertSame(["requiredChild"], array_keys($validator->validate($parent)->messages()));

        $parent->requiredChild = new NestedValidationNode();

        self::assertSame(["requiredChild.label"], array_keys($validator->validate($parent)->messages()));
    }

    public function testNestedArrayValidationReportsDottedPaths(): void
    {
        $validator = Validator::for(NestedValidationParent::class);
        $data = [
            "requiredChild" => [
                "label" => "",
                "child" => ["label" => ""],
                "unmarked" => ["label" => ""],
            ],
        ];

        self::assertSame(
            ["requiredChild.label", "requiredChild.child.label"],
            array_keys($validator->validate($data)->messages())
        );

        $data["requiredChild"]["label"] = "Parent";
        $data["requiredChild"]["child"]["label"] = "Child";
        $data["requiredChild"]["child"]["child"] = &$data["requiredChild"];
        self::assertTrue($validator->validate($data)->passed());
    }

    public function testAttributeValidationOnlyUsesPublicInstanceProperties(): void
    {
        $model = new class {
            #[Required]
            public string $label = "";

            #[Required]
            private string $internalCode = "configured";

            #[ValidateNested]
            protected NestedValidationNode $internalChild;

            #[Required]
            public static string $configuration = "";
        };

        $validator = Validator::for($model::class);
        self::assertSame(["label"], array_keys($validator->validate($model)->messages()));
        self::assertTrue($validator->validate([
            "label" => "Public value",
            "internalCode" => "",
            "internalChild" => ["label" => ""],
            "configuration" => "",
        ])->passed());
    }

    public function testLegacyValidationBuilderStillWorksForSimpleRules(): void
    {
        $validation = Validation::create(function (Validation $rules): void {
            $rules->title->required()->maxLength(10);
            $rules->body->required();
        });

        $result = $validation->validate([
            "title" => "Too long for this field",
            "body" => "",
        ]);

        $this->assertTrue($result->failed());
        $this->assertSame("max_length", $result->errorsFor("title")[0]->code);
        $this->assertSame("required", $result->errorsFor("body")[0]->code);
    }

    public function testResultMessagesAreFormFriendly(): void
    {
        $schema = Schema::make()
            ->field("title")->required("Give this article a title.")
            ->schema();

        $result = $schema->validate(["title" => ""]);

        $this->assertSame("Give this article a title.", $result->first("title"));
        $this->assertSame([
            "title" => ["Give this article a title."],
        ], $result->messages());
    }
}

final class NestedValidationParent
{
    #[Required]
    #[ValidateNested]
    public ?NestedValidationNode $requiredChild = null;

    #[ValidateNested]
    public NestedValidationNode $optionalChild;
}

final class NestedValidationNode
{
    #[Required]
    public string $label = "";
    #[ValidateNested]
    public ?self $child = null;
    #[ValidateNested]
    public ?self $alternate = null;
    public ?self $unmarked = null;
}

