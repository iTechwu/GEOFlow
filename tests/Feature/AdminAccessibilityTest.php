<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\Article;
use App\Models\Author;
use App\Models\Category;
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
            ->assertSee('id="modelModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"', false)
            ->assertSee('id="modalTitle"', false);
    }

    /** @return list<string> */
    private function accessibilityFailures(string $routeName, string $html): array
    {
        $document = new DOMDocument;
        libxml_use_internal_errors(true);
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($document);
        $failures = [];

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

        return $id !== '' && ($xpath->query('//label[@for='.json_encode($id).']')?->length ?? 0) > 0;
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
        foreach (['id', 'name', 'type', 'href'] as $attribute) {
            $value = trim($element->getAttribute($attribute));
            if ($value !== '') {
                $attributes[] = $attribute.'="'.$value.'"';
            }
        }

        return '<'.strtolower($element->tagName).($attributes === [] ? '' : ' '.implode(' ', $attributes)).'>';
    }
}
