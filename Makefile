.PHONY: dev dev-up dev-down dev-logs dev-shell

dev: .env.local
	GEOFLOW_ENV_FILE=.env.local docker compose --env-file .env.local up --build

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
