<?php

declare(strict_types=1);

namespace Atom\Tests\Mail;

use Atom\Mail\ArrayMailer;
use Atom\Mail\MailException;
use Atom\Mail\Mailable;
use Atom\Mail\MailMessage;
use Atom\Mail\MailAddress;
use Atom\Mail\MailTemplate;
use Atom\Mail\MailTemplateRenderer;
use Atom\Mail\SymfonyMailer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final class MailTest extends TestCase
{
    public function testArrayMailerBuildsAndRecordsMailables(): void
    {
        $mailer = new ArrayMailer();
        $mailer->send(new TestMailable());

        $message = $mailer->messages()[0];
        $this->assertSame("Welcome", $message->subjectLine());
        $this->assertSame("person@example.com", $message->recipients()[0]->address);
        $this->assertSame("Hello", $message->textContent());
    }

    public function testSymfonyAdapterMapsFrameworkMessageAndRendersPhpTemplate(): void
    {
        $directory = sys_get_temp_dir() . "/atom_mail_" . uniqid();
        mkdir($directory);
        $template = $directory . "/message.php";
        file_put_contents($template, "<strong><?= htmlspecialchars(\$name) ?></strong>");
        $attachment = $directory . "/report.txt";
        file_put_contents($attachment, "report");

        try {
            $transport = new CapturingTransport();
            $mailer = new SymfonyMailer(
                new Mailer($transport),
                new MailTemplateRenderer(),
                new MailAddress("sender@example.com", "Atom")
            );
            $mailer->send(new ConfiguredMailable($template, $attachment));

            $email = $transport->message;
            $this->assertInstanceOf(Email::class, $email);
            $this->assertSame("sender@example.com", $email->getFrom()[0]->getAddress());
            $this->assertSame("person@example.com", $email->getTo()[0]->getAddress());
            $this->assertSame("Message subject", $email->getSubject());
            $this->assertSame("Plain message", $email->getTextBody());
            $this->assertSame("<strong>Edin</strong>", $email->getHtmlBody());
            $this->assertCount(1, $email->getAttachments());
        } finally {
            @unlink($template);
            @unlink($attachment);
            @rmdir($directory);
        }
    }

    public function testMailTemplatePreservesZeroOutput(): void
    {
        $template = tempnam(sys_get_temp_dir(), "atom_mail_");
        self::assertNotFalse($template);

        try {
            file_put_contents($template, "<?php echo 0;");
            self::assertSame("0", (new MailTemplateRenderer())->render(new MailTemplate($template)));
        } finally {
            unlink($template);
        }
    }

    public function testMissingMailTemplateRaisesMailException(): void
    {
        $this->expectException(MailException::class);
        $this->expectExceptionMessage("does not exist");

        (new MailTemplateRenderer())->render(new MailTemplate("missing-template.php"));
    }

    public function testDefaultNullTransportCanSafelyAcceptMail(): void
    {
        $mailer = new SymfonyMailer(
            new Mailer(Transport::fromDsn("null://null")),
            new MailTemplateRenderer(),
            new MailAddress("no-reply@localhost")
        );

        $mailer->send(new TestMailable());
        $this->addToAssertionCount(1);
    }
}

final class TestMailable extends Mailable
{
    public function build(): MailMessage
    {
        return MailMessage::create()
            ->to("person@example.com")
            ->subject("Welcome")
            ->text("Hello");
    }
}

final class ConfiguredMailable extends Mailable
{
    public function __construct(
        private readonly string $template,
        private readonly string $attachment
    ) {
    }

    public function build(): MailMessage
    {
        return MailMessage::create()
            ->to("person@example.com", "Person")
            ->replyTo("help@example.com")
            ->subject("Message subject")
            ->text("Plain message")
            ->htmlTemplate($this->template, ["name" => "Edin"])
            ->attach($this->attachment, "report.txt", "text/plain");
    }
}

final class CapturingTransport implements TransportInterface
{
    public ?RawMessage $message = null;

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        $this->message = $message;
        return null;
    }

    public function __toString(): string
    {
        return "capture://";
    }
}
