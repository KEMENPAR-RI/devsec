<?php

namespace App\Services\Monitoring;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Resolves judol AI-classifier config from the settings table (managed from the
 * Pengaturan page, the single source - nothing is read from .env). Tunables fall
 * back to the defaults in config('judol.ai') until saved; a key rotation or a
 * toggle flip takes effect immediately without a deploy/restart.
 */
class JudolAiSettings
{
    public function enabled(): bool
    {
        $value = Setting::get('judol_ai_enabled');

        return $value === '1';
    }

    public function model(): string
    {
        $value = Setting::get('judol_ai_model') ?: (string) config('judol.ai.model');

        return $value !== '' ? $value : 'claude-haiku-4-5';
    }

    public function confidenceThreshold(): float
    {
        $value = Setting::get('judol_ai_confidence_threshold');

        return $value !== null ? (float) $value : (float) config('judol.ai.confidence_threshold', 0.5);
    }

    public function maxImages(): int
    {
        $value = Setting::get('judol_ai_max_images');

        return $value !== null ? (int) $value : (int) config('judol.ai.max_images', 3);
    }

    /**
     * The API key is stored encrypted (App\Livewire\Settings\Index encrypts it on
     * save) so it isn't sitting in plaintext in the database.
     */
    public function apiKey(): ?string
    {
        $encrypted = Setting::get('anthropic_api_key');

        if ($encrypted) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                // Corrupted or undecryptable (e.g. APP_KEY rotated since it was saved) -
                // treat as unset rather than hard-failing every scan.
                Log::warning('Stored Anthropic API key could not be decrypted; AI features are disabled until it is re-saved.');
            }
        }

        return null;
    }

    /**
     * Whether a usable API key has been saved - one that actually decrypts.
     */
    public function hasApiKey(): bool
    {
        return filled($this->apiKey());
    }

    /**
     * AI switched on AND a usable key saved - the gate for every AI call.
     */
    public function usable(): bool
    {
        return $this->enabled() && $this->hasApiKey();
    }

    /**
     * Required only for "identity-linked" API keys (personal/OAuth keys that span
     * multiple workspaces) - the API rejects those with a 400 unless every request
     * carries an explicit anthropic-workspace-id header naming which workspace it
     * acts in. A normal Console-generated key (Settings > API Keys > Create Key,
     * scoped to one workspace) doesn't need this at all - leave it blank.
     */
    public function workspaceId(): ?string
    {
        return Setting::get('anthropic_workspace_id') ?: null;
    }

    /**
     * Builds a ready-to-use Anthropic client, attaching the anthropic-workspace-id
     * header automatically when one is configured. Pass $apiKeyOverride /
     * $workspaceIdOverride to test unsaved values (e.g. the "Tes Koneksi" button
     * on the Pengaturan page, checking what's in the form before it's saved).
     *
     * Key, auth token and base URL are always passed explicitly: the SDK would
     * otherwise fall back to ANTHROPIC_* env vars, credential files or OIDC.
     *
     * @throws RuntimeException when no usable API key is available
     */
    public function client(?string $apiKeyOverride = null, ?string $workspaceIdOverride = null): Client
    {
        $apiKey = filled($apiKeyOverride) ? $apiKeyOverride : $this->apiKey();
        $workspaceId = $workspaceIdOverride ?? $this->workspaceId();

        if (blank($apiKey)) {
            throw new RuntimeException('Belum ada Anthropic API key yang dikonfigurasi. Atur di halaman Pengaturan.');
        }

        return new Client(
            apiKey: $apiKey,
            authToken: '',
            webhookKey: '',
            baseUrl: 'https://api.anthropic.com',
            requestOptions: blank($workspaceId)
                ? null
                : RequestOptions::with(extraHeaders: ['anthropic-workspace-id' => $workspaceId]),
        );
    }
}
