<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AppSettings;
use App\Services\Backup\DatabaseBackup;
use App\Services\Monitoring\AiSettings;
use App\Services\Notifications\MailSettings;
use App\Services\Security\Recaptcha;
use App\Support\Permissions;
use App\View\Composers\BrandingComposer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * The settings-backed services, resolved once per request.
     *
     * Every one of these reads the same settings table and holds no per-call
     * state, so a fresh instance per use bought nothing - and they are asked
     * constantly: AppSettings on every rendered timestamp, MailSettings on every
     * notification. Registering them as singletons means one instance per request instead of dozens, and lets each
     * safely memoize what it resolves (see AppSettings).
     *
     * @var array<int, class-string>
     */
    protected const SETTINGS_SERVICES = [
        AppSettings::class,
        AiSettings::class,
        MailSettings::class,
        Recaptcha::class,
        DatabaseBackup::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        foreach (self::SETTINGS_SERVICES as $service) {
            $this->app->singleton($service);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyRuntimeAppSettings();
        $this->composeViews();
        $this->definePasswordPolicy();

        // Every permission in the registry becomes a Gate of the same name, so one
        // list drives @can() in Blade, the `can:` route middleware, and
        // $this->authorize() inside Livewire components - and adding a page to
        // App\Support\Permissions is all it takes to make it enforceable.
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }

    /**
     * One password policy for every place a password is set: the profile page, the
     * user form, and the reset-link page.
     *
     * uncompromised() is production-only because it asks haveibeenpwned.com over
     * the network. Leaving it on in tests would make the suite depend on an
     * outside service, and in development it would fail whenever the machine is
     * offline - both of which teach people to work around the policy rather than
     * with it. The structural rules stay on everywhere, so they are actually tested.
     */
    protected function definePasswordPolicy(): void
    {
        Password::defaults(function () {
            $rule = Password::min(12)->mixedCase()->numbers();

            return $this->app->isProduction() ? $rule->uncompromised() : $rule;
        });
    }

    /**
     * Binds the data a view needs to the view itself, so Blade files stay markup
     * and stop resolving services out of the container mid-render.
     */
    protected function composeViews(): void
    {
        View::composer(
            ['components.layouts.app', 'components.layouts.guest', 'components.layouts.tv', 'livewire.tv.display'],
            BrandingComposer::class
        );

        // The reCAPTCHA widget is one <script> whose whole behaviour depends on
        // whether it is configured; the component asked the container itself.
        View::composer('components.recaptcha', function ($view) {
            $recaptcha = $this->app->make(Recaptcha::class);

            $view->with([
                'recaptchaEnabled' => $recaptcha->enabled(),
                'recaptchaSiteKey' => $recaptcha->enabled() ? $recaptcha->siteKey() : null,
            ]);
        });

        // Mail template placeholders, for the help text under the template editor.
        View::composer('livewire.settings.index', function ($view) {
            $view->with('mailPlaceholders', $this->app->make(MailSettings::class)->placeholders());
        });
    }

    /**
     * Overrides the app name and timezone with what's saved in Pengaturan, so both
     * can be changed without touching .env.
     *
     * Wrapped in a try/catch because boot() also runs before the settings table
     * exists - during the very first `migrate`, or on a machine with no database
     * yet - and a config override is never worth failing the whole boot over.
     */
    protected function applyRuntimeAppSettings(): void
    {
        try {
            $settings = $this->app->make(AppSettings::class);
            $timezone = $settings->timezone();

            config(['app.name' => $settings->name(), 'app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        } catch (Throwable) {
            // No settings table yet - the .env values stand.
        }
    }
}
