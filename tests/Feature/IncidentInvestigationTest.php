<?php

namespace Tests\Feature;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\BadRequestException;
use App\Livewire\Incidents\Index;
use App\Models\Incident;
use App\Models\User;
use App\Models\Website;
use App\Services\Monitoring\AiSettings;
use App\Services\Monitoring\IncidentInvestigator;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class IncidentInvestigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_investigating_an_incident_stores_the_ai_analysis(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs down', 'status' => 'open', 'detected_at' => now(),
        ]);

        $this->app->instance(IncidentInvestigator::class, new class(new AiSettings) extends IncidentInvestigator
        {
            public function investigate(Incident $incident): string
            {
                return 'Kemungkinan penyebab: server kehabisan memori.';
            }
        });

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('investigate', $incident->uuid)
            ->assertSee('Kemungkinan penyebab: server kehabisan memori.');

        $incident->refresh();
        $this->assertSame('Kemungkinan penyebab: server kehabisan memori.', $incident->ai_analysis);
        $this->assertNotNull($incident->ai_analyzed_at);
    }

    public function test_a_failed_investigation_shows_an_error_without_crashing(): void
    {
        $user = User::factory()->create();
        $website = Website::factory()->create();
        $incident = Incident::create([
            'website_id' => $website->id, 'type' => 'downtime', 'severity' => 'critical',
            'title' => 'Situs down', 'status' => 'open', 'detected_at' => now(),
        ]);

        $this->app->instance(IncidentInvestigator::class, new class(new AiSettings) extends IncidentInvestigator
        {
            public function investigate(Incident $incident): string
            {
                throw new RuntimeException('Belum ada provider AI yang dikonfigurasi. Atur di halaman Pengaturan.');
            }
        });

        Livewire::actingAs($user)
            ->test(Index::class)
            ->call('investigate', $incident->uuid)
            ->assertSee('Belum ada provider AI yang dikonfigurasi. Atur di halaman Pengaturan.');

        $this->assertNull($incident->fresh()->ai_analysis);
    }

    public function test_describe_api_error_extracts_the_clean_message_from_an_api_status_exception(): void
    {
        // Regression: APIStatusException::getMessage() is "{description}\n{pretty json}",
        // not bare JSON - json_decode()-ing it directly (the previous code) always
        // failed silently and lost the real error text.
        $request = new Request('POST', 'https://api.anthropic.com/v1/messages');
        $response = new Response(400, [], json_encode([
            'type' => 'error',
            'error' => [
                'type' => 'invalid_request_error',
                'message' => 'anthropic-workspace-id is required when authenticating with an identity-linked API key.',
            ],
            'request_id' => null,
        ]));

        $exception = new BadRequestException(request: $request, response: $response);
        $investigator = $this->investigatorWithExposedDescribeApiError();

        $this->assertSame(
            'Error Code 400, anthropic-workspace-id is required when authenticating with an identity-linked API key.',
            $investigator->describeApiErrorForTest($exception)
        );
    }

    public function test_describe_api_error_falls_back_to_the_plain_message_for_non_status_exceptions(): void
    {
        // Connection-level failures (timeouts, DNS errors, ...) don't carry a JSON
        // body at all - describeApiError() must not choke on those either.
        $exception = new APIConnectionException(
            request: new Request('POST', 'https://api.anthropic.com/v1/messages'),
            message: 'Connection timed out.',
        );

        $investigator = $this->investigatorWithExposedDescribeApiError();

        $this->assertStringContainsString('Connection timed out.', $investigator->describeApiErrorForTest($exception));
    }

    protected function investigatorWithExposedDescribeApiError(): IncidentInvestigator
    {
        return new class(new AiSettings) extends IncidentInvestigator
        {
            public function describeApiErrorForTest(Throwable $e): string
            {
                return $this->describeApiError($e);
            }
        };
    }
}
