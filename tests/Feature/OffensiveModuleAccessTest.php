<?php

namespace Tests\Feature;

use App\Jobs\PruneStaleJudolJob;
use App\Livewire\ContentChanges\Index as ContentChangesIndex;
use App\Livewire\Defacement\Index as DefacementIndex;
use App\Livewire\JudolDetections\Index as JudolIndex;
use App\Models\AuditLog;
use App\Models\DefacementFinding;
use App\Models\JudolDetection;
use App\Models\JudolExclusion;
use App\Models\JudolKeyword;
use App\Models\PageChange;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\JudolScanner;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Offensive Module: three pages in one sidebar group, each behind its own
 * {page}.view, every mutation behind its own ability - not just a login.
 */
class OffensiveModuleAccessTest extends TestCase
{
    use RefreshDatabase;

    // --- Pages --------------------------------------------------------------

    public static function pages(): array
    {
        return [
            'deteksi judol' => ['/deteksi-judol', 'deteksi-judol.view'],
            'defacement' => ['/defacement', 'defacement.view'],
            'perubahan konten' => ['/perubahan-konten', 'perubahan-konten.view'],
        ];
    }

    #[DataProvider('pages')]
    public function test_a_page_opens_with_its_view_permission(string $path, string $permission): void
    {
        $this->actingAs($this->userWith([$permission]))->get($path)->assertOk();
    }

    #[DataProvider('pages')]
    public function test_a_page_is_forbidden_without_its_view_permission(string $path, string $permission): void
    {
        $others = array_values(array_diff(['deteksi-judol.view', 'defacement.view', 'perubahan-konten.view', 'dashboard.view'], [$permission]));

        $this->actingAs($this->userWith($others))->get($path)->assertForbidden();
        $this->actingAs(User::factory()->withoutRole()->create())->get($path)->assertForbidden();
    }

    #[DataProvider('pages')]
    public function test_a_guest_is_sent_to_login(string $path): void
    {
        $this->get($path)->assertRedirect(route('login'));
    }

    public function test_the_new_permissions_are_listed_for_roles_and_given_to_superadmin(): void
    {
        foreach (['defacement.view', 'defacement.update', 'defacement.delete', 'perubahan-konten.view', 'perubahan-konten.delete'] as $permission) {
            $this->assertContains($permission, Permissions::all());
            $this->assertTrue(User::factory()->create()->can($permission), $permission);
        }

        $this->assertFalse($this->userWith(['dashboard.view'])->can('defacement.view'));
    }

    // --- Sidebar ------------------------------------------------------------

    public function test_the_sidebar_group_shows_only_the_items_a_user_may_view(): void
    {
        $user = $this->userWith(['dashboard.view', 'defacement.view']);

        $this->actingAs($user)->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Offensive Module')
            ->assertSee(route('defacement'), false)
            ->assertDontSee(route('deteksi-judol'), false)
            ->assertDontSee(route('perubahan-konten'), false);
    }

    public function test_the_sidebar_group_is_hidden_without_any_of_its_permissions(): void
    {
        $this->actingAs($this->userWith(['dashboard.view']))->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Offensive Module');
    }

    public function test_the_sidebar_lists_all_three_for_a_superadmin(): void
    {
        $this->actingAs(User::factory()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['Offensive Module', route('deteksi-judol'), route('defacement'), route('perubahan-konten')], false);
    }

    // --- Livewire actions: Defacement --------------------------------------

    public function test_defacement_actions_need_update_or_delete(): void
    {
        $finding = DefacementFinding::factory()->create();
        $viewer = $this->userWith(['defacement.view']);

        foreach (['markLegitimate', 'markResolved', 'delete'] as $action) {
            Livewire::actingAs($viewer)->test(DefacementIndex::class)->call($action, $finding->uuid)->assertForbidden();
        }

        Livewire::actingAs($viewer)->test(DefacementIndex::class)
            ->set('replacePercent', 70)
            ->call('saveThresholds')
            ->assertForbidden();

        $this->assertSame('open', $finding->fresh()->status);
        $this->assertNull(Setting::get('defacement_replace_percent'));
    }

    public function test_defacement_update_does_not_grant_delete(): void
    {
        $finding = DefacementFinding::factory()->create();

        Livewire::actingAs($this->userWith(['defacement.view', 'defacement.update']))
            ->test(DefacementIndex::class)
            ->call('delete', $finding->uuid)
            ->assertForbidden();

        $this->assertModelExists($finding);
    }

    public function test_defacement_delete_works_with_its_permission(): void
    {
        $finding = DefacementFinding::factory()->create();

        Livewire::actingAs($this->userWith(['defacement.view', 'defacement.delete']))
            ->test(DefacementIndex::class)
            ->call('delete', $finding->uuid)
            ->assertHasNoErrors();

        $this->assertModelMissing($finding);
    }

    // --- Livewire actions: Perubahan Konten ---------------------------------

