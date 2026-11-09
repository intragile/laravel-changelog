<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Markdown;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\Autolink\AutolinkExtension;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\Strikethrough\StrikethroughExtension;
use League\CommonMark\MarkdownConverter;

final class SafeMarkdown
{
    private ?MarkdownConverter $converter = null;

    public function toHtml(string $markdown): string
    {
        return trim($this->converter()->convert($markdown)->getContent());
    }

    public function toText(string $markdown): string
    {
        $text = html_entity_decode(strip_tags($this->toHtml($markdown)), ENT_QUOTES | ENT_HTML5, 'UTF-8');

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
