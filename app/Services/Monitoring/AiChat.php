<?php

namespace App\Services\Monitoring;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

/**
 * One plain-text completion against whichever provider Pengaturan points at:
 * Anthropic (or a compatible proxy), or any OpenAI-style /chat/completions
 * endpoint. Every request goes through OutboundUrlGuard, since the base URL is
 * typed by an admin - an unchecked one would turn this into a proxy into the
 * server's own network (add the host to MONITORING_ALLOWED_PRIVATE_CIDRS to
 * use a model on the LAN).
 */
class AiChat
{
    /**
     * @param  array{provider?: string, base_url?: string, model?: string, api_key?: string, workspace_id?: string}  $overrides
     *                                                                                                                           Unsaved form values ("Tes Koneksi" checks what is typed before it is saved).
     */
    public function __construct(protected AiSettings $settings, protected array $overrides = []) {}

    /**
     * @return array{text: string, truncated: bool} truncated: the reply hit $maxTokens and ends mid-thought
     *
     * @throws RuntimeException when the provider answers with an error or nothing at all
     */
    public function complete(string $system, string $user, int $maxTokens): array
    {
        return $this->isOpenAi()
            ? $this->completeOpenAi($system, $user, $maxTokens)
            : $this->completeAnthropic($system, $user, $maxTokens);
    }

    /**
     * Cheap connectivity check: a metadata call, so no completion tokens are
     * spent unless the provider has no model listing at all.
     *
     * @throws RuntimeException|\Throwable when the provider cannot be reached or refuses the credentials
     */
    public function ping(): void
    {
        if (! $this->isOpenAi()) {
            $this->anthropicClient()->models->retrieve($this->model());

            return;
        }

        $response = $this->http()->get($this->openAiUrl('/models'));

        // Plenty of compatible servers never implemented the listing.
        if (in_array($response->status(), [404, 405], true)) {
            $this->completeOpenAi('Balas dengan satu kata.', 'ping', 1);

            return;
        }

        $this->throwIfFailed($response);
    }

    protected function isOpenAi(): bool
    {
        return ($this->overrides['provider'] ?? $this->settings->provider()) === AiSettings::PROVIDER_OPENAI;
    }

    protected function model(): string
    {
        return $this->pick('model', $this->settings->model());
    }

    protected function baseUrl(): string
    {
        return rtrim($this->pick('base_url', $this->settings->baseUrl()), '/');
    }

    protected function apiKey(): ?string
    {
        return $this->pick('api_key', (string) $this->settings->apiKey()) ?: null;
    }

    protected function pick(string $key, string $saved): string
    {
        return filled($this->overrides[$key] ?? null) ? trim($this->overrides[$key]) : $saved;
    }

    protected function completeAnthropic(string $system, string $user, int $maxTokens): array
    {
        $message = $this->anthropicClient()->messages->create(
            model: $this->model(),
            maxTokens: $maxTokens,
            system: $system,
            messages: [['role' => 'user', 'content' => $user]],
        );

        foreach ($message->content as $block) {
            if ($block->type === 'text' && trim($block->text) !== '') {
                return ['text' => $block->text, 'truncated' => $message->stopReason === 'max_tokens'];
            }
        }

        throw new RuntimeException('AI tidak mengembalikan analisis (respons kosong).');
    }

