<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Tests\Unit;

use IntrAgile\Changelog\Markdown\SafeMarkdown;
use IntrAgile\Changelog\Parsing\ChangelogParser;
use IntrAgile\Changelog\Parsing\ParsedChangelog;
use IntrAgile\Changelog\Parsing\ParseIssue;
use IntrAgile\Changelog\Parsing\Release;
use IntrAgile\Changelog\Parsing\ReleaseGroup;
use PHPUnit\Framework\TestCase;

final class ChangelogParserTest extends TestCase
{
    public function test_parses_intrawatch_changelog(): void
    {
        $changelog = $this->parseFixture('intrawatch-CHANGELOG.md');

        $this->assertSame(['1.2.0', '1.1.1', '1.1.0', '1.0.0'], $this->versions($changelog));

        $release = $this->release($changelog, '1.2.0');
        $this->assertSame('2026-11-09', $release->date);
        $this->assertFalse($release->unreleased);
        $this->assertFalse($release->yanked);
        $this->assertNull($release->url);
        $this->assertStringStartsWith('Live group strips from every change', (string) $release->summary);
        $this->assertSame(['Admin interface', 'Public status page', 'Docker image'], $this->groupNames($release));

        $first = $release->groups[0]->items[0];
        $this->assertStringStartsWith('A group\'s heartbeat strip also moves live', $first);
        $this->assertStringContainsString('or a maintenance window starting or ending.', $first);
        $this->assertCount(2, $release->groups[0]->items);

        $this->assertSame([], $changelog->issues);
    }

    public function test_trailing_document_footer_is_ignored_silently(): void
    {
        $changelog = $this->parseFixture('intrawatch-CHANGELOG.md');
        $release = $this->release($changelog, '1.0.0');

        $names = $this->groupNames($release);
        $this->assertSame('Known limitations', end($names));

        foreach ($release->groups as $group) {
            foreach ($group->items as $item) {
                $this->assertStringNotContainsString('Developed and maintained by', $item);
            }
        }

        $this->assertSame([], $changelog->issues);
    }

    public function test_paragraph_before_a_groups_list_becomes_its_summary(): void
    {
        $changelog = $this->parseFixture('intrawatch-CHANGELOG.md');
        $security = $this->group($this->release($changelog, '1.0.0'), 'Security');

        $this->assertStringStartsWith('Changes from the pre-release security review.', (string) $security->summary);
        $this->assertStringStartsWith('**Action:** members are invited instead of added.', $security->items[0]);
        $this->assertNull($this->group($this->release($changelog, '1.0.0'), 'Platform')->summary);
    }

    public function test_parses_keepachangelog_example(): void
    {
        $changelog = $this->parseFixture('keepachangelog.md');

        $unreleased = $changelog->releases[0];
        $this->assertSame('Unreleased', $unreleased->version);
        $this->assertTrue($unreleased->unreleased);
        $this->assertNull($unreleased->date);
        $this->assertSame('https://github.com/olivierlacan/keep-a-changelog/compare/v1.1.0...HEAD', $unreleased->url);
        $this->assertSame([], $unreleased->groups);

        $release = $this->release($changelog, '1.1.0');
        $this->assertSame('2019-02-15', $release->date);
        $this->assertSame('https://github.com/olivierlacan/keep-a-changelog/compare/v1.0.0...v1.1.0', $release->url);
        $this->assertSame(['Added', 'Fixed'], $this->groupNames($release));
        $this->assertSame(['Danish translation (#297).', 'Georgian translation from (#337).', 'Changelog inconsistency section in Bad Practices.'], $release->groups[0]->items);

        $this->assertNull($this->release($changelog, '1.1.1')->url, '1.1.1 has no reference definition');
        $this->assertSame(['Added', 'Fixed', 'Changed', 'Removed'], $this->groupNames($this->release($changelog, '1.1.1')));
        $this->assertCount(15, $changelog->releases);
        $this->assertSame([], $changelog->issues);
    }

    public function test_nested_list_stays_in_item(): void
    {
        $changelog = (new ChangelogParser)->parse("## [1.0.0] - 2026-01-01\n### Added\n- a\n  - b\n- c\n");

        $items = $changelog->releases[0]->groups[0]->items;
        $this->assertCount(2, $items);
        $this->assertStringContainsString('- b', $items[0]);
        $this->assertSame("a\n- b", $items[0]);
        $this->assertSame('c', $items[1]);
    }

    public function test_multiline_and_loose_items_are_dedented(): void
    {
        $markdown = "## 1.0.0\n\n### Added\n\n1.  First line\n    second line\n\n    Second paragraph.\n\n2.  Next\n";
        $items = (new ChangelogParser)->parse($markdown)->releases[0]->groups[0]->items;

        $this->assertSame(["First line\nsecond line\n\nSecond paragraph.", 'Next'], $items);
    }

