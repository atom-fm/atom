<?php

declare(strict_types=1);

namespace Atom\Tests\Mail;

use Atom\Application;
use Atom\Mail\MailerInterface;
use Atom\Mail\MailMessage;
use Atom\Mail\Mailable;
use Atom\Mail\MailTemplateRenderer;
use Atom\Mail\SymfonyMailer;
use Atom\Modules\Accounts\Mail\PasswordResetMail;
use PHPUnit\Framework\TestCase;

final class MailTest extends TestCase
{
    public function testApplicationRegistersMailWithTheDefaultNullTransport(): void
    {
        $app = new class extends Application {};
        $app->initialize();
        $mailer = $app->getInjector()->get(MailerInterface::class);

        $this->assertInstanceOf(SymfonyMailer::class, $mailer);
        $mailer->send(new class extends Mailable {
            public function build(): MailMessage
            {
                return MailMessage::create()
                    ->to("person@example.com")
                    ->subject("Welcome")
                    ->text("Hello");
            }
        });
    }

    public function testAccountsPasswordResetMailProvidesTextAndHtmlContent(): void
    {
        $message = (new PasswordResetMail(
            "person@example.com",
            "https://example.com/account/reset-password?token=secret",
            "Edin"
        ))->build();

        $this->assertSame("Reset your password", $message->subjectLine());
        $this->assertSame("Edin", $message->recipients()[0]->name);
        $this->assertStringContainsString("https://example.com", $message->textContent() ?? "");

        $template = $message->htmlView();
        $this->assertNotNull($template);
        $html = (new MailTemplateRenderer())->render($template);
        $this->assertStringContainsString("Reset password", $html);
        $this->assertStringContainsString("token=secret", $html);
    }
}
