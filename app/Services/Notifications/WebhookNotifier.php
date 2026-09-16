<?php

namespace App\Services\Notifications;

use App\Models\User;
use Illuminate\Notifications\Notification as NotificationClass;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Pushes an incident to Telegram.
 *
 * Called once per event, never once per admin: the destination is a shared
 * channel, and a team of six getting six identical Telegram messages for one
 * outage is how a channel gets muted.
 *
 * Failure is always swallowed and logged. This runs inside the monitoring loop,
 * and a chat provider having a bad afternoon must not stop the check that found
 * the problem from being recorded.
 */
class WebhookNotifier
{
    public function __construct(protected WebhookSettings $settings) {}

    /**
     * Sends the payload a notification already builds for the in-app channel.
     *
     * Reusing toArray() rather than adding a toWebhook() to every notification
     * keeps the two from drifting: whatever the dropdown says is what the chat
     * message says.
     */
    public function sendNotification(NotificationClass $notification, ?User $context = null): bool
    {
        if (! method_exists($notification, 'toArray')) {
            return false;
        }

        // The notifiable is unused by every toArray() here, but the signature
        // requires one - an unsaved User is enough and touches no database.
        $data = $notification->toArray($context ?? new User);

        return $this->send([
            'title' => (string) ($data['title'] ?? 'Notifikasi pemantauan'),
            'message' => (string) ($data['message'] ?? ''),
            'severity' => (string) ($data['severity'] ?? 'warning'),
            'url' => $data['url'] ?? null,
        ]);
    }

    /**
     * @param  array{title: string, message: string, severity?: string, url?: ?string}  $data
     * @return bool whether the message was accepted by the far end
     */
    public function send(array $data): bool
    {
        if (! $this->settings->isConfigured()) {
            return false;
        }

        if (! $this->settings->wants($data['severity'] ?? 'warning')) {
            return false;
        }

        try {
            return $this->dispatch($data)['ok'];
        } catch (Throwable $e) {
            Log::warning('WebhookNotifier: gagal mengirim notifikasi: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Sends a sample message, reporting what happened - for the "Kirim Uji"
     * button on Pengaturan, where a silent failure is the worst outcome.
     *
     * @return array{success: bool, message: string}
     */
    public function test(): array
    {
        if (! $this->settings->enabled()) {
            return ['success' => false, 'message' => 'Notifikasi webhook belum diaktifkan.'];
        }

        if (! $this->settings->isConfigured()) {
            return ['success' => false, 'message' => 'Konfigurasi belum lengkap untuk format yang dipilih.'];
        }

        try {
            $result = $this->dispatch([
                'title' => 'Uji coba notifikasi',
                'message' => 'Kalau pesan ini sampai, kanal notifikasi '.config('app.name').' sudah aktif.',
                'severity' => 'info',
                'url' => url('/'),
            ]);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => 'Gagal mengirim: '.Str::limit($e->getMessage(), 180)];
        }

        return $result['ok']
            ? ['success' => true, 'message' => 'Pesan uji terkirim. Cek kanal tujuan Anda.']
            : ['success' => false, 'message' => 'Tujuan menolak pesan (HTTP '.$result['status'].'): '.Str::limit($result['body'], 160)];
    }

    /**
     * @return array{ok: bool, status: int, body: string}
     */
    protected function dispatch(array $data): array
    {
        [$url, $payload, $headers] = $this->request($data);

        $response = Http::withHeaders($headers)
            ->timeout(10)
            ->connectTimeout(5)
            // One retry only. This sits in the monitoring loop; a provider that is
            // down stays down, and burning the loop's budget on it helps nobody.
            ->retry(2, 300, throw: false)
            ->post($url, $payload);

        return [
            'ok' => $response->successful(),
            'status' => $response->status(),
            'body' => Str::limit((string) $response->body(), 300),
        ];
    }

    /**
     * Where to post, what body, which headers.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: array<string, string>}
     */
    protected function request(array $data): array
    {
        return [
            'https://api.telegram.org/bot'.$this->settings->token().'/sendMessage',
            [
                'chat_id' => $this->settings->target(),
                'text' => $this->plainText($data),
                'disable_web_page_preview' => true,
            ],
            [],
        ];
    }

    /**
     * One plain-text block that reads correctly in every target.
     *
     * No Markdown or HTML: Telegram would need every _ and * in a URL or site
     * name escaped, and one unescaped character rejects the whole message.
     */
    protected function plainText(array $data): string
    {
        $prefix = match ($data['severity'] ?? 'warning') {
            'critical' => '[KRITIS]',
            'info' => '[INFO]',
            default => '[PERINGATAN]',
        };

        return collect([
            $prefix.' '.$data['title'],
            $data['message'] ?? '',
            $data['url'] ?? '',
        ])->filter()->implode(PHP_EOL);
    }
}
