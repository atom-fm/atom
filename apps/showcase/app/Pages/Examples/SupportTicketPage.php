<?php

declare(strict_types=1);

namespace Showcase\Pages\Examples;

use Atom\Http\Request;
use Atom\Http\Response;
use Atom\Page\PageRoute;
use Showcase\Pages\AppPage;
use Showcase\SupportDesk\Tickets;

#[PageRoute("/examples/support-desk/tickets/{id}", name: "showcase.examples.support-ticket")]
final class SupportTicketPage extends AppPage
{
    public string $contentWidth = "full";

    public array $ticket = [];
    public string $backUrl = "";
    public string $statusVariant = "";

    public function get(Request $request, string $id): ?Response
    {
        foreach (Tickets::all() as $ticket) {
            if ((string) $ticket["id"] !== $id) {
                continue;
            }

            $this->ticket = $ticket;
            $this->title = "Ticket #" . $id . " - Atom Showcase";
            $this->statusVariant = Tickets::variant($ticket["status"]);
            $this->backUrl = Tickets::url(
                $request->query()->string("query", ""),
                $request->query()->string("status", ""),
                $request->query()->int("page", 1)
            );

            return null;
        }

        return (new Response())->status(404)->content("Support ticket not found.");
    }
}
