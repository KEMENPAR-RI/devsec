<?php

namespace Tests\Feature;

use App\Livewire\JudolDetections\Index;
use App\Livewire\Settings\Index as SettingsIndex;
use App\Models\JudolDetection;
use App\Models\JudolFinding;
use App\Models\JudolKeyword;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\JudolAiSettings;
use App\Services\Monitoring\JudolScanner;
use App\Services\Notifications\MailSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Livewire\Livewire;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_judol_ai_settings_falls_back_to_config_until_overridden(): void
    {
        config([
            'judol.ai.model' => 'claude-haiku-4-5',
            'judol.ai.confidence_threshold' => 0.5,
            'judol.ai.max_images' => 3,
        ]);

        $settings = new JudolAiSettings;

        $this->assertFalse($settings->enabled());
        $this->assertSame('claude-haiku-4-5', $settings->model());
        $this->assertSame(0.5, $settings->confidenceThreshold());
        $this->assertSame(3, $settings->maxImages());

        Setting::set('judol_ai_enabled', '1');
        Setting::set('judol_ai_model', 'claude-sonnet-5');
        Setting::set('judol_ai_confidence_threshold', '0.8');
        Setting::set('judol_ai_max_images', '5');

        // A fresh instance to be sure nothing is memoized on the object itself.
        $settings = new JudolAiSettings;

        $this->assertTrue($settings->enabled());
        $this->assertSame('claude-sonnet-5', $settings->model());
        $this->assertSame(0.8, $settings->confidenceThreshold());
        $this->assertSame(5, $settings->maxImages());
    }

    public function test_judol_ai_settings_model_falls_back_to_the_default_even_when_the_config_value_is_blank(): void
    {
        config(['judol.ai.model' => '']);

        $this->assertSame('claude-haiku-4-5', (new JudolAiSettings)->model());

        Setting::set('judol_ai_model', 'claude-sonnet-5');
        $this->assertSame('claude-sonnet-5', (new JudolAiSettings)->model());
    }

    public function test_judol_ai_settings_decrypts_the_stored_api_key(): void
    {
        // Nothing saved yet - no key at all, .env is never consulted.
        $settings = new JudolAiSettings;
        $this->assertFalse($settings->hasApiKey());
        $this->assertNull($settings->apiKey());

        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-real-key'));

        $settings = new JudolAiSettings;
        $this->assertTrue($settings->hasApiKey());
        $this->assertSame('sk-ant-real-key', $settings->apiKey());
    }

    public function test_settings_page_saves_ai_config_and_encrypts_the_api_key(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('apiKey', 'sk-ant-new-key')
            ->set('aiEnabled', true)
            ->set('aiModel', 'claude-sonnet-5')
            ->set('confidenceThreshold', 0.7)
            ->set('maxImages', 2)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('sk-ant-new-key', Crypt::decryptString(Setting::get('anthropic_api_key')));
        $this->assertSame('1', Setting::get('judol_ai_enabled'));
        $this->assertSame('claude-sonnet-5', Setting::get('judol_ai_model'));
        $this->assertSame('0.7', Setting::get('judol_ai_confidence_threshold'));
        $this->assertSame('2', Setting::get('judol_ai_max_images'));
    }

    public function test_settings_page_rejects_an_invalid_confidence_threshold(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->set('confidenceThreshold', 1.5)
            ->call('save')
            ->assertHasErrors(['confidenceThreshold']);
    }

    public function test_clear_api_key_removes_the_stored_setting(): void
    {
        $user = User::factory()->create();
        Setting::set('anthropic_api_key', Crypt::encryptString('sk-ant-existing'));

        Livewire::actingAs($user)
            ->test(SettingsIndex::class)
            ->call('clearApiKey');

        $this->assertNull(Setting::get('anthropic_api_key'));
    }

    public function test_judol_detections_page_can_manage_keywords_and_threshold(): void
    {
        $user = User::factory()->create();
        $keyword = JudolKeyword::create(['keyword' => 'contoh spam lama', 'is_active' => true]);

        $component = Livewire::actingAs($user)
            ->test(Index::class)
            ->set('newKeyword', 'spam baru unik')
            ->call('addKeyword')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('judol_keywords', ['keyword' => 'spam baru unik', 'is_active' => true]);

        $component->call('toggleKeyword', $keyword->uuid);
        $this->assertFalse($keyword->fresh()->is_active);

        $component->call('removeKeyword', $keyword->uuid);
        $this->assertDatabaseMissing('judol_keywords', ['id' => $keyword->id]);

        $component->set('threshold', 2)->call('saveThreshold')->assertHasNoErrors();
        $this->assertSame('2', Setting::get('judol_threshold'));
    }

    public function test_judol_detections_page_rejects_a_duplicate_keyword(): void
    {
        $user = User::factory()->create();
        JudolKeyword::create(['keyword' => 'sudah ada', 'is_active' => true]);

        Livewire::actingAs($user)
            ->test(Index::class)
            ->set('newKeyword', 'sudah ada')
            ->call('addKeyword')
            ->assertHasErrors(['newKeyword']);
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

    /**
     * The re-check button now hangs off an affected URL inside an injection site,
     * so the page has to be reached the way the crawl reaches it: scan(), which
     * files the finding, rather than scanUrlNow(), which files only the detection.
     */
    public function test_recheck_button_removes_a_now_stale_detection(): void
    {
        NotificationFacade::fake();
        $user = User::factory()->create();
        $keyword = JudolKeyword::create(['keyword' => 'frasa ui stale tes', 'is_active' => true]);

        Http::fake([
            'ui-stale-test.example/sitemap.xml' => Http::response('', 404),
            'ui-stale-test.example' => Http::response('<html><body><p>Frasa UI Stale Tes</p></body></html>', 200),
        ]);

        $website = Website::factory()->create(['url' => 'http://ui-stale-test.example', 'last_status' => 'up']);
        (new JudolScanner)->scan($website);

        $detection = JudolDetection::where('website_id', $website->id)->firstOrFail();
        $finding = JudolFinding::where('website_id', $website->id)->firstOrFail();

        $keyword->delete();

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('recheckUrl', $finding->uuid, 0)
            ->assertDispatched('toast', fn (string $event, array $params) => str_contains($params['message'], 'sudah bersih sekarang'));

        $this->assertDatabaseMissing('judol_detections', ['id' => $detection->id]);
    }
}
