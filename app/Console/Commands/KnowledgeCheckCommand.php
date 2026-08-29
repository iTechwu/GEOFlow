<?php

namespace App\Console\Commands;

use App\Services\Knowledge\KnowledgeInfraClient;
use Illuminate\Console\Command;
use Throwable;

final class KnowledgeCheckCommand extends Command
{
    protected $signature = 'geoflow:knowledge-check
        {query=knowledge infrastructure readiness : Read-only query used for the smoke check}';

    protected $description = 'Verify SSO M2M and tenant-scoped Knowledge API connectivity';

    public function __construct(private readonly KnowledgeInfraClient $knowledge)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->knowledge->isConfigured()) {
            $this->error('knowledge check failed: deployment configuration is incomplete.');

            return self::FAILURE;
        }

        $query = trim((string) $this->argument('query'));
        if ($query === '') {
            $this->error('knowledge check failed: query must not be empty.');

            return self::FAILURE;
        }

        try {
            $result = $this->knowledge->search($query, 1, false);
            $this->info(sprintf(
                'knowledge check passed: source=geoflow tenant=%s hits=%d',
                (string) config('geoflow.knowledge_tenant_slug', 'yootun'),
                count($result['list']),
            ));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error('knowledge check failed: '.$error->getMessage());

            return self::FAILURE;
        }
    }
}
