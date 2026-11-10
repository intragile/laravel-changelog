<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Unit;

use FilesystemIterator;
use PhpToken;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * The package requires only illuminate/* components, so it must not call the global helpers that only
 * laravel/framework defines (Illuminate/Foundation/helpers.php). String callables (`'app'`) are not detected.
 */
final class NoFoundationHelpersTest extends TestCase
{
    private const HELPERS = [
        'abort', 'abort_if', 'abort_unless', 'app', 'auth', 'base_path', 'cache', 'config', 'config_path',
        'event', 'info', 'logger', 'now', 'public_path', 'report', 'request', 'rescue', 'resolve', 'resource_path',
        'response', 'route', 'storage_path', 'trans', 'url', 'view', '__',
        'app_path', 'back', 'bcrypt', 'broadcast', 'cookie', 'csrf_token', 'database_path', 'decrypt', 'dispatch',
        'encrypt', 'lang_path', 'old', 'policy', 'redirect', 'session', 'to_route', 'today', 'validator',
    ];

    public function test_src_does_not_call_foundation_helpers(): void
    {
        $found = [];
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(__DIR__.'/../../src', FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $tokens = array_values(array_filter(
                PhpToken::tokenize((string) file_get_contents($file->getPathname())),
                static fn (PhpToken $token): bool => ! $token->isIgnorable(),
            ));

            foreach ($tokens as $i => $token) {
                $previous = $tokens[$i - 1] ?? null;
                $next = $tokens[$i + 1] ?? null;

                // `config(`, `\config(` and `namespace\config(` are all caught.
                $name = strtolower((string) preg_replace('/^(namespace)?\\\\/i', '', $token->text));

                if ($token->is([T_STRING, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE])
                    && in_array($name, self::HELPERS, true)
                    && $next?->text === '('
                    && ! $previous?->is([T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW])) {
                    $found[] = $file->getFilename().':'.$token->line.' '.$token->text.'()';
                }
            }
        }

        $this->assertSame([], $found);
    }
}
