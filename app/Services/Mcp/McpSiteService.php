<?php

namespace App\Services\Mcp;

use App\Exceptions\ApiException;
use App\Http\McpAuthContext;
use App\Models\Article;
use Illuminate\Database\Eloquent\Builder;

/**
 * Structured, read-only access to the published public site.
 *
 * Site controllers render HTML and the article controller increments views.
 * MCP uses this adapter instead so agent reads are tenant-scoped and free of
 * visitor analytics side effects.
 */
class McpSiteService
{
    private const MAX_PER_PAGE = 50;

    private const MAX_CONTENT_LENGTH = 50000;

    /** @param array<string,mixed> $input */
    public function search(array $input, McpAuthContext $auth): array
    {
        $query = $this->publishedQuery($auth);
        $search = trim((string) ($input['search'] ?? ''));
        if ($search !== '') {
            if (mb_strlen($search) > 200) {
                throw new ApiException('validation_failed', '搜索词不能超过 200 个字符', 422);
            }

            $needle = '%'.$search.'%';
            $query->where(static function (Builder $builder) use ($needle): void {
                $builder->where('title', 'like', $needle)
                    ->orWhere('excerpt', 'like', $needle)
                    ->orWhere('content', 'like', $needle);
            });
        }
        if (isset($input['category_id'])) {
            $query->where('category_id', (int) $input['category_id']);
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($input['per_page'] ?? 20)));
        $total = (clone $query)->count();
        $items = $query->orderByDesc('published_at')->orderByDesc('id')
            ->forPage($page, $perPage)
            ->get([
                'id', 'title', 'slug', 'excerpt', 'category_id', 'author_id', 'published_at',
            ])
            ->map(fn (Article $article): array => $this->summary($article))
            ->all();

        return [
            'tenant_id' => $this->tenantId($auth),
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    /** @param array<string,mixed> $input */
    public function article(array $input, McpAuthContext $auth): array
    {
        $slug = trim((string) ($input['slug'] ?? ''));
        if ($slug === '' || mb_strlen($slug) > 200) {
            throw new ApiException('validation_failed', 'slug 必须为 1-200 个字符', 422);
        }

        $article = $this->publishedQuery($auth)->where('slug', $slug)->first();
        if (! $article instanceof Article) {
            throw new ApiException('site_article_not_found', '公开文章不存在', 404);
        }

        $content = (string) ($article->content ?? '');
        $truncated = mb_strlen($content) > self::MAX_CONTENT_LENGTH;
        $content = mb_substr($content, 0, self::MAX_CONTENT_LENGTH);
        $related = $this->publishedQuery($auth)
            ->where($article->getTable().'.id', '!=', $article->getKey())
            ->when($article->category_id !== null, fn (Builder $query) => $query->where('category_id', $article->category_id))
            ->orderByDesc('published_at')->orderByDesc('id')->limit(6)->get([
                'id', 'title', 'slug', 'excerpt', 'category_id', 'author_id', 'published_at',
            ])->map(fn (Article $item): array => $this->summary($item))->all();

        return [
            'tenant_id' => $this->tenantId($auth),
            'article' => [
                'id' => (int) $article->getKey(),
                'title' => (string) $article->title,
                'slug' => (string) $article->slug,
                'excerpt' => $article->excerpt,
                'content' => $content,
                'content_truncated' => $truncated,
                'keywords' => $article->keywords,
                'meta_description' => $article->meta_description,
                'category' => $this->relationData($article, 'category'),
                'author' => $this->relationData($article, 'author'),
                'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
            ],
            'related' => $related,
        ];
    }

    /** @param array<string,mixed> $input */
    public function archive(array $input, McpAuthContext $auth): array
    {
        $year = isset($input['year']) ? (int) $input['year'] : null;
        $month = isset($input['month']) ? (int) $input['month'] : null;
        if ($year !== null && ($year < 2000 || $year > 2200)) {
            throw new ApiException('validation_failed', 'year 必须在 2000-2200 范围内', 422);
        }
        if ($month !== null && ($month < 1 || $month > 12)) {
            throw new ApiException('validation_failed', 'month 必须在 1-12 范围内', 422);
        }

        $query = $this->publishedQuery($auth)->whereNotNull('published_at');
        if ($year !== null) {
            $query->whereYear('published_at', $year);
        }
        if ($month !== null) {
            $query->whereMonth('published_at', $month);
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = max(1, min(self::MAX_PER_PAGE, (int) ($input['per_page'] ?? 20)));
        $total = (clone $query)->count();
        $items = $query->orderByDesc('published_at')->orderByDesc('id')
            ->forPage($page, $perPage)->get([
                'id', 'title', 'slug', 'excerpt', 'category_id', 'author_id', 'published_at',
            ])->map(fn (Article $article): array => $this->summary($article))->all();

        return [
            'tenant_id' => $this->tenantId($auth),
            'year' => $year,
            'month' => $month,
            'items' => $items,
            'pagination' => $this->pagination($page, $perPage, $total),
        ];
    }

    private function publishedQuery(McpAuthContext $auth): Builder
    {
        $tenantId = $this->tenantId($auth);

        return Article::query()->with([
            'category:id,name,slug',
            'author:id,name',
        ])->published()->whereHas('task', static fn (Builder $query) => $query->where('sso_team_id', $tenantId));
    }

    private function tenantId(McpAuthContext $auth): string
    {
        $tenantId = trim((string) $auth->tenantId);
        if ($tenantId === '') {
            throw new McpToolException('站点 MCP 工具必须绑定明确的租户');
        }

        return $tenantId;
    }

    /** @return array<string,mixed> */
    private function summary(Article $article): array
    {
        return [
            'id' => (int) $article->getKey(),
            'title' => (string) $article->title,
            'slug' => (string) $article->slug,
            'excerpt' => $article->excerpt,
            'category' => $this->relationData($article, 'category'),
            'author' => $this->relationData($article, 'author'),
            'published_at' => $article->published_at?->format('Y-m-d H:i:s'),
        ];
    }

    /** @return array<string,mixed>|null */
    private function relationData(Article $article, string $relation): ?array
    {
        $model = $article->getRelation($relation);
        if (! $model) {
            return null;
        }

        return array_filter([
            'id' => (int) $model->getKey(),
            'name' => (string) ($model->name ?? ''),
            'slug' => $model->slug ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
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
