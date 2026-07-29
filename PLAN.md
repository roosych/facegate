# План доработки ZKBio

## 1. Очереди и планировщик

### docker-compose.yml
- [ ] Добавить `healthcheck` к сервису `postgres` (`pg_isready`)
- [ ] Заменить текущий `queue` сервис на два воркера:
  - `queue-default` — обрабатывает `SyncTurnstileJob` + `PushTurnstileToDevicesJob`, `--tries=3 --backoff=30 --timeout=600`
  - `queue-sync-full` — обрабатывает `SyncAllJob`, `--tries=1 --timeout=3600`
- [ ] Добавить сервис `scheduler` (`php artisan schedule:work`)
- [ ] Все три сервиса — `depends_on: postgres: condition: service_healthy`

### Job-ы
- [ ] `SyncAllJob` — задать `public string $queue = 'sync-full'`
- [ ] `SyncTurnstileJob` — добавить `implements ShouldBeUnique` (уникальность по ID турникета)
- [ ] `SyncTurnstileJob` + `SyncHikvisionTerminalJob` — добавить `implements ShouldBeEncrypted`
- [ ] Запуск 15-минутного синха через `Bus::chain([SyncTurnstileJob, PushTurnstileToDevicesJob])`

### routes/console.php
- [ ] Ежедневно в 02:00 — `SyncAllJob` с `withoutOverlapping()` и `onFailure()`
- [ ] Каждые 15 минут — foreach по активным турникетам, chain Sync→Push, `withoutOverlapping(20)`
- [ ] Еженедельно — `queue:prune-failed --hours=72`

---

## 2. Устойчивость

### Модели
- [ ] Добавить поля `last_synced_at` и `last_error` на модель `Turnstile`
- [ ] Добавить поля `last_synced_at` и `last_error` на модель `HikvisionTerminal`
- [ ] Обновлять эти поля в методе `failed()` каждого job-а

### Повтор ежедневного синха
- [ ] Команда или механизм: если в 02:00 RusGuard был недоступен — повторить при первой успешной связи в окне 02:00–06:00

### Логирование
- [ ] Таблица `sync_runs`: `turnstile_id`, `started_at`, `finished_at`, `synced`, `errors`, `status`
- [ ] Писать запись после каждого прохода `SyncTurnstileJob`

---

## 3. Мониторинг и алерты

- [ ] Уведомление (email / Telegram) при падении критичных job-ов (`SyncAllJob`, `SyncTurnstileJob`)
- [ ] Health endpoint `GET /health` — проверяет: БД, очередь живая (последний job не старше N минут), RusGuard доступен
- [ ] Подключить UptimeRobot или аналог к `/health`
- [ ] Для `access_events` (Hikvision) — план по архивации или партиционированию (когда таблица вырастет)

---

## 4. Админка

### Терминалы / турникеты
- [ ] Страница списка с колонками: имя, IP, статус связи, `last_synced_at`, `last_error`
- [ ] Включить / выключить терминал (`is_active`)
- [ ] Кнопка "Проверить связь" (ping / test connection)
- [ ] Кнопка "Синхронизировать сейчас" — запускает цепочку Sync→Push для конкретного терминала

### Ручной запуск синха
- [ ] Кнопка "Синхронизировать все терминалы" — диспатчит chain для каждого активного
- [ ] Кнопка "Полный синх из RusGuard" — диспатчит `SyncAllJob`

### Failed jobs
- [ ] Страница с упавшими задачами: job, ошибка, время
- [ ] Кнопка "Повторить" (queue:retry)
- [ ] Кнопка "Удалить"

### История синхронизаций
- [ ] Список из таблицы `sync_runs` с фильтром по терминалу и дате
- [ ] Статистика: успешно / с ошибками / пропущено
