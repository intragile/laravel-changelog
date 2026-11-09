<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Parsing;

final readonly class ReleaseGroup
{
    /**
     * @param  string  $name  The plain text of the group heading, e.g. "Added".
     * @param  list<string>  $items  The markdown of each list item, nested lists included.
     * @param  string|null  $summary  Markdown between the group heading and its first list.
     */
    public function __construct(
        public string $name,
        public array $items,
        public ?string $summary = null,
    ) {}
}
