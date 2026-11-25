<?php

declare(strict_types=1);

namespace Atom\Http;

enum AccessFailure: string
{
    public const string HEADER = "X-Atom-Error-Code";

    case AuthenticationRequired = "authentication_required";
    case InvalidCsrfToken = "invalid_csrf_token";
    case PermissionDenied = "permission_denied";

    public function response(Request $request, ?string $message = null): Response
    {
        [$status, $title, $defaultMessage] = match ($this) {
            self::AuthenticationRequired => [401, "Authentication required", "Authentication required."],
            self::InvalidCsrfToken => [403, "Invalid CSRF token", "Invalid CSRF token."],
            self::PermissionDenied => [403, "Access denied", "Permission denied."],
        };
        $message ??= $defaultMessage;
        $response = (new Response())
            ->status($status)
            ->header(self::HEADER, $this->value)
            ->header("Cache-Control", "no-store")
            ->header("Vary", "Accept");

        if ($request->acceptsJson()) {
            return $response->json(["error" => [
                "status" => $status,
                "title" => $title,
                "message" => $message,
                "code" => $this->value,
            ]]);
        }

        return $response->header("Content-Type", "text/plain; charset=utf-8")->content($message);
    }
}
