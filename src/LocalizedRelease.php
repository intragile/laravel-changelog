<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use IntrAgile\Changelog\Parsing\Release;

final readonly class LocalizedRelease
{
    /**
     * @param  string  $locale  The locale the release text actually comes from.
     * @param  bool  $fallback  True when the requested locale had no translation of this version.
     */
    public function __construct(
        public Release $release,
        public string $locale,
        public bool $fallback,
    ) {}
}
