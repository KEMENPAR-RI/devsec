<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\AiChat;
use App\Services\Monitoring\AiSettings;
use App\Services\Monitoring\IncidentInvestigator;
use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Psr\Http\Message\RequestInterface;
use RuntimeException;
use Tests\TestCase;

class AiProviderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Setting::set('ai_provider', 'openai');
        Setting::set('ai_base_url', 'https://llm.example.com/v1');
        Setting::set('ai_model', 'llama3');
    }

    public function test_a_custom_provider_gets_an_openai_style_chat_completion_request(): void
    {
        Setting::set('ai_api_key', Crypt::encryptString('sk-custom'));
        Http::fake(['llm.example.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Jawaban model'], 'finish_reason' => 'stop']],
        ])]);

        $reply = app(AiChat::class)->complete('sistem', 'pertanyaan', 300);

        $this->assertSame(['text' => 'Jawaban model', 'truncated' => false], $reply);
        Http::assertSent(function (Request $request) {
            return $request->url() === 'https://llm.example.com/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer sk-custom')
                && $request['model'] === 'llama3'
                && $request['max_tokens'] === 300
                && $request['messages'] === [
                    ['role' => 'system', 'content' => 'sistem'],
                    ['role' => 'user', 'content' => 'pertanyaan'],
                ];
        });
    }

    public function test_a_custom_provider_without_a_key_sends_no_authorization_header(): void
    {
        Http::fake(['llm.example.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'ok']]],
        ])]);

        app(AiChat::class)->complete('s', 'u', 10);

        Http::assertSent(fn (Request $request) => ! $request->hasHeader('Authorization'));
    }

    public function test_a_reply_cut_off_by_the_token_limit_is_reported_as_truncated(): void
    {
        Http::fake(['llm.example.com/*' => Http::response([
            'choices' => [['message' => ['content' => 'Setengah jal'], 'finish_reason' => 'length']],
        ])]);

        $this->assertTrue(app(AiChat::class)->complete('s', 'u', 10)['truncated']);
    }

    public function test_content_given_as_parts_is_joined(): void
    {
        Http::fake(['llm.example.com/*' => Http::response([
            'choices' => [['message' => ['content' => [['type' => 'text', 'text' => 'satu '], ['type' => 'text', 'text' => 'dua']]]]],
        ])]);

        $this->assertSame('satu dua', app(AiChat::class)->complete('s', 'u', 10)['text']);
    }

    public function test_an_error_response_surfaces_the_providers_message(): void
    {
        Http::fake(['llm.example.com/*' => Http::response(['error' => ['message' => 'Invalid API key']], 401)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Error Code 401, Invalid API key');

        app(AiChat::class)->complete('s', 'u', 10);
    }

    public function test_an_empty_reply_is_an_error_not_a_blank_analysis(): void
    {
        Http::fake(['llm.example.com/*' => Http::response(['choices' => [['message' => ['content' => '  ']]]])]);

        $this->expectException(RuntimeException::class);

        app(AiChat::class)->complete('s', 'u', 10);
    }

    public function test_the_connection_test_lists_models_without_spending_tokens(): void
    {
        Http::fake(['llm.example.com/*' => Http::response(['data' => []])]);

        app(AiChat::class)->ping();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request) => $request->method() === 'GET' && $request->url() === 'https://llm.example.com/v1/models');
    }

    public function test_the_connection_test_falls_back_to_a_tiny_completion_when_there_is_no_model_listing(): void
    {
        Http::fake([
            'llm.example.com/v1/models' => Http::response('', 404),
            'llm.example.com/v1/chat/completions' => Http::response(['choices' => [['message' => ['content' => 'ok']]]]),
        ]);

        app(AiChat::class)->ping();

        Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/chat/completions') && $request['max_tokens'] === 1);
    }

    public function test_a_refused_key_fails_the_connection_test(): void
    {
        Http::fake(['llm.example.com/*' => Http::response(['error' => ['message' => 'Bad key']], 401)]);

        $this->expectExceptionMessage('Error Code 401, Bad key');

        app(AiChat::class)->ping();
    }

    public function test_unsaved_form_values_are_tested_instead_of_the_saved_ones(): void
    {
        Http::fake(['other.example.org/*' => Http::response(['data' => []])]);

        (new AiChat(new AiSettings, ['base_url' => 'https://other.example.org/api', 'model' => 'x']))->ping();

        Http::assertSent(fn (Request $request) => $request->url() === 'https://other.example.org/api/models');
    }

    public function test_the_provider_is_only_ever_reached_at_a_vetted_address(): void
    {
        Http::allowStrayRequests(); // the transport is pinned and faked below, never a real socket
        OutboundUrlGuard::fakeDns(['llm.example.com' => ['93.184.216.34']]);
        $sent = [];
        OutboundUrlGuard::fakeTransport(function (RequestInterface $request, array $options) use (&$sent) {
            $sent[] = $options;

            return Create::promiseFor(new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'choices' => [['message' => ['content' => 'ok']]],
            ])));
        });

        app(AiChat::class)->complete('s', 'u', 10);

        $this->assertSame(['llm.example.com:443:93.184.216.34'], $sent[0]['curl'][CURLOPT_RESOLVE]);
        $this->assertSame('', $sent[0]['proxy']);
    }

    public function test_a_provider_that_resolves_to_an_internal_address_is_never_contacted(): void
    {
        Http::allowStrayRequests();
        OutboundUrlGuard::fakeDns(['llm.example.com' => ['10.0.0.5']]);
        $reached = false;
        OutboundUrlGuard::fakeTransport(function () use (&$reached) {
            $reached = true;

            return Create::promiseFor(new Response(200));
        });

        try {
            app(AiChat::class)->complete('s', 'u', 10);
            $this->fail('An internal provider address must be refused.');
        } catch (\Throwable) {
        }

        $this->assertFalse($reached);
    }

    public function test_the_investigator_works_through_a_custom_provider(): void
    {
        $incident = Incident::create([
            'website_id' => Website::factory()->create()->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs down', 'status' => 'open', 'detected_at' => now(),
        ]);
        Http::fake(['llm.example.com/*' => Http::response([
            'choices' => [['message' => ['content' => "## Penyebab\n**Server** kehabisan memori."], 'finish_reason' => 'stop']],
        ])]);

        $analysis = app(IncidentInvestigator::class)->investigate($incident);

        // Markdown is stripped exactly as for the Anthropic path.
        $this->assertSame("Penyebab\nServer kehabisan memori.", $analysis);
    }

    public function test_the_investigator_refuses_to_run_when_nothing_is_configured(): void
    {
        Setting::forget('ai_base_url');
        Setting::forget('ai_model');
        $incident = Incident::create([
            'website_id' => Website::factory()->create()->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs down', 'status' => 'open', 'detected_at' => now(),
        ]);

        $this->expectExceptionMessage('Belum ada provider AI yang dikonfigurasi');

        app(IncidentInvestigator::class)->investigate($incident);
    }

    public function test_the_settings_page_can_test_the_form_values_before_saving(): void
    {
        OutboundUrlGuard::fakeDns(['new.example.net' => ['93.184.216.34']]);
        Http::fake(['new.example.net/*' => Http::response(['data' => []])]);

        Livewire::actingAs(User::factory()->create())
            ->test(SettingsIndex::class)
            ->set('aiProvider', 'openai')
            ->set('aiBaseUrl', 'https://new.example.net/v1')
            ->set('aiModel', 'qwen')
            ->call('testConnection')
            ->assertSet('testResult', ['success' => true, 'message' => 'Koneksi berhasil.']);

        // Testing is not saving.
        $this->assertSame('https://llm.example.com/v1', Setting::get('ai_base_url'));
    }

    public function test_the_settings_page_reports_a_failed_connection_test(): void
    {
        OutboundUrlGuard::fakeDns(['new.example.net' => ['93.184.216.34']]);
        Http::fake(['new.example.net/*' => Http::response(['error' => ['message' => 'nope']], 403)]);

        Livewire::actingAs(User::factory()->create())
            ->test(SettingsIndex::class)
            ->set('aiProvider', 'openai')
            ->set('aiBaseUrl', 'https://new.example.net/v1')
            ->set('aiModel', 'qwen')
            ->call('testConnection')
            ->assertSet('testResult', ['success' => false, 'message' => 'Error Code 403, nope']);
    }
}
