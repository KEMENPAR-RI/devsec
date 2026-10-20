<?php

namespace App\Services\Monitoring;

use App\Models\PageChange;
use App\Models\PageChangeForensic;
use App\Support\ElementIdentity;
use App\Support\VisibleText;

/**
 * Builds the immutable forensic payload of one content-change event (schema v1):
 * every text/element change with its location and raw snippet on each side,
 * capped at forensics_max_items items and forensics_max_payload_bytes of JSON
 * (lowest priority dropped first, counted as "undetailed").
 *
 * Documents are parsed one at a time - "after" first, then "before".
 */
class ContentForensics
{
    public const HASH_ALGORITHM = 'SHA-256';

    protected const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR;

    /** Hard ceiling for the gzipped payload (BLOB column). */
    protected const MAX_GZ_BYTES = 65000;

    public function __construct(protected PageTextExtractor $extractor) {}

    /**
     * @param  array{before_unavailable?: ?string, before_base?: ?string, after_base?: ?string, title?: ?array, redirect?: ?array, hidden?: array<string, bool>}  $context
     * @param  array{added?: array<int, string>, removed?: array<int, string>, added_total?: int, removed_total?: int}  $textDiff
     * @param  array{added?: array<string, string>, removed?: array<string, string>, modified?: array<string, array{before: string, after: string}>}  $elementDiff
     * @return array{0: array<string, mixed>, 1: string, 2: string} [payload, its JSON, its gzip] - pass all three to store()
     */
    public function build(array $context, array $textDiff, array $elementDiff, ?string $beforeHtml, string $afterHtml): array
    {
        $candidates = $this->candidates($context, $textDiff, $elementDiff);
        $counts = $this->counts($context, $textDiff, $elementDiff);
        $items = array_slice($candidates, 0, max(0, (int) config('monitoring.forensics_max_items', 50)));
        $maxHtml = (int) config('monitoring.forensics_max_html_bytes', 2097152);
        $tooLarge = strlen($afterHtml) > $maxHtml;
        $beforeReason = $context['before_unavailable'] ?? ($beforeHtml === null ? 'missing' : null);

        if (! $tooLarge) {
            $this->locate($items, 'after', $afterHtml, $context['after_base'] ?? null);

            if ($beforeHtml !== null && $beforeReason === null) {
                $this->locate($items, 'before', $beforeHtml, $context['before_base'] ?? null);
            }
        }

        foreach ($items as $i => &$item) {
            $item = [
                'n' => $i + 1,
                'kind' => $item['kind'],
                'field' => $item['field'],
                'change' => $item['change'],
                'text' => $item['text'],
                'identity' => $item['identity'],
                'value' => $item['value'],
                'before' => $item['before'] ?? null,
                'after' => $item['after'] ?? null,
                'before_unavailable' => $item['change'] === 'added' || $item['kind'] === 'redirect' ? 'not_applicable' : $beforeReason,
            ];
        }

        unset($item);

        return $this->fit([
            'v' => PageChangeForensic::SCHEMA_VERSION,
            'unavailable' => $tooLarge ? 'too_large' : null,
            'before_unavailable' => $beforeReason,
            'counts' => $counts,
            'undetailed' => max(0, $counts['total'] - count($items)),
            'items' => $items,
        ]);
    }

    /** $json/$gz from build() are reused; only a payload without them is encoded here. */
    public function store(PageChange $change, array $payload, ?string $json = null, ?string $gz = null): PageChangeForensic
    {
        $json ??= json_encode($payload, self::JSON_FLAGS);
        $gz ??= gzencode($json, 6);

        return PageChangeForensic::create([
            'page_change_id' => $change->id,
            'payload_gz' => $gz,
            'payload_bytes' => strlen($json),
            'item_count' => min(65535, count($payload['items'] ?? [])),
            'undetailed_count' => (int) ($payload['undetailed'] ?? 0),
            'schema_version' => PageChangeForensic::SCHEMA_VERSION,
        ]);
    }

