<?php

namespace App\Console\Commands;

use App\Services\Models\ModelsInternalClient;
use Illuminate\Console\Command;
use Throwable;

class ModelsInternalCheckCommand extends Command
{
    protected $signature = 'geoflow:models-internal-check
                            {--list : Dump the model catalog returned by models}';

    protected $description = 'Verify models /internal/* HMAC connectivity by listing models';

    public function handle(): int
    {
        if (! ModelsInternalClient::isConfigured()) {
            $this->error('models internal HMAC 未配置：需 MODELS_INTERNAL_BASE_URL 与 MODELS_INTERNAL_API_SECRET（兼容 INTERNAL_API_SECRET）同时非空。');

            return self::FAILURE;
        }

        try {
            $models = ModelsInternalClient::listModels();
            $this->info('models internal OK: '.ModelsInternalClient::baseUrl().'/internal/models');

            if ($this->option('list')) {
                $this->line((string) json_encode($models, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('models internal check failed: '.$exception->getMessage());

            return self::FAILURE;
        }
    }
}
