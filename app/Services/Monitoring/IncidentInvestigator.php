<?php

namespace App\Services\Monitoring;

use Anthropic\Core\Exceptions\APIStatusException;
use App\Models\Incident;
use App\Support\AiText;
use RuntimeException;
use Throwable;

/**
 * On-demand "why did this happen" analysis for one incident - reuses the same
 * Anthropic credentials configured for judol classification (JudolAiSettings),
 * since it's the one Claude API key this app has. Given the incident's own detail
 * lines plus the website's recent check history, asks Claude for a plain-language
 * root cause and next steps. Triggered explicitly per incident (never automatic),
 * so no cost-control gating is needed beyond the credential being configured.
 */
class IncidentInvestigator
{
    public function __construct(protected JudolAiSettings $settings) {}

    /**
     * @throws RuntimeException when no API key is configured or the API call fails
     */
    public function investigate(Incident $incident): string
    {
        if (! $this->settings->hasApiKey()) {
            throw new RuntimeException('Belum ada Anthropic API key yang dikonfigurasi. Atur di halaman Pengaturan.');
        }

        $website = $incident->website;
        $recentChecks = $website->checks()->latest('checked_at')->limit(15)->get()->reverse()->values();

        $checksSummary = $recentChecks->isEmpty()
            ? '(tidak ada riwayat pengecekan)'
            : $recentChecks->map(fn ($check) => sprintf(
                '%s | %s | HTTP %s | %sms | %s',
                $check->checked_at->format('d M H:i'),
                $check->is_up ? 'UP' : 'DOWN',
                $check->status_code ?? '-',
                $check->response_time_ms,
                $check->error_message ?? '-'
            ))->implode("\n");

        $detailLines = collect($incident->detailLines())
            ->map(fn ($value, $label) => "{$label}: {$value}")
            ->implode("\n");

        $prompt = <<<PROMPT
        Insiden: {$incident->title}
        Jenis: {$incident->type} | Tingkat: {$incident->severity} | Terdeteksi: {$incident->detected_at->format('d M Y H:i')}
        Deskripsi: {$incident->description}
        Detail:
        {$detailLines}

        Situs: {$website->name} ({$website->url})
        Interval cek: {$website->check_interval_minutes} menit | Ambang batas response time: {$website->response_time_threshold_ms}ms | Ambang batas gagal berturut-turut: {$website->down_threshold_failures}x

        Riwayat {$recentChecks->count()} pengecekan terakhir (waktu | status | HTTP | response time | pesan error):
        {$checksSummary}
        PROMPT;

        try {
            $message = $this->settings->client()->messages->create(
                model: $this->settings->model(),
                maxTokens: 500,
                system: <<<'SYS'
                Kamu asisten diagnostik infrastruktur web. Diberikan detail satu insiden
                monitoring website beserta riwayat pengecekan terakhirnya, jelaskan dalam
                Bahasa Indonesia yang jelas dan ringkas (maksimal sekitar 150 kata):

                1. Kemungkinan penyebab utama insiden ini
                2. Pola yang terlihat dari riwayat pengecekan (mis. gradual vs tiba-tiba,
                   konsisten vs intermiten, terkait waktu tertentu)
                3. Saran langkah investigasi/tindakan lanjut yang konkret

                Jangan mengulang data mentah yang sudah diberikan - langsung ke analisis dan
                saran. Kalau datanya tidak cukup untuk menyimpulkan penyebab pasti, katakan
                begitu dan sebutkan apa yang perlu dicek manual (mis. log server, panel hosting).

                Tulis sebagai teks biasa untuk ditampilkan apa adanya di dashboard: tanpa
                format Markdown sama sekali (tanpa #, **, _, backtick, atau tabel). Pakai
                baris baru dan angka/tanda hubung biasa kalau perlu memberi daftar.
                SYS,
                messages: [['role' => 'user', 'content' => $prompt]],
            );
        } catch (Throwable $e) {
            throw new RuntimeException($this->describeApiError($e), previous: $e);
        }

        foreach ($message->content as $block) {
            if ($block->type === 'text' && trim($block->text) !== '') {
                return static::cleanAnalysisText($block->text);
            }
        }

        throw new RuntimeException('AI tidak mengembalikan analisis (respons kosong).');
    }

    /**
     * Kept as the entry point the analysis flow (and the backfill migration that
     * cleaned pre-existing rows) calls; the logic itself is shared with the judol
     * classifier's reasoning - see App\Support\AiText.
     */
    public static function cleanAnalysisText(string $text): string
    {
        return AiText::clean($text);
    }

    /**
     * APIStatusException::getMessage() is a pretty-printed "{description}\n{json}"
     * blob, not the bare API error - status is exposed as a clean ->status property,
     * but the human-readable message text only lives inside that JSON, so it needs
     * parsing out (skipping the description line json_decode() would choke on).
     * Falls back to the plain exception message for non-API errors (timeouts, DNS
     * failures, ...), which don't have this shape at all.
     */
    protected function describeApiError(Throwable $e): string
    {
        if (! $e instanceof APIStatusException) {
            return $e->getMessage();
        }

        $jsonStart = strpos($e->getMessage(), '{');
        $decoded = $jsonStart !== false ? json_decode(substr($e->getMessage(), $jsonStart), true) : null;
        $message = $decoded['body']['error']['message'] ?? null;

        return $message ? "Error Code {$e->status}, {$message}" : $e->getMessage();
    }
}
