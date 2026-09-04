# CLAUDE.md

## Shared Deployment Infrastructure

- In every deployment change, Dockerfiles and Docker Compose files must not create, run, or embed PostgreSQL, Redis, or RabbitMQ services.
- Do not add service definitions, images, containers, initialization jobs, or persistent volumes for these dependencies.
- PostgreSQL, Redis, and RabbitMQ are centrally managed by `../docker-helm.dofe.ai`; application deployments must connect to those externally managed services through configuration.

## Commit Discipline

- Commit after completing each single functional change; do not accumulate or batch unrelated changes into one commit.
- Write all commit messages in Chinese and push each completed commit to the configured upstream branch before reporting completion.

## Knowledge Source Contract

- Article generation and GEO diagnostics must use `knowledge.dofe.ai` as their only knowledge source and must fail closed when Knowledge is unavailable.
- Local knowledge tables may support authoring or migration, but must never become a generation fallback or an independent source of truth.
