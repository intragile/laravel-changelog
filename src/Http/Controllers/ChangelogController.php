<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\Http\Resources\ReleaseResource;
use IntrAgile\Changelog\LocaleResolver;
use IntrAgile\Changelog\LocalizedRelease;

/**
 * @internal Not part of the public API; it may change in any release.
 */
final class ChangelogController extends Controller
{
    public function __construct(
        private readonly ChangelogRepository $repository,
        private readonly LocaleResolver $locales,
        private readonly Config $config,
    ) {}

    /**
     * List the releases, newest first, paginated.
     *
     * `locale` is the resolved language; `fallback` is true when any returned release is shown in the base language
     * because it has no translation.
     *
     * @response array{
     *     data: list<array{version: string, date: string|null, url: string|null, unreleased: bool, yanked: bool, summary_html: string|null, groups: list<array{name: string, summary_html: string|null, items: list<array{html: string, text: string}>}>, locale: string, fallback: bool}>,
     *     meta: array{current_page: int, last_page: int, per_page: int, total: int},
     *     locale: string,
     *     fallback: bool,
     * }
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $default = $this->intConfig('changelog.per_page', 20);
        $max = max(1, $this->intConfig('changelog.max_per_page', 100));

        /** @var array{page?: int|string, per_page?: int|string, locale?: string} $validated */
        $validated = Validator::make($request->query(), [
            'page' => ['sometimes', 'integer', 'min:1', 'max:1000000'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$max],
            'locale' => ['sometimes', 'string', 'max:20'],
        ])->validate();

        $page = (int) ($validated['page'] ?? 1);
        $perPage = (int) ($validated['per_page'] ?? min($default, $max));

        $locale = $this->locales->resolve($request);
        $all = $this->repository->releases($locale);
        $total = count($all);
        $slice = array_slice($all, ($page - 1) * $perPage, $perPage);

        return ReleaseResource::collection($slice)->additional([
            'meta' => [
                'current_page' => $page,
                'last_page' => max(1, (int) ceil($total / $perPage)),
                'per_page' => $perPage,
                'total' => $total,
            ],
            'locale' => $locale,
            'fallback' => array_filter($slice, static fn (LocalizedRelease $r): bool => $r->fallback) !== [],
        ]);
    }

    /**
     * The newest released version.
     */
    public function latest(Request $request): ReleaseResource
    {
        Validator::make($request->query(), [
            'locale' => ['sometimes', 'string', 'max:20'],
        ])->validate();

        $release = $this->repository->latest($this->locales->resolve($request));
        abort_if($release === null, 404);

        return new ReleaseResource($release);
    }

    /**
     * One release by version number.
     */
    public function show(Request $request, string $version): ReleaseResource
    {
        Validator::make($request->query(), [
            'locale' => ['sometimes', 'string', 'max:20'],
        ])->validate();

        $release = $this->repository->find($version, $this->locales->resolve($request));
        abort_if($release === null, 404);

        return new ReleaseResource($release);
    }

    private function intConfig(string $key, int $default): int
    {
        $value = $this->config->get($key, $default);

        return is_int($value) ? $value : $default;
    }
}
