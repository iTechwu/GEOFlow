# GEOFlow Agent Integration

## Runtime

- MCP endpoint: `https://geo.local.dofe.ai/mcp`
- Protocol: MCP Streamable HTTP, JSON-RPC `2.0`, protocol version `2025-06-18`
- Default tenant: `GEOFLOW_MCP_DEFAULT_TENANT` (当前部署为“优惠豚/全体”的 SSO team ID)
- Cross-tenant system access is disabled unless `GEOFLOW_MCP_ALLOW_CROSS_TENANT=true` is explicitly set.
- Configure the bearer token through `GEOFLOW_MCP_TOKEN`; do not hard-code it in prompts or source files.

## Codex

The project configuration is in `../../.codex/config.toml` and uses `GEOFLOW_MCP_TOKEN`.

```bash
export GEOFLOW_MCP_TOKEN='...'
codex mcp get geoflow
```

## Claude Code

The project configuration is in `../../.mcp.json` and uses `${GEOFLOW_MCP_TOKEN}`.

```bash
export GEOFLOW_MCP_TOKEN='...'
claude mcp get geoflow
claude -p '只使用 geoflow MCP 查询当前任务和素材摘要。'
```

## MCP Capability Matrix

| Domain | Tools |
| --- | --- |
| Catalog | `geoflow.catalog` |
| Materials | `geoflow.materials.summary`, `.list`, `.get`, `.create`, `.update`, `.delete` |
| Material items | `geoflow.materials.items.list`, `.create`, `.delete` |
| Tasks | `geoflow.tasks.list`, `.create`, `.get`, `.update`, `.delete`, `.start`, `.stop`, `.enqueue` |
| Task monitoring | `geoflow.tasks.jobs`, `geoflow.jobs.get` |
| Articles | `geoflow.articles.list`, `.get`, `.create`, `.update`, `.review`, `.publish`, `.trash` |

All tool arguments are validated against the advertised JSON Schema. Write tools support `idempotency_key`; retries with the same key are tenant-isolated and audited.

## Agent Workflow

1. Call `geoflow.catalog` and the relevant `geoflow.materials.*` tools to resolve IDs.
2. Create or update a title library, keyword library, category, author, or knowledge base as needed.
3. Call `geoflow.tasks.create` with `title_library_id`, `prompt_id`, and `ai_model_id`.
4. Start or enqueue the task with `geoflow.tasks.start` or `.enqueue`.
5. Poll `geoflow.tasks.get`, `geoflow.tasks.jobs`, and `geoflow.jobs.get` for progress and failures.
6. Use article review/publish tools only when the token has the corresponding `articles:publish` ability.

## Deliberate Boundaries

The existing REST/Admin surfaces remain authoritative for binary image uploads, URL import jobs, public site/channel management, distribution packages, theme replication, analytics, lead capture, system updates, and direct model/prompt administration. These are not represented as fake MCP tools; add a dedicated tool only after defining its tenant, permission, idempotency, and error contract.
