<?php

declare(strict_types=1);

namespace Atom\Identity;

use Atom\Http\AccessFailure;
use Atom\Http\MiddlewareInterface;
use Atom\Http\Request;
use Atom\Http\RequestHandlerInterface;
use Atom\Http\Response;

final readonly class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(private AuthenticatorInterface $authenticator)
    {
    }

    public function process(Request $request, RequestHandlerInterface $handler): Response
    {
        if ($this->authenticator->guest()) {
            return $handler->handle($request);
        }

        return AccessFailure::PermissionDenied->response($request, "Guest access required.");
    }
}
