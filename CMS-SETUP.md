# Управление сайтом Q17.tech через Decap CMS (версия v22)

## Что добавлено
- `/admin/` — браузерная панель управления Decap CMS, интерфейс адаптирован под мобильный браузер самим Decap CMS.
- `content/blog/*.md` — исходники статей блога с заголовком, slug, категорией, датой, описанием, временем чтения и содержимым.
- `content/settings/pricing.json` — тарифы, цены и состав работ.
- `content/settings/site.json` — выбранные тексты главной страницы и блога.
- `build-cms.py` — генератор, который переносит данные CMS в существующие статические HTML-страницы и карточки блога.

## Важно про публикацию

Для автоматической публикации на Timeweb используйте `TIMEWEB-CMS-START.md` и workflow `.github/workflows/build-and-deploy-timeweb.yml`.
Сайт остаётся статическим HTML/CSS для SpaceWeb. CMS хранит изменения в GitHub, а `build-cms.py` генерирует обновлённые HTML-файлы. После изменений необходимо запускать сборку и выкладывать обновлённые файлы на SpaceWeb (либо настроить автоматическую FTP-публикацию через GitHub Actions и секреты хостинга).

## Авторизация GitHub на Timeweb
В архив добавлен PHP OAuth proxy (`oauth.php`) с маршрутами `/auth` и `/callback`; `.htaccess` направляет эти адреса в обработчик. В `admin/config.yml` уже указаны `base_url: https://q17.tech` и `auth_endpoint: auth`.

1. В GitHub откройте Settings → Developer settings → OAuth Apps → New OAuth App.
2. Homepage URL: `https://q17.tech`; Authorization callback URL: `https://q17.tech/callback`.
3. Скопируйте Client ID и создайте Client secret.
4. Скопируйте `q17-oauth-config.php.example` в файл `q17-oauth-config.php` на уровень выше каталога сайта (не внутрь `public_html`) и вставьте Client ID/secret. Файл с реальными ключами не загружайте в GitHub.
5. На Timeweb включите актуальную поддерживаемую версию PHP и расширение cURL, загрузите сайт с сохранением `.htaccess`, проверьте HTTPS.
6. В GitHub OAuth App разрешайте доступ только своему аккаунту/пользователям, которым действительно нужен доступ на запись в репозиторий. Decap GitHub backend требует права записи к репозиторию.

OAuth proxy реализует обмен кода на токен на сервере; секрет не попадает в HTML. До ввода Client ID/secret авторизация намеренно не работает.

**Критично:** OAuth не решает вопрос сборки и доставки статического сайта. CMS сохраняет Markdown/JSON в GitHub. После изменения контента необходимо выполнить `build-cms.py` и загрузить сгенерированные HTML-файлы на Timeweb. Автоматическая публикация выполняется workflow `.github/workflows/build-and-deploy-timeweb.yml` после настройки секретов Timeweb.

## Сборка
В каталоге сайта выполните:

```bash
python -m pip install -r requirements-cms.txt
python build-cms.py
```

Проверьте обновлённые `blog.html`, страницы статей, `index.html`, затем загрузите их на SpaceWeb. При добавлении новой статьи укажите slug латиницей и через дефисы; не используйте slug уже существующей статьи.

## Проверки
После сборки проверьте фильтры блога, ссылки карточек, мобильное отображение и цены на главной. Если меняете структуру HTML-шаблонов вручную, селекторы в `build-cms.py` могут потребовать обновления.
