<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Parsing;

final readonly class Release
{
    /**
     * @param  string  $version  The version without a "v" prefix, or "Unreleased".
     * @param  string|null  $date  The release date as YYYY-MM-DD; null when absent or not a real calendar date.
     * @param  string|null  $url  The target of the link in the heading, inline or by reference definition; null unless it is an http(s) or relative URL.
     * @param  string|null  $summary  Markdown between the version heading and its first group.
     * @param  list<ReleaseGroup>  $groups
     */
    public function __construct(
        public string $version,
        public ?string $date,
        public ?string $url,
        public bool $unreleased,
        public bool $yanked,
        public ?string $summary,
        public array $groups,
    ) {}
}
