<?php

namespace Tests\Feature;

use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\Setting;
use App\Models\User;
use App\Services\Monitoring\AiChat;
use App\Services\Monitoring\AiSettings;
use App\Services\Notifications\MailSettings;
use App\Services\Security\OutboundUrlGuard;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        putenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_AUTH_TOKEN');
        putenv('ANTHROPIC_BASE_URL');

        parent::tearDown();
    }

    public function test_setting_get_set_forget_roundtrip_and_cache_invalidation(): void
    {
        $this->assertNull(Setting::get('some_key'));
        $this->assertSame('default', Setting::get('some_key', 'default'));

        Setting::set('some_key', 'value-1');
        $this->assertSame('value-1', Setting::get('some_key'));

        // Overwrite must bust the cache, not keep serving the old cached value.
        Setting::set('some_key', 'value-2');
        $this->assertSame('value-2', Setting::get('some_key'));

        Setting::forget('some_key');
        $this->assertNull(Setting::get('some_key'));
    }

    public function test_ai_settings_default_to_anthropic_until_overridden(): void
    {
        $settings = new AiSettings;

        $this->assertSame('anthropic', $settings->provider());
        $this->assertSame('https://api.anthropic.com', $settings->baseUrl());
        $this->assertSame('claude-haiku-4-5', $settings->model());
        $this->assertFalse($settings->configured());

        Setting::set('ai_model', 'claude-sonnet-5');
        $this->assertSame('claude-sonnet-5', (new AiSettings)->model());

        // A blank saved model never wins over the default.
        Setting::set('ai_model', '');
        $this->assertSame('claude-haiku-4-5', (new AiSettings)->model());
    }

    public function test_a_custom_provider_is_configured_with_an_endpoint_and_a_model_even_without_a_key(): void
    {
        Setting::set('ai_provider', 'openai');

        $settings = new AiSettings;
        $this->assertSame('', $settings->baseUrl());
        $this->assertSame('', $settings->model());
        $this->assertFalse($settings->configured());

        Setting::set('ai_base_url', 'https://llm.example.com/v1/');
        Setting::set('ai_model', 'llama3');

        $settings = new AiSettings;
        $this->assertSame('https://llm.example.com/v1', $settings->baseUrl());
        $this->assertTrue($settings->configured());
        $this->assertFalse($settings->hasApiKey());
    }

    public function test_an_unknown_provider_falls_back_to_anthropic(): void
    {
        Setting::set('ai_provider', 'something-else');

        $this->assertSame('anthropic', (new AiSettings)->provider());
    }

    public function test_ai_settings_decrypts_the_stored_api_key(): void
    {
        // Nothing saved yet - no key at all, .env is never consulted.
        $settings = new AiSettings;
        $this->assertFalse($settings->hasApiKey());
        $this->assertNull($settings->apiKey());

        Setting::set('ai_api_key', Crypt::encryptString('sk-ant-real-key'));

        $settings = new AiSettings;
        $this->assertTrue($settings->hasApiKey());
        $this->assertTrue($settings->configured());
        $this->assertSame('sk-ant-real-key', $settings->apiKey());
    }

    public function test_client_never_falls_back_to_anthropic_env_credentials(): void
    {
        putenv('ANTHROPIC_API_KEY=sk-test');
        putenv('ANTHROPIC_AUTH_TOKEN=env-token');
        putenv('ANTHROPIC_BASE_URL=http://attacker.example');

        $this->assertFalse((new AiSettings)->configured());

        try {
            $this->anthropicClient(new AiChat(new AiSettings));
            $this->fail('the client must not be built from env credentials');
        } catch (RuntimeException) {
        }

        // With a saved key, env still can't add a token or redirect the base URL.
        Setting::set('ai_api_key', Crypt::encryptString('sk-ant-real-key'));
        $client = $this->anthropicClient(new AiChat(new AiSettings));

        $this->assertSame('sk-ant-real-key', $client->apiKey);
        $this->assertSame('', $client->authToken);
        $this->assertSame('api.anthropic.com', (fn () => $this->getBaseUrl())->call($client)->getHost());
    }

    public function test_client_is_bounded_by_configured_timeout_and_retries(): void
    {
        config(['ai.timeout' => 12.5, 'ai.max_retries' => 0]);

        $client = $this->anthropicClient(new AiChat(new AiSettings, ['api_key' => 'sk-ant-test', 'workspace_id' => 'ws-1']));
        $options = (fn () => $this->options)->call($client);

        $this->assertSame(12.5, $options->timeout);
        $this->assertSame(0, $options->maxRetries);
        $this->assertSame(['anthropic-workspace-id' => 'ws-1'], $options->extraHeaders);
        // The SDK doesn't enforce `timeout` itself; the transport must.
        $this->assertInstanceOf(GuzzleClient::class, $options->transporter);
        $this->assertSame(12.5, $options->transporter->getConfig('timeout'));
    }

    public function test_client_caps_a_large_retry_after(): void
    {
        config(['ai.max_retry_delay' => 10]);

        $client = $this->anthropicClient(new AiChat(new AiSettings, ['api_key' => 'sk-ant-test']));
        $transporter = (fn () => $this->options)->call($client)->transporter;
        $transporter->getConfig('handler')->setHandler(new MockHandler([
            new Response(429, ['retry-after' => '600', 'retry-after-ms' => '600000']),
            new Response(429, ['retry-after' => '3']),
        ]));

        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');

        $capped = $transporter->sendRequest($request);
        $this->assertSame('10', $capped->getHeaderLine('retry-after'));
        $this->assertSame('10000', $capped->getHeaderLine('retry-after-ms'));

        $this->assertSame('3', $transporter->sendRequest($request)->getHeaderLine('retry-after'));
    }

    public function test_undecryptable_stored_key_is_not_usable(): void
    {
        Log::spy();
        Setting::set('ai_api_key', 'not-a-valid-ciphertext');

        $settings = new AiSettings;

        $this->assertNull($settings->apiKey());
        $this->assertFalse($settings->hasApiKey());
        $this->assertFalse($settings->configured());
        Log::shouldHaveReceived('warning')->withArgs(
            fn (string $message) => ! str_contains($message, 'not-a-valid-ciphertext')
        );
    }

    public function test_settings_page_saves_ai_config_and_encrypts_the_api_key(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('apiKey', 'sk-ant-new-key')
            ->set('aiModel', 'claude-sonnet-5')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('sk-ant-new-key', Crypt::decryptString(Setting::get('ai_api_key')));
        $this->assertSame('anthropic', Setting::get('ai_provider'));
        $this->assertSame('claude-sonnet-5', Setting::get('ai_model'));
    }

    public function test_settings_page_saves_a_custom_provider(): void
    {
        OutboundUrlGuard::fakeDns(['llm.example.com' => ['93.184.216.34']]);

        Livewire::actingAs(User::factory()->create())
            ->test(SettingsIndex::class)
            ->set('aiProvider', 'openai')
            ->set('aiBaseUrl', 'https://llm.example.com/v1/')
            ->set('aiModel', 'llama3')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('openai', Setting::get('ai_provider'));
        $this->assertSame('https://llm.example.com/v1', Setting::get('ai_base_url'));
        $this->assertSame('llama3', Setting::get('ai_model'));
        $this->assertTrue((new AiSettings)->configured());
    }

    public function test_a_custom_provider_requires_an_endpoint_and_a_model(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(SettingsIndex::class)
            ->set('aiProvider', 'openai')
            ->set('aiBaseUrl', '')
            ->set('aiModel', '')
            ->call('save')
            ->assertHasErrors(['aiBaseUrl', 'aiModel']);
    }

    public function test_settings_page_rejects_an_unknown_provider(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(SettingsIndex::class)
            ->set('aiProvider', 'skynet')
            ->call('save')
            ->assertHasErrors(['aiProvider']);
    }

    public function test_clear_api_key_removes_the_stored_setting(): void
    {
        $user = User::factory()->create();
        Setting::set('ai_api_key', Crypt::encryptString('sk-ant-existing'));

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->call('clearApiKey');

        $this->assertNull(Setting::get('ai_api_key'));
    }

    public function test_settings_page_saves_mail_config_and_encrypts_the_password(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('mailEnabled', true)
            ->set('mailHost', 'smtp.example.com')
            ->set('mailPort', 465)
            ->set('mailUsername', 'notifikasi@example.com')
            ->set('mailPassword', 'super-secret')
            ->set('mailEncryption', 'ssl')
            ->set('mailFromAddress', 'noreply@example.com')
            ->set('mailFromName', config('app.name').' Monitoring')
            ->set('mailTemplateSubject', '[{{app_name}}] {{title}}')
            ->set('mailTemplateBody', '<p>{{message}}</p>')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('1', Setting::get('mail_enabled'));
        $this->assertSame('smtp.example.com', Setting::get('mail_host'));
        $this->assertSame('465', Setting::get('mail_port'));
        $this->assertSame('notifikasi@example.com', Setting::get('mail_username'));
        $this->assertSame('super-secret', Crypt::decryptString(Setting::get('mail_password')));
        $this->assertSame('ssl', Setting::get('mail_encryption'));
        $this->assertSame('noreply@example.com', Setting::get('mail_from_address'));
        $this->assertSame(config('app.name').' Monitoring', Setting::get('mail_from_name'));
        $this->assertSame('[{{app_name}}] {{title}}', Setting::get('mail_template_subject'));
        $this->assertSame('<p>{{message}}</p>', Setting::get('mail_template_body'));
    }

    public function test_settings_page_requires_host_and_from_address_when_mail_is_enabled(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('mailEnabled', true)
            ->set('mailHost', '')
            ->set('mailFromAddress', '')
            ->call('save')
            ->assertHasErrors(['mailHost', 'mailFromAddress']);
    }

    public function test_clear_mail_password_removes_the_stored_setting(): void
    {
        $user = User::factory()->create();
        Setting::set('mail_password', Crypt::encryptString('super-secret'));

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->call('clearMailPassword');

        $this->assertNull(Setting::get('mail_password'));
    }

    public function test_reset_mail_template_reverts_the_form_fields_to_the_defaults(): void
    {
        $user = User::factory()->create();

        $mailSettings = app(MailSettings::class);

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('mailTemplateSubject', 'Custom subject')
            ->set('mailTemplateBody', '<p>Custom body</p>')
            ->call('resetMailTemplate')
            ->assertSet('mailTemplateSubject', $mailSettings->defaultSubjectTemplate())
            ->assertSet('mailTemplateBody', $mailSettings->defaultBodyTemplate());
    }

    private function anthropicClient(AiChat $chat)
    {
        return (new ReflectionMethod($chat, 'anthropicClient'))->invoke($chat);
    }
}
