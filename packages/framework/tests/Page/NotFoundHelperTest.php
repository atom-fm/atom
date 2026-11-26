<?php

declare(strict_types=1);

namespace Atom\Tests\Page;

use Atom\Http\HttpException;
use Atom\Http\Request;
use Atom\Modules\ErrorPages\DefaultErrorPageHandler;
use Atom\Modules\ErrorPages\ErrorPagesOptions;
use Atom\Page\Page;
use PHPUnit\Framework\TestCase;

final class NotFoundHelperTest extends TestCase
{
    public function testHelperThrowsAnHttp404AndStopsInitialization(): void
    {
        $page = new MissingEntityPage();
        try {
            $page->init();
            self::fail('Initialization must stop.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->status());
            self::assertSame('Variant not found.', $exception->getMessage());
            self::assertFalse($page->continued);
        }
    }

    public function testExistingErrorHandlerRendersTheHelperAsHtmlOrJson(): void
    {
        try {
            (new MissingEntityPage())->init();
            self::fail('Expected HTTP exception.');
        } catch (HttpException $exception) {
            $handler = new DefaultErrorPageHandler(new ErrorPagesOptions());
            $html = $handler->forException($exception, new Request('GET', '/missing'));
            self::assertSame(404, $html->getStatus());
            self::assertStringContainsString('Page not found', $html->getContent());
            self::assertStringContainsString('Variant not found.', $html->getContent());
            self::assertStringNotContainsString('Diagnostics', $html->getContent());
            $json = $handler->forException($exception, new Request('POST', '/missing', headers: ['Accept' => 'application/json']));
            $body = json_decode($json->getContent(), true);
            self::assertSame(404, $body['error']['status']);
            self::assertSame('Variant not found.', $body['error']['message']);
        }
    }
}

final class MissingEntityPage extends Page
{
    public bool $continued = false;
    public function init(): void
    {
        $this->notFound('Variant not found.');
        $this->continued = true;
    }
}
