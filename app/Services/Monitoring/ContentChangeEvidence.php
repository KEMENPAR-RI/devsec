<?php

namespace App\Services\Monitoring;

use App\Models\PageChange;
use App\Models\User;
use App\Support\VisibleText;
use Illuminate\Support\Carbon;

/**
 * Plain-text evidence file for one content-change event (format v1, fixed
 * order and keys). Built in memory on request, never written to disk. Every
 * value from the target page goes through VisibleText::plain(), and snippets
 * are prefixed per line - the file is inert text, not markup.
 *
 * The closing SHA-256 line only detects accidental edits; it is not a signature.
 */
class ContentChangeEvidence
{
    protected const REASONS = [
        'no_baseline' => 'belum ada salinan sebelumnya',
        'too_large' => 'halaman terlalu besar',
        'mismatch' => 'salinan tidak cocok dengan hash',
        'missing' => 'salinan tidak ditemukan',
        'not_applicable' => 'tidak berlaku',
        'error' => 'gagal dibuat',
    ];

    /** Why an event has no detail - see ContentForensics::unavailableReason(). */
    protected const MISSING = [
        'legacy' => 'event dibuat sebelum fitur aktif',
        'new_page' => 'halaman baru, belum ada versi sebelumnya untuk dirinci',
        'removed_page' => 'halaman hilang, tidak ada konten sesudah untuk dirinci',
        'corrupt' => 'data forensik rusak atau tidak terbaca',
    ];

    public function render(PageChange $change, User $exporter): string
    {
        $change->loadMissing(['website:id,uuid,name,url', 'forensic']);
        $payload = $change->forensic?->payload();
        $missing = 'tidak tersedia';

        $lines = [
            'BUKTI PERUBAHAN KONTEN',
            'Versi format: 1',
            'Situs: '.$this->line(($change->website?->name ?? $missing).' ('.($change->website?->url ?? $missing).')'),
            'ID event: '.$change->uuid,
            'URL diminta: '.$this->line($change->url),
            'URL final: '.($change->final_url ? $this->line($change->final_url) : 'sama dengan URL diminta'),
            'Status HTTP: '.($change->http_status ?? $missing),
            'Jumlah redirect: '.($change->redirect_count ?? $missing),
            'Terdeteksi: '.$this->time($change->detected_at, $missing),
            'Crawl sebelumnya: '.$this->time($change->previous_crawled_at, $missing),
            'Last-Modified: '.$this->time($change->page_last_modified_at, 'tidak dikirim server'),
            'Algoritma hash: '.($change->hash_algorithm ?? $missing),
            'Hash body sebelum: '.($change->body_hash_before ?? $missing),
            'Hash body sesudah: '.($change->body_hash_after ?? $missing),
            'Diekspor: '.$this->time(now(), $missing).'  oleh '.$this->line($exporter->name.' ('.$exporter->email.')'),
        ];

        if ($payload === null) {
            $reason = ContentForensics::unavailableReason($change, $change->forensic, null);
            $lines[] = 'Detail forensik tidak tersedia ('.self::MISSING[$reason].')';
        } else {
            $this->appendDetail($lines, $payload);
        }

        $lines[] = '== Akhir';
        $text = implode("\n", $lines)."\n";

        return $text.'SHA-256 isi di atas: '.hash('sha256', $text)."\n";
    }

