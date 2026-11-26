<?php

declare(strict_types=1);

use Atom\Application;
use Atom\Config\Config;
use Atom\Di\Injector;
use Atom\Modules\ErrorPages\ErrorPagesOptions;
use Atom\Page\Form;
use Atom\Page\FormModel;
use Atom\Page\FormResult;
use Atom\Page\Page;
use Atom\Page\PageAction;
use Atom\Page\PageDescriptor;
use Atom\Page\PageRouteRegistrar;
use Atom\Session\SessionInterface;

$framework = dirname(__DIR__, 4);
require $framework . "/vendor/autoload.php";

$path = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if (in_array($path, ["/atom.js", "/morphdom.js", "/atom-morphdom.js"], true)) {
    header("Content-Type: application/javascript");
    readfile($framework . "/src/Modules/Client/Resources" . $path);
    return;
}

final class EditPage extends Page
{
    #[FormModel]
    public RecordForm $record;

    public function init(): void
    {
        $this->record = new RecordForm();
    }

    public function template(): ?string
    {
        return "EditPage.atom.html";
    }
}

final class RecordForm extends Form
{
    public string $title = "";

    #[PageAction]
    public function save(SessionInterface $session): FormResult
    {
        $session->put("record-title", $this->title);
        return FormResult::saved("Record saved.", "/records/42");
    }
}

final class DetailPage extends Page
{
    public string $title = "";

    public function get(SessionInterface $session): void
    {
        $this->title = (string) $session->get("record-title");
    }

    public function template(): ?string
    {
        return "DetailPage.atom.html";
    }
}

// The PHP development server uses the request path as SCRIPT_NAME for this router.
$_SERVER["SCRIPT_NAME"] = "/server.php";

(new class extends Application {
    protected function configure(Config $config): void
    {
        $config->set(new ErrorPagesOptions(debug: true));
    }

    protected function bootstrap(Injector $injector): void
    {
        (new PageRouteRegistrar())->register($this->getRouter(), [
            new PageDescriptor("/records/42/edit", EditPage::class),
            new PageDescriptor("/records/42", DetailPage::class),
        ]);
    }
})->run();
