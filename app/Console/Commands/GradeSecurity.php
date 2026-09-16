<?php

namespace App\Console\Commands;

use App\Models\Website;
use App\Services\Monitoring\SecurityGrader;
use Illuminate\Console\Command;
use Throwable;

/**
 * The daily security-posture pass over every monitored site.
 *
 * Kept apart from websites:check (per minute) and websites:check-domains (DNS and
 * registry): browser-hardening headers change on the order of a year, so grading
 * them more often than daily is pure cost.
 */
class GradeSecurity extends Command
{
    protected $signature = 'websites:grade-security
        {--site= : Grade only the website with this uuid}';

    protected $description = 'Grade security headers and TLS for the monitored websites';

    public function handle(SecurityGrader $grader): int
    {
        $websites = Website::query()
            ->where('is_active', true)
            ->when($this->option('site'), fn ($q) => $q->where('uuid', $this->option('site')))
            ->get();

        if ($websites->isEmpty()) {
            $this->info('Tidak ada situs aktif untuk dinilai.');

            return self::SUCCESS;
        }

        $graded = 0;
        $skipped = 0;

        foreach ($websites as $website) {
            // A site under maintenance is being worked on; a header that is
            // temporarily missing mid-deploy is not a finding.
            if ($website->isUnderMaintenance()) {
                continue;
            }

            try {
                $result = $grader->grade($website);
            } catch (Throwable $e) {
                $this->warn("{$website->name}: {$e->getMessage()}");

                continue;
            }

            // Unreachable is not an F - the previous grade stands rather than
            // being replaced by a scary letter that only means "timeout".
            if ($result === null) {
                $skipped++;

                continue;
            }

            $website->update([
                'security_score' => $result['score'],
                'security_grade' => $result['grade'],
                'security_findings' => $result['findings'],
                'security_checked_at' => now(),
            ]);

            $graded++;
        }

        $this->info("{$graded} situs dinilai, {$skipped} dilewati karena tidak merespons.");

        return self::SUCCESS;
    }
}
