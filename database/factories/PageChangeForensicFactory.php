<?php

namespace Database\Factories;

use App\Models\PageChange;
use App\Models\PageChangeForensic;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PageChangeForensic>
 */
class PageChangeForensicFactory extends Factory
{
    public function definition(): array
    {
        $text = fake()->sentence();

        return $this->encode([
            'v' => PageChangeForensic::SCHEMA_VERSION,
            'unavailable' => null,
            'before_unavailable' => null,
            'counts' => [
                'text_added' => 1,
                'text_removed' => 0,
                'elements_added' => 0,
                'elements_removed' => 0,
                'elements_modified' => 0,
                'total' => 1,
            ],
            'undetailed' => 0,
            'items' => [[
                'n' => 1,
                'kind' => 'text',
                'field' => 'body',
                'change' => 'added',
                'text' => $text,
                'identity' => null,
                'value' => null,
                'before' => null,
                'after' => [
                    'xpath' => '/html[1]/body[1]/p[1]',
                    'css' => 'html > body > p:nth-of-type(1)',
                    'tag' => 'p',
                    'attrs' => [],
                    'line' => 10,
                    'line_precision' => 'exact',
                    'hidden' => false,
                    'snippet' => '<p>'.$text.'</p>',
                    'snippet_length' => strlen($text) + 7,
                    'snippet_truncated' => false,
                    'snippet_starts_inside' => false,
                    'snippet_source' => 'raw',
                ],
                'before_unavailable' => 'not_applicable',
            ]],
        ]) + ['page_change_id' => PageChange::factory()];
    }

    /** Replaces the payload; size and count columns follow it. */
    public function payload(array $payload): static
    {
        return $this->state(fn () => $this->encode($payload));
    }

    /** A blob that cannot be gzip-decoded, for the "not available" path. */
    public function corrupt(): static
    {
        return $this->state(fn () => ['payload_gz' => 'not-gzip']);
    }

    private function encode(array $payload): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [
            'payload_gz' => gzencode($json, 6),
            'payload_bytes' => strlen($json),
            'item_count' => count($payload['items'] ?? []),
            'undetailed_count' => (int) ($payload['undetailed'] ?? 0),
            'schema_version' => (int) ($payload['v'] ?? PageChangeForensic::SCHEMA_VERSION),
        ];
    }
}
