<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

final class LocaleResolver
{
    public function __construct(private readonly Config $config) {}

    /**
     * An explicit ?locale wins, then the first allowed primary tag of Accept-Language, then the base locale.
     * An explicit ?locale that is not allowed is not second-guessed: it resolves to the base locale.
     */
    public function resolve(Request $request): string
    {
        $allowed = $this->allowed();
        $base = $this->base();

        $query = $request->query('locale');

        if ($query !== null) {
            return is_string($query) && in_array($query, $allowed, true) ? $query : $base;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok($language, '-_') ?: '');

            if (in_array($primary, $allowed, true)) {
                return $primary;
            }
        }

        return $base;
    }

    /**
     * @return list<string>
     */
    private function allowed(): array
    {
        $locales = $this->config->get('changelog.locales', []);

        return is_array($locales) ? array_values(array_filter($locales, is_string(...))) : [];
    }

    private function base(): string
    {
        $base = $this->config->get('changelog.base_locale', 'en');

        return is_string($base) ? $base : 'en';
    }
}
