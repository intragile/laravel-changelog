<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\Parsing\ParsedChangelog;
use IntrAgile\Changelog\Parsing\ParseIssue;
use Symfony\Component\Console\Input\InputOption;

/**
 * @internal The changelog:check command, its options and exit codes are public API; this class is not.
 */
final class CheckCommand extends Command
{
    protected $name = 'changelog:check';

    protected $description = 'Validate the changelog file and check that its translations are in step with it';

    public function __construct()
    {
        parent::__construct();

        $this->addOption('locale', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Check only this translation (repeatable); it must exist');
    }

    public function handle(ChangelogRepository $repository, Config $config): int
    {
        $errors = 0;

        $baseLocale = $config->get('changelog.base_locale', 'en');
        $basePath = $config->get('changelog.path');
        $baseFile = is_string($basePath) ? $basePath : '';

        $base = $repository->parsed();
        $errors += $this->report($baseFile, $base);

        if ($base->releases === [] && ! $this->hasError($base)) {
            $this->error($baseFile.': the changelog contains no releases.');
            $errors++;
        }

        $requested = array_values(array_filter(
            array_map(strval(...), (array) $this->option('locale')),
            static fn (string $locale): bool => $locale !== '',
        ));

        foreach ($this->localesToCheck($repository, $config, $requested) as $locale) {
            $path = $repository->translationPath($locale);

            if ($path === null) {
                $this->error(sprintf('Locale "%s" is not a translation locale (the base locale is "%s"; others must be listed in changelog.locales).', $locale, is_string($baseLocale) ? $baseLocale : 'en'));
                $errors++;

                continue;
            }

            if (! is_file($path)) {
                if (in_array($locale, $requested, true)) {
                    $this->error($path.': translation file for "'.$locale.'" does not exist.');
                    $errors++;
                }

                continue;
            }

            $translation = $repository->parsed($locale);
            $errors += $this->report($path, $translation);
            $errors += $this->compare($path, $locale, $base, $translation);
        }

        if ($errors > 0) {
            $this->error(sprintf('Changelog check failed with %d error%s.', $errors, $errors === 1 ? '' : 's'));

            return self::FAILURE;
        }

        $this->info('Changelog check passed.');

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $requested
     * @return list<string>
     */
    private function localesToCheck(ChangelogRepository $repository, Config $config, array $requested): array
    {
        if ($requested !== []) {
            return array_values(array_unique($requested));
        }

        $locales = $config->get('changelog.locales', []);

        if (! is_array($locales)) {
            return [];
        }

        return array_values(array_filter(
            $locales,
            static fn (mixed $locale): bool => is_string($locale) && $repository->translationPath($locale) !== null,
        ));
    }

    private function hasError(ParsedChangelog $parsed): bool
    {
        foreach ($parsed->issues as $issue) {
            if ($issue->severity === ParseIssue::ERROR) {
                return true;
            }
        }

        return false;
    }

    /**
     * Prints every parse issue and returns the number of errors.
     */
    private function report(string $file, ParsedChangelog $parsed): int
    {
        $errors = 0;

        foreach ($parsed->issues as $issue) {
            $line = $file.':'.$issue->line.': '.$issue->message;

            if ($issue->severity === ParseIssue::ERROR) {
                $this->error($line);
                $errors++;
            } else {
                $this->warn($line);
            }
        }

        return $errors;
    }

    private function compare(string $file, string $locale, ParsedChangelog $base, ParsedChangelog $translation): int
    {
        $errors = 0;

        foreach ($base->releases as $release) {
            $own = $translation->find($release->version);

            if ($own === null) {
                if (! $release->unreleased) {
                    $this->error(sprintf('%s:0: version %s is missing from the "%s" translation.', $file, $release->version, $locale));
                    $errors++;
                }

                continue;
            }

            if ($own->date !== $release->date) {
                $this->error(sprintf(
                    '%s:0: version %s is dated %s in the "%s" translation but %s in the base file.',
                    $file,
                    $release->version,
                    $own->date ?? 'nothing',
                    $locale,
                    $release->date ?? 'nothing',
                ));
                $errors++;
            }
        }

        foreach ($translation->releases as $release) {
            if ($base->find($release->version) === null) {
                $this->error(sprintf('%s:0: version %s exists in the "%s" translation but not in the base file.', $file, $release->version, $locale));
                $errors++;
            }
        }

        return $errors;
    }
}
