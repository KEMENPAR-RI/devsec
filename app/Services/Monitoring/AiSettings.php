<?php

namespace App\Services\Monitoring;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Resolves the AI provider config from the settings table (managed from the
 * Pengaturan page, the single source - nothing is read from .env). Timeouts and
 * the default model fall back to config/ai.php until saved; a key rotation takes
 * effect immediately without a deploy/restart.
 */
class AiSettings
{
    public const PROVIDER_ANTHROPIC = 'anthropic';

    /** Any endpoint that speaks OpenAI's /chat/completions (OpenAI, OpenRouter, LiteLLM, vLLM, Ollama...). */
    public const PROVIDER_OPENAI = 'openai';

    public const PROVIDERS = [
        self::PROVIDER_ANTHROPIC => 'Anthropic (Claude)',
        self::PROVIDER_OPENAI => 'Kompatibel OpenAI (provider kustom)',
    ];

    public function provider(): string
    {
        $value = Setting::get('ai_provider');

        return array_key_exists((string) $value, self::PROVIDERS) ? $value : self::PROVIDER_ANTHROPIC;
    }

    public function isOpenAi(): bool
    {
        return $this->provider() === self::PROVIDER_OPENAI;
    }

    /**
     * Without a trailing slash. Anthropic falls back to the official API; a
     * custom provider has no sensible default, so it stays blank until set.
     */
    public function baseUrl(): string
    {
        $value = rtrim(trim((string) Setting::get('ai_base_url')), '/');

        if ($value !== '') {
            return $value;
        }

        return $this->isOpenAi() ? '' : (string) config('ai.anthropic_base_url');
    }

    public function model(): string
    {
        $value = trim((string) Setting::get('ai_model'));

        if ($value !== '') {
            return $value;
        }

        return $this->isOpenAi() ? '' : (string) (config('ai.default_model') ?: 'claude-haiku-4-5');
    }

    /**
     * The API key is stored encrypted (App\Livewire\Settings\Index encrypts it on
     * save) so it isn't sitting in plaintext in the database.
     */
    public function apiKey(): ?string
    {
        $encrypted = Setting::get('ai_api_key');

        if ($encrypted) {
            try {
                return Crypt::decryptString($encrypted);
            } catch (Throwable) {
                // Corrupted or undecryptable (e.g. APP_KEY rotated since it was saved) -
                // treat as unset rather than hard-failing every request.
                Log::warning('Stored AI API key could not be decrypted; AI features are disabled until it is re-saved.');
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
     * Whether the saved settings are enough to make a call. A custom provider may
     * legitimately have no key (a local model), but never works without an
     * endpoint and a model name.
     */
    public function configured(): bool
    {
        return $this->isOpenAi()
            ? $this->baseUrl() !== '' && $this->model() !== ''
            : $this->hasApiKey();
    }

    /**
     * Required only for Anthropic "identity-linked" API keys (personal/OAuth keys that
     * span multiple workspaces) - the API rejects those with a 400 unless every
     * request carries an explicit anthropic-workspace-id header. A normal
     * Console-generated key doesn't need this at all - leave it blank.
     */
    public function workspaceId(): ?string
    {
        return Setting::get('ai_workspace_id') ?: null;
    }
}
