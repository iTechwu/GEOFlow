<?php

namespace App\Console\Commands;

use App\Services\Models\ModelsGatewayClient;
use Illuminate\Console\Command;
use Throwable;

class ModelsGatewayCheckCommand extends Command
{
    protected $signature = 'geoflow:models-gateway-check';

    protected $description = 'Verify models public Chat and Embedding connectivity';

    public function handle(): int
    {
        try {
            ModelsGatewayClient::check();
            $this->info('models public Chat and Embedding checks passed.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('models public gateway check failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