    protected function completeOpenAi(string $system, string $user, int $maxTokens): array
    {
        $response = $this->http()->post($this->openAiUrl('/chat/completions'), [
            'model' => $this->model(),
            'max_tokens' => $maxTokens,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user],
            ],
        ]);

        $this->throwIfFailed($response);

        $text = data_get($response->json(), 'choices.0.message.content');

        // Some servers answer with a list of content parts instead of a string.
        if (is_array($text)) {
            $text = collect($text)->map(fn ($part) => is_array($part) ? ($part['text'] ?? '') : (string) $part)->implode('');
        }

        if (! is_string($text) || trim($text) === '') {
            throw new RuntimeException('AI tidak mengembalikan analisis (respons kosong).');
        }

        return ['text' => $text, 'truncated' => data_get($response->json(), 'choices.0.finish_reason') === 'length'];
    }

    protected function openAiUrl(string $path): string
    {
        if ($this->baseUrl() === '') {
            throw new RuntimeException('Base URL provider AI belum diisi. Atur di halaman Pengaturan.');
        }

        return $this->baseUrl().$path;
    }

    protected function http(): PendingRequest
    {
        $timeout = (float) config('ai.timeout', 60);
        $request = Http::acceptJson()
            ->timeout($timeout)
            ->connectTimeout(min(10.0, $timeout))
            ->withOptions(OutboundUrlGuard::httpOptions())
            ->setHandler(OutboundUrlGuard::pinnedHandler())
            ->retry((int) config('ai.max_retries', 1), 1000, fn ($e) => $e instanceof ConnectionException, throw: false);

        return filled($key = $this->apiKey()) ? $request->withToken($key) : $request;
    }

    protected function throwIfFailed($response): void
    {
        if ($response->successful()) {
            return;
        }

        $detail = data_get($response->json(), 'error.message') ?? data_get($response->json(), 'error');

        throw new RuntimeException('Error Code '.$response->status().(is_string($detail) && $detail !== '' ? ", {$detail}" : ''));
    }

    /**
     * Key, auth token and base URL are always passed explicitly: the SDK would
     * otherwise fall back to ANTHROPIC_* env vars, credential files or OIDC.
     *
     * @throws RuntimeException when no usable API key is available
     */
    protected function anthropicClient(): Client
    {
        $apiKey = $this->apiKey();
        $workspaceId = $this->overrides['workspace_id'] ?? $this->settings->workspaceId();

        if (blank($apiKey)) {
            throw new RuntimeException('Belum ada API key AI yang dikonfigurasi. Atur di halaman Pengaturan.');
        }

        $timeout = (float) config('ai.timeout', 60);

        // The SDK only records `timeout`; the transport is what actually enforces it.
        return new Client(
            apiKey: $apiKey,
            authToken: '',
            webhookKey: '',
            baseUrl: $this->baseUrl() ?: (string) config('ai.anthropic_base_url'),
            requestOptions: RequestOptions::with(
                timeout: $timeout,
                maxRetries: (int) config('ai.max_retries', 1),
                extraHeaders: blank($workspaceId) ? null : ['anthropic-workspace-id' => $workspaceId],
                transporter: $this->transporter($timeout),
            ),
        );
    }

    /**
     * The SDK sleeps for whatever retry-after says, uncapped - so cap it here,
     * before the SDK reads the response.
     */
    protected function transporter(float $timeout): GuzzleClient
    {
        $maxDelay = (float) config('ai.max_retry_delay', 10);

        $stack = HandlerStack::create(OutboundUrlGuard::pinnedHandler());
        $stack->push(Middleware::mapResponse(function (ResponseInterface $response) use ($maxDelay) {
            if ($response->hasHeader('retry-after')) {
                $value = $response->getHeaderLine('retry-after');
                $seconds = is_numeric($value) ? (float) $value : (float) max(0, (strtotime($value) ?: 0) - time());
                $response = $response->withHeader('retry-after', (string) min($seconds, $maxDelay));
            }

            if ($response->hasHeader('retry-after-ms')) {
                $ms = (float) $response->getHeaderLine('retry-after-ms');
                $response = $response->withHeader('retry-after-ms', (string) min($ms, $maxDelay * 1000));
            }

            return $response;
        }));

        return new GuzzleClient([
            'handler' => $stack,
            'timeout' => $timeout,
            'connect_timeout' => min(10.0, $timeout),
            ...OutboundUrlGuard::httpOptions(),
        ]);
    }
}
