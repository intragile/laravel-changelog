<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Parsing;

final readonly class ParseIssue
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    /**
     * @param  int  $line  1-based line number in the source file.
     * @param  'error'|'warning'  $severity
     */
    public function __construct(
        public int $line,
        public string $severity,
        public string $message,
    ) {}
}