    /**
     * The payload for the detail view, with *_tokens (see VisibleText) beside
     * every value taken from the target page. Null when there is nothing usable.
     *
     * @return array<string, mixed>|null
     */
    public function forView(array $payload): ?array
    {
        if (($payload['v'] ?? null) !== PageChangeForensic::SCHEMA_VERSION) {
            return null;
        }

        $payload += ['unavailable' => null, 'before_unavailable' => null, 'undetailed' => 0, 'items' => []];
        $payload['counts'] = (array) ($payload['counts'] ?? []) + array_fill_keys(['text_added', 'text_removed', 'elements_added', 'elements_removed', 'elements_modified', 'total'], 0);

        foreach ($payload['items'] as &$item) {
            $item['text_tokens'] = isset($item['text']) ? VisibleText::tokens((string) $item['text']) : null;
            $item['identity_tokens'] = isset($item['identity']) ? VisibleText::tokens((string) $item['identity']) : null;
            $item['value_tokens'] = is_array($item['value'] ?? null) ? [
                'before' => isset($item['value']['before']) ? VisibleText::tokens((string) $item['value']['before']) : null,
                'after' => isset($item['value']['after']) ? VisibleText::tokens((string) $item['value']['after']) : null,
            ] : null;

            foreach (['before', 'after'] as $side) {
                if (! is_array($item[$side] ?? null)) {
                    $item[$side] = null;

                    continue;
                }

                $item[$side]['snippet_tokens'] = VisibleText::tokens((string) ($item[$side]['snippet'] ?? ''));
                $item[$side]['attrs_tokens'] = array_map(fn ($value) => VisibleText::tokens((string) $value), (array) ($item[$side]['attrs'] ?? []));
            }
        }

        unset($item);

        return $payload;
    }

    /**
     * Every change in priority order: redirect, meta refresh, iframe, script,
     * hidden link, link, other meta, text/title added, text/title removed.
     *
     * @return list<array<string, mixed>>
     */
    protected function candidates(array $context, array $textDiff, array $elementDiff): array
    {
        $items = [];

        if ($redirect = $context['redirect'] ?? null) {
            $items[] = $this->item(1, 'redirect', null, 'modified', null, null, ['before' => $redirect['before'], 'after' => $redirect['after']], null, null);
        }

        foreach (['added', 'removed', 'modified'] as $change) {
            foreach ($elementDiff[$change] ?? [] as $key => $value) {
                $key = (string) $key;
                $kind = ElementIdentity::kindOf($key);
                $isMeta = $kind === 'meta';
                $target = ['element', $key];

                $items[] = $this->item(
                    $this->priority($key, $kind, (bool) ($context['hidden'][$key] ?? false)),
                    $kind,
                    null,
                    $change,
                    null,
                    ElementIdentity::identity($key),
                    $isMeta ? match ($change) {
                        'added' => ['before' => null, 'after' => (string) $value],
                        'removed' => ['before' => (string) $value, 'after' => null],
                        default => ['before' => $value['before'], 'after' => $value['after']],
                    } : null,
                    $change === 'added' ? null : $target,
                    $change === 'removed' ? null : $target,
                );
            }
        }

        if ($title = $context['title'] ?? null) {
            $change = $title['before'] === null ? 'added' : ($title['after'] === null ? 'removed' : 'modified');
            $items[] = $this->item(
                $change === 'removed' ? 9 : 8,
                'text',
                'title',
                $change,
                $title['after'] ?? $title['before'],
                null,
                ['before' => $title['before'], 'after' => $title['after']],
                $change === 'added' ? null : ['tag', 'title'],
                $change === 'removed' ? null : ['tag', 'title'],
            );
        }

        foreach ($textDiff['added'] ?? [] as $chunk) {
            $items[] = $this->item(8, 'text', 'body', 'added', $chunk, null, null, null, ['text', $chunk]);
        }

        foreach ($textDiff['removed'] ?? [] as $chunk) {
            $items[] = $this->item(9, 'text', 'body', 'removed', $chunk, null, null, ['text', $chunk], null);
        }

        // usort is stable: equal priorities keep their order.
        usort($items, fn (array $a, array $b) => $a['priority'] <=> $b['priority']);

        return $items;
    }

