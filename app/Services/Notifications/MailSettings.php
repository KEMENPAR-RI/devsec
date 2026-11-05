<?php

namespace App\Services\Notifications;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Resolves email-notification config dynamically from the settings table (managed
 * from the Pengaturan page) - SMTP credentials plus a customizable subject/body
 * template - falling back to config('mail.*') / .env whenever no override has
 * been saved yet, same read pattern as App\Services\Monitoring\AiSettings.
 *
 * Email is entirely opt-in: enabled() gates whether the 'mail' channel is added
 * at all (see App\Notifications\Concerns\SendsIncidentEmail), so a fresh install
 * with nothing configured here keeps behaving exactly as before (database-only
 * notifications).
 */
class MailSettings
{
    public function enabled(): bool
    {
        return Setting::get('mail_enabled') === '1';
    }

    /**
     * Whether mail can actually leave this app: switched on AND pointed at a host.
     * Distinct from enabled(), which only says somebody flipped the toggle.
     */
    public function isConfigured(): bool
    {
        return $this->enabled() && filled($this->host()) && filled($this->fromAddress());
    }

    public function host(): ?string
    {
        return Setting::get('mail_host') ?: config('mail.mailers.smtp.host');
    }

    public function port(): int
    {
        return (int) (Setting::get('mail_port') ?: config('mail.mailers.smtp.port', 587));
    }

    public function username(): ?string
    {
        return Setting::get('mail_username') ?: config('mail.mailers.smtp.username');
    }

