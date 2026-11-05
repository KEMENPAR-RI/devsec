<?php

namespace App\Livewire\Settings;

use App\Console\Commands\EscalateIncidents;
use App\Livewire\Concerns\InteractsWithToasts;
use App\Mail\IncidentEmail;
use App\Models\Setting;
use App\Rules\PublicUrl;
use App\Services\AppSettings;
use App\Services\Backup\DatabaseBackup;
use App\Services\Monitoring\AiChat;
use App\Services\Monitoring\AiSettings;
use App\Services\Monitoring\ReputationChecker;
use App\Services\Notifications\MailSettings;
use App\Services\Notifications\WebhookNotifier;
use App\Services\Notifications\WebhookSettings;
use App\Services\Security\Recaptcha;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

#[Layout('components.layouts.app')]
class Index extends Component
{
    use InteractsWithToasts;
    use WithFileUploads;

    /**
     * Write-only: never populated with the real decrypted key, so it never
     * round-trips into the browser. Left blank on save means "keep the current key".
     */
    public string $apiKey = '';

    public bool $apiKeyIsSet = false;

    /**
     * Only needed for an Anthropic "identity-linked" API key (personal/OAuth keys
     * spanning multiple workspaces) - see AiSettings::workspaceId(). Not a secret,
     * so stored in plain text (unlike apiKey) and safe to prefill/round-trip.
     */
    public string $workspaceId = '';

    public string $aiProvider = AiSettings::PROVIDER_ANTHROPIC;

    /** Blank means the provider's own default (Anthropic only; a custom provider needs one). */
    public string $aiBaseUrl = '';

    public string $aiModel = '';

    /** @var array{success: bool, message: string}|null */
    public ?array $testResult = null;

    public bool $mailEnabled = false;

    public string $mailHost = '';

    public int $mailPort = 587;

    public string $mailUsername = '';

    /**
     * Write-only, same treatment as apiKey above: never populated with the real
     * decrypted password, blank on save means "keep the current password".
     */
    public string $mailPassword = '';

    public bool $mailPasswordIsSet = false;

    public string $mailEncryption = 'tls';

    public string $mailFromAddress = '';

    public string $mailFromName = '';

    public string $mailTemplateSubject = '';

    public string $mailTemplateBody = '';

    /** @var array{success: bool, message: string}|null */
    public ?array $mailTestResult = null;

    // --- Push channel (Telegram / Slack / WhatsApp gateway) ----------------

    public bool $webhookEnabled = false;

    public string $webhookToken = '';

    public bool $webhookTokenIsSet = false;

    public string $webhookTarget = '';

    /** @var array<int, string> */
    public array $webhookSeverities = ['critical', 'warning'];

    public ?array $webhookTestResult = null;

    public string $safeBrowsingKey = '';

    public bool $safeBrowsingKeyIsSet = false;

    public bool $escalationEnabled = false;

    public int $escalationMinutes = 30;

    public string $appName = '';

    public string $appTimezone = 'Asia/Jakarta';

    public int $defaultCheckIntervalMinutes = 5;

    public int $defaultDownThresholdFailures = 3;

    public int $defaultResponseTimeThresholdMs = 15000;

    public int $tvPollSeconds = 300;

    /** When on, nobody may use the app until they have enrolled a second factor. */
    public bool $twoFactorRequired = false;

    public bool $recaptchaEnabled = false;

    public string $recaptchaSiteKey = '';

    /** Write-only, like the other secrets here: blank on save means "keep it". */
    public string $recaptchaSecretKey = '';

    public bool $recaptchaSecretIsSet = false;

    public float $recaptchaMinScore = 0.5;

    /** The pending upload; the saved one lives on the "public" disk, not here. */
    public $logo = null;

    public $favicon = null;

    public bool $backupScheduleEnabled = false;

    public string $backupFrequency = 'daily';

    public string $backupTime = '02:00';

    public int $backupKeep = 7;

    /** @var array{success: bool, message: string}|null */
    public ?array $backupResult = null;

