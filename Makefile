.PHONY: init up install down logs lint

init:
	python3 scripts/init-env.py

up: init
	docker compose up -d --wait

install: up
	docker compose run --rm wpcli wp eval-file /project-scripts/bootstrap.php --skip-wordpress

down:
	docker compose down

logs:
	docker compose logs --tail=100 -f

lint:
	docker compose exec -T wordpress php -l wp-content/plugins/wordpress-quiz/wordpress-quiz.php
	docker compose run --rm --entrypoint php wpcli -l /project-scripts/bootstrap.php