    public function test_content_history_cannot_be_deleted_with_view_only(): void
    {
        $change = PageChange::factory()->create();
        $viewer = $this->userWith(['perubahan-konten.view']);

        Livewire::actingAs($viewer)->test(ContentChangesIndex::class)->call('delete', $change->uuid)->assertForbidden();
        Livewire::actingAs($viewer)->test(ContentChangesIndex::class)->call('deleteFiltered')->assertForbidden();

        $this->assertModelExists($change);
    }

    public function test_content_history_deletion_respects_filters_and_is_audited(): void
    {
        $a = Website::factory()->create();
        $b = Website::factory()->create();
        $keep = PageChange::factory()->create(['website_id' => $b->id]);
        $drop = PageChange::factory()->create(['website_id' => $a->id]);

        Livewire::actingAs($this->userWith(['perubahan-konten.view', 'perubahan-konten.delete']))
            ->test(ContentChangesIndex::class)
            ->set('severity', 'all')
            ->set('site', $a->uuid)
            ->call('deleteFiltered');

        $this->assertModelMissing($drop);
        $this->assertModelExists($keep);
        $this->assertDatabaseHas('audit_logs', ['event' => 'perubahan-konten.dihapus']);
    }

    public function test_content_history_filters_by_site_and_date(): void
    {
        $a = Website::factory()->create(['name' => 'Situs Alfa']);
        $b = Website::factory()->create(['name' => 'Situs Beta']);
        PageChange::factory()->create(['website_id' => $a->id, 'url' => 'https://alfa.example/baru', 'detected_at' => now()]);
        PageChange::factory()->create(['website_id' => $a->id, 'url' => 'https://alfa.example/lama', 'detected_at' => now()->subDays(20)]);
        PageChange::factory()->create(['website_id' => $b->id, 'url' => 'https://beta.example/baru', 'detected_at' => now()]);

        Livewire::actingAs(User::factory()->create())
            ->test(ContentChangesIndex::class)
            ->set('severity', 'all')
            ->set('site', $a->uuid)
            ->assertSee('https://alfa.example/baru')
            ->assertDontSee('https://alfa.example/lama')
            ->assertDontSee('https://beta.example/baru')
            ->set('from', now()->subDays(30)->format('Y-m-d'))
            ->assertSee('https://alfa.example/lama')
            ->set('to', 'bukan-tanggal')
            ->assertHasErrors(['to']);
    }

    // --- Livewire actions: Deteksi Judol (weights, exclusions, threshold) ----

    public function test_judol_rule_management_needs_update_or_delete(): void
    {
        $keyword = JudolKeyword::where('keyword', 'slot gacor')->firstOrFail();
        $exclusion = JudolExclusion::firstOrFail();
        $viewer = $this->userWith(['deteksi-judol.view']);

        Livewire::actingAs($viewer)->test(JudolIndex::class)->call('setKeywordWeight', $keyword->uuid, 1)->assertForbidden();
        Livewire::actingAs($viewer)->test(JudolIndex::class)->set('newExclusion', 'frasa baru')->call('addExclusion')->assertForbidden();
        Livewire::actingAs($viewer)->test(JudolIndex::class)->call('toggleExclusion', $exclusion->uuid)->assertForbidden();
        Livewire::actingAs($viewer)->test(JudolIndex::class)->call('removeExclusion', $exclusion->uuid)->assertForbidden();
        Livewire::actingAs($viewer)->test(JudolIndex::class)->set('threshold', 5)->call('saveThreshold')->assertForbidden();

        $this->assertSame(3, $keyword->fresh()->weight);
        $this->assertTrue($exclusion->fresh()->is_active);
        $this->assertDatabaseMissing('judol_exclusions', ['phrase' => 'frasa baru']);
        $this->assertNull(Setting::get('judol_score_threshold'));
    }

    public function test_judol_rules_are_managed_with_permission_and_validated(): void
    {
        $keyword = JudolKeyword::where('keyword', 'slot gacor')->firstOrFail();
        $component = Livewire::actingAs(User::factory()->create())->test(JudolIndex::class);

        $component->call('setKeywordWeight', $keyword->uuid, 2);
        $this->assertSame(2, $keyword->fresh()->weight);

        $component->call('setKeywordWeight', $keyword->uuid, 9);
        $this->assertSame(2, $keyword->fresh()->weight);

        $component->set('newExclusion', 'bandar lampung')->call('addExclusion')->assertHasErrors(['newExclusion']);
        $component->set('newExclusion', 'ab')->call('addExclusion')->assertHasErrors(['newExclusion']);
        $component->set('threshold', 0)->call('saveThreshold')->assertHasErrors(['threshold']);
        $component->set('threshold', 51)->call('saveThreshold')->assertHasErrors(['threshold']);
    }

    // --- Security fix #3: pruning needs delete, is audited ---------------------

