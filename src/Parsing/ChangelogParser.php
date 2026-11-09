<?php

declare(strict_types=1);

namespace IntrAgile\Changelog\Parsing;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Node\Block\AbstractBlock;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerInterface;
use League\CommonMark\Parser\MarkdownParser;
use League\CommonMark\Reference\ReferenceInterface;
use Throwable;

/**
 * Parses a Keep a Changelog file into releases. Never throws: problems in the
 * file are returned as issues next to whatever could be parsed.
 *
 * @phpstan-type GroupState array{name: string, summary: list<AbstractBlock>, items: list<string>, hasList: bool, pending: list<AbstractBlock>}
 * @phpstan-type ReleaseState array{version: string, date: ?string, url: ?string, unreleased: bool, yanked: bool, summary: list<AbstractBlock>, groups: list<GroupState>}
 */
final class ChangelogParser
{
    private const VERSION_HEADING = '/^\[?(Unreleased|v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.+-]+)?)\]?(?:\s+-\s+(\d{4}-\d{2}-\d{2}))?(?:\s+\[YANKED\])?$/i';

    /** @var list<string> */
    private array $lines = [];

    private ?Document $document = null;

    /** @var list<ParseIssue> */
    private array $issues = [];

    public function parse(string $markdown): ParsedChangelog
    {
        try {
            return $this->parseDocument($markdown);
        } catch (Throwable $e) {
            return new ParsedChangelog([], [
                new ParseIssue(1, ParseIssue::ERROR, 'The changelog could not be parsed: '.$e->getMessage()),
            ]);
        } finally {
            $this->lines = [];
            $this->document = null;
            $this->issues = [];
        }
    }

    private function parseDocument(string $markdown): ParsedChangelog
    {
        if (str_starts_with($markdown, "\u{FEFF}")) {
            $markdown = substr($markdown, 3);
        }

        $environment = new Environment;
        $environment->addExtension(new CommonMarkCoreExtension);

        $this->lines = preg_split('/\r\n|\r|\n/', $markdown) ?: [];
        $this->document = (new MarkdownParser($environment))->parse($markdown);
        $this->issues = [];

        /** @var list<Release> $releases */
        $releases = [];
        /** @var array<string, true> $seen */
        $seen = [];
        /** @var ReleaseState|null $current */
        $current = null;

        foreach ($this->document->children() as $block) {
            if (! $block instanceof AbstractBlock) {
                continue;
            }

            if ($block instanceof Heading && $block->getLevel() === 2) {
                if ($current !== null) {
                    $releases[] = $this->buildRelease($current, false);
                }

                $current = $this->startRelease($block, $seen);

                continue;
            }

            // Content before the first version heading, or under a skipped one.
            if ($current === null) {
                continue;
            }

            if ($block instanceof Heading && $block->getLevel() === 3) {
                $current['groups'][] = [
                    'name' => $this->plainText($block),
                    'summary' => [],
                    'items' => [],
                    'hasList' => false,
                    'pending' => [],
                ];

                continue;
            }

            $index = array_key_last($current['groups']);

            if ($index === null) {
                $current['summary'][] = $block;

                continue;
            }

            $group = &$current['groups'][$index];

            if ($block instanceof ListBlock) {
                $this->warnAbout($group['pending'], $group['name']);
                $group['pending'] = [];
                $group['hasList'] = true;

                foreach ($block->children() as $item) {
                    if ($item instanceof ListItem) {
                        $group['items'][] = $this->itemMarkdown($item);
                    }
                }
            } elseif (! $group['hasList'] && ! $block instanceof Heading) {
                $group['summary'][] = $block;
            } else {
                $group['pending'][] = $block;
            }

            unset($group);
        }

        if ($current !== null) {
            $releases[] = $this->buildRelease($current, true);
        }

        return new ParsedChangelog($releases, $this->issues);
    }

    /**
     * @param  array<string, true>  $seen
     * @return ReleaseState|null
     */
    private function startRelease(Heading $heading, array &$seen): ?array
    {
        $line = $heading->getStartLine() ?? 1;
        $text = $this->plainText($heading);

        if (preg_match(self::VERSION_HEADING, $text, $match) !== 1) {
            $this->issue($line, ParseIssue::ERROR, sprintf(
                '"%s" is not a version heading (expected "[x.y.z] - YYYY-MM-DD" or "[Unreleased]"); the section is skipped.',
                $text,
            ));

            return null;
        }

        $unreleased = strcasecmp($match[1], 'Unreleased') === 0;
        $version = $unreleased ? 'Unreleased' : ltrim($match[1], 'vV');
        $key = strtolower($version);

        if (isset($seen[$key])) {
            $this->issue($line, ParseIssue::ERROR, sprintf('Version %s appears more than once; this section is skipped.', $version));

            return null;
        }

        $seen[$key] = true;

        $date = $match[2] ?? null;

        if ($date !== null && ! checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $this->issue($line, ParseIssue::ERROR, sprintf('%s is not a valid calendar date; version %s is listed without a date.', $date, $version));
            $date = null;
        }

        $url = null;

        foreach ($heading->iterator() as $node) {
            if ($node instanceof Link) {
                $url = $node->getUrl();

                break;
            }
        }

        return [
            'version' => $version,
            'date' => $date,
            'url' => $url,
            'unreleased' => $unreleased,
            'yanked' => preg_match('/\[YANKED\]$/i', $text) === 1,
            'summary' => [],
            'groups' => [],
        ];
    }