    public function mount(AiSettings $settings, MailSettings $mailSettings, AppSettings $appSettings, Recaptcha $recaptcha): void
    {
        $this->authorize('pengaturan.view');

        $this->apiKeyIsSet = $settings->hasApiKey();
        $this->workspaceId = (string) $settings->workspaceId();
        $this->aiProvider = $settings->provider();
        $this->aiBaseUrl = (string) Setting::get('ai_base_url');
        $this->aiModel = $settings->model();

        $this->mailEnabled = $mailSettings->enabled();
        $this->mailHost = (string) $mailSettings->host();
        $this->mailPort = $mailSettings->port();
        $this->mailUsername = (string) $mailSettings->username();
        $this->mailPasswordIsSet = $mailSettings->hasPassword();
        $this->mailEncryption = (string) $mailSettings->encryption();
        $this->mailFromAddress = $mailSettings->fromAddress();
        $this->mailFromName = $mailSettings->fromName();
        $this->mailTemplateSubject = $mailSettings->subjectTemplate();
        $this->mailTemplateBody = $mailSettings->bodyTemplate();

        $this->appName = $appSettings->name();
        $this->appTimezone = $appSettings->timezone();
        $this->defaultCheckIntervalMinutes = $appSettings->defaultCheckIntervalMinutes();
        $this->defaultDownThresholdFailures = $appSettings->defaultDownThresholdFailures();
        $this->defaultResponseTimeThresholdMs = $appSettings->defaultResponseTimeThresholdMs();
        $this->tvPollSeconds = $appSettings->tvPollSeconds();

        $webhook = app(WebhookSettings::class);
        $this->webhookEnabled = $webhook->enabled();
        // Never echoed back into the form - a stored secret is shown as "sudah
        // diisi" and only replaced when something new is typed.
        $this->webhookTokenIsSet = $webhook->hasToken();
        $this->webhookTarget = (string) $webhook->target();
        $this->webhookSeverities = $webhook->severities();

        $this->safeBrowsingKeyIsSet = app(ReputationChecker::class)->isConfigured();

        $this->escalationEnabled = Setting::get('escalation_enabled') === '1';
        $this->escalationMinutes = (int) (Setting::get('escalation_minutes') ?: EscalateIncidents::DEFAULT_MINUTES);

        $this->twoFactorRequired = Setting::get('two_factor_required') === '1';

        $this->recaptchaEnabled = Setting::get('recaptcha_enabled') === '1';
        $this->recaptchaSiteKey = (string) $recaptcha->siteKey();
        $this->recaptchaSecretIsSet = $recaptcha->hasSecretKey();
        $this->recaptchaMinScore = $recaptcha->minScore();

        $backup = app(DatabaseBackup::class);
        $this->backupScheduleEnabled = $backup->scheduleEnabled();
        $this->backupFrequency = $backup->frequency();
        $this->backupTime = $backup->scheduleTime();
        $this->backupKeep = $backup->keep();
    }

    /**
     * The shared AppSettings instance.
     *
     * A Livewire component cannot hold a service in a public property (everything
     * public is serialized between requests), so it is resolved on demand - but it
     * is a singleton now, so this hands back the same instance every time rather
     * than building a new one per call site.
     */
    protected function appSettings(): AppSettings
    {
        return app(AppSettings::class);
    }

    /**
     * Stored the moment a file is picked rather than waiting for "Simpan": an
     * upload that only lands on save would be lost by any validation error
     * elsewhere on the page.
     */
    public function updatedLogo(): void
    {
        $this->authorize('pengaturan.update');

        $this->validate([
            'logo' => ['image', 'mimes:png,jpg,jpeg,webp,svg', 'max:1024'],
        ], [], ['logo' => 'logo']);

        $previous = $this->appSettings()->logoPath();

        $path = $this->logo->store('branding', 'public');
        Setting::set('app_logo_path', $path);

        // Only after the new one is safely stored, so a failed upload never leaves
        // the app with no logo at all.
        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        $this->reset('logo');
        $this->toastSuccess('Logo aplikasi diperbarui.');
    }