    public function test_rule_edits_that_prune_detections_need_delete_too(): void
    {
        $this->flagPage();
        $keyword = JudolKeyword::where('keyword', 'slot gacor')->firstOrFail();
        $exclusion = JudolExclusion::firstOrFail();
        $exclusion->update(['is_active' => false]);
        $editor = $this->userWith(['deteksi-judol.view', 'deteksi-judol.update']);

        Livewire::actingAs($editor)->test(JudolIndex::class)->set('threshold', 50)->call('saveThreshold')->assertForbidden();
        Livewire::actingAs($editor)->test(JudolIndex::class)->call('setKeywordWeight', $keyword->uuid, 1)->assertForbidden();
        Livewire::actingAs($editor)->test(JudolIndex::class)->set('newExclusion', 'frasa baru')->call('addExclusion')->assertForbidden();
        Livewire::actingAs($editor)->test(JudolIndex::class)->call('toggleExclusion', $exclusion->uuid)->assertForbidden();
        Livewire::actingAs($editor)->test(JudolIndex::class)->call('toggleKeyword', $keyword->uuid)->assertForbidden();

        $this->assertNull(Setting::get('judol_score_threshold'));
        $this->assertSame(3, $keyword->fresh()->weight);
        $this->assertTrue($keyword->fresh()->is_active);
        $this->assertFalse($exclusion->fresh()->is_active);
        $this->assertDatabaseCount('judol_detections', 1);
    }

    public function test_a_prune_after_a_rule_edit_is_audited_with_ids(): void
    {
        $detection = $this->flagPage();

        Livewire::actingAs($this->userWith(['deteksi-judol.view', 'deteksi-judol.update', 'deteksi-judol.delete']))
            ->test(JudolIndex::class)
            ->set('threshold', 50)
            ->call('saveThreshold')
            ->assertHasNoErrors();

        $this->assertDatabaseCount('judol_detections', 0);
        $log = AuditLog::where('event', 'deteksi-judol.dibersihkan')->sole();
        $this->assertSame([$detection->id], $log->changes['deteksi']);
        $this->assertSame((1 + count($log->changes['titik_penyisipan'])).' temuan', $log->subject);
    }

    public function test_an_exclusion_identical_to_a_keyword_is_refused(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(JudolIndex::class)
            ->set('newExclusion', 'SLOT  Gacor')
            ->call('addExclusion')
            ->assertHasErrors(['newExclusion']);

        $this->assertDatabaseMissing('judol_exclusions', ['phrase' => 'SLOT  Gacor']);
    }

    public function test_a_large_exclusion_recheck_goes_to_the_queue(): void
    {
        Queue::fake();
        config(['monitoring.judol_recheck_sync_limit' => 0]);
        $this->flagPage();

        Livewire::actingAs(User::factory()->create())
            ->test(JudolIndex::class)
            ->set('newExclusion', 'lomba kicau')
            ->call('addExclusion')
            ->assertHasNoErrors();

        Queue::assertPushed(PruneStaleJudolJob::class);
    }

    // --- Final re-test: rule buttons follow update+delete ---------------------

    public function test_rule_controls_are_hidden_from_an_update_only_role(): void
    {
        JudolExclusion::query()->first()->update(['is_active' => false]);
        $editor = $this->userWith(['deteksi-judol.view', 'deteksi-judol.update']);

        $this->actingAs($editor)->get('/deteksi-judol')
            ->assertOk()
            ->assertSee('wire:submit="addKeyword"', false)
            ->assertDontSee('wire:click="saveThreshold"', false)
            ->assertDontSee('setKeywordWeight(', false)
            ->assertDontSee('wire:submit="addExclusion"', false)
            ->assertDontSee('toggleKeyword(', false);
    }

    public function test_rule_controls_are_shown_with_update_and_delete(): void
    {
        $manager = $this->userWith(['deteksi-judol.view', 'deteksi-judol.update', 'deteksi-judol.delete']);

        $this->actingAs($manager)->get('/deteksi-judol')
            ->assertOk()
            ->assertSee('wire:click="saveThreshold"', false)
            ->assertSee('setKeywordWeight(', false)
            ->assertSee('wire:submit="addExclusion"', false)
            ->assertSee('toggleKeyword(', false);
    }

    /** One stored keyword detection ("slot gacor", weight 3). */
    protected function flagPage(): JudolDetection
    {
        Notification::fake();
        Http::fake(['*' => Http::response('<html><body><p>Info slot gacor hari ini.</p></body></html>')]);
        $website = Website::factory()->create(['url' => 'https://desa.example']);
        (new JudolScanner)->scanUrlNow($website, 'https://desa.example/promo');

        return JudolDetection::sole();
    }

    // --- Helpers ------------------------------------------------------------

    /** @param  array<int, string>  $permissions */
    protected function userWith(array $permissions): User
    {
        return User::factory()->create(['role_id' => Role::factory()->withPermissions($permissions)->create()->id]);
    }
}
