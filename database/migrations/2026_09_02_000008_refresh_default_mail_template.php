<?php

use App\Models\Setting;
use App\Services\Notifications\MailSettings;
use Illuminate\Database\Migrations\Migration;

/**
 * The stored email template used to be incident-shaped: a hard-coded "Situs /
 * Waktu" table and a "Lihat Detail" button, which read as nonsense on a password
 * reset. The new default drives both from {{details}} and {{action}} instead.
 *
 * Only rewrites a template that still matches one of the defaults this app has
 * shipped - if an admin has edited theirs, it is left exactly as they wrote it,
 * and they can pick up the new layout from "Reset ke Default" when they choose.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stored = Setting::get('mail_template_body');

        $isUntouched = collect($this->previousDefaults())
            ->map(fn (string $template) => $this->normalize($template))
            ->contains($this->normalize((string) $stored));

        if ($stored === null || $isUntouched) {
            Setting::set('mail_template_body', app(MailSettings::class)->defaultBodyTemplate());
        }
    }

    public function down(): void
    {
        // The template is editable data, not schema - there is nothing to roll back
        // to that wouldn't risk clobbering whatever is there by then.
    }

    /**
     * Every default this app has shipped, so an install that never customized its
     * template gets the new layout no matter which release it was set up on.
     *
     * @return array<int, string>
     */
    protected function previousDefaults(): array
    {
        $withItems = $this->previousDefault();

        return [
            $withItems,
            // The original, from before {{items}} existed - identical apart from
            // that one line, so it is derived rather than duplicated wholesale.
            str_replace('            {{items}}'.PHP_EOL, '', $withItems),
            str_replace('            {{items}}'."\n", '', $withItems),
        ];
    }

    /**
     * Line endings normalized before comparing: a template that was merely opened
     * and re-saved from the Pengaturan textarea comes back with CRLF endings, and
     * that alone must not count as "the admin customized this".
     */
    protected function normalize(string $template): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $template));
    }

    protected function previousDefault(): string
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
                    <p style="margin:0 0 4px;font-size:12px;font-weight:600;letter-spacing:.04em;text-transform:uppercase;color:#dc2626;">{{severity}}</p>
                    <h1 style="margin:0 0 16px;font-size:20px;line-height:1.3;color:#18181b;">{{title}}</h1>
                    <p style="margin:0 0 20px;font-size:14px;line-height:1.6;color:#52525b;">{{message}}</p>
                    {{items}}
                    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fafafa;border-radius:10px;margin-bottom:24px;">
                      <tr>
                        <td style="padding:14px 16px;font-size:13px;color:#71717a;">Situs</td>
                        <td style="padding:14px 16px;font-size:13px;color:#18181b;text-align:right;">{{website_name}}</td>
                      </tr>
                      <tr>
                        <td style="padding:0 16px 14px;font-size:13px;color:#71717a;">Waktu</td>
                        <td style="padding:0 16px 14px;font-size:13px;color:#18181b;text-align:right;">{{detected_at}}</td>
                      </tr>
                    </table>
                    <a href="{{action_url}}" style="display:inline-block;background:#e11d48;color:#ffffff;font-size:14px;font-weight:600;text-decoration:none;padding:10px 20px;border-radius:8px;">Lihat Detail</a>
                  </td>
                </tr>
                <tr>
                  <td style="padding:16px 28px;border-top:1px solid #f4f4f5;">
                    <p style="margin:0;font-size:11px;color:#a1a1aa;">Notifikasi otomatis dari {{app_name}} - {{website_url}}</p>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
        </table>
        HTML;
    }
};