    public function test_tab_after_marker_and_tab_indented_continuation_are_dedented_by_columns(): void
    {
        $items = (new ChangelogParser)->parse("## 1.0.0\n### Added\n-\tfoo\n\tbar\n")->releases[0]->groups[0]->items;

        $this->assertSame(["foo\nbar"], $items);
    }

    public function test_tab_indented_nested_list_stays_a_list(): void
    {
        $items = (new ChangelogParser)->parse("## 1.0.0\n### Added\n- a\n\t- nested\n- c\n")->releases[0]->groups[0]->items;

        $this->assertSame(["a\n  - nested", 'c'], $items);
        $this->assertStringContainsString('<li>nested</li>', (new SafeMarkdown)->toHtml($items[0]));
    }

    public function test_tab_indented_summary_is_dedented_by_columns(): void
    {
        $release = (new ChangelogParser)->parse("## 1.0.0\n\n  Intro line\n\tand more.\n")->releases[0];

        $this->assertSame("Intro line\n  and more.", $release->summary);
    }

    public function test_slices_keep_the_reference_definitions_they_use(): void
    {
        $markdown = "## 1.0.0\n\nSee [the docs].\n\n### Added\n\n- Thanks to [@ada] and [Bob](https://bob.example).\n\n[the docs]: https://docs.example\n[@ada]: https://github.com/ada \"Ada\"\n[bob]: https://unused.example\n";
        $release = (new ChangelogParser)->parse($markdown)->releases[0];

        $this->assertSame("See [the docs].\n\n[the docs]: <https://docs.example>", $release->summary);
        $this->assertSame("Thanks to [@ada] and [Bob](https://bob.example).\n\n[@ada]: <https://github.com/ada> \"Ada\"", $release->groups[0]->items[0]);
    }

    public function test_malformed_version_heading_is_reported_not_thrown(): void
    {
        $changelog = (new ChangelogParser)->parse("## [1.0.0] - 2026-01-01\n### Added\n- ok\n## 1.3\n### Added\n- x\n");

        $this->assertCount(1, $changelog->releases);
        $this->assertSame(['ok'], $changelog->releases[0]->groups[0]->items);
        $this->assertCount(1, $changelog->issues);
        $this->assertSame('error', $changelog->issues[0]->severity);
        $this->assertSame(4, $changelog->issues[0]->line);
    }

    public function test_duplicate_version_and_invalid_date_are_errors(): void
    {
        $changelog = $this->parseFixture('malformed.md');

        $this->assertSame(['2.0.0', '1.0.0'], $this->versions($changelog));
        $this->assertNull($this->release($changelog, '2.0.0')->date);
        $this->assertSame('2026-01-01', $this->release($changelog, '1.0.0')->date);
        $this->assertSame(['First fix.', 'Second fix.'], $this->release($changelog, '1.0.0')->groups[0]->items);

        $this->assertSame([
            [3, 'error'],
            [9, 'error'],
            [21, 'warning'],
            [25, 'error'],
        ], array_map(fn (ParseIssue $issue): array => [$issue->line, $issue->severity], $changelog->issues));

        $this->assertStringContainsString('2026-02-30', $changelog->issues[0]->message);
        $this->assertStringContainsString('1.0.0', $changelog->issues[3]->message);
    }

    public function test_v_prefix_and_yanked(): void
    {
        $markdown = "## [v1.2.3-rc.1] - 2026-03-01 [YANKED]\n\n### Fixed\n\n- x\n\n## [unreleased]\n\n## 1.0.0 - 2026-01-01\n";
        $changelog = (new ChangelogParser)->parse($markdown);

        $this->assertSame(['1.2.3-rc.1', 'Unreleased', '1.0.0'], $this->versions($changelog));
        $release = $changelog->releases[0];
        $this->assertTrue($release->yanked);
        $this->assertSame('2026-03-01', $release->date);
        $this->assertTrue($changelog->releases[1]->unreleased);
        $this->assertFalse($changelog->releases[2]->yanked);
        $this->assertSame([], $changelog->releases[2]->groups);
        $this->assertNull($changelog->releases[2]->summary);

        $this->assertSame($release, $changelog->find('1.2.3-rc.1'));
        $this->assertSame($release, $changelog->find('v1.2.3-rc.1'));
        $this->assertSame($changelog->releases[1], $changelog->find('unreleased'));
        $this->assertNull($changelog->find('9.9.9'));
        $this->assertSame([], $changelog->issues);
    }

    public function test_inline_link_heading_gives_url(): void
    {
        $changelog = (new ChangelogParser)->parse("## [1.0.0](https://example.com/v1) - 2026-01-01\n");

        $this->assertSame('https://example.com/v1', $changelog->releases[0]->url);
        $this->assertSame('2026-01-01', $changelog->releases[0]->date);
    }