    /** @return array<string, mixed> */
    protected function item(int $priority, string $kind, ?string $field, string $change, ?string $text, ?string $identity, ?array $value, ?array $beforeTarget, ?array $afterTarget): array
    {
        return [
            'priority' => $priority,
            'kind' => $kind,
            'field' => $field,
            'change' => $change,
            'text' => $text !== null ? mb_substr($text, 0, 500) : null,
            'identity' => $identity,
            'value' => $value,
            '_before' => $beforeTarget,
            '_after' => $afterTarget,
        ];
    }

    protected function priority(string $key, string $kind, bool $hidden): int
    {
        return match (true) {
            $key === 'meta|refresh' => 2,
            $kind === 'iframe' => 3,
            $kind === 'script' => 4,
            $kind === 'link' && $hidden => 5,
            $kind === 'link' => 6,
            default => 7,
        };
    }

    /** @return array<string, int> */
    protected function counts(array $context, array $textDiff, array $elementDiff): array
    {
        $title = $context['title'] ?? null;
        $titleRemoved = $title && $title['after'] === null;

        $counts = [
            'text_added' => (int) ($textDiff['added_total'] ?? count($textDiff['added'] ?? [])) + ($title && ! $titleRemoved ? 1 : 0),
            'text_removed' => (int) ($textDiff['removed_total'] ?? count($textDiff['removed'] ?? [])) + ($titleRemoved ? 1 : 0),
            'elements_added' => count($elementDiff['added'] ?? []),
            'elements_removed' => count($elementDiff['removed'] ?? []),
            'elements_modified' => count($elementDiff['modified'] ?? []) + (empty($context['redirect']) ? 0 : 1),
        ];

        return $counts + ['total' => array_sum($counts)];
    }

    /** Fills one side's Location on every item; the document is released before returning. */
    protected function locate(array &$items, string $side, string $html, ?string $base): void
    {
        $locator = new HtmlLocator($html, $this->extractor, $base);

        try {
            foreach ($items as &$item) {
                $target = $item['_'.$side];

                $item[$side] = match ($target[0] ?? null) {
                    'text' => $locator->locateText($target[1]),
                    'element' => $locator->locateElement($target[1]),
                    'tag' => $locator->locateTag($target[1]),
                    default => null,
                };
            }

            unset($item);
        } finally {
            $locator->free();
        }
    }

    /**
     * Drops the lowest-priority items until the JSON and its gzip fit, cutting
     * several at once from the average item size; the gzip is only computed
     * once the JSON fits.
     *
     * @return array{0: array<string, mixed>, 1: string, 2: string}
     */
    protected function fit(array $payload): array
    {
        $maxBytes = (int) config('monitoring.forensics_max_payload_bytes', 65536);

        while (true) {
            $json = json_encode($payload, self::JSON_FLAGS);
            $count = count($payload['items']);

            if (strlen($json) <= $maxBytes) {
                $gz = (string) gzencode($json, 6);

                if (strlen($gz) <= self::MAX_GZ_BYTES || $count === 0) {
                    return [$payload, $json, $gz];
                }

                $drop = 1;
            } elseif ($count === 0) {
                return [$payload, $json, (string) gzencode($json, 6)];
            } else {
                $drop = max(1, (int) ceil((strlen($json) - $maxBytes) / max(1, intdiv(strlen($json), $count))));
            }

            $drop = min($drop, $count);
            array_splice($payload['items'], -$drop);
            $payload['undetailed'] += $drop;
        }
    }

    /**
     * Why an event has no readable detail, for the view and the evidence file:
     * corrupt (row unreadable), new_page / removed_page (never detailed), or
     * legacy (recorded before this feature). Null when the payload is usable.
     */
    public static function unavailableReason(PageChange $change, ?PageChangeForensic $forensic, ?array $payload): ?string
    {
        return match (true) {
            $payload !== null => null,
            $forensic !== null => 'corrupt',
            $change->type === 'added' => 'new_page',
            $change->type === 'removed' => 'removed_page',
            default => 'legacy',
        };
    }
}
