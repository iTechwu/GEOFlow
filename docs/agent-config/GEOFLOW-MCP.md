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
| Discovery | `geoflow.capabilities`, `geoflow.catalog` |
| Materials | `geoflow.materials.summary`, `.list`, `.get`, `.create`, `.update`, `.delete` |
| Material items | `geoflow.materials.items.list`, `.create`, `.delete` |
| Tasks | `geoflow.tasks.list`, `.create`, `.get`, `.update`, `.delete`, `.start`, `.stop`, `.enqueue` |
| Task monitoring | `geoflow.tasks.jobs`, `geoflow.jobs.get`, `geoflow.jobs.cancel` |
| Articles | `geoflow.articles.list`, `.get`, `.create`, `.update`, `.review`, `.publish`, `.trash` |
| URL import | `geoflow.url_import.create`, `.run`, `.status`, `.commit` |
| Analytics | `geoflow.analytics.overview` (tenant-scoped aggregate metrics) |
| Enterprise knowledge | `geoflow.enterprise_knowledge.create`, `.status`, `.validate`, `.autosave`, `.publish` |
| Published site read | `geoflow.site.search`, `.article`, `.archive` (tenant-scoped, no view counter side effect) |
| Frontend discovery | `geoflow.site.capabilities` (theme catalog and homepage builder contract) |
| System diagnostics | `geoflow.system.status` (safe deployment and runtime checks) |
| Distribution read | `geoflow.distribution.channels`, `.jobs`, `.health` (tenant-scoped, redacted, no remote call) |
| Leads | `geoflow.leads.forms`, `.submissions`, `.get`, `.update_status` (tenant-scoped; payload requires `leads:pii`) |

All tool arguments are validated against the advertised JSON Schema. Write tools support `idempotency_key`; retries with the same key are tenant-isolated and audited.

## Agent Workflow

1. Call `geoflow.catalog` and the relevant `geoflow.materials.*` tools to resolve IDs.
2. Create or update a title library, keyword library, category, author, or knowledge base as needed.
3. Call `geoflow.tasks.create` with `title_library_id`, `prompt_id`, and `ai_model_id`.
4. Start or enqueue the task with `geoflow.tasks.start` or `.enqueue`.
5. Poll `geoflow.tasks.get`, `geoflow.tasks.jobs`, and `geoflow.jobs.get` for progress and failures. Cancel only a pending/running execution with `geoflow.jobs.cancel` when the token has `jobs:write`.
6. Use article review/publish tools only when the token has the corresponding `articles:publish` ability.

### URL Import Workflow

1. Call `geoflow.url_import.create` with the public URL and an optional output selection. The job is always created in the authenticated tenant and remains a preview until committed.
2. Call `geoflow.url_import.run` once. It atomically queues the network/AI work and returns immediately; poll `geoflow.url_import.status` until the status is `completed` or `failed`. The URL parser keeps the existing SSRF, private-network, redirect, timeout, and response-size protections.
3. Review the returned redacted preview. Only after explicit user approval call `geoflow.url_import.commit` with `confirmation: "IMPORT"`. This creates the knowledge, keyword, and title libraries under the same tenant; retries should provide an `idempotency_key`.

The per-tenant queued/running limit is controlled by `GEOFLOW_MCP_URL_IMPORT_MAX_ACTIVE` (default `3`). A job from another tenant is returned as not found, and an MCP token without a concrete tenant cannot create, run, inspect, or commit URL imports.

`geoflow.analytics.overview` requires a concrete tenant and supports `today`, `yesterday`, `7d`, `30d`, `90d`, or a custom date range up to 90 days. It returns production/task/article/distribution/import/traffic aggregates only; raw IP, user-agent, prompts, article bodies, lead payloads, and shared model usage are excluded.

### Published Site Read Workflow

1. Call `geoflow.site.search` to find published articles by title, excerpt, content, or category.
2. Call `geoflow.site.article` with a slug to read the bounded article body and related published articles. This is a read-only query and does not increment `view_count`.
3. Call `geoflow.site.archive` with optional `year` and `month` filters to browse dated published articles. Results are limited to the authenticated SSO team.

### Enterprise Knowledge Workflow

1. Call `geoflow.enterprise_knowledge.list` to discover bounded project metadata for the authenticated tenant. The response excludes draft content.
2. Call `.create` with text content. The project and source are tenant-owned and draft generation is queued on the GEOFlow worker.
3. Poll `.status`, then call `.validate` or `.autosave` while reviewing the bounded draft preview.
4. Publish only after explicit approval with `confirmation: "PUBLISH"`. The resulting knowledge base and chunks inherit the same tenant.
5. Delete only after explicit approval with `confirmation: "DELETE"`. Deletion is tenant-scoped and refuses a published knowledge base that is still referenced by a task; binary file upload, editor images, and restore remain Admin operations.

### Distribution Read Workflow

1. Call `geoflow.distribution.channels` to list channels explicitly owned by the authenticated SSO team.
2. Call `geoflow.distribution.jobs` to inspect article delivery status and retry timing. Jobs are visible only when both the article task and channel belong to the same team.
3. Call `geoflow.distribution.health` to read the last cached health result. It does not make a remote request.

### Leads Workflow

1. Call `geoflow.leads.forms` to list forms owned by the current SSO team.
2. Call `geoflow.leads.submissions` to inspect statuses and payload field names. Values, IP addresses, user agents, source URLs, and notes are excluded by default.
3. Call `geoflow.leads.get` with `include_payload: true` only when the token has the separate `leads:pii` scope. Use `geoflow.leads.update_status` for status transitions; CSV export and anonymous public submission remain Admin/site operations.

### Frontend Discovery Workflow

1. Call `geoflow.site.capabilities` before generating homepage or theme-related instructions.
2. Use the returned theme IDs, module types, layouts, presets, and limits as the only allowed frontend vocabulary.
3. Theme switching, replication, preview publishing, and site settings changes remain Admin operations.

### System Diagnostics Workflow

1. Call `geoflow.system.status` after deployment or when a queue/task operation behaves unexpectedly.
2. Check `database.reachable`, `queue.driver`, `migrations.pending_count`, required PHP extension flags, and `mcp.audit_admin_configured`.
3. For a deployment-level write token, set `GEOFLOW_MCP_AUDIT_ADMIN_ID` so enterprise knowledge, lead status changes, URL imports, and other writes retain a concrete admin attribution. Article review/publish already reject a system token without it.
4. The tool intentionally excludes hosts, credentials, filesystem paths, raw exceptions, update plans, and shell execution.

## Deliberate Boundaries

The existing REST/Admin surfaces remain authoritative for binary image uploads, public site/channel management, distribution writes/packages, theme replication, lead capture, system updates, and direct model/prompt administration. URL import, tenant-scoped analytics, text-based enterprise knowledge, published site reads, and redacted distribution reads are exposed through the dedicated MCP contracts above; Admin HTML routes and arbitrary worker shell execution are not. Add another dedicated tool only after defining its tenant, permission, idempotency, and error contract.