    public function test_heading_url_is_kept_only_for_http_and_relative_targets(): void
    {
        $markdown = <<<'MD'
        ## [5.0.0](javascript:alert(1)) - 2026-05-01
        ## [4.0.0] - 2026-04-01
        ## [3.0.0](HTTPS://example.com/v3) - 2026-03-01
        ## [2.0.0](/releases/v2) - 2026-02-01
        ## [1.0.0](data:text/html,x) - 2026-01-01
        ## [0.9.0](http://example.com/v0.9) - 2025-12-01

        [4.0.0]: vbscript:msgbox
        MD;

        $changelog = (new ChangelogParser)->parse($markdown);

        $this->assertSame(
            ['5.0.0' => null, '4.0.0' => null, '3.0.0' => 'HTTPS://example.com/v3', '2.0.0' => '/releases/v2', '1.0.0' => null, '0.9.0' => 'http://example.com/v0.9'],
            array_combine($this->versions($changelog), array_map(fn (Release $release): ?string => $release->url, $changelog->releases)),
        );
    }

    public function test_protocol_relative_heading_url_is_not_treated_as_relative(): void
    {
        $markdown = <<<'MD'
        ## [3.0.0](//evil.example/v3) - 2026-03-01
        ## [2.0.0](\\evil.example/v2) - 2026-02-01
        ## [1.0.0](/\evil.example/v1) - 2026-01-01
        ## [0.9.0](releases/v0.9) - 2025-12-01
        MD;

        $changelog = (new ChangelogParser)->parse($markdown);

        $this->assertSame(
            ['3.0.0' => null, '2.0.0' => '%5Cevil.example/v2', '1.0.0' => '/%5Cevil.example/v1', '0.9.0' => 'releases/v0.9'],
            array_combine($this->versions($changelog), array_map(fn (Release $release): ?string => $release->url, $changelog->releases)),
        );
    }

    public function test_non_list_block_in_group_warns_unless_it_is_the_document_footer(): void
    {
        $markdown = "## 2.0.0\n### Added\n- a\n\nNot a footer.\n\n## 1.0.0\n### Added\n- b\n\n> quoted\n\n- c\n\nFooter.\n";
        $changelog = (new ChangelogParser)->parse($markdown);

        $this->assertSame([[5, 'warning'], [11, 'warning']], array_map(
            fn (ParseIssue $issue): array => [$issue->line, $issue->severity],
            $changelog->issues,
        ));
        $this->assertSame(['b', 'c'], $changelog->releases[1]->groups[0]->items);
    }

    public function test_issues_are_in_line_order(): void
    {
        $markdown = "## [2.0.0] - 2026-02-01\n\n### Added\n\n- a\n\nStray A.\n\n### Fixed\n\n- b\n\n> Stray B.\n\n- c\n\n## [1.0.0] - 2026-01-01\n\n### Added\n\n- d\n";
        $changelog = (new ChangelogParser)->parse($markdown);

        $this->assertSame([[7, 'warning'], [13, 'warning']], array_map(
            fn (ParseIssue $issue): array => [$issue->line, $issue->severity],
            $changelog->issues,
        ));
        $this->assertStringContainsString('"Added"', $changelog->issues[0]->message);
        $this->assertStringContainsString('"Fixed"', $changelog->issues[1]->message);
    }

    public function test_empty_and_heading_less_input_gives_no_releases(): void
    {
        $this->assertSame([], (new ChangelogParser)->parse('')->releases);
        $changelog = (new ChangelogParser)->parse("# Changelog\n\nNothing yet.\n");
        $this->assertSame([], $changelog->releases);
        $this->assertSame([], $changelog->issues);
    }

    private function parseFixture(string $name): ParsedChangelog
    {
        $markdown = file_get_contents(__DIR__.'/../Fixtures/'.$name);
        $this->assertIsString($markdown);

        return (new ChangelogParser)->parse($markdown);
    }

    /**
     * @return list<string>
     */
    private function versions(ParsedChangelog $changelog): array
    {
        return array_map(fn (Release $release): string => $release->version, $changelog->releases);
    }

    /**
     * @return list<string>
     */
    private function groupNames(Release $release): array
    {
        return array_map(fn (ReleaseGroup $group): string => $group->name, $release->groups);
    }

    private function release(ParsedChangelog $changelog, string $version): Release
    {
        $release = $changelog->find($version);
        $this->assertNotNull($release, "Release {$version} not found");

        return $release;
    }

    private function group(Release $release, string $name): ReleaseGroup
    {
        foreach ($release->groups as $group) {
            if ($group->name === $name) {
                return $group;
            }
        }

        $this->fail("Group {$name} not found");
    }
}
