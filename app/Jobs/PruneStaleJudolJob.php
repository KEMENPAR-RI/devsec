<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Monitoring\JudolScanner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;

/** Re-reads every stored judol snapshot after an exclusion edit - too slow for one request. */
class PruneStaleJudolJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    // One pending recheck at a time; an edit made while it runs queues another.
    public int $uniqueFor = 900;

    public function __construct(public string $trigger, public ?int $userId = null)
    {
        $this->onQueue(config('monitoring.queues.scans'));
    }

    public function handle(JudolScanner $scanner): void
    {
        // The audit entry names the operator who triggered the cleanup.
        if ($this->userId && ($user = User::find($this->userId))) {
            Auth::setUser($user);
        }

        try {
            $scanner->pruneStaleDetections(recheckSnapshots: true);
            $scanner->pruneStaleFindings();
            $scanner->auditPrune($this->trigger);
        } finally {
            // The worker lives on: the next job must not run as this user.
            Auth::forgetUser();
        }
    }
}