    /**
     * Same store-on-pick behaviour as the logo. .ico is allowed alongside the image
     * types because it is still the format most favicon generators hand back, and
     * Laravel's "image" rule does not recognise it.
     */
    public function updatedFavicon(): void
    {
        $this->authorize('pengaturan.update');

        $this->validate([
            'favicon' => ['file', 'mimes:png,ico,svg,webp', 'max:512'],
        ], [], ['favicon' => 'favicon']);

        $previous = $this->appSettings()->faviconPath();

        $path = $this->favicon->store('branding', 'public');
        Setting::set('app_favicon_path', $path);

        if ($previous && $previous !== $path) {
            Storage::disk('public')->delete($previous);
        }

        $this->reset('favicon');
        $this->toastSuccess('Favicon diperbarui. Muat ulang halaman untuk melihatnya di tab browser.');
    }

    public function removeFavicon(): void
    {
        $this->authorize('pengaturan.update');

        if ($path = $this->appSettings()->faviconPath()) {
            Storage::disk('public')->delete($path);
        }

        Setting::forget('app_favicon_path');
        // The file is gone but this request already memoized a URL for it.
        $this->appSettings()->forgetResolvedUrls();
        $this->toastSuccess('Favicon dihapus.');
    }

    public function removeLogo(): void
    {
        $this->authorize('pengaturan.update');

        if ($path = $this->appSettings()->logoPath()) {
            Storage::disk('public')->delete($path);
        }

        Setting::forget('app_logo_path');
        $this->appSettings()->forgetResolvedUrls();
        $this->toastSuccess('Logo aplikasi dihapus - kembali ke inisial nama aplikasi.');
    }

    public function backupNow(DatabaseBackup $backup): void
    {
        $this->authorize('pengaturan.update');

        try {
            $filename = $backup->create();
            $this->backupResult = ['success' => true, 'message' => "Backup berhasil dibuat: {$filename}"];
        } catch (Throwable $e) {
            $this->backupResult = ['success' => false, 'message' => 'Backup gagal: '.$e->getMessage()];
        }
    }

    public function deleteBackup(string $filename, DatabaseBackup $backup): void
    {
        $this->authorize('pengaturan.update');

        $backup->delete($filename);
        $this->toastSuccess('Berkas backup dihapus.');
    }

    /**
     * Sends a sample message to the configured channel.
     *
     * Saves first, deliberately: testing against what is on screen rather than
     * what is stored is how somebody "tests successfully" and then finds the
     * channel silent during a real outage.
     */
    public function testWebhook(): void
    {
        $this->authorize('pengaturan.update');

        $this->save();

        $this->webhookTestResult = app(WebhookNotifier::class)->test();
    }

