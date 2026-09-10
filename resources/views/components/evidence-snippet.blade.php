@props([
    'html' => null,
    'terms' => [],
])

{{--
    The injected markup, shown as text with the incriminating parts picked out.

    Rendered ESCAPED, never as live HTML and never inside an <iframe srcdoc>: this
    string was lifted off a site that is - by definition, since we flagged it -
    under someone else's control. Escaping first and injecting only our own <mark>
    afterwards is what makes the {!! !!} below safe.

    The highlight is one pass over a single alternation, not a loop of replaces. A
    sequential loop re-scans markup it just inserted, so a term like "mark" or a
    domain sharing a substring with a Tailwind class would start matching the
    highlighter's own output.

    Order matters: escape, then lay out, then highlight. Laying out escaped text
    keeps EvidenceMarkup working on inert characters, and doing it before the
    highlight keeps our own <mark> tags - the only real HTML here - out of the
    indenter's reach.
--}}
@php
    $snippet = trim((string) $html);

    // Longest first (JudolFinding::highlightTerms() already sorts that way) so the
    // alternation prefers "situs slot gacor" over the "slot gacor" inside it.
    $needles = collect($terms)
        ->filter(fn ($term) => is_string($term) && trim($term) !== '')
        ->take(20)
        ->map(fn (string $term) => preg_quote(e(trim($term)), '/'))
        ->all();

    // Stored squished onto one line by PageRegion::snippet(), which is right for a
    // database column and unreadable on screen - a spammed widget arrives as two
    // thousand unbroken characters with its nesting invisible.
    $rendered = \App\Support\EvidenceMarkup::format(e($snippet));

    if ($needles !== []) {
        $rendered = preg_replace(
            '/('.implode('|', $needles).')/iu',
            '<mark class="rounded bg-amber-200/70 px-0.5 text-amber-950 dark:bg-amber-500/30 dark:text-amber-100">$0</mark>',
            $rendered,
        ) ?? $rendered;
    }
@endphp

@if ($snippet !== '')
    <pre {{ $attributes->merge(['class' => 'max-h-72 overflow-auto whitespace-pre rounded-lg bg-zinc-900/95 px-3 py-2.5 font-mono text-[11px] leading-relaxed text-zinc-200 dark:bg-zinc-950']) }}><code>{!! $rendered !!}</code></pre>
@endif
