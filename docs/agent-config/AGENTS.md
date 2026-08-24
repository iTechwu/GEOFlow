# geo.dofe.ai Agent Instructions

## Test Resource Limits

For pnpm, Turbo, and Jest monorepos, the root `test` script must use `turbo run test --concurrency=2`, and the API package `test` script must use `jest --passWithNoTests --maxWorkers=2`.

- Do not run an unconstrained `pnpm test` command; prefer the smallest relevant test.
- For a single API Jest file, use `pnpm --filter @repo/api exec jest path/to/file.spec.ts --runInBand`.
- A full test run must pass `--maxWorkers=2`, for example `pnpm test -- --maxWorkers=2`.

## Shared Deployment Infrastructure

- In every deployment change, Dockerfiles and Docker Compose files must not create, run, or embed PostgreSQL, Redis, or RabbitMQ services.
- Do not add service definitions, images, containers, initialization jobs, or persistent volumes for these dependencies.
- PostgreSQL, Redis, and RabbitMQ are centrally managed by `../docker-helm.dofe.ai`; application deployments must connect to those externally managed services through configuration.

Laravel Boost support is installed for this repository.

Before making Laravel, PHP, Tailwind, Horizon, or AI SDK changes, read:

`../../.boost/guidelines.md`

The Boost MCP server is configured in:

`../../.mcp.json`

Tool-specific configuration files are kept at their default discovery paths in the repository root or hidden tool folders.

## Distribution Channel Deletion Safety

- Run channel-scoped remote calls and credential/package exports through `DistributionChannelOperationLeaseService` so final deletion can detect in-flight work.
- Lock the distribution channel row before channel writes, task-channel binding changes, queue claims, retries, or immediate distribution actions, and reject work while the channel status is `deleting`.
- Keep final deletion behind the two-step impact review. Recompute and verify the impact fingerprint inside the locked deletion transaction before removing local data.
