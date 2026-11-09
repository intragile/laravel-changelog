<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use IntrAgile\Changelog\ChangelogRepository;
use IntrAgile\Changelog\LocalizedRelease;

/**
 * @property LocalizedRelease $resource
 */
final class ReleaseResource extends JsonResource
{
    /**
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
        $rendered = app(ChangelogRepository::class)->rendered($this->resource);

        $groups = [];
        foreach ($release->groups as $index => $group) {
            $groups[] = [
                'name' => $group->name,
                'summary_html' => $rendered['groups'][$index]['summary_html'] ?? null,
                'items' => $rendered['groups'][$index]['items'] ?? [],
            ];
        }

        return [
            'version' => $release->version,
            'date' => $release->date,
            'url' => $release->url,
            'unreleased' => $release->unreleased,
            'yanked' => $release->yanked,
            'summary_html' => $rendered['summary_html'],
            'groups' => $groups,
            'locale' => $this->resource->locale,
            'fallback' => $this->resource->fallback,
        ];
    }
}
