<?php

namespace App\Services\Monitoring;

use DOMElement;
use Illuminate\Support\Str;

/**
 * Where on a page an element sits, expressed stably enough to recognise the same
 * spot on a different URL.
 *
 * Injected backlinks are almost never planted on one page: they go into a shared
 * template - the footer, a sidebar widget, the nav - and every page rendered from
 * that template carries them. Recording those as one finding per URL turns a
 * single edit to footer.php into forty rows that all say the same thing, and
 * buries the one fact that matters: it is in the footer, so fixing the footer
 * fixes all forty.
 *
 * The signature deliberately ignores anything that varies per page. Two footers
 * rendered on /berita and /kontak differ in their text and their sibling
 * positions, but share the landmark they hang off and the class names of the
 * containers around them - so those are what identify the region.
 */
class PageRegion
{
    /**
     * HTML landmarks, most specific first. <footer> beats <nav> when a footer
     * contains a nav, which is the common case for a link list at the bottom of a
     * page - and "footer" is the more useful thing to tell an operator.
     *
     * @var array<string, string>
     */
    protected const LANDMARKS = [
        'footer' => 'Footer',
        'header' => 'Header',
        'nav' => 'Menu navigasi',
        'aside' => 'Sidebar',
        'main' => 'Konten utama',
        'article' => 'Konten utama',
    ];

    /**
     * Class/id fragments that name a region when no landmark tag is present -
     * the shape of markup that predates HTML5 landmarks, still overwhelmingly
     * common on the CMS templates this app scans.
     *
     * Ordered: the first fragment found on the nearest ancestor wins.
     *
     * @var array<string, string>
     */
    protected const HINTS = [
        'footer' => 'Footer',
        'header' => 'Header',
        'topbar' => 'Header',
        'masthead' => 'Header',
        'sidebar' => 'Sidebar',
        'widget' => 'Widget sidebar',
        'menu' => 'Menu navigasi',
        'navbar' => 'Menu navigasi',
        'breadcrumb' => 'Breadcrumb',
        'comment' => 'Kolom komentar',
        'banner' => 'Banner',
    ];

    /** Ancestors walked before giving up - deep enough for nested template markup. */
    protected const MAX_DEPTH = 12;

    /**
     * Characters of container markup kept as evidence. Enough to show the injected
     * block in context; a themed footer's full markup runs to tens of kilobytes and
     * nobody reads past the first screen of it.
     */
    protected const MAX_SNIPPET = 2000;

    /**
     * A stable key for the region containing this element, plus a label an
     * operator can act on.
     *
     * @return array{key: string, label: string, selector: ?string}
     */
    public function locate(DOMElement $element): array
    {
        $container = $this->resolveContainer($element);

        if (! $container) {
            // Nothing identifiable. Reported honestly rather than guessed at: a
            // wrong region label would send someone editing the wrong template.
            return ['key' => 'unknown', 'label' => 'Lokasi tidak dikenali', 'selector' => null];
        }

        return [
            'key' => $container['key'],
            'label' => $container['label'],
            'selector' => $this->describe($container['node']),
        ];
    }

    /**
     * The exact route down to this element, e.g.
     * "body > footer.site-footer > ul > li:nth-child(4) > a".
     *
     * locate() names the region, which is the unit of REPAIR - "it is in the
     * footer, so fix the template". This is the unit of SEARCH: a themed footer runs
     * to hundreds of lines, and this says which one of them. Pasteable into
     * devtools and specific enough to grep the theme file for.
     */
    public function path(DOMElement $element): string
    {
        $segments = [];
        $node = $element;
        $depth = 0;

        while ($node instanceof DOMElement && $depth <= self::MAX_DEPTH) {
            $segments[] = $this->segment($node);

            if (Str::lower($node->nodeName) === 'body') {
                break;
            }

            $node = $node->parentNode;
            $depth++;
        }

        return implode(' > ', array_reverse($segments));
    }

    /**
     * The container's own markup, capped - what was injected, in context.
     *
     * Deliberately the container rather than the element alone: a bare
     * <a href="gacor.example">Slot</a> says nothing about which of the footer's six
     * link lists it was appended to. Callers render this ESCAPED - it is markup
     * lifted off a compromised site.
     */
    public function snippet(DOMElement $element): ?string
    {
        $node = $this->resolveContainer($element)['node'] ?? $element;

        $html = $node->ownerDocument?->saveHTML($node);

        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        return Str::limit(Str::squish($html), self::MAX_SNIPPET);
    }

    /**
     * The nearest ancestor that names a region, or null when nothing on the way up
     * is identifiable.
     *
     * @return array{node: DOMElement, key: string, label: string}|null
     */
    protected function resolveContainer(DOMElement $element): ?array
    {
        $node = $element->parentNode;
        $depth = 0;

        while ($node instanceof DOMElement && $depth < self::MAX_DEPTH) {
            $tag = Str::lower($node->nodeName);

            if (isset(self::LANDMARKS[$tag])) {
                return ['node' => $node, 'key' => $tag, 'label' => self::LANDMARKS[$tag]];
            }

            if ($hint = $this->hintFrom($node)) {
                return ['node' => $node, 'key' => $hint['key'], 'label' => $hint['label']];
            }

            $node = $node->parentNode;
            $depth++;
        }

        return null;
    }

    /**
     * One step of a DOM path. :nth-child is appended only when the tag alone is
     * ambiguous among its siblings - "ul > li:nth-child(4)" locates something,
     * while "footer.site-footer:nth-child(1)" only adds noise.
     */
    protected function segment(DOMElement $node): string
    {
        $base = $this->describe($node);

        if (! $node->parentNode instanceof DOMElement) {
            return $base;
        }

        $position = 0;
        $index = 0;
        $ambiguous = false;

        foreach ($node->parentNode->childNodes as $sibling) {
            if (! $sibling instanceof DOMElement) {
                continue;
            }

            $index++;

            if ($sibling === $node) {
                $position = $index;
            } elseif (strcasecmp($sibling->nodeName, $node->nodeName) === 0) {
                $ambiguous = true;
            }
        }

        return $ambiguous && $position > 0 ? $base.':nth-child('.$position.')' : $base;
    }

    /**
     * The region name implied by an element's class or id, if any.
     *
     * @return array{key: string, label: string}|null
     */
    protected function hintFrom(DOMElement $node): ?array
    {
        $haystack = Str::lower($node->getAttribute('class').' '.$node->getAttribute('id'));

        if (trim($haystack) === '') {
            return null;
        }

        foreach (self::HINTS as $fragment => $label) {
            if (str_contains($haystack, $fragment)) {
                return ['key' => $fragment, 'label' => $label];
            }
        }

        return null;
    }

    /**
     * A CSS-ish selector for the container, so an operator can find it in the
     * page source. Only the first class is kept - template classes are often a
     * long utility list, and the rest adds noise without adding location.
     */
    protected function describe(DOMElement $node): string
    {
        $tag = Str::lower($node->nodeName);

        if ($id = trim($node->getAttribute('id'))) {
            return $tag.'#'.$id;
        }

        $class = Str::of($node->getAttribute('class'))->squish()->explode(' ')->first();

        return $class ? $tag.'.'.$class : $tag;
    }
}
