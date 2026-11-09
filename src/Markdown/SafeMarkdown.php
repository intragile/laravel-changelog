<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

/**
 * @internal Not part of the public API; it may change in any release.
 */
final class SafeMarkdown
{
    private ?MarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        return trim($this->converter()->convert($markdown)->getContent());
    }

    public function toText(string $markdown): string
    {
        return $this->htmlToText($this->toHtml($markdown));
    }

    /**
     * Flattens HTML produced by toHtml() into one line of plain text.
     */
    public function htmlToText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    private function converter(): MarkdownConverter
    {
        if ($this->converter === null) {
            $environment = new Environment([
                'html_input' => 'strip',
                'allow_unsafe_links' => false,
            ]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new AutolinkExtension);
            $environment->addExtension(new StrikethroughExtension);

            $this->converter = new MarkdownConverter($environment);
        }

        return $this->converter;
    }
}
