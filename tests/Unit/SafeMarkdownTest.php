<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Unit;

use IntrAgile\Changelog\Markdown\SafeMarkdown;
use PHPUnit\Framework\TestCase;

final class SafeMarkdownTest extends TestCase
{
    public function test_inline_markdown_renders(): void
    {
        $markdown = new SafeMarkdown;

        $this->assertStringContainsString('<code>X-Powered-By</code>', $markdown->toHtml('`X-Powered-By`'));
        $this->assertStringContainsString('<strong>a</strong>', $markdown->toHtml('**a**'));
    }

    public function test_raw_html_is_stripped(): void
    {
        $this->assertStringNotContainsString('<script', (new SafeMarkdown)->toHtml('<script>alert(1)</script>ok'));
    }

    public function test_unsafe_links_are_dropped(): void
    {
        $this->assertStringNotContainsString('javascript:', (new SafeMarkdown)->toHtml('[x](javascript:alert(1))'));
    }

    public function test_to_text_flattens(): void
    {
        $this->assertSame('Use bold and code', (new SafeMarkdown)->toText("Use **bold**\n and `code`"));
    }
}
