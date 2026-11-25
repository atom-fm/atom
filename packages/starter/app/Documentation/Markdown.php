<?php

declare(strict_types=1);

namespace App\Documentation;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterExtension;
use League\CommonMark\Extension\FrontMatter\FrontMatterParser;
use League\CommonMark\Extension\FrontMatter\Data\SymfonyYamlFrontMatterParser;
use League\CommonMark\Extension\FrontMatter\Output\RenderedContentWithFrontMatter;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\Extension\HeadingPermalink\HeadingPermalinkExtension;
use League\CommonMark\MarkdownConverter;
use Phiki\Adapters\CommonMark\PhikiExtension;
use Phiki\Theme\Theme;

final readonly class Markdown
{
    private MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment([
            "html_input" => "strip",
            "allow_unsafe_links" => false,
            "heading_permalink" => [
                "apply_id_to_heading" => true,
                "html_class" => "docs-heading-link",
                "id_prefix" => "",
                "fragment_prefix" => "",
                "insert" => "after",
                "min_heading_level" => 2,
                "symbol" => "#",
            ],
        ]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $environment->addExtension(new FrontMatterExtension());
        $environment->addExtension(new HeadingPermalinkExtension());
        $environment->addExtension(new PhikiExtension(Theme::GithubDarkDefault));

        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $source, string $slug, string $fallbackTitle): Document
    {
        $rendered = $this->converter->convert($source);
        $frontMatter = $rendered instanceof RenderedContentWithFrontMatter
            ? $rendered->getFrontMatter()
            : [];
        $metadata = is_array($frontMatter) ? $frontMatter : [];

        $html = preg_replace(
            '/<a href="(\/docs(?:\/[^\"]*)?)"/',
            '<a atom:navigate href="$1"',
            (string) $rendered
        );

        return new Document(
            $slug,
            $this->string($metadata, "title", $fallbackTitle),
            $this->string($metadata, "description"),
            $this->integer($metadata, "order"),
            is_string($html) ? $html : (string) $rendered
        );
    }

    /** @return array<array-key, mixed> */
    public function frontMatter(string $source): array
    {
        $parsed = (new FrontMatterParser(new SymfonyYamlFrontMatterParser()))->parse($source)->getFrontMatter();
        return is_array($parsed) ? $parsed : [];
    }

    /** @param array<array-key, mixed> $metadata */
    private function string(array $metadata, string $key, string $fallback = ""): string
    {
        $value = $metadata[$key] ?? null;
        return is_string($value) && trim($value) !== "" ? trim($value) : $fallback;
    }

    /** @param array<array-key, mixed> $metadata */
    private function integer(array $metadata, string $key): int
    {
        $value = $metadata[$key] ?? null;
        return is_int($value) ? $value : 1000;
    }
}
