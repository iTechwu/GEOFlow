<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\AiModel;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
use App\Support\GeoFlow\ApiKeyCrypto;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_core_admin_pages_label_controls_and_icon_actions(): void
    {
        $admin = Admin::query()->create([
            'username' => 'accessibility_admin',
            'password' => 'secret-123',
            'email' => 'accessibility@example.com',
            'display_name' => 'Accessibility Admin',
            'role' => 'super_admin',
            'status' => 'active',
        ]);
        $category = Category::query()->create(['name' => 'Accessibility', 'slug' => 'accessibility']);
        $author = Author::query()->create(['name' => 'Accessibility Author']);
        Article::query()->create([
            'title' => 'Accessible Article',
            'slug' => 'accessible-article',
            'excerpt' => 'Accessibility fixture',
            'content' => 'Accessible content',
            'category_id' => $category->id,
            'author_id' => $author->id,
            'status' => 'published',
            'review_status' => 'approved',
            'published_at' => now(),
        ]);
        AiModel::query()->create([
            'name' => 'Accessibility Chat',
            'version' => 'test',
            'api_key' => app(ApiKeyCrypto::class)->encrypt('accessibility-test-key'),
            'model_id' => 'accessibility-chat',
            'model_type' => 'chat',
            'api_url' => 'https://models.test',
            'failover_priority' => 100,
            'daily_limit' => 0,
            'used_today' => 0,
            'total_used' => 0,
            'status' => 'active',
        ]);

        $routes = [
            'admin.articles.index',
            'admin.analytics',
            'admin.site-settings.index',
            'admin.ai-models.index',
            'admin.url-import',
            'admin.lead-forms.create',
        ];

        $failures = [];
        foreach ($routes as $routeName) {
            $response = $this->actingAs($admin, 'admin')->get(route($routeName));
            $response->assertOk();
            $failures = [...$failures, ...$this->accessibilityFailures($routeName, $response->getContent())];
        }

        $this->assertSame([], $failures, implode("\n", $failures));

        $modelResponse = $this->actingAs($admin, 'admin')->get(route('admin.ai-models.index'));
        $modelResponse
            ->assertSee('id="modelModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true"', false)
            ->assertSee('id="modalTitle"', false)
            ->assertSee('aria-live="polite"', false)
            ->assertSee('data-model-modal-panel', false)
            ->assertSee('data-model-modal-close', false)
            ->assertSee("event.key === 'Escape'", false)
            ->assertSee("event.key !== 'Tab'", false)
            ->assertSee('modelModalTrigger.focus()', false);
    }

    /** @return list<string> */
    private function accessibilityFailures(string $routeName, string $html): array
    {
        $document = new DOMDocument;
        $previousLibxmlSetting = libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        libxml_use_internal_errors($previousLibxmlSetting);
        $xpath = new DOMXPath($document);
        $failures = [];

        foreach ($xpath->query('//*[@id]') ?: [] as $element) {
            if (! $element instanceof DOMElement) {
                continue;
            }

            $id = trim($element->getAttribute('id'));
            if ($id === '' || ($xpath->query('//*[@id='.json_encode($id).']')?->length ?? 0) !== 1) {
                $failures[] = $routeName.': duplicate or empty id '.$this->describe($element);
            }
        }

        foreach ($xpath->query('//*[@aria-controls]') ?: [] as $controller) {
            if (! $controller instanceof DOMElement) {
                continue;
            }

            foreach (preg_split('/\s+/', trim($controller->getAttribute('aria-controls'))) ?: [] as $controlledId) {
                if ($controlledId === '' || ($xpath->query('//*[@id='.json_encode($controlledId).']')?->length ?? 0) !== 1) {
                    $failures[] = $routeName.': invalid aria-controls '.$this->describe($controller);
                }
            }
        }

        foreach ($xpath->query('//*[@aria-expanded and not(@aria-controls)]') ?: [] as $controller) {
            if ($controller instanceof DOMElement) {
                $failures[] = $routeName.': aria-expanded without aria-controls '.$this->describe($controller);
            }
        }

        if (in_array($routeName, ['admin.articles.index', 'admin.analytics', 'admin.url-import'], true)) {
            $unassociatedLabels = '//label[not(@for) and not(descendant::input or descendant::select or descendant::textarea)'
                .' and following-sibling::*[1][self::input or self::select or self::textarea]]';
            foreach ($xpath->query($unassociatedLabels) ?: [] as $label) {
                if ($label instanceof DOMElement) {
                    $failures[] = $routeName.': visible label is not associated '.$this->describe($label);
                }
            }
        }

        foreach ($xpath->query('//input[translate(@type, "HIDDEN", "hidden") != "hidden"] | //select | //textarea') ?: [] as $control) {
            if ($control instanceof DOMElement && ! $this->controlHasLabel($control, $xpath)) {
                $failures[] = $routeName.': unlabeled '.$this->describe($control);
            }
        }

        foreach ($xpath->query('//a[@href] | //button') ?: [] as $action) {
            if ($action instanceof DOMElement && ! $this->actionHasName($action, $xpath)) {
                $failures[] = $routeName.': unnamed '.$this->describe($action);
            }
        }

        return $failures;
    }

    private function controlHasLabel(DOMElement $control, DOMXPath $xpath): bool
    {
        if (trim($control->getAttribute('aria-label')) !== '') {
            return true;
        }

        $labelledBy = trim($control->getAttribute('aria-labelledby'));
        if ($labelledBy !== '' && $this->labelledByHasText($labelledBy, $xpath)) {
            return true;
        }

        if (($xpath->query('ancestor::label', $control)?->length ?? 0) > 0) {
            return true;
        }

        $id = trim($control->getAttribute('id'));
        if ($id === '') {
            return false;
        }

        $labels = $xpath->query('//label[@for='.json_encode($id).']');

        return ($labels?->length ?? 0) === 1 && trim((string) $labels->item(0)?->textContent) !== '';
    }

    private function actionHasName(DOMElement $action, DOMXPath $xpath): bool
    {
        if (trim($action->textContent) !== '' || trim($action->getAttribute('aria-label')) !== '' || trim($action->getAttribute('title')) !== '') {
            return true;
        }

        return ($xpath->query('.//img[@alt and normalize-space(@alt) != ""]', $action)?->length ?? 0) > 0;
    }

    private function labelledByHasText(string $labelledBy, DOMXPath $xpath): bool
    {
        foreach (preg_split('/\s+/', $labelledBy) ?: [] as $id) {
            $nodes = $xpath->query('//*[@id='.json_encode($id).']');
            if (($nodes?->length ?? 0) !== 1 || trim((string) $nodes->item(0)?->textContent) === '') {
                return false;
            }
        }

        return true;
    }

    private function describe(DOMElement $element): string
    {
        $attributes = [];
        foreach (['id', 'name', 'type', 'href', 'class', 'data-action'] as $attribute) {
            $value = trim($element->getAttribute($attribute));
            if ($value !== '') {
                $attributes[] = $attribute.'="'.$value.'"';
            }
        }

        return '<'.strtolower($element->tagName).($attributes === [] ? '' : ' '.implode(' ', $attributes)).'>';
    }
}
