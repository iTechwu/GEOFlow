<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Models\LeadForm;
use App\Models\LeadSubmission;
use Illuminate\Database\Eloquent\Builder;

/**
 * Tenant-scoped lead operations with an explicit PII boundary.
 *
 * Default responses contain form/status/timestamps and payload field names
 * only. Raw payloads are available only to a token with leads:pii.
 */
class McpLeadService
{
    public function forms(McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $items = LeadForm::query()
            ->where('sso_team_id', $tenantId)
            ->withCount('submissions')
            ->orderByDesc('id')
            ->get(['id', 'name', 'slug', 'status', 'description', 'created_at'])
            ->map(fn (LeadForm $form): array => [
                'id' => (int) $form->id,
                'name' => (string) $form->name,
                'slug' => (string) $form->slug,
                'status' => (string) $form->status,
                'description' => $form->description,
                'submissions_count' => (int) $form->submissions_count,
                'created_at' => $form->created_at?->format('Y-m-d H:i:s'),
            ])->all();

        return ['tenant_id' => $tenantId, 'items' => $items];
    }

    /** @param array<string,mixed> $input */
    public function submissions(array $input, McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($input['per_page'] ?? 20)));
        $query = $this->submissionQuery($tenantId)->with('form:id,name,slug');
        if (isset($input['status']) && trim((string) $input['status']) !== '') {
            $status = trim((string) $input['status']);
            if (! in_array($status, LeadSubmission::STATUSES, true)) {
                throw new ApiException('validation_failed', 'status 参数不受支持', 422);
            }
            $query->where('status', $status);
        }
        if (isset($input['form_id'])) {
            $query->where('lead_form_id', (int) $input['form_id']);
        }

        $total = (clone $query)->count();
        $items = $query->orderByDesc('id')->forPage($page, $perPage)->get([
            'id', 'lead_form_id', 'status', 'payload', 'handled_at', 'created_at', 'updated_at',
        ])->map(fn (LeadSubmission $submission): array => $this->submission($submission, false))->all();

        return [
            'tenant_id' => $tenantId,
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    /** @param array<string,mixed> $input */
    public function get(int $submissionId, array $input, McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $submission = $this->submissionQuery($tenantId)->with('form:id,name,slug')->whereKey($submissionId)->first();
        if (! $submission instanceof LeadSubmission) {
            throw new ApiException('lead_not_found', '线索不存在', 404);
        }

        $includePayload = (bool) ($input['include_payload'] ?? false);
        if ($includePayload && ! $auth->allows('leads:pii')) {
            throw new McpToolException('读取线索 payload 需要 leads:pii 权限');
        }

        return [
            'tenant_id' => $tenantId,
            'submission' => $this->submission($submission, $includePayload),
        ];
    }

    /** @param array<string,mixed> $input */
    public function updateStatus(int $submissionId, array $input, McpAuthContext $auth): array
    {
        $tenantId = $this->tenantId($auth);
        $submission = $this->submissionQuery($tenantId)->whereKey($submissionId)->first();
        if (! $submission instanceof LeadSubmission) {
            throw new ApiException('lead_not_found', '线索不存在', 404);
        }

        $status = trim((string) ($input['status'] ?? ''));
        if (! in_array($status, LeadSubmission::STATUSES, true)) {
            throw new ApiException('validation_failed', 'status 参数不受支持', 422);
        }
        $note = trim((string) ($input['note'] ?? ''));
        if (mb_strlen($note) > 5000) {
            throw new ApiException('validation_failed', 'note 不能超过 5000 个字符', 422);
        }

        $submission->update([
            'status' => $status,
            'note' => $note,
            'handled_by' => $auth->auditAdminId,
            'handled_at' => now(),
        ]);

        return [
            'tenant_id' => $tenantId,
            'submission' => [
                'id' => (int) $submission->id,
                'status' => (string) $submission->status,
                'handled_at' => $submission->handled_at?->format('Y-m-d H:i:s'),
            ],
        ];
    }

    private function tenantId(McpAuthContext $auth): string
    {
        $tenantId = trim((string) $auth->tenantId);
        if ($tenantId === '') {
            throw new McpToolException('线索 MCP 工具必须绑定明确的租户');
        }

        return $tenantId;
    }

    /** @return Builder<LeadSubmission> */
    private function submissionQuery(string $tenantId): Builder
    {
        return LeadSubmission::query()->whereHas('form', static fn (Builder $query) => $query->where('sso_team_id', $tenantId));
    }

    /** @return array<string,mixed> */
    private function submission(LeadSubmission $submission, bool $includePayload): array
    {
        $payload = is_array($submission->payload) ? $submission->payload : [];
        $result = [
            'id' => (int) $submission->id,
            'form' => $submission->form ? [
                'id' => (int) $submission->form->id,
                'name' => (string) $submission->form->name,
                'slug' => (string) $submission->form->slug,
            ] : null,
            'status' => (string) $submission->status,
            'payload_fields' => array_values(array_map('strval', array_keys($payload))),
            'payload_count' => count($payload),
            'handled_at' => $submission->handled_at?->format('Y-m-d H:i:s'),
            'created_at' => $submission->created_at?->format('Y-m-d H:i:s'),
            'updated_at' => $submission->updated_at?->format('Y-m-d H:i:s'),
        ];
        if ($includePayload) {
            $result['payload'] = $payload;
        }

        return $result;
    }

    /** @return array{page:int,per_page:int,total:int,total_pages:int} */
    private function pagination(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }
}
