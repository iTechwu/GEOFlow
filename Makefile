.PHONY: dev dev-init dev-up dev-down dev-logs dev-shell check-boundaries test test-boundaries test-mcp-smoke

dev: .env.local
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local up --build

dev-init: .env.local
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local build app
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local run --rm --no-deps -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true -e AUTO_MIGRATE=false -e AUTO_INIT_ONCE=false -e AUTO_INSTALL_ONCE=false app php artisan migrate --force --no-interaction
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local run --rm --no-deps -e GEOFLOW_SECURITY_FRESH_INSTALL_CONFIRMED=true -e AUTO_MIGRATE=false -e AUTO_INIT_ONCE=false -e AUTO_INSTALL_ONCE=false app php artisan geoflow:install --no-interaction

dev-up: .env.local
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local up --build -d

dev-down:
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local down

dev-logs:
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local logs -f --tail=200

dev-shell:
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local exec app sh

.env.local:
	cp .env.example .env.local

# 校验 Compose 未内嵌 PostgreSQL/Redis/RabbitMQ 服务（见 AGENTS.md）
check-boundaries:
	bash deploy-scripts/check-compose-boundaries.sh

test-boundaries:
	bash deploy-scripts/check-compose-boundaries.test.sh

# 运行 PHPUnit（需本地 php + vendor）
test:
	php artisan test

# 无需真实 Docker，验证 MCP smoke 的协议、失败和清理路径
test-mcp-smoke:
	npm run test:mcp-smoke
