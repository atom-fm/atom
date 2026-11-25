# Mail

[Atom Framework](Index.md)

Build a welcome email with plain-text and HTML versions. You can inspect the recipient and both bodies locally before configuring a mail transport.

## Mailables

In an Atom Starter project with Composer dependencies installed, create `app/Mail/WelcomeMail.php`. The constructor takes the two values available after signup, the member's email address and display name.

```php
<?php

declare(strict_types=1);

namespace App\Mail;

use Atom\Mail\Mailable;
use Atom\Mail\MailMessage;

final class WelcomeMail extends Mailable
{
    public function __construct(
        private readonly string $email,
        private readonly string $name
    ) {
    }

    public function build(): MailMessage
    {
        return MailMessage::create()
            ->to($this->email, $this->name)
            ->subject("Welcome to the book club")
            ->text("Hello {$this->name}, your membership is ready.")
            ->htmlTemplate(__DIR__ . "/welcome.php", ["name" => $this->name]);
    }
}
```

Save the HTML body in `app/Mail/welcome.php`. The key passed to `htmlTemplate()` becomes the variable `$name` in this PHP template. Escape the display name where it enters the markup.

```php
<p>Hello <strong><?= htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8") ?></strong>, your membership is ready.</p>
```

## Preview the welcome email

Create `preview-welcome.php` in the project root. `ArrayMailer` builds the message and keeps it in memory. Use `MailTemplateRenderer` to inspect the HTML that the configured mailer would generate.

```php
<?php

declare(strict_types=1);

use App\Mail\WelcomeMail;
use Atom\Mail\ArrayMailer;
use Atom\Mail\MailTemplateRenderer;

require __DIR__ . "/vendor/autoload.php";

$mailer = new ArrayMailer();
$mailer->send(new WelcomeMail("reader@example.test", "Alex & Sam"));
$message = $mailer->messages()[0];

echo $message->recipients()[0]->address, PHP_EOL;
echo $message->subjectLine(), PHP_EOL;
echo $message->textContent(), PHP_EOL;
echo (new MailTemplateRenderer())->render($message->htmlView());
```

Run `php preview-welcome.php` from the project root. The ampersand in the name stays literal in plain text and appears as `&amp;` in the HTML source.

```text
reader@example.test
Welcome to the book club
Hello Alex & Sam, your membership is ready.
<p>Hello <strong>Alex &amp; Sam</strong>, your membership is ready.</p>
```

## Attach the club's reading list

Give new members a copy of the reading list with their welcome email. Save this file as `app/Mail/books.csv` beside `WelcomeMail.php`.

```csv
title,author
Frankenstein,Mary Shelley
Persuasion,Jane Austen
```

In `WelcomeMail::build()`, add the following call immediately after `->subject("Welcome to the book club")` in the existing chain.

```php
->attach(__DIR__ . "/books.csv", "reading-list.csv", "text/csv")
```

The first argument locates the file on the server. The second supplies the filename readers see, and the third sets its MIME type. Keep `books.csv` with the application files so the mailer can read it when it prepares the email.

To check the attachment locally, append this code to the existing `preview-welcome.php` script.

```php
$attachment = $message->attachments()[0];
$contents = file_get_contents($attachment->path);
if ($contents === false) {
    throw new RuntimeException("Could not read the attached reading list.");
}

printf("\n%s (%s)\n", $attachment->name, $attachment->contentType);
echo $contents;
```

Run `php preview-welcome.php` again. After the welcome message, the preview shows the attachment name, type, and file contents.

```text
reading-list.csv (text/csv)
title,author
Frankenstein,Mary Shelley
Persuasion,Jane Austen
```

## Send replies to the club organizer

Members who click Reply on the welcome email should reach the organizer. Keep the club's configured sender and add the organizer's address to the message. In [WelcomeMail](#mailables), insert this call immediately after `->to($this->email, $this->name)`.

```php
->replyTo("organizer@example.test", "Book Club Organizer")
```

Use the `$message` in [preview-welcome.php](#preview-the-welcome-email) to check the addressing before delivery. Append these lines to the script.

```php
$member = $message->recipients()[0];
$organizer = $message->replyToAddresses()[0];
printf("To: %s <%s>\n", $member->name, $member->address);
printf("Reply-To: %s <%s>\n", $organizer->name, $organizer->address);
```

Run `php preview-welcome.php`. The final two lines show who receives the welcome email and which address the member's mail client uses for a reply.

```text
To: Alex & Sam <reader@example.test>
Reply-To: Book Club Organizer <organizer@example.test>
```

Replace `organizer@example.test` with the club's monitored mailbox before using a delivery transport.

## Send from the application

Atom registers `Atom\Mail\MailerInterface` with a Symfony Mailer transport. Inject that interface into the service that completes signup. Once signup succeeds, call `$mailer->send(new WelcomeMail($email, $name))` on the injected mailer.

The default transport, `null://null`, discards messages. For delivery, set your provider's DSN and sender in the application's `.env`. Replace the example values with the ones supplied by your provider.

```env
MAIL_DSN=smtp://username:password@smtp.example.com:587
MAIL_FROM_ADDRESS=no-reply@example.com
MAIL_FROM_NAME="Book Club"
```

`MailMessage` also supports an explicit sender, CC, BCC, reply-to addresses and file attachments.
