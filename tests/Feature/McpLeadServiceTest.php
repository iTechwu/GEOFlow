<?php

namespace Tests\Feature;

use App\Http\McpAuthContext;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Services\Mcp\McpLeadService;
use App\Services\Mcp\McpToolException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class McpLeadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_forms_and_submissions_are_filtered_to_the_authenticated_team(): void
    {
        $teamAForm = $this->form('Team A form', 'team-a');
        $teamBForm = $this->form('Team B form', 'team-b');
        $this->submission($teamAForm, ['email' => 'a@example.test']);
        $this->submission($teamBForm, ['email' => 'b@example.test']);

        $service = app(McpLeadService::class);
        $forms = $service->forms($this->auth('team-a'));
        $submissions = $service->submissions([], $this->auth('team-a'));

        $this->assertSame(['Team A form'], collect($forms['items'])->pluck('name')->all());
        $this->assertSame(1, $submissions['pagination']['total']);
        $this->assertSame(['email'], $submissions['items'][0]['payload_fields']);
        $this->assertArrayNotHasKey('payload', $submissions['items'][0]);
    }

    public function test_payload_requires_the_separate_pii_scope_and_status_update_is_scoped(): void
    {
        $form = $this->form('Team A form', 'team-a');
        $submission = $this->submission($form, ['email' => 'a@example.test']);
        $service = app(McpLeadService::class);

        try {
            $service->get((int) $submission->id, ['include_payload' => true], $this->auth('team-a'));
            $this->fail('Expected leads:pii scope to be required');
        } catch (McpToolException $exception) {
            $this->assertStringContainsString('leads:pii', $exception->getMessage());
        }

        $result = $service->get((int) $submission->id, ['include_payload' => true], $this->auth('team-a', ['leads:read', 'leads:pii']));
        $this->assertSame('a@example.test', $result['submission']['payload']['email']);

        $updated = $service->updateStatus((int) $submission->id, ['status' => 'contacted', 'note' => 'Follow up'], $this->auth('team-a', ['leads:read', 'leads:write']));
        $this->assertSame('contacted', $updated['submission']['status']);
        $this->assertSame('contacted', $submission->fresh()->status);
    }

    public function test_lead_tools_require_a_tenant(): void
    {
        $this->expectException(McpToolException::class);

        app(McpLeadService::class)->forms($this->auth(null));
    }

    private function form(string $name, string $teamId): LeadForm
    {
        return LeadForm::query()->create([
            'name' => $name,
            'slug' => strtolower(str_replace(' ', '-', $name)).'-'.str_replace('-', '', $teamId),
            'status' => LeadForm::STATUS_ACTIVE,
            'fields' => [['name' => 'email', 'label' => 'Email', 'type' => 'email', 'required' => true]],
            'sso_team_id' => $teamId,
        ]);
    }

    private function submission(LeadForm $form, array $payload): LeadSubmission
    {
        return LeadSubmission::query()->create([
            'lead_form_id' => $form->id,
            'status' => LeadSubmission::STATUS_NEW,
            'payload' => $payload,
            'ip_address' => '192.0.2.1',
            'user_agent' => 'test-agent',
            'source_url' => 'https://example.test/contact?secret=1',
        ]);
    }

    private function auth(?string $tenantId, array $scopes = ['leads:read']): McpAuthContext
    {
        return new McpAuthContext(McpAuthContext::SCOPE_WRITE, 'hash', $tenantId, null, $scopes);
    }
}