    public function save(): void
    {
        $this->authorize('pengaturan.update');

        $this->validate([
            'apiKey' => ['nullable', 'string', 'max:200'],
            'workspaceId' => ['nullable', 'string', 'max:100'],
            'aiProvider' => ['required', Rule::in(array_keys(AiSettings::PROVIDERS))],
            'aiBaseUrl' => [
                'nullable', 'string', 'url:http,https', 'max:255',
                // A custom provider has no default endpoint to fall back to.
                'required_if:aiProvider,'.AiSettings::PROVIDER_OPENAI,
                new PublicUrl,
            ],
            'aiModel' => ['nullable', 'string', 'max:100', 'required_if:aiProvider,'.AiSettings::PROVIDER_OPENAI],
            'mailEnabled' => ['boolean'],
            'mailHost' => ['nullable', 'string', 'max:255', 'required_if:mailEnabled,true'],
            'mailPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'mailUsername' => ['nullable', 'string', 'max:255'],
            'mailPassword' => ['nullable', 'string', 'max:255'],
            'mailEncryption' => ['nullable', 'string', 'in:tls,ssl,'],
            'mailFromAddress' => ['nullable', 'email', 'max:255', 'required_if:mailEnabled,true'],
            'mailFromName' => ['nullable', 'string', 'max:255'],
            'mailTemplateSubject' => ['nullable', 'string', 'max:255'],
            'mailTemplateBody' => ['nullable', 'string', 'max:20000'],
            'appName' => ['required', 'string', 'max:60'],
            'appTimezone' => ['required', 'string', Rule::in(array_keys($this->appSettings()->timezoneOptions()))],
            'defaultCheckIntervalMinutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'defaultDownThresholdFailures' => ['required', 'integer', 'min:1', 'max:20'],
            'defaultResponseTimeThresholdMs' => ['required', 'integer', 'min:200', 'max:25000'],
            'tvPollSeconds' => ['required', 'integer', 'min:10', 'max:3600'],
            'twoFactorRequired' => ['boolean'],
            'webhookEnabled' => ['boolean'],
            'webhookToken' => ['nullable', 'string', 'max:500'],
            // A Telegram chat id: digits, optionally negative for a group.
            'webhookTarget' => ['nullable', 'string', 'max:255', 'regex:/^-?\d+$/'],
            'webhookSeverities' => ['array'],
            'webhookSeverities.*' => [Rule::in(['critical', 'warning', 'info'])],
            'safeBrowsingKey' => ['nullable', 'string', 'max:255'],
            'escalationEnabled' => ['boolean'],
            'escalationMinutes' => ['required', 'integer', 'min:5', 'max:1440'],
            'recaptchaEnabled' => ['boolean'],
            // Required only when switching it on, and only when nothing is stored
            // yet - otherwise the blank box means "keep the saved secret".
            'recaptchaSiteKey' => ['nullable', 'string', 'max:255', 'required_if:recaptchaEnabled,true'],
            'recaptchaSecretKey' => ['nullable', 'string', 'max:255'],
            'recaptchaMinScore' => ['required', 'numeric', 'min:0', 'max:1'],
            'backupScheduleEnabled' => ['boolean'],
            'backupFrequency' => ['required', 'in:daily,weekly,monthly'],
            'backupTime' => ['required', 'regex:/^\d{2}:\d{2}$/'],
            'backupKeep' => ['required', 'integer', 'min:1', 'max:'.DatabaseBackup::MAX_KEEP],
        ]);

        if ($this->recaptchaEnabled && blank($this->recaptchaSecretKey) && ! $this->recaptchaSecretIsSet) {
            $this->addError('recaptchaSecretKey', 'Secret key wajib diisi untuk mengaktifkan reCAPTCHA.');

            return;
        }

        if (filled($this->apiKey)) {
            Setting::set('ai_api_key', Crypt::encryptString(trim($this->apiKey)));
            $this->apiKeyIsSet = true;
            $this->apiKey = '';
        }

        if (filled($this->workspaceId)) {
            Setting::set('ai_workspace_id', trim($this->workspaceId));
        } else {
            Setting::forget('ai_workspace_id');
        }

        Setting::set('ai_provider', $this->aiProvider);
        Setting::set('ai_base_url', rtrim(trim($this->aiBaseUrl), '/') ?: null);
        Setting::set('ai_model', trim($this->aiModel) ?: null);

        Setting::set('mail_enabled', $this->mailEnabled ? '1' : '0');
        Setting::set('mail_host', trim($this->mailHost) ?: null);
        Setting::set('mail_port', (string) $this->mailPort);
        Setting::set('mail_username', trim($this->mailUsername) ?: null);

        if (filled($this->mailPassword)) {
            Setting::set('mail_password', Crypt::encryptString(trim($this->mailPassword)));
            $this->mailPasswordIsSet = true;
            $this->mailPassword = '';
        }

        Setting::set('mail_encryption', $this->mailEncryption ?: null);
        Setting::set('mail_from_address', trim($this->mailFromAddress) ?: null);
        Setting::set('mail_from_name', trim($this->mailFromName) ?: null);
        Setting::set('mail_template_subject', trim($this->mailTemplateSubject) ?: null);
        Setting::set('mail_template_body', trim($this->mailTemplateBody) ?: null);

        Setting::set('app_name', trim($this->appName) ?: null);
        Setting::set('app_timezone', $this->appTimezone);
        Setting::set('default_check_interval_minutes', (string) $this->defaultCheckIntervalMinutes);
        Setting::set('default_down_threshold_failures', (string) $this->defaultDownThresholdFailures);
        Setting::set('default_response_time_threshold_ms', (string) $this->defaultResponseTimeThresholdMs);
        Setting::set('tv_poll_seconds', (string) $this->tvPollSeconds);
        // TV board cache TTL follows the poll interval, so drop the old entry.
        Cache::forget('tv-board');

        if (filled($this->safeBrowsingKey)) {
            Setting::set('safebrowsing_api_key', Crypt::encryptString(trim($this->safeBrowsingKey)));
            $this->safeBrowsingKeyIsSet = true;
            $this->reset('safeBrowsingKey');
        }

        Setting::set('escalation_enabled', $this->escalationEnabled ? '1' : '0');
        Setting::set('escalation_minutes', (string) $this->escalationMinutes);

        Setting::set('webhook_enabled', $this->webhookEnabled ? '1' : '0');
        Setting::set('webhook_target', trim($this->webhookTarget) ?: null);
        Setting::set('webhook_severities', implode(',', $this->webhookSeverities ?: ['critical']));

        // Blank means "leave what is stored alone", so saving the page for an
        // unrelated reason cannot wipe a secret nobody retyped.
        if (filled($this->webhookToken)) {
            Setting::set('webhook_token', Crypt::encryptString(trim($this->webhookToken)));
            $this->webhookTokenIsSet = true;
            $this->reset('webhookToken');
        }

        Setting::set('two_factor_required', $this->twoFactorRequired ? '1' : '0');

        Setting::set('recaptcha_enabled', $this->recaptchaEnabled ? '1' : '0');
        Setting::set('recaptcha_site_key', trim($this->recaptchaSiteKey) ?: null);
        Setting::set('recaptcha_min_score', (string) $this->recaptchaMinScore);

        Setting::set('backup_schedule_enabled', $this->backupScheduleEnabled ? '1' : '0');
        Setting::set('backup_frequency', $this->backupFrequency);
        Setting::set('backup_time', $this->backupTime);
        Setting::set('backup_keep', (string) $this->backupKeep);

        if (filled($this->recaptchaSecretKey)) {
            Setting::set('recaptcha_secret_key', Crypt::encryptString(trim($this->recaptchaSecretKey)));
            $this->recaptchaSecretIsSet = true;
            $this->recaptchaSecretKey = '';
        }

        // Applied to this request too, so the sidebar and page title update without
        // waiting for the next boot.
        config(['app.name' => $this->appName, 'app.timezone' => $this->appTimezone]);
        date_default_timezone_set($this->appTimezone);

        $this->testResult = null;
        $this->mailTestResult = null;
        $this->toastSuccess('Pengaturan disimpan.');
    }