    /**
     * @param  ReleaseState  $state
     */
    private function buildRelease(array $state, bool $lastInDocument): Release
    {
        $groups = [];
        $lastIndex = array_key_last($state['groups']);

        foreach ($state['groups'] as $index => $group) {
            $pending = $group['pending'];

            // Paragraphs after the very last list of the file are a document
            // footer (e.g. "Developed and maintained by ..."), not changes.
            if ($lastInDocument && $index === $lastIndex && $group['hasList']) {
                $pending = array_values(array_filter(
                    $pending,
                    fn (AbstractBlock $block): bool => ! $block instanceof Paragraph && ! $block instanceof ThematicBreak,
                ));
            }

            $this->warnAbout($pending, $group['name']);

            $groups[] = new ReleaseGroup($group['name'], $group['items'], $this->blocksMarkdown($group['summary']));
        }

        return new Release(
            $state['version'],
            $state['date'],
            $state['url'],
            $state['unreleased'],
            $state['yanked'],
            $this->blocksMarkdown($state['summary']),
            $groups,
        );
    }

    /**
     * @param  list<AbstractBlock>  $blocks
     */
    private function warnAbout(array $blocks, string $group): void
    {
        foreach ($blocks as $block) {
            $this->issue($block->getStartLine() ?? 1, ParseIssue::WARNING, sprintf(
                'Only lists belong under a change group; this %s under "%s" is ignored.',
                $this->describe($block),
                $group,
            ));
        }
    }

    private function describe(AbstractBlock $block): string
    {
        if ($block instanceof Heading) {
            return 'level '.$block->getLevel().' heading';
        }

        $class = (new \ReflectionClass($block))->getShortName();

        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', ' $0', $class));
    }

    /**
     * The item's source lines with the list marker removed and the
     * continuation lines dedented by the marker's width.
     */
    private function itemMarkdown(ListItem $item): string
    {
        $start = $item->getStartLine() ?? 1;
        $end = $item->getEndLine() ?? $start;
        $data = $item->getListData();
        $width = $data->markerOffset + $data->padding;

        $lines = [];

        foreach ($this->sourceLines($start, $end) as $offset => $line) {
            if ($offset === 0) {
                $lines[] = substr($line, $width);

                continue;
            }

            $indent = strspn($line, ' ');
            $lines[] = substr($line, min($indent, $width));
        }

        return $this->withReferences(trim(implode("\n", $lines)));
    }

    /**
     * The source lines spanned by consecutive blocks, dedented by their
     * common indentation; null when there are none.
     *
     * @param  list<AbstractBlock>  $blocks
     */
    private function blocksMarkdown(array $blocks): ?string
    {
        if ($blocks === []) {
            return null;
        }

        $start = $blocks[0]->getStartLine() ?? 1;
        $end = $blocks[count($blocks) - 1]->getEndLine() ?? $start;
        $lines = $this->sourceLines($start, $end);

        $indents = array_map(
            fn (string $line): int => strspn($line, ' '),
            array_filter($lines, fn (string $line): bool => trim($line) !== ''),
        );
        $indent = $indents === [] ? 0 : min($indents);

        $markdown = trim(implode("\n", array_map(fn (string $line): string => substr($line, $indent), $lines)));

        return $markdown === '' ? null : $this->withReferences($markdown);
    }

    /**
     * Appends the link reference definitions a slice uses, so it still
     * renders on its own once cut out of the file.
     */
    private function withReferences(string $markdown): string
    {
        if ($this->document === null || ! str_contains($markdown, '[')) {
            return $markdown;
        }

        $definitions = [];

        /** @var ReferenceInterface $reference */
        foreach ($this->document->getReferenceMap() as $reference) {
            $pattern = '/\['.preg_quote($reference->getLabel(), '/').'\](?!\(|:)/iu';

            if (preg_match($pattern, $markdown) === 1) {
                $definition = '['.$reference->getLabel().']: <'.$reference->getDestination().'>';

                if ($reference->getTitle() !== '') {
                    $definition .= ' "'.addcslashes($reference->getTitle(), '"\\').'"';
                }

                $definitions[] = $definition;
            }
        }

        return $definitions === [] ? $markdown : $markdown."\n\n".implode("\n", $definitions);
    }

    /**
     * @return list<string>
     */
    private function sourceLines(int $start, int $end): array
    {
        return array_slice($this->lines, $start - 1, max(0, $end - $start + 1));
    }

    private function plainText(Node $node): string
    {
        $text = '';

        foreach ($node->iterator() as $child) {
            if ($child instanceof StringContainerInterface) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof Newline) {
                $text .= ' ';
            }
        }

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * @param  'error'|'warning'  $severity
     */
    private function issue(int $line, string $severity, string $message): void
    {
        $this->issues[] = new ParseIssue($line, $severity, $message);
    }
}
