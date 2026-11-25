<?php

declare(strict_types=1);

namespace Atom\Page;

use Atom\Di\InjectionContext;
use Atom\Di\Injector;
use Atom\Http\Request;
use Atom\Session\NativeSession;
use Atom\Session\SessionInterface;

/** Carries a Page notice over its normal POST/redirect/GET cycle. */
final readonly class PageFlashStore
{
    private const KEY = "atom.page.uri.flash";
    public function __construct(private Injector $injector, private InjectionContext $context) {}

    public function store(Page $page, string $url): void
    {
        $this->storeFlash($page, $url, $page::class);
    }

    public function storeForDestination(Page $page, string $url): void
    {
        $query = [];
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $destination = new Request("GET", (string) parse_url($url, PHP_URL_PATH), $query);
        $this->storeFlash($page, $destination->getRequestUri(), null);
    }

    private function storeFlash(Page $page, string $url, ?string $pageClass): void
    {
        if (!$page->hasFlash()) return;
        $this->session()?->put(self::KEY, [
            'page' => $pageClass, 'url' => $url,
            'message' => $page->flashMessage(), 'variant' => $page->flashVariant(), 'title' => $page->flashTitle(),
        ]);
    }

    public function restore(Page $page, ?string $pageUrl, ?string $requestUri = null): void
    {
        $session = $this->session();
        if ($session instanceof NativeSession && !$session->isStarted() && !$session->hasSessionCookie()) {
            return;
        }
        $flash = $session?->get(self::KEY);
        if (!is_array($flash)) return;
        $pageClass = $flash['page'] ?? null;
        $url = $pageClass === null ? $requestUri : $pageUrl;
        if ($url === null || ($flash['url'] ?? null) !== $url || ($pageClass !== null && $pageClass !== $page::class)) return;
        $session?->remove(self::KEY);
        if (!$page->hasFlash()) $page->flash((string) $flash['message'], (string) $flash['variant'], (string) $flash['title']);
    }

    private function session(): ?SessionInterface
    {
        $session = $this->context->get(SessionInterface::class);
        if ($session instanceof SessionInterface) return $session;
        return $this->injector->has(SessionInterface::class) ? $this->injector->get(SessionInterface::class, $this->context) : null;
    }
}