    /**
     * Stored encrypted (App\Livewire\Settings\Index encrypts it on save) so it
     * isn't sitting in plaintext in the database, same treatment as the
     * Anthropic API key.
     */
    public function password(): ?string
    {
        $encrypted = Setting::get('mail_password');

        if ($encrypted) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                // Corrupted/undecryptable (e.g. APP_KEY rotated since it was saved) -
                // fall through to .env rather than hard-failing every send.
            }
        }

        return config('mail.mailers.smtp.password');
    }

    public function hasPassword(): bool
    {
        return Setting::get('mail_password') !== null || filled(config('mail.mailers.smtp.password'));
    }

    /** 'tls', 'ssl', or null (no encryption) - blank/omitted means auto-detect from the port. */
    public function encryption(): ?string
    {
        return Setting::get('mail_encryption') ?: null;
    }

    public function fromAddress(): string
    {
        return Setting::get('mail_from_address') ?: (string) config('mail.from.address', 'noreply@example.com');
    }

    public function fromName(): string
    {
        return Setting::get('mail_from_name') ?: (string) config('mail.from.name', config('app.name'));
    }

    public function defaultSubjectTemplate(): string
    {
        return '[{{app_name}}] {{title}}';
    }

    public function defaultBodyTemplate(): string
    {
        return <<<'HTML'
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f5;padding:32px 16px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
          <tr>
            <td align="center">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:520px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e4e4e7;">
                <tr>
                  <td style="background:#171717;padding:20px 28px;">
                    <span style="color:#ffffff;font-size:15px;font-weight:600;">{{app_name}}</span>
                  </td>
                </tr>
                <tr>
                  <td style="padding:28px;">
                    {{eyebrow}}
                    <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#18181b;">{{title}}</h1>
                    <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#52525b;">{{message}}</p>
                    {{items}}
                    {{details}}
                    {{action}}
                  </td>
                </tr>
                <tr>
                  <td style="padding:16px 28px;border-top:1px solid #f4f4f5;">
                    <p style="margin:0;font-size:11px;color:#a1a1aa;">{{footer}}</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        HTML;
    }

    public function subjectTemplate(): string
    {
        return Setting::get('mail_template_subject') ?: $this->defaultSubjectTemplate();
    }

    public function bodyTemplate(): string
    {
        return Setting::get('mail_template_body') ?: $this->defaultBodyTemplate();
    }

    /**
     * @return array<string, string> token => placeholder, for the settings page's
     *                               "available placeholders" legend and for render() below.
     */
    public function placeholders(): array
    {
        return [
            '{{title}}' => 'Judul singkat email',
            '{{message}}' => 'Isi pesan utama',
            '{{eyebrow}}' => 'Label kecil di atas judul (mis. tingkat keparahan) - kosong bila tidak ada',
            '{{items}}' => 'Daftar temuan, sudah dalam bentuk <ul> (untuk email yang menggabungkan beberapa insiden)',
            '{{details}}' => 'Tabel rincian label/nilai, sudah jadi HTML - kosong bila tidak ada',
            '{{action}}' => 'Tombol aksi lengkap - kosong bila email ini tidak punya tautan',
            '{{action_url}}' => 'URL tujuan tombol saja, kalau ingin merangkai tombol sendiri',
            '{{action_label}}' => 'Teks tombol saja (mis. "Lihat Detail")',
            '{{footer}}' => 'Baris penutup di bagian bawah',
            '{{website_name}}' => 'Nama situs (kosong untuk email non-insiden)',
            '{{website_url}}' => 'URL situs (kosong untuk email non-insiden)',
            '{{severity}}' => 'Tingkat keparahan (critical/warning/info)',
            '{{type}}' => 'Jenis email (downtime/attack/ssl/recovered/password_reset)',
            '{{detected_at}}' => 'Waktu kejadian terdeteksi',
            '{{app_name}}' => 'Nama aplikasi',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderSubject(array $data): string
    {
        // escape: false - a subject line is plain text, where an escaped "&" would
        // arrive as the literal five characters "&amp;".
        return $this->render($this->subjectTemplate(), $data, escape: false);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function renderBody(array $data): string
    {
        return $this->render($this->bodyTemplate(), $data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  bool  $escape  whether the plain-text tokens are being dropped into
     *                        HTML (the body) or into a plain-text subject line
     */
    protected function render(string $template, array $data, bool $escape = true): string
    {
        $actionUrl = (string) ($data['action_url'] ?? '');
        $actionLabel = (string) ($data['action_label'] ?? 'Lihat Detail');

        // Plain text arriving from outside the template. {{title}} and {{message}}
        // in particular can carry data read off a monitored site, so they
        // are escaped here rather than trusted, same as renderDetails() already
        // does for every row of the details table.
        $text = [
            '{{title}}' => (string) ($data['title'] ?? ''),
            '{{message}}' => (string) ($data['message'] ?? ''),
            '{{action_url}}' => $actionUrl,
            '{{action_label}}' => $actionLabel,
            '{{footer}}' => (string) ($data['footer'] ?? 'Email otomatis dari '.config('app.name').'. Mohon tidak membalas email ini.'),
            '{{website_name}}' => (string) ($data['website_name'] ?? ''),
            '{{website_url}}' => (string) ($data['website_url'] ?? ''),
            '{{severity}}' => (string) ($data['severity'] ?? ''),
            '{{type}}' => (string) ($data['type'] ?? ''),
            '{{detected_at}}' => (string) ($data['detected_at'] ?? ''),
            '{{app_name}}' => (string) config('app.name'),
        ];

        $tokens = [
            ...($escape ? array_map(fn (string $value) => e($value), $text) : $text),
            // Already-built HTML - renderEyebrow / renderAction / renderItemsList /
            // renderDetails each escape what came from outside before wrapping it in
            // markup - so these four are the only tokens that go in raw.
            '{{eyebrow}}' => $this->renderEyebrow((string) ($data['eyebrow'] ?? $data['severity'] ?? '')),
            '{{items}}' => (string) ($data['items'] ?? ''),
            '{{details}}' => (string) ($data['details'] ?? ''),
            '{{action}}' => $this->renderAction($actionUrl, $actionLabel),
        ];

        return strtr($template, $tokens);
    }

    /**
     * The small coloured label above the title. Renders nothing at all when there
     * is none, rather than leaving an empty red line above the heading.
     */
    protected function renderEyebrow(string $text): string
    {
        return $text === ''
            ? ''
            : '<p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#dc2626;">'.e($text).'</p>';
    }

    /** Same idea for the button: an email with nowhere to go shows no button. */
    protected function renderAction(string $url, string $label): string
    {
        return $url === ''
            ? ''
            : '<a href="'.e($url).'" style="display:inline-block;background:#e11d48;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:10px 20px;border-radius:8px;">'.e($label).'</a>';
    }

    /**
     * Builds the label/value table that fills {{details}}. Shared so every email
     * type gets identical rows instead of each one hand-rolling its own markup.
     *
     * @param  array<string, string>  $rows  label => value, blank values dropped
     */
    public function renderDetails(array $rows): string
    {
        $cells = collect($rows)
            ->filter(fn ($value) => filled($value))
            ->map(fn ($value, $label) => '<tr>'
                .'<td style="padding:12px 16px;font-size:13px;color:#71717a;">'.e($label).'</td>'
                .'<td style="padding:12px 16px;font-size:13px;color:#18181b;text-align:right;">'.e($value).'</td>'
                .'</tr>')
            ->implode('');

        return $cells === ''
            ? ''
            : '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border-radius:10px;margin-bottom:24px;">'.$cells.'</table>';
    }

    /**
     * Points Laravel's "smtp" mailer at the saved settings and purges its cached
     * transport - MailManager only builds a mailer's transport once per name and
     * reuses it, so a plain config() set wouldn't take effect on an
     * already-resolved mailer without this. Called right before every send (see
     * App\Notifications\Concerns\SendsIncidentEmail) so a credential change or a
     * toggle flip takes effect immediately, no deploy/restart needed.
     *
     * @param  array<string, mixed>  $overrides  unsaved form values to test with
     *                                           before they're persisted (e.g. the "Tes Email" button on Pengaturan) -
     *                                           anything omitted falls back to what's already saved/configured.
     */
    public function applyToRuntime(array $overrides = []): void
    {
        config([
            'mail.mailers.smtp.transport' => 'smtp',
            'mail.mailers.smtp.host' => $overrides['host'] ?? $this->host(),
            'mail.mailers.smtp.port' => $overrides['port'] ?? $this->port(),
            'mail.mailers.smtp.username' => $overrides['username'] ?? $this->username(),
            'mail.mailers.smtp.password' => $overrides['password'] ?? $this->password(),
            'mail.mailers.smtp.encryption' => $overrides['encryption'] ?? $this->encryption(),
            'mail.from.address' => $overrides['from_address'] ?? $this->fromAddress(),
            'mail.from.name' => $overrides['from_name'] ?? $this->fromName(),
        ]);

        // Without this the SMTP credentials above are configured but never used:
        // mail.default still points at whatever MAIL_MAILER says (Laravel ships
        // with 'log'), so every message is quietly written to storage/logs instead
        // of being delivered. Selecting the mailer is what actually sends it.
        // Only when mail is switched on and pointed somewhere, so a half-configured
        // install keeps falling back to the log rather than erroring on every send.
        if ($this->isConfigured() || filled($overrides)) {
            config(['mail.default' => 'smtp']);
        }

        // Mail::purge() is undefined on Illuminate's MailFake (Mail::fake() swaps
        // the "mail.manager" container binding too, so this resolves to the fake
        // even called this way) - guarded so tests using Mail::fake() don't have
        // to know or care that this method exists at all; purging is meaningless
        // there anyway since no real transport ever gets built.
        $manager = app('mail.manager');

        if (method_exists($manager, 'purge')) {
            $manager->purge('smtp');
        }
    }
}
