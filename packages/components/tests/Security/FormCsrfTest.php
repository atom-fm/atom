<?php

declare(strict_types=1);

namespace Atom\Tests\Security;

use Atom\Di\Bindings;
use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Components\Form;
use Atom\Security\CsrfTokenManager;
use Atom\Security\CsrfTokenManagerInterface;
use Atom\Security\SecurityServices;
use Atom\Session\ArraySession;
use Atom\Session\SessionInterface;
use Atom\View\Component\AttributeBag;
use Atom\View\Component\Fragment;
use Atom\View\Component\InjectorComponentFactory;
use PHPUnit\Framework\TestCase;

final class FormCsrfTest extends TestCase
{
    public function testCsrfEnabledFormRendersHiddenToken(): void
    {
        $tokens = new CsrfTokenManager(new ArraySession(id: "form-session"));
        $form = new Form($tokens);
        $form->attributes = new AttributeBag();
        $form->csrf = true;
        $form->submit = "save";
        $form->content = new Fragment(static fn(): string => "<button>Save</button>");

        $html = $form->render();

        $this->assertStringContainsString('<form method="post" atom:submit="save" class="atom-form">', $html);
        $this->assertStringContainsString(
            '<input type="hidden" name="_token" value="' . $tokens->token() . '">',
            $html
        );
        $this->assertStringContainsString("<button>Save</button>", $html);
    }

    public function testInjectedFormUsesTokenManagerFromActiveRequestScope(): void
    {
        $bindings = Bindings::create();
        $bindings->bind(SessionInterface::class)
            ->toFactory(fn() => new ArraySession(id: "component-session"))
            ->scoped();
        (new SecurityServices())->register($bindings);
        $injector = new Injector($bindings);
        $context = new InjectionContext();
        $tokens = $injector->get(CsrfTokenManagerInterface::class, $context);
        $form = (new InjectorComponentFactory($injector, $context))->create(Form::class);

        $this->assertInstanceOf(Form::class, $form);
        $form->attributes = new AttributeBag();
        $form->csrf = true;

        $this->assertStringContainsString('value="' . $tokens->token() . '"', $form->render());
    }

    public function testGetFormDoesNotStartCsrfSession(): void
    {
        $session = new ArraySession(id: "get-form-session");
        $form = new Form(new CsrfTokenManager($session));
        $form->attributes = new AttributeBag();
        $form->csrf = true;
        $form->method = "get";

        $this->assertStringNotContainsString('name="_token"', $form->render());
        $this->assertFalse($session->isStarted());
    }
}
