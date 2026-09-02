<?php

namespace Tests\Unit;

use App\Services\Monitoring\IncidentInvestigator;
use PHPUnit\Framework\TestCase;

class CleanAnalysisTextTest extends TestCase
{
    public function test_headings_lose_their_hashes_but_keep_their_text(): void
    {
        $this->assertSame(
            "Analisis Insiden\nPenyebab Utama",
            IncidentInvestigator::cleanAnalysisText("# Analisis Insiden\n### Penyebab Utama")
        );
    }

    public function test_bold_and_italic_markers_are_removed(): void
    {
        $this->assertSame(
            'Lonjakan trafik sesaat, bukan serangan.',
            IncidentInvestigator::cleanAnalysisText('**Lonjakan trafik sesaat**, _bukan serangan_.')
        );
    }

    public function test_bullets_become_readable_dots_and_numbering_survives(): void
    {
        $this->assertSame(
            "• Tiba-tiba dan intermiten\n• Spike sporadis\n1. Cek log aplikasi",
            IncidentInvestigator::cleanAnalysisText("- Tiba-tiba dan intermiten\n* Spike sporadis\n1.  Cek log aplikasi")
        );
    }

    public function test_code_ticks_and_fences_are_stripped(): void
    {
        $this->assertSame(
            'Cari slow query di log.',
            IncidentInvestigator::cleanAnalysisText('Cari `slow query` di log.')
        );
    }

    public function test_horizontal_rules_are_dropped_and_blank_runs_collapse(): void
    {
        $this->assertSame(
            "Bagian Satu\n\nBagian Dua",
            IncidentInvestigator::cleanAnalysisText("Bagian Satu\n\n---\n\n\nBagian Dua")
        );
    }

    public function test_plain_text_passes_through_untouched(): void
    {
        $plain = "Penyebab: SSL handshake timeout.\n\nSaran: cek sertifikat di panel hosting.";

        $this->assertSame($plain, IncidentInvestigator::cleanAnalysisText($plain));
    }

    public function test_an_asterisk_that_is_not_markdown_is_left_alone(): void
    {
        // Multiplication and wildcards shouldn't be eaten as emphasis markers.
        $this->assertSame(
            'Timeout 3 * 10 detik pada path /api/*',
            IncidentInvestigator::cleanAnalysisText('Timeout 3 * 10 detik pada path /api/*')
        );
    }
}
