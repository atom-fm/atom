<?php

declare(strict_types=1);

use App\Documentation\Document;
use App\Documentation\DocumentEntry;
use App\Pages\DocsPage;

require dirname(__DIR__) . "/vendor/autoload.php";

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function navigation(DocsPage $page): DOMXPath
{
    $dom = new DOMDocument();
    $dom->loadHTML('<!doctype html><html><body>' . (string) $page->navigation() . '</body></html>', LIBXML_NOERROR | LIBXML_NOWARNING);
    return new DOMXPath($dom);
}

$page = new DocsPage();
$page->navigation = [
    new DocumentEntry("", "Documentation", "", 0),
    new DocumentEntry("concepts", 'Concepts & <patterns>', "", 10),
    new DocumentEntry("concepts/pages", "Pages", "", 20),
    new DocumentEntry("concepts/pages/actions", "Actions", "", 30),
    new DocumentEntry("guides", "Guides", "", 40),
    new DocumentEntry("guides/forms", "Forms", "", 50),
];
$page->document = new Document("concepts/pages/actions", "Actions", "", 30, "");
$xpath = navigation($page);

check($xpath->query('//summary//a | //summary//button | //summary//input')->length === 0,
    "Disclosure summaries must not contain interactive controls.");
check($xpath->query('//summary[normalize-space(.) = ""]')->length === 0,
    "Every disclosure must have an accessible name.");
check($xpath->query('//a[@aria-current="page"]')->length === 1,
    "Exactly one link must identify the current document.");
check($xpath->query('//a[@aria-current="page"]')->item(0)?->getAttribute("href") === "/docs/concepts/pages/actions",
    "The selected link must match the requested nested document.");
check($xpath->query('//details[@open]')->length === 2,
    "Ancestors of a nested document must be expanded, leaving unrelated branches closed.");
check($xpath->query('//details/preceding-sibling::a')->length === 3,
    "Each branch must keep its navigation link outside the disclosure.");
check($xpath->query('//summary')->item(0)?->textContent === 'Toggle Concepts & <patterns> section',
    "Section names must remain safely escaped text.");
check($xpath->query('//summary//patterns')->length === 0, "Section titles must not become HTML.");
foreach ($xpath->query('//a') as $link) {
    check($link->hasAttribute("atom:navigate"), "Sidebar links must retain enhanced navigation.");
}

$page->document = new Document("concepts", "Concepts", "", 10, "");
$xpath = navigation($page);
check($xpath->query('//a[@aria-current="page"]')->item(0)?->getAttribute("href") === "/docs/concepts",
    "A section landing page must also be marked current.");

echo "Documentation navigation checks passed." . PHP_EOL;
