<?php

namespace App\Services\GeoFlow;

use App\Ai\Agents\MarkdownContentWriterAgent;
use App\Models\AiModel;
use App\Models\Task;
use App\Services\Ixicai\IxicaiRuntimeCredentials;
use App\Support\GeoFlow\OpenAiRuntimeProvider;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Article post-processor based on the installed humanize-text-skill contract.
 *
 * The model first audits the draft, then rewrites it with facts, evidence IDs,
 * Markdown structure and protected spans preserved. The second response also
 * contains the audit of the rewritten text so the article keeps an explainable
 * quality record instead of an opaque "humanized" flag.
 */
class HumanizeArticleService
{
    public function __construct(private readonly IxicaiRuntimeCredentials $ixicaiCredentials) {}

    /**
     * @return array{title:string,content:string,audit:array<string,mixed>,original_hash:string,model:string,status:string,error:?string}
     */
    public function process(Task $task, AiModel $aiModel, string $title, string $content): array
    {
        $originalHash = hash('sha256', $title."\n".$content);
        if (! (bool) config('geoflow.humanize_enabled', true)) {
            return [
                'title' => $title,
                'content' => $content,
                'audit' => [],
                'original_hash' => $originalHash,
                'model' => (string) ($aiModel->name ?? ''),
                'status' => 'disabled',
                'error' => null,
            ];
        }

        $maxInputChars = max(1000, (int) config('geoflow.humanize_max_input_chars', 180000));
        if (mb_strlen($content, 'UTF-8') > $maxInputChars) {
            throw new RuntimeException('文章正文超过 humanize 审查输入上限');
        }

        try {
            $audit = $this->decodeJson($this->callModel(
                $task,
                $aiModel,
                $this->auditPrompt($title, $content),
                'humanize_audit',
            ));
            $rewritten = $this->decodeJson($this->callModel(
                $task,
                $aiModel,
                $this->rewritePrompt($title, $content, $audit),
                'humanize_rewrite',
            ));

            $rewrittenContent = trim((string) ($rewritten['content'] ?? ''));
            if ($rewrittenContent === '') {
                throw new RuntimeException('humanize 改写返回空正文');
            }

            $finalAudit = is_array($rewritten['audit'] ?? null) ? $rewritten['audit'] : $audit;
            return [
                'title' => trim((string) ($rewritten['title'] ?? $title)) ?: $title,
                'content' => $rewrittenContent,
                'audit' => $this->normalizeAudit($finalAudit),
                'original_hash' => $originalHash,
                'model' => (string) ($aiModel->name ?? ''),
                'status' => 'processed',
                'error' => null,
            ];
        } catch (Throwable $exception) {
            $message = trim($exception->getMessage());
            if ((bool) config('geoflow.humanize_fail_closed', true)) {
                throw new RuntimeException('humanize 审查/润色失败：'.$message, 0, $exception);
            }

            return [
                'title' => $title,
                'content' => $content,
                'audit' => [],
                'original_hash' => $originalHash,
                'model' => (string) ($aiModel->name ?? ''),
                'status' => 'failed',
                'error' => mb_substr($message !== '' ? $message : 'humanize 未知错误', 0, 2000),
            ];
        }
    }

    private function auditPrompt(string $title, string $content): string
    {
        return <<<PROMPT
你是 humanize-text-skill 的中文文章审查器。请只返回 JSON，不要 Markdown 代码围栏，不要解释过程。
审查目标：识别 AI 风格信号，而不是判断文章是否使用过 AI。分数 0-100；越高表示模板化、空泛、机械或夸大越明显。必须保护事实、数字、日期、法规名称、证据编号 [K1]、引用、Markdown 链接和产品名。
检查：模板化开头和总结、堆叠连接词、空泛商业词、过度对称或三段式、无来源归因、夸大承诺、重复摘要、聊天机器人残留、句式节奏过于整齐。专业术语和必要免责声明不要因为像 AI 就删除。
返回格式：{"score":0,"classification":"HUMAN_ONLY|MIXED|AI_ONLY","issues":[{"type":"string","text":"原文片段","suggestion":"具体修改建议"}]}
文章标题：{$title}
文章正文：
{$content}
PROMPT;
    }

