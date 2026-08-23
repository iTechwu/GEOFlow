<?php

namespace App\Services\Mcp;

use App\Http\McpAuthContext;
use App\Support\Site\HomepageModuleBuilder;
use App\Support\Site\SiteThemeCatalog;

/**
 * Read-only discovery of the frontend themes and homepage builder contract.
 */
class McpFrontendService
{
    public function __construct(
        private readonly SiteThemeCatalog $themes,
    ) {}

    public function capabilities(McpAuthContext $auth): array
    {
        return [
            'tenant_id' => $auth->tenantId,
            'scope' => 'read_only_global_catalog',
            'themes' => $this->themes->all(),
            'homepage' => [
                'module_types' => HomepageModuleBuilder::TYPES,
                'layouts' => HomepageModuleBuilder::LAYOUTS,
                'article_sources' => HomepageModuleBuilder::ARTICLE_SOURCES,
                'container_widths' => HomepageModuleBuilder::CONTAINER_WIDTHS,
                'spacings' => HomepageModuleBuilder::SPACINGS,
                'radii' => HomepageModuleBuilder::RADII,
                'alignments' => HomepageModuleBuilder::ALIGNMENTS,
                'presets' => HomepageModuleBuilder::presetIds(),
                'preset_modes' => HomepageModuleBuilder::presetModes(),
                'max_modules' => HomepageModuleBuilder::MAX_MODULES,
            ],
        ];
    }
}
