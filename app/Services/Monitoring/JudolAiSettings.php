<?php

namespace App\Services\Monitoring;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * Resolves judol AI-classifier config dynamically from the settings table
 * (managed from the Pengaturan page), falling back to config('judol.ai') / .env
 * whenever no override has been saved yet - so the app keeps working from .env
 * alone until someone touches the settings UI, and a key rotation or a toggle
 * flip takes effect immediately without a deploy/restart.
 */
class JudolAiSettings
{
    public function enabled(): bool
    {
        $value = Setting::get('judol_ai_enabled');

        return $value !== null ? $value === '1' : (bool) config('judol.ai.enabled');
    }

    public function model(): string
    {
        // Chained ?: rather than config('judol.ai.model', 'claude-haiku-4-5') - the
        // .env template ships JUDOL_AI_MODEL as present-but-blank (meant to signal
        // "use the default"), so env()/config() already resolve to '' before ever
        // reaching that default param, which only applies when the key is missing
        // entirely. Falling through here catches blank too, not just unset.
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
                // fall through to the .env key rather than hard-failing every scan.
            }
        }

        return config('services.anthropic.key') ?: null;
    }

    /**
     * Whether an API key is available from either source - lets the UI/scanner
     * short-circuit without needing to decrypt just to check presence.
     */
    public function hasApiKey(): bool
    {
        return Setting::get('anthropic_api_key') !== null || filled(config('services.anthropic.key'));
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
        return Setting::get('anthropic_workspace_id') ?: (config('services.anthropic.workspace_id') ?: null);
    }

    /**
     * Builds a ready-to-use Anthropic client, attaching the anthropic-workspace-id
     * header automatically when one is configured. Pass $apiKeyOverride /
     * $workspaceIdOverride to test unsaved values (e.g. the "Tes Koneksi" button
     * on the Pengaturan page, checking what's in the form before it's saved).
     */
    public function client(?string $apiKeyOverride = null, ?string $workspaceIdOverride = null): Client
    {
        $apiKey = $apiKeyOverride ?? $this->apiKey();
        $workspaceId = $workspaceIdOverride ?? $this->workspaceId();

        if (blank($workspaceId)) {
            return new Client(apiKey: $apiKey);
        }

        return new Client(
            apiKey: $apiKey,
            requestOptions: RequestOptions::with(extraHeaders: ['anthropic-workspace-id' => $workspaceId]),
        );
    }
}
