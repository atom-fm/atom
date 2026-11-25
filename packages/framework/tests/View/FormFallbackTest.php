<?php

declare(strict_types=1);

namespace Atom\Tests\View;

use Atom\Http\Request;
use Atom\Http\RequestHandlerInterface;
use Atom\Http\Response;
use Atom\Modules\Components\Form;
use Atom\Security\CsrfMiddleware;
use Atom\Security\CsrfTokenManager;
use Atom\Session\ArraySession;
use Atom\View\Component\AttributeBag;
use DOMDocument;
use DOMXPath;
use PHPUnit\Framework\TestCase;

final class FormFallbackTest extends TestCase
{
    public function testRenderedFormCanSubmitItsActionAndCsrfWithoutJavaScript(): void
    {
        $tokens = new CsrfTokenManager(new ArraySession());
        $form = new Form($tokens);
        $form->attributes = new AttributeBag();
        $form->submit = "save(12, 'draft')";
        $form->csrf = true;
        $document = new DOMDocument();
        $document->loadHTML($form->render());
        $inputs = (new DOMXPath($document))->query("//input[@type='hidden']");
        $body = [];
        foreach ($inputs as $input) {
            $body[$input->getAttribute("name")] = $input->getAttribute("value");
        }

        $handler = new class implements RequestHandlerInterface {
            public function handle(Request $request): Response
            {
                return (new Response())->content($request->post()->string("_action"));
            }
        };
        $response = (new CsrfMiddleware($tokens))->process(new Request("POST", "/edit", parsedBody: $body), $handler);

        self::assertSame(200, $response->getStatus());
        self::assertSame("save(12, 'draft')", $body["_action"]);
        self::assertTrue($tokens->validate($body["_token"]));
    }

    public function testGetAndPlainFormsDoNotAddPageActionInputs(): void
    {
        $form = new Form();
        $form->attributes = new AttributeBag();
        self::assertStringNotContainsString('name="_action"', $form->render());
        $form->submit = "search";
        $form->method = "get";
        self::assertStringNotContainsString('name="_action"', $form->render());
    }
}
