# GeoFlow humanize-text-skill 集成

GeoFlow 在每次 Worker 生成文章后执行两阶段内容处理：

1. 对标题和正文做 AI 风格审查，识别模板化开头、空泛商业词、重复摘要、机械连接词、夸大承诺和聊天机器人残留。
2. 根据审查结果做最小必要润色，再返回完整 Markdown 和改写后的审查结果。

处理遵循以下边界：

- 优先保护事实、数字、日期、法规名称、证据编号（如 `[K1]`）、引用、Markdown 链接和专业术语。
- 不新增价格、案例、来源或业务承诺，不以规避 AI 检测为目的。
- 文章已足够自然时只做必要修改。
- 默认 `GEOFLOW_HUMANIZE_FAIL_CLOSED=true`。审查或润色失败时，任务失败且不会静默写入未审查正文。

审查结果写入 `articles` 表的 `humanize_*` 字段，并通过文章列表和详情 MCP/API 返回，供后台和 yootun-agent 插件展示：

- `humanize_status`: `processed`、`failed` 或 `disabled`
- `humanize_score`: 0-100 风格信号分数，不代表 AI 使用概率
- `humanize_classification`: `HUMAN_ONLY`、`MIXED` 或 `AI_ONLY`
- `humanize_issues`: 问题、原文片段和修改建议
- `humanize_original_hash`: 润色前标题和正文的 SHA-256

本集成参考已安装的公开 `humanize-text-skill` 规则，来源：<https://github.com/fendouai/humanize-text-skill>。
