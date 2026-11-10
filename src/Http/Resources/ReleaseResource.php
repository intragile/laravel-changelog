<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Http\Resources;

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\LocalizedRelease;

/**
 * @property LocalizedRelease $resource
 *
 * @internal Not part of the public API; it may change in any release.
 */
final class ReleaseResource extends JsonResource
{
    /**
     * Pinned so a host-wide JsonResource::withoutWrapping() cannot change the documented shape.
     *
     * @var string|null
     */
    public static $wrap = 'data';

    /**
     * The per-item @var tags are read by OpenAPI generators such as Scramble, which cannot infer
     * these types through the repository; they mirror the @return shape.
     *
     * @return array{
     *     version: string,
     *     date: string|null,
     *     url: string|null,
     *     unreleased: bool,
     *     yanked: bool,
     *     summary_html: string|null,
     *     groups: list<array{name: string, summary_html: string|null, items: list<array{html: string, text: string}>}>,
     *     locale: string,
     *     fallback: bool,
     * }
     */
    public function toArray(Request $request): array
    {
        $release = $this->resource->release;
        $rendered = Container::getInstance()->make(ChangelogRepository::class)->rendered($this->resource);

        $groups = [];
        foreach ($release->groups as $index => $group) {
            $groups[] = [
                'name' => $group->name,
                'summary_html' => $rendered['groups'][$index]['summary_html'] ?? null,
                'items' => $rendered['groups'][$index]['items'] ?? [],
            ];
        }

        return [
            /** @var string */
            'version' => $release->version,
            /** @var string|null */
            'date' => $release->date,
            /** @var string|null */
            'url' => $release->url,
            /** @var bool */
            'unreleased' => $release->unreleased,
            /** @var bool */
            'yanked' => $release->yanked,
            /** @var string|null */
            'summary_html' => $rendered['summary_html'],
            /** @var list<array{name: string, summary_html: string|null, items: list<array{html: string, text: string}>}> */
            'groups' => $groups,
            /** @var string */
            'locale' => $this->resource->locale,
            /** @var bool */
            'fallback' => $this->resource->fallback,
        ];
    }
}
