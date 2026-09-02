<?php

namespace App\Providers;

use App\Models\User;
use App\Services\AppSettings;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Throwable;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->applyRuntimeAppSettings();

        // Every permission in the registry becomes a Gate of the same name, so one
        // list drives @can() in Blade, the `can:` route middleware, and
        // $this->authorize() inside Livewire components - and adding a page to
        // App\Support\Permissions is all it takes to make it enforceable.
        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
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
            $settings = app(AppSettings::class);
            $timezone = $settings->timezone();

            config(['app.name' => $settings->name(), 'app.timezone' => $timezone]);
            date_default_timezone_set($timezone);
        } catch (Throwable) {
            // No settings table yet - the .env values stand.
        }
    }
}
