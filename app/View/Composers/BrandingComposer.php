<?php

namespace App\View\Composers;

use App\Services\AppSettings;
use Illuminate\View\View;

/**
 * Hands the layouts the branding saved in Pengaturan, so neither of them has to
 * reach into the container to get it.
 *
 * Both layouts needed the same three values and each resolved AppSettings itself
 * in an @php block at the top of the file - the kind of thing that quietly drifts
 * apart, and did: only the guest layout was showing the saved app name, while the
 * app layout still printed the .env one.
 *
 * Registered against the two layout views in AppServiceProvider. AppSettings is a
 * singleton and memoizes its disk lookups, so binding this on every render costs
 * nothing after the first.
 */
class BrandingComposer
{
    public function __construct(protected AppSettings $settings) {}

    public function compose(View $view): void
    {
        $view->with([
            // Also available as app_name() for the components the composer does not
            // cover (brand-mark, pwa-meta, the mail layout).
            'appName' => $this->settings->name(),
            'appLogoUrl' => $this->settings->logoUrl(),
            'appFaviconUrl' => $this->settings->faviconUrl(),
        ]);
    }
}
