<?php

declare(strict_types=1);

namespace Atom\Tests\Module;

use Atom\Application;
use Atom\Components\Components;
use Atom\Integration\View\ComponentModule;
use Atom\Components\ComponentsScripts;
use Atom\Components\ComponentsStyles;
use Atom\Components\FieldError;
use Atom\Components\FileField;
use Atom\Components\InputGroup;
use Atom\Components\PasswordField;
use Atom\Components\TextArea;
use Atom\Components\TextInput;
use Atom\Components\Progress;
use Atom\Components\RadioField;
use Atom\Components\Skeleton;
use Atom\Components\Spinner;
use Atom\Components\SwitchField;
use Atom\Components\ValidationSummary;
use Atom\Http\Request;
use Atom\View\Component\ComponentRegistry;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

final class ComponentsModuleTest extends TestCase
{
    protected function setUp(): void
    {
        Application::$app = null;
    }

    protected function tearDown(): void
    {
        Application::$app = null;
    }

    #[TestWith([Components::DEFAULT_RESOURCE_PATH], "default asset path")]
    #[TestWith(["/widgets/assets"], "custom asset path")]
    public function testComponentModuleRegistersSharedComponentsAndResources(string $resourcePath): void
    {
        $app = new class extends Application {};
        $app->initialize();

        $app->registerModule(new ComponentModule(
            Components::definitions(),
            Components::resourceDirectory(),
            $resourcePath
        ));

        $components = $app->getInjector()->get(ComponentRegistry::class);

        $this->assertSame(FieldError::class, $components->get("FieldError"));
        $this->assertSame(FileField::class, $components->get("FileField"));
        $this->assertSame(ComponentsStyles::class, $components->get("ComponentsStyles"));
        $this->assertSame(ComponentsScripts::class, $components->get("ComponentsScripts"));
        $this->assertSame(TextArea::class, $components->get("TextArea"));
        $this->assertSame(TextInput::class, $components->get("TextInput"));
        $this->assertSame(ValidationSummary::class, $components->get("ValidationSummary"));
        $this->assertSame(RadioField::class, $components->get("RadioField"));
        $this->assertSame(SwitchField::class, $components->get("SwitchField"));
        $this->assertSame(InputGroup::class, $components->get("InputGroup"));
        $this->assertSame(PasswordField::class, $components->get("PasswordField"));
        $this->assertSame(Progress::class, $components->get("Progress"));
        $this->assertSame(Spinner::class, $components->get("Spinner"));
        $this->assertSame(Skeleton::class, $components->get("Skeleton"));
        foreach ([
            "atom.css" => "text/css; charset=utf-8",
            "atom-controls.js" => "application/javascript; charset=utf-8",
        ] as $asset => $contentType) {
            $response = $app->handle(new Request("GET", "{$resourcePath}/{$asset}"));

            $this->assertSame(200, $response->getStatus());
            $this->assertSame($contentType, $response->headers()->get("Content-Type"));
            $this->assertSame(file_get_contents(Components::resourceDirectory() . "/{$asset}"), $response->getContent());
        }
    }
}
