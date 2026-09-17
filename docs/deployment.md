# Развёртывание демонстрации

Сервер Ubuntu 24.04, Docker Compose, Nginx и сертификат Let's Encrypt.
Репозиторий клонируется в `/opt/wordpress-quiz`. Порт 8088 слушает только localhost;
MariaDB и CRM не публикуют порты. Снаружи Nginx обслуживает HTTPS и проксирует WordPress.

1. Выполнить `python3 scripts/init-env.py` на сервере для новых секретов.
2. В `.env` задать `WP_URL=https://test-wordpress-quiz.duckdns.org`,
   `WP_ENVIRONMENT_TYPE=production`, сохранить права 600.
3. Запустить `docker compose up -d --wait`.
4. До подключения публичного proxy выполнить:

```sh
docker compose run --rm -e WPQ_ALLOW_DEMO_SETUP=1 wpcli wp eval-file /project-scripts/bootstrap.php --skip-wordpress
docker compose run --rm wpcli wp language core install ru_RU --activate
docker compose run --rm -e WPQ_ALLOW_DEMO_SETUP=1 wpcli wp eval-file /project-scripts/seed-demo.php
```

5. Настроить Nginx с `proxy_pass http://127.0.0.1:8088`, исходным Host и
   `X-Forwarded-Proto $scheme`, перенаправлением HTTP на HTTPS. Проверить `nginx -t`.
6. Проверить HTTPS, форму, сохранение и доставку заявки, язык админки.

Использовать отдельные серверные пароли. Не коммитить `.env`, SQL-дампы и заявки.
`make demo` предназначен для локальной среды: production требует явного CLI opt-in.
Перед обновлением проверить изменения и возможность отката; сначала обновить код,
затем при необходимости контейнеры. Изменения образов требуют повторной проверки.
Оплачиваемые резервные копии отключены по запросу владельца.