    public function clearRecaptchaSecret(): void
    {
        $this->authorize('pengaturan.update');

        Setting::forget('recaptcha_secret_key');
        $this->recaptchaSecretIsSet = false;
        $this->recaptchaSecretKey = '';
        $this->toastSuccess('Secret key reCAPTCHA dihapus.');
    }

    public function clearApiKey(): void
    {
        $this->authorize('pengaturan.update');

        Setting::forget('ai_api_key');
        $this->apiKeyIsSet = false;
        $this->testResult = null;
        $this->toastSuccess('API key dihapus. Analisis AI tidak akan berjalan sampai API key baru disimpan.');
    }

    public function clearMailPassword(): void
    {
        $this->authorize('pengaturan.update');

        Setting::forget('mail_password');
        $this->mailPasswordIsSet = false;
        $this->mailTestResult = null;
        $this->toastSuccess('Kata sandi SMTP dihapus. Sistem akan memakai MAIL_PASSWORD dari .env (jika ada).');
    }

    /**
     * Discards any customization and reverts the subject/body fields (in the
     * form only - still needs Simpan Pengaturan to persist) to the built-in
     * default template.
     */
    public function resetMailTemplate(MailSettings $mailSettings): void
    {
        $this->authorize('pengaturan.update');

        $this->mailTemplateSubject = $mailSettings->defaultSubjectTemplate();
        $this->mailTemplateBody = $mailSettings->defaultBodyTemplate();
    }