    /** @param  list<string>  $lines */
    protected function appendDetail(array &$lines, array $payload): void
    {
        $counts = (array) ($payload['counts'] ?? []);
        $lines[] = sprintf(
            'Ringkasan: teks ditambah %d, teks dihapus %d, elemen ditambah %d, elemen dihapus %d, elemen diubah %d, total %d; +%d perubahan lain tidak dirinci',
            $counts['text_added'] ?? 0,
            $counts['text_removed'] ?? 0,
            $counts['elements_added'] ?? 0,
            $counts['elements_removed'] ?? 0,
            $counts['elements_modified'] ?? 0,
            $counts['total'] ?? 0,
            $payload['undetailed'] ?? 0,
        );

        if ($payload['unavailable'] ?? null) {
            $lines[] = 'Detail forensik tidak tersedia ('.$this->reason($payload['unavailable']).')';
        }

        if ($payload['before_unavailable'] ?? null) {
            $lines[] = 'Sisi sebelum tidak tersedia ('.$this->reason($payload['before_unavailable']).')';
        }

        foreach ((array) ($payload['items'] ?? []) as $item) {
            $hidden = ($item['after']['hidden'] ?? false) || ($item['before']['hidden'] ?? false);
            $lines[] = sprintf('== Perubahan #%d [%s/%s]%s', $item['n'] ?? 0, $item['kind'] ?? '?', $item['change'] ?? '?', $hidden ? ' TERSEMBUNYI' : '');

            if (($item['field'] ?? null) !== null) {
                $lines[] = 'Bagian: '.$item['field'];
            }

            if (($item['text'] ?? null) !== null) {
                $lines[] = 'Teks: '.$this->line($item['text']);
            }

            if (($item['identity'] ?? null) !== null) {
                $lines[] = 'Identitas: '.$this->line($item['identity']);
            }

            if (is_array($item['value'] ?? null)) {
                $lines[] = 'Nilai: '.$this->line($item['value']['before'] ?? '(tidak ada)').' → '.$this->line($item['value']['after'] ?? '(tidak ada)');
            }

            foreach (['before' => 'Sebelum', 'after' => 'Sesudah'] as $side => $label) {
                $this->appendLocation($lines, $label, $item[$side] ?? null, $side === 'before' ? ($item['before_unavailable'] ?? null) : null, $item, $side);
            }
        }
    }

    /** @param  list<string>  $lines */
    protected function appendLocation(array &$lines, string $label, ?array $location, ?string $reason, array $item, string $side): void
    {
        if ($location === null) {
            $absent = ($side === 'before' && ($item['change'] ?? null) === 'added') || ($side === 'after' && ($item['change'] ?? null) === 'removed');
            $lines[] = '-- '.$label.': '.($absent ? 'tidak ada' : 'tidak tersedia'.($reason ? ' ('.$this->reason($reason).')' : ''));

            return;
        }

        $attrs = [];

        foreach ((array) ($location['attrs'] ?? []) as $name => $value) {
            $attrs[] = $name.'="'.$value.'"';
        }

        $lines[] = '-- '.$label.':';
        $lines[] = 'XPath: '.$this->line($location['xpath'] ?? '');
        $lines[] = 'CSS: '.$this->line($location['css'] ?? '');
        $lines[] = 'Tag: '.$this->line($location['tag'] ?? '');
        $lines[] = 'Atribut: '.($attrs === [] ? '-' : $this->line(implode(' ', $attrs)));
        $lines[] = 'Baris: '.($location['line'] ?? 'tidak diketahui').' ('.($location['line_precision'] ?? 'unknown').')';
        $lines[] = 'Tersembunyi: '.(! empty($location['hidden']) ? 'ya' : 'tidak');
        $lines[] = sprintf(
            'Panjang asli: %d karakter%s%s; sumber cuplikan: %s',
            $location['snippet_length'] ?? 0,
            ! empty($location['snippet_truncated']) ? '; terpotong' : '',
            ! empty($location['snippet_starts_inside']) ? '; dimulai di tengah elemen' : '',
            $location['snippet_source'] ?? 'raw',
        );

        foreach (explode("\n", VisibleText::plain((string) ($location['snippet'] ?? ''))) as $snippetLine) {
            $lines[] = '    | '.$snippetLine;
        }
    }

    /** One line of untrusted text: invisible characters marked, line breaks made visible. */
    protected function line(mixed $value): string
    {
        return str_replace("\n", '[LF]', VisibleText::plain((string) $value));
    }

    protected function time(?Carbon $time, string $fallback): string
    {
        return $time ? $time->copy()->setTimezone(config('app.timezone'))->toIso8601String() : $fallback;
    }

    protected function reason(string $reason): string
    {
        return self::REASONS[$reason] ?? $reason;
    }
}
