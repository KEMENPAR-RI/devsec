<?php

namespace App\Services\Monitoring;

use DOMElement;
use DOMNode;
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
     * A stable key for the region containing this element, plus a label an
     * operator can act on.
     *
     * @return array{key: string, label: string, selector: ?string}
     */
    public function locate(DOMElement $element): array
    {
        $selector = null;
        $label = null;
        $key = null;

        $node = $element->parentNode;
        $depth = 0;

        while ($node instanceof DOMElement && $depth < self::MAX_DEPTH) {
            $tag = Str::lower($node->nodeName);

            if (isset(self::LANDMARKS[$tag])) {
                $label = self::LANDMARKS[$tag];
                $key = $tag;
                $selector = $this->describe($node);

                break;
            }

            if ($hint = $this->hintFrom($node)) {
                $label = $hint['label'];
                $key = $hint['key'];
                $selector = $this->describe($node);

                break;
            }

            $node = $node->parentNode;
            $depth++;
        }

        if ($key === null) {
            // Nothing identifiable. Reported honestly rather than guessed at: a
            // wrong region label would send someone editing the wrong template.
            return ['key' => 'unknown', 'label' => 'Lokasi tidak dikenali', 'selector' => null];
        }

        return ['key' => $key, 'label' => $label, 'selector' => $selector];
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
