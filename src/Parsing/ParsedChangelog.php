<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Parsing;

final readonly class ParsedChangelog
{
    /**
     * @param  list<Release>  $releases  In file order.
     * @param  list<ParseIssue>  $issues  In line order.
     */
    public function __construct(
        public array $releases,
        public array $issues,
    ) {}

    /**
     * Finds a release by version; a "v" prefix and the case of "Unreleased" do not matter.
     */
    public function find(string $version): ?Release
    {
        $needle = (string) preg_replace('/^v(?=\d)/i', '', trim($version));

        foreach ($this->releases as $release) {
            if (strcasecmp($release->version, $needle) === 0) {
                return $release;
            }
        }

        return null;
    }
}