    /**
     * Cheap connectivity check (metadata call, no completion tokens spent where the
     * provider allows it) - lets an admin confirm the endpoint and key actually work
     * right after typing them, before saving, or confirm the saved ones still work
     * (e.g. after rotating the key).
     */
    public function testConnection(AiSettings $settings): void
    {
        $this->authorize('pengaturan.update');

        $this->validate([
            'aiProvider' => ['required', Rule::in(array_keys(AiSettings::PROVIDERS))],
            'aiBaseUrl' => ['nullable', 'string', 'url:http,https', 'max:255', 'required_if:aiProvider,'.AiSettings::PROVIDER_OPENAI, new PublicUrl],
            'aiModel' => ['nullable', 'string', 'max:100', 'required_if:aiProvider,'.AiSettings::PROVIDER_OPENAI],
        ]);

        $anthropic = $this->aiProvider === AiSettings::PROVIDER_ANTHROPIC;

        // Test with whatever is currently in the form (even if unsaved yet). Blank
        // Anthropic fields use its defaults, not whatever a saved custom provider has.
        $chat = new AiChat($settings, [
            'provider' => $this->aiProvider,
            'base_url' => trim($this->aiBaseUrl) ?: ($anthropic ? (string) config('ai.anthropic_base_url') : ''),
            'model' => trim($this->aiModel) ?: ($anthropic ? (string) config('ai.default_model') : ''),
            'api_key' => $this->apiKey,
            'workspace_id' => $this->workspaceId,
        ]);

        if ($anthropic && blank($this->apiKey) && ! $settings->hasApiKey()) {
            $this->testResult = ['success' => false, 'message' => 'Belum ada API key untuk diuji.'];

            return;
        }

        try {
            $chat->ping();
            $this->testResult = ['success' => true, 'message' => 'Koneksi berhasil.'];
        } catch (Throwable $e) {
            $this->testResult = ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Sends a real test email to the current user, using whatever is currently
     * in the form - even if unsaved - so an admin can confirm SMTP credentials
     * actually work before committing to them (mirrors testConnection() above).
     */
    public function testEmail(MailSettings $mailSettings): void
    {
        $this->authorize('pengaturan.update');

        $this->validate([
            'mailHost' => ['required', 'string'],
            'mailPort' => ['required', 'integer', 'min:1', 'max:65535'],
            'mailFromAddress' => ['required', 'email'],
        ]);

        $overrides = [
            'host' => trim($this->mailHost),
            'port' => $this->mailPort,
            'username' => filled($this->mailUsername) ? trim($this->mailUsername) : $mailSettings->username(),
            'password' => filled($this->mailPassword) ? trim($this->mailPassword) : $mailSettings->password(),
            'encryption' => $this->mailEncryption ?: null,
            'from_address' => trim($this->mailFromAddress),
            'from_name' => trim($this->mailFromName) ?: $mailSettings->fromName(),
        ];

        try {
            $mailSettings->applyToRuntime($overrides);

            Mail::mailer('smtp')->to(auth()->user()->email)->send(new IncidentEmail([
                'title' => 'Email Tes dari '.config('app.name'),
                'message' => 'Ini adalah email percobaan untuk memverifikasi konfigurasi SMTP Anda berfungsi dengan benar.',
                'website_name' => '-',
                'website_url' => '-',
                'severity' => 'info',
                'type' => 'test',
                'action_url' => route('pengaturan'),
                'detected_at' => now()->translatedFormat('d M Y, H:i'),
            ]));

            $this->mailTestResult = ['success' => true, 'message' => 'Email tes berhasil dikirim ke '.auth()->user()->email.'.'];
        } catch (Throwable $e) {
            $this->mailTestResult = ['success' => false, 'message' => $e->getMessage()];
        }
    }

    public function render()
    {
        $backup = app(DatabaseBackup::class);

        return view('livewire.settings.index', [
            'timezoneOptions' => $this->appSettings()->timezoneOptions(),
            'logoUrl' => $this->appSettings()->logoUrl(),
            'faviconUrl' => $this->appSettings()->faviconUrl(),
            'backups' => $backup->all(),
            'backupLastRunAt' => $backup->lastRunAt(),
            // So the toggle below can explain itself when .env is overruling it.
            'recaptchaLockedByEnv' => ! app(Recaptcha::class)->allowedByEnv(),
        ]);
    }
}
