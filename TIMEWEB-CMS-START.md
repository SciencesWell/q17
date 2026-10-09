# Q17.tech: запуск Decap CMS на Timeweb

## Что должно быть в репозитории

Загрузите содержимое этого архива в корень репозитория `SciencesWell/q17` (а не сам ZIP). Основная ветка — `main`. Сохраните `content/`, `admin/`, `assets/`, HTML/PHP-файлы, `.htaccess`, `build-cms.py`, `requirements-cms.txt`, `scripts/` и `.github/workflows/`.

## 1. OAuth в GitHub

1. Откройте GitHub → Settings → Developer settings → OAuth Apps → New OAuth App.
2. Homepage URL: `https://q17.tech`.
3. Authorization callback URL: `https://q17.tech/callback`.
4. Создайте Client secret.
5. В файловом менеджере Timeweb разместите файл `q17-oauth-config.php` **на один уровень выше document root сайта** (обычно выше `public_html`). Возьмите шаблон `q17-oauth-config.php.example`, заполните `client_id` и `client_secret`, затем переименуйте его в `q17-oauth-config.php`. Не коммитьте настоящий секрет в GitHub и не размещайте его в `public_html`.
6. Убедитесь, что домен работает по HTTPS, на хостинге включён PHP с расширением cURL, а PHP-сессии разрешены.

OAuth-приложение не ограничивает само по себе доступ к админке. Вход разрешайте только пользователям, которым вы доверяете право записи в репозиторий.

## 2. Секреты GitHub Actions для автоматической публикации

В репозитории откройте Settings → Secrets and variables → Actions → New repository secret и добавьте:

- `TIMEWEB_FTP_SERVER` — FTP/FTPS-сервер из панели Timeweb.
- `TIMEWEB_FTP_USERNAME` — FTP-логин.
- `TIMEWEB_FTP_PASSWORD` — FTP-пароль.
- `TIMEWEB_FTP_SERVER_DIR` — абсолютная папка сайта на FTP, обычно `/public_html/`, но используйте точный путь из панели Timeweb.

Workflow настроен на FTPS по порту 21. Если Timeweb выдаёт иной сервер/порт или не поддерживает FTPS, скорректируйте параметры workflow по данным панели хостинга. Не вставляйте пароль прямо в YAML.

После добавления файлов в `main` откройте вкладку Actions. Первый запуск должен завершиться зелёным статусом. При каждом изменении `content/` сборка создаст HTML и автоматически загрузит сайт на Timeweb. Синхронизация не удаляет остальные файлы на сервере (`dangerous-clean-slate: false`).

## 3. Проверка после публикации

- `https://q17.tech/` — главная и тарифы.
- `https://q17.tech/blog.html` — карточки и фильтры блога.
- `https://q17.tech/admin/` — интерфейс CMS.
- `https://q17.tech/auth` — должен перенаправлять на GitHub для авторизации; не проверяйте URL с незаполненным конфигом.
- Создайте тестовую черновую статью и проверьте, что после сохранения workflow пересобрал и опубликовал HTML.

## Важно

GitHub CMS сохраняет контент в репозитории. Workflow выполняет сборку и FTP-публикацию только после того, как исходники и этот workflow действительно находятся в ветке `main`, а четыре секрета Timeweb настроены. Настоящий OAuth Client secret хранится только на сервере, вне публичной папки сайта.
