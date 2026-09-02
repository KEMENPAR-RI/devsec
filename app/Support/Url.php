<?php

namespace App\Support;

/**
 * Shared URL tidying for the places where someone types a site by hand - the
 * add/edit form, bulk import, and the per-site "cek URL tertentu" box.
 */
class Url
{
    /**
     * Prepends https:// when the input has no scheme at all. An explicit http:// or
     * https:// the user typed is always left untouched.
     */
    public static function withScheme(string $url): string
    {
        $url = trim($url);

        return ($url !== '' && ! preg_match('#^https?://#i', $url)) ? "https://{$url}" : $url;
    }
}
