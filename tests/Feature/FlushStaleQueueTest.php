<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FlushStaleQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs on the sync driver, where there is no jobs table to
        // inspect and the command correctly refuses to do anything.
        config(['queue.default' => 'database']);
    }

    /** On a driver with no inspectable queue the command must not pretend to work. */
    public function test_it_declines_when_the_queue_is_not_a_database_queue(): void
    {
        config(['queue.default' => 'sync']);

        $this->artisan('queue:flush-stale')
            ->expectsOutputToContain('tidak memakai driver database')
            ->assertSuccessful();
    }

    /**
     * Starting a worker against a days-old backlog would run every stale check at
     * once. Clearing it loses nothing: the scheduler re-dispatches both jobs on
     * the next tick with fresh state.
     */
    public function test_it_drops_queued_jobs(): void
    {
        $this->queueJob();
        $this->queueJob();

        $this->artisan('queue:flush-stale')->assertSuccessful();

        $this->assertSame(0, DB::table('jobs')->count());
    }

    /** failed_jobs is kept unless asked for, so a real failure can still be read. */
    public function test_failed_jobs_are_kept_unless_requested(): void
    {
        $this->failJob();

        $this->artisan('queue:flush-stale')->assertSuccessful();
        $this->assertSame(1, DB::table('failed_jobs')->count());

        $this->artisan('queue:flush-stale --failed')->assertSuccessful();
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_an_empty_queue_is_reported_rather_than_erroring(): void
    {
        $this->artisan('queue:flush-stale')
            ->expectsOutputToContain('Antrean sudah bersih.')
            ->assertSuccessful();
    }

    private function queueJob(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'available_at' => now()->subDay()->timestamp,
            'created_at' => now()->subDay()->timestamp,
        ]);
    }

    private function failJob(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'boom',
            'failed_at' => now(),
        ]);
    }
}
