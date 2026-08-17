.PHONY: up down restart logs shell test migrate fresh lint analyse queue

up: ## Start the whole stack
	docker compose up -d --build

down: ## Stop the stack and drop the volumes
	docker compose down -v

restart:
	docker compose restart app worker

logs:
	docker compose logs -f app worker

shell:
	docker compose exec app sh

test:
	docker compose exec app php artisan test

migrate:
	docker compose exec app php artisan migrate

fresh:
	docker compose exec app php artisan migrate:fresh

lint:
	docker compose exec app vendor/bin/pint

analyse:
	docker compose exec app vendor/bin/phpstan analyse

queue:
	docker compose logs -f worker
