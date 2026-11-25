<?php

declare(strict_types=1);

namespace Showcase\SupportDesk;

final class Tickets
{
    /** @return list<array{id: int, subject: string, customer: string, status: string, description: string}> */
    public static function all(): array
    {
        return [
            ["id" => 108, "subject" => "Invoice is missing from billing history", "customer" => "Nora Patel", "status" => "Open", "description" => "I renewed our annual plan yesterday, but the invoice hasn’t appeared in billing history. Could you help me find a copy?"],
            ["id" => 107, "subject" => "Can’t invite a teammate", "customer" => "Eli Warren", "status" => "Waiting", "description" => "I sent an invite to our new designer, but the invitation shows as expired before they can accept it."],
            ["id" => 106, "subject" => "Update the workspace name", "customer" => "Mia Torres", "status" => "Resolved", "description" => "Our studio has changed its name. Where can I update the name that appears on shared reports?"],
            ["id" => 105, "subject" => "Export stops before finishing", "customer" => "Sam Reed", "status" => "Open", "description" => "A CSV export for the last quarter stops partway through. Exports for individual weeks still work."],
            ["id" => 104, "subject" => "Change the billing contact", "customer" => "Ava Chen", "status" => "Open", "description" => "Our finance team has a new shared address. I’d like future invoices to go there."],
            ["id" => 103, "subject" => "Reset a team member’s access", "customer" => "Theo Brooks", "status" => "Waiting", "description" => "A teammate lost access to their old phone and needs help signing back in."],
            ["id" => 102, "subject" => "Duplicate charge on renewal", "customer" => "Jo Ellis", "status" => "Open", "description" => "Our card statement shows two charges for the same renewal. Can you check whether one is a pending authorization?"],
            ["id" => 101, "subject" => "Shared report permissions", "customer" => "Lea Martin", "status" => "Resolved", "description" => "We need contractors to view a single report without access to the rest of the workspace."],
        ];
    }

    public static function url(string $query = "", string $status = "", int $page = 1, ?int $ticket = null): string
    {
        $path = "/examples/support-desk" . ($ticket === null ? "" : "/tickets/" . $ticket);
        $params = array_filter(["query" => $query, "status" => $status, "page" => $page > 1 ? $page : ""], static fn ($value) => $value !== "");

        return $path . ($params === [] ? "" : "?" . http_build_query($params, "", "&", PHP_QUERY_RFC3986));
    }

    public static function variant(string $status): string
    {
        return match ($status) {
            "Open" => "info",
            "Waiting" => "warning",
            "Resolved" => "success",
            default => "",
        };
    }
}
