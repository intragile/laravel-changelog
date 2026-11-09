<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use IntrAgile\Changelog\LocalizedRelease;
use IntrAgile\Changelog\Markdown\SafeMarkdown;

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
        $markdown = app(SafeMarkdown::class);
        $release = $this->resource->release;

        $groups = [];
        foreach ($release->groups as $group) {
            $items = [];
            foreach ($group->items as $item) {
                $items[] = ['html' => $markdown->toHtml($item), 'text' => $markdown->toText($item)];
            }

            $groups[] = [
                'name' => $group->name,
                'summary_html' => $group->summary !== null ? $markdown->toHtml($group->summary) : null,
                'items' => $items,
            ];
        }

        return [
            'version' => $release->version,
            'date' => $release->date,
            'url' => $release->url,
            'unreleased' => $release->unreleased,
            'yanked' => $release->yanked,
            'summary_html' => $release->summary !== null ? $markdown->toHtml($release->summary) : null,
            'groups' => $groups,
            'locale' => $this->resource->locale,
            'fallback' => $this->resource->fallback,
        ];
    }
}
