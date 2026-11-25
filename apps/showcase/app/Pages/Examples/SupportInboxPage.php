<?php

declare(strict_types=1);

namespace Showcase\Pages\Examples;

use Atom\Http\Request;
use Atom\Page\PageRoute;
use Showcase\Pages\AppPage;
use Showcase\SupportDesk\Tickets;

#[PageRoute("/examples/support-desk", name: "showcase.examples.support-desk")]
final class SupportInboxPage extends AppPage
{
    public string $title = "Support Desk - Atom Showcase";
    public string $contentWidth = "full";
    public string $query = "";
    public string $status = "";
    public int $page = 1;
    public int $pages = 1;
    public int $total = 0;
    public array $tickets = [];
    public array $statuses = [
        ["value" => "", "text" => "All statuses"],
        ["value" => "Open", "text" => "Open"],
        ["value" => "Waiting", "text" => "Waiting"],
        ["value" => "Resolved", "text" => "Resolved"],
    ];

    public function get(Request $request): void
    {
        $this->query = trim($request->query()->string("query", ""));
        $this->status = $request->query()->string("status", "");
        if (!in_array($this->status, ["Open", "Waiting", "Resolved"], true)) {
            $this->status = "";
        }
        $tickets = array_values(array_filter(Tickets::all(), fn (array $ticket): bool =>
            ($this->status === "" || $ticket["status"] === $this->status)
            && ($this->query === "" || stripos($ticket["subject"] . " " . $ticket["customer"], $this->query) !== false)
        ));
        $this->total = count($tickets);
        $this->pages = max(1, (int) ceil($this->total / 3));
        $this->page = min(max(1, $request->query()->int("page", 1)), $this->pages);
        $this->tickets = array_slice($tickets, ($this->page - 1) * 3, 3);
    }

    public function ticketUrl(int $id): string
    {
        return Tickets::url($this->query, $this->status, $this->page, $id);
    }

    public function pageUrl(): string
    {
        $url = Tickets::url($this->query, $this->status);

        return $url . (str_contains($url, "?") ? "&" : "?") . "page={page}";
    }

    public function statusVariant(string $status): string
    {
        return Tickets::variant($status);
    }
}
