<?php

declare(strict_types=1);

namespace IntrAgile\Changelog;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;

final class LocaleResolver
{
    public function __construct(private readonly Config $config) {}

    /**
     * The first allowed of: ?locale, the primary tags of Accept-Language in order, the application locale.
     * Anything not allowed falls through to the next step; the base locale is the last resort.
     */
    public function resolve(Request $request): string
    {
        $allowed = $this->allowed();

        $query = $request->query('locale');

        if (is_string($query) && in_array($query, $allowed, true)) {
            return $query;
        }

        foreach ($request->getLanguages() as $language) {
            $primary = strtolower(strtok($language, '-_') ?: '');

            if (in_array($primary, $allowed, true)) {
                return $primary;
            }
        }

        $app = app()->getLocale();

        return in_array($app, $allowed, true) ? $app : $this->base();
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
