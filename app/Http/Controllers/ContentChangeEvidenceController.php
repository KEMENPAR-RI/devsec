<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PageChange;
use App\Services\Monitoring\ContentChangeEvidence;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Evidence file for one content-change event: built in memory, streamed as
 * inert text/plain, never stored. Every download is audited.
 */
class ContentChangeEvidenceController extends Controller
{
    public function download(Request $request, PageChange $change, ContentChangeEvidence $evidence): StreamedResponse
    {
        // The route gate already checks this; repeated so the action never relies on routing alone.
        Gate::authorize('perubahan-konten.view');

        $text = $evidence->render($change, $request->user());

        AuditLog::record('perubahan-konten.bukti-diunduh', $change->url, ['id' => $change->uuid]);

        return response()->streamDownload(
            fn () => print ($text),
            'bukti-perubahan-konten-'.$change->uuid.'-'.now()->format('YmdHis').'.txt',
            [
                'Content-Type' => 'text/plain; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'no-store',
            ],
        );
    }
}
