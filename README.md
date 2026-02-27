# CBR Currency API

**API курсов ЦБ РФ: кэш (Redis), очередь (Redis), опциональное хранение в БД.**

---

## Быстрый старт (проверить за 2 минуты)

1. `docker-compose up -d`
2. `docker-compose exec app php artisan key:generate` (если ещё не делали)
3. `docker-compose exec app php artisan migrate`
4. `docker-compose exec app php artisan app:sync-currency-history USD --days=180`
5. Подождать ~30 сек, пока воркер обработает задачи
6. `curl "http://localhost:8080/api/rates?date=2025-02-20&currency_code=USD"`

Ожидается JSON с полями `rate`, `previous_trade_date`, `delta`.

---

## Описание

Проект — **только backend API** (без фронтенда). Загружает ежедневные курсы из XML API ЦБ РФ, кэширует ответы в Redis и может сохранять курсы в PostgreSQL. Очередь обрабатывает задачи синхронизации. Основной эндпоинт возвращает курс на дату, предыдущий торговый день и **дельту** (изменение к предыдущему дню).

---

## Требования

- **Docker** и **Docker Compose**

Скопируйте `.env.example` в `.env` и задайте минимум `APP_KEY` (и учётные данные БД при необходимости). Для локальной разработки без CA bundle можно временно задать `CBR_VERIFY_SSL=false` в `.env`.

---

## Запуск через Docker

Запуск всех сервисов (app, nginx, postgres, redis, worker):

```bash
docker-compose up -d
```

API доступен по адресу **http://localhost:8080** (nginx проксирует на php-fpm).

---

## Миграции

Выполните миграции в контейнере приложения:

```bash
docker-compose exec app php artisan migrate
```

---

## Синхронизация истории курсов

Чтобы заполнить БД (и кэш) историческими курсами, выполните команду синхронизации. Она **ставит в очередь** задачи по дням; контейнер **worker** их обрабатывает.

Пример для USD за последние 180 дней:

```bash
docker-compose exec app php artisan app:sync-currency-history USD --days=180
```

Можно указать другой код валюты (например EUR) и изменить `--days`.

---

## API: GET /api/rates

**Пример запроса:**

```http
GET /api/rates?date=2025-02-20&currency_code=USD&base_currency_code=RUR
```

**Параметры запроса:**

| Параметр              | Обязательный | Описание                                           |
|-----------------------|--------------|----------------------------------------------------|
| `date`                | да           | Дата в формате `Y-m-d` (например 2025-02-20)       |
| `currency_code`       | да           | Код валюты (например USD, EUR)                     |
| `base_currency_code`  | нет          | Базовая валюта; по умолчанию `RUR` (поддерживается только RUR) |

**Пример ответа:**

```json
{
  "date": "2025-02-20",
  "currency_code": "USD",
  "base_currency_code": "RUR",
  "rate": 98.1234,
  "previous_trade_date": "2025-02-19",
  "delta": 0.5678
}
```

- **rate** — курс на запрошенную дату (из БД или ЦБ с кэшем).
- **previous_trade_date** — последняя дата до запрошенной, по которой есть курс (предыдущий торговый день).
- **delta** — разница между текущим курсом и курсом предыдущего торгового дня (`rate − previous_rate`).

Если курс на запрошенную дату не найден, API возвращает **404**. Проверка здоровья: **GET /up**.

---

## Архитектура

Запрос к **GET /api/rates** обрабатывается контроллером `RatesController`; вход проверяется через Form Request `GetRatesRequest`. Данные берутся из БД (таблица `currency_rates`) или, при отсутствии, из API ЦБ РФ через `CbrClient` с кэшем Redis (ключ по дате). Поиск предыдущего торгового дня выполняется одним запросом к БД по индексу; при отсутствии данных в БД — перебор дней назад с обращением к ЦБ (кэш). Контракт `CbrClientInterface` позволяет подменять источник курсов в тестах и при смене провайдера.

Синхронизация истории вынесена в команду `app:sync-currency-history`: она ставит в очередь Job'ы `FetchCurrencyRateJob` по одному на день; воркер (отдельный контейнер) обрабатывает их и сохраняет курсы в БД идемпотентно (`updateOrCreate` по дате, валюте и базе). Инфраструктура: **app** (PHP-FPM), **nginx**, **postgres**, **redis**, **worker** (queue:work); все сервисы в одной Docker-сети.

---

## Проверка

Чтобы убедиться, что всё работает, используйте Docker (приложение рассчитано на PostgreSQL и Redis; запуск `php artisan serve` локально без них приведёт к ошибке).

1. **Запуск:** `docker-compose up -d`

2. **Миграции:** `docker-compose exec app php artisan migrate`

3. **Синхронизация данных (по желанию, но рекомендуется):**  
   `docker-compose exec app php artisan app:sync-currency-history USD --days=30`  
   Подождите 30–60 секунд, пока воркер обработает задачи.

4. **Проверка здоровья:** `curl http://localhost:8080/up` — в ответе должно быть `OK`.

5. **API курсов:**  
   `curl "http://localhost:8080/api/rates?date=2025-02-20&currency_code=USD&base_currency_code=RUR"`  
   Ожидается JSON с полями `rate`, `previous_trade_date`, `delta`.

---

## Тесты и CI

Локально: `php artisan test` (нужны PHP 8.2+ и расширения из `composer.json`; БД и очередь в тестах — SQLite in-memory и sync). Форматирование кода: `composer run lint`. Статический анализ: `composer run stan`.

В репозитории настроен **GitHub Actions**: на каждый push и pull request в `main`/`master` запускаются Pint (проверка стиля), PHPStan и тесты (`.github/workflows/tests.yml`). Окружение: PHP 8.4, без Docker.

---

## Перед коммитом: 

Чтобы локально прогнать **то же самое**, что делает CI, используйте контейнер **app** (там уже есть PHP и зависимости из `composer.lock`). Так вы избежите расхождений «у меня проходит, в CI падает».

```bash
docker-compose up -d
docker-compose exec app vendor/bin/pint --test
docker-compose exec app vendor/bin/pint
docker-compose exec app vendor/bin/phpstan analyse --memory-limit=512M
docker-compose exec app php artisan test
```
