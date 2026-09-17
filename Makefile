.PHONY: init up install demo down logs lint

init:
	python3 scripts/init-env.py

up: init
	docker compose up -d --wait

install: up
	docker compose run --rm wpcli wp eval-file /project-scripts/bootstrap.php --skip-wordpress
	docker compose run --rm wpcli wp language core install ru_RU --activate

demo: install
	docker compose run --rm wpcli wp eval-file /project-scripts/seed-demo.php

down:
	docker compose down

logs:
	docker compose logs --tail=100 -f

lint:
	docker compose exec -T wordpress sh -c 'find wp-content/plugins/wordpress-quiz -name "*.php" -exec php -l {} \;'
	docker compose run --rm --entrypoint php wpcli -l /project-scripts/bootstrap.php
