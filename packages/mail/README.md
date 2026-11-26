# Atom Mail

Build mail messages with `Mailable` and `MailMessage`, render PHP templates with
`MailTemplateRenderer`, and send through `SymfonyMailer`. Use `ArrayMailer`
to capture messages in tests.

For local development, add this directory as a Composer path repository in
your project and require `atom-fm/mail:0.1.x-dev`.

```php
use Atom\Mail\MailAddress;
use Atom\Mail\MailTemplateRenderer;
use Atom\Mail\SymfonyMailer;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;

$mailer = new SymfonyMailer(
    new Mailer(Transport::fromDsn("null://null")),
    new MailTemplateRenderer(),
    new MailAddress("sender@example.com", "Example")
);
```

Pass a `Mailable` to `$mailer->send()`. Choose a Symfony Mailer transport DSN
for your environment. A sender set on the message takes precedence over the
constructor's default sender.