    /** @param array<string,mixed> $audit */
    private function rewritePrompt(string $title, string $content, array $audit): string
    {
        $auditJson = json_encode($audit, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return <<<PROMPT
你是 humanize-text-skill 的中文文章编辑。请先按审查结果做最小必要改写，再返回 JSON，不要 Markdown 代码围栏，不要解释过程。
规则：保留原文事实、数字、日期、法规、证据编号 [K1]、引用、Markdown 链接、标题含义和专业术语；不编造案例、价格、来源或业务承诺；不以规避 AI 检测为目的。删掉空泛的开场、重复摘要、内部审核备注、过度免责声明和机械总结。把长句拆开，连接词适量，改成自然、具体、面向消费者的中文。若句子已经自然，只做必要修改。
必须返回：{"title":"{$title}","content":"改写后的完整 Markdown 正文","audit":{"score":0,"classification":"HUMAN_ONLY|MIXED|AI_ONLY","issues":[{"type":"string","text":"改写后仍存在的问题","suggestion":"建议"}]}}
原始审查：{$auditJson}
文章标题：{$title}
原始文章：
{$content}
PROMPT;
    }

    private function callModel(Task $task, AiModel $aiModel, string $prompt, string $operation): string
    {
        $this->assertModelAvailable($aiModel);
        $credentials = $this->ixicaiCredentials->forAdmin($task->ssoOwner);
        $providerUrl = OpenAiRuntimeProvider::resolveChatBaseUrl($credentials['base_url']);
        $apiKey = $credentials['api_key'];
        $driver = OpenAiRuntimeProvider::resolveChatDriver($providerUrl, (string) ($aiModel->model_id ?? ''));
        $providerName = OpenAiRuntimeProvider::registerProvider($operation, $driver, $providerUrl, $apiKey);
        $agent = new MarkdownContentWriterAgent(
            instructions: '你是 humanize-text-skill 中文编辑，只输出请求的 JSON。',
            maxTokens: max(512, (int) config('geoflow.humanize_max_tokens', 8192)),
        );

        try {
            $response = $agent->prompt($prompt, [], $providerName, (string) ($aiModel->model_id ?? ''));
        } catch (Throwable $exception) {
            throw new RuntimeException(OpenAiRuntimeProvider::normalizeApiException($exception, $providerUrl), 0, $exception);
        }

        $raw = OpenAiRuntimeProvider::normalizeGeneratedText((string) ($response->text ?? ''));
        if ($raw === '') {
            throw new RuntimeException('humanize 模型返回空响应');
        }

        AiModel::query()->whereKey((int) $aiModel->id)->update([
            'used_today' => DB::raw('COALESCE(used_today,0)+1'),
            'total_used' => DB::raw('COALESCE(total_used,0)+1'),
            'updated_at' => now(),
        ]);

        return $raw;
    }

    private function assertModelAvailable(AiModel $aiModel): void
    {
        $current = AiModel::query()->find((int) $aiModel->id);
        if (! $current || ($current->status ?? 'inactive') !== 'active') {
            throw new RuntimeException('humanize 使用的 AI 模型不可用');
        }

        $dailyLimit = (int) ($current->daily_limit ?? 0);
        if ($dailyLimit > 0 && (int) ($current->used_today ?? 0) >= $dailyLimit) {
            throw new RuntimeException('humanize 使用的 AI 模型已达每日限制');
        }
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $raw): array
    {
        $raw = trim($raw);
        if (str_starts_with($raw, '```')) {
            $raw = preg_replace('/^```(?:json)?\s*|\s*```$/iu', '', $raw) ?? $raw;
        }
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end <= $start) {
            throw new RuntimeException('humanize 返回不是有效 JSON');
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('humanize JSON 结构无效');
        }

        return $decoded;
    }

    /** @param array<string,mixed> $audit */
    private function normalizeAudit(array $audit): array
    {
        $score = max(0, min(100, (int) ($audit['score'] ?? 0)));
        $classification = strtoupper(trim((string) ($audit['classification'] ?? 'MIXED')));
        if (! in_array($classification, ['HUMAN_ONLY', 'MIXED', 'AI_ONLY'], true)) {
            $classification = 'MIXED';
        }

        $issues = [];
        foreach (is_array($audit['issues'] ?? null) ? $audit['issues'] : [] as $issue) {
            if (! is_array($issue)) {
                continue;
            }
            $issues[] = [
                'type' => mb_substr(trim((string) ($issue['type'] ?? 'style')), 0, 80),
                'text' => mb_substr(trim((string) ($issue['text'] ?? '')), 0, 500),
                'suggestion' => mb_substr(trim((string) ($issue['suggestion'] ?? '')), 0, 500),
            ];
        }

        return [
            'score' => $score,
            'classification' => $classification,
            'issues' => array_slice($issues, 0, 100),
        ];
    }
}
