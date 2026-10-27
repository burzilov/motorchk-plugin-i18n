<?php

/**
 * Тесты плагина i18n (без PHPUnit):
 *   php tests/run.php [путь/к/core]
 * Путь к ядру: аргумент, переменная MOTORCHK_CORE или ../motorchk/public_html/core рядом с репозиторием плагинов.
 *
 * Создаёт временный сайт (skeleton ядра ≥ 0.5 + этот плагин) в sys_get_temp_dir() и проверяет
 * установку, службу, определение языка, адреса, переводы страниц, hreflang, переключатель,
 * подписи меню, админку (настройки, словарь, импорт/экспорт) и поведение при повреждённых файлах.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pluginRoot = dirname(__DIR__);
$corePath = $argv[1] ?? (getenv('MOTORCHK_CORE') ?: dirname($pluginRoot, 2) . '/motorchk/public_html/core');
$corePath = realpath($corePath) ?: $corePath;
if (!is_file($corePath . '/includes/SiteLocale.php')) {
    fwrite(STDERR, "Не найдено ядро motorchk ≥ 0.5 в {$corePath}. Укажите путь: php tests/run.php /path/to/core\n");
    exit(2);
}
require_once $corePath . '/vendor/autoload.php';
spl_autoload_register(static function (string $class) use ($corePath): void {
    $file = $corePath . '/includes/' . $class . '.php';
    if (is_file($file)) {
        require $file;
    }
});

ob_start();
Env::set('APP_SECRET', 'i18n-plugin-test-secret');
$failures = 0;
$passed = 0;

function out(string $text): void
{
    fwrite(STDOUT, $text);
}

function check(bool $ok, string $name, string $details = ''): void
{
    global $failures, $passed;
    if ($ok) {
        $passed++;
        out("  ok   {$name}\n");
        return;
    }
    $failures++;
    out("  FAIL {$name}" . ($details !== '' ? " — {$details}" : '') . "\n");
}

function t_copy(string $from, string $to, array $skip = []): void
{
    if (is_dir($from)) {
        @mkdir($to, 0775, true);
        foreach (scandir($from) ?: [] as $item) {
            if ($item !== '.' && $item !== '..' && !in_array($item, $skip, true)) {
                t_copy($from . '/' . $item, $to . '/' . $item);
            }
        }
        return;
    }
    copy($from, $to);
}

function t_rm(string $path): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $item) {
        if ($item !== '.' && $item !== '..') {
            t_rm($path . '/' . $item);
        }
    }
    rmdir($path);
}

function t_page(string $title, string $body, array $extra = []): string
{
    $fm = $extra + ['title' => $title, 'published' => 'true', 'template' => 'default', 'description' => "''", 'og_title' => "''", 'og_description' => "''"];
    $yaml = '';
    foreach ($fm as $k => $v) {
        $yaml .= $k . ': ' . $v . "\n";
    }

    return "---\n{$yaml}---\n\n## block: content\n\n{$body}\n";
}

/** @return array{0: int, 1: string} */
function t_capture(callable $fn): array
{
    http_response_code(200);
    ob_start();
    try {
        $fn();
    } finally {
        $body = (string) ob_get_clean();
    }

    return [http_response_code(), $body];
}

$site = sys_get_temp_dir() . '/i18n-plugin-test-' . getmypid();
t_rm($site);
mkdir($site, 0775, true);
foreach (['content', 'themes', 'config'] as $dir) {
    t_copy($corePath . '/skeleton/' . $dir, $site . '/' . $dir);
}
@mkdir($site . '/cache', 0775, true);
file_put_contents($site . '/cache/update-check.json', json_encode(['checked_at' => time() + 3600, 'current' => Version::current(), 'update' => null]));
file_put_contents($site . '/content/about.md', t_page('О нас', "Русский текст. [Команда](/team) и [внешняя](https://example.com/x)\n\n<!--i18n:switcher-->"));
file_put_contents($site . '/content/about.en.md', t_page('About us', 'English text. [Team](/team), [admin](/admin-panel/), [file](/plugin-assets/i18n/switcher.css)'));
file_put_contents($site . '/content/team.md', t_page('Команда', 'Русский текст команды.'));
file_put_contents($site . '/content/_menus.yaml', "menus:\n  main:\n    label: Основное\n    items:\n      - label: Главная\n        slug: index\n      - slug: about\n      - slug: team\n");
t_copy($pluginRoot, $site . '/plugins/i18n', ['.git', 'tests', 'dist', 'release.sh']);
$config = [
    'site_path' => $site, 'core_path' => $corePath, 'base_path' => $site,
    'content_path' => $site . '/content', 'cache_path' => $site . '/cache', 'themes_path' => $site . '/themes',
    'forms_path' => $site . '/content/forms', 'submissions_path' => $site . '/submissions',
    'admin_templates_path' => $corePath . '/admin-panel/templates', 'menu_file' => $site . '/content/_menus.yaml',
    'skeleton_path' => $corePath . '/skeleton', 'plugins_path' => $site . '/plugins',
    'plugin_data_path' => $site . '/plugin-data', 'installed' => true,
];
$data = $site . '/plugin-data/i18n';
$_SESSION = [];
$_GET = $_POST = $_FILES = $_COOKIE = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['HTTP_HOST'] = 'example.test';
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

/** Публичный запрос: [status, body]; $get — $_GET, $cookie — cookie site_locale, $accept — Accept-Language */
$public = static function (string $uri, array $get = [], ?string $cookie = null, ?string $accept = null) use ($config): array {
    $_GET = $get;
    $_COOKIE = $cookie === null ? [] : ['site_locale' => $cookie];
    if ($accept === null) {
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
    } else {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $accept;
    }
    I18n\Hooks::$cookieLog = [];
    $r = t_capture(fn() => (new Router($config))->dispatchPublic($uri));
    $_GET = [];

    return $r;
};
$cookies = static fn(): array => array_column(I18n\Hooks::$cookieLog ?? [], 'value');

out('i18n plugin tests (core v' . Version::current() . ', plugin v' . json_decode((string) file_get_contents($pluginRoot . '/plugin.json'), true)['version'] . ")\n");

// ---------------------------------------------------------------- установка
out("\n[манифест и установка]\n");
$manager = new PluginManager($config);
$info = $manager->discover()['i18n'] ?? null;
check($info !== null && $info['status'] === 'ok', 'манифест валиден, совместим с ядром', json_encode($info['errors'] ?? null, JSON_UNESCAPED_UNICODE));
check(($info['provides'] ?? null) === ['i18n'] && ($manager->adminMenu()[0]['label'] ?? null) === 'Языки', 'служба i18n и пункт меню «Языки»');
$ctx = $manager->boot('i18n');
I18n\Hooks::$cookieLog = []; // перехват setcookie() (класс доступен после загрузки плагина)
check($ctx instanceof PluginContext, 'плагин загружается');
$settingsFile = json_decode((string) @file_get_contents($data . '/settings.json'), true);
check(($settingsFile['default'] ?? null) === 'ru' && array_column($settingsFile['locales'] ?? [], 'code') === ['ru', 'en'] && $settingsFile['accept_language'] === false && $settingsFile['remember'] === true, 'install: ru (основной) + en, Accept-Language выключен');
check(json_decode((string) file_get_contents($data . '/dictionary.json'), true)['i18n.switcher']['en'] === 'Language', 'install: стартовый словарь');
file_put_contents($data . '/settings.json', json_encode(['locales' => [['code' => 'ru', 'name' => 'Русский'], ['code' => 'en', 'name' => 'English'], ['code' => 'de', 'name' => 'Deutsch']]] + $settingsFile));
$m2 = new PluginManager($config);
$m2->boot('i18n');
check(count(json_decode((string) file_get_contents($data . '/settings.json'), true)['locales']) === 3, 'повторная загрузка не перезаписывает настройки');

// ---------------------------------------------------------------- служба
out("\n[служба]\n");
$svc = (new PluginManager($config))->service('i18n');
check($svc instanceof I18n\Service && $svc instanceof LocaleProvider, 'service(i18n) реализует LocaleProvider ядра');
check($svc->locales() === ['ru', 'en', 'de'] && $svc->defaultLocale() === 'ru' && $svc->names()['de'] === 'Deutsch', 'locales(), defaultLocale(), names()');
check($svc->url('/about', 'en') === '/en/about' && $svc->url('/', 'en') === '/en/' && $svc->url('/en/about', 'ru') === '/about' && $svc->url('/en/about', 'de') === '/de/about', 'url(): префикс языка, у основного — без префикса, идемпотентно');
check($svc->url('/about?x=1#top', 'en') === '/en/about?x=1#top' && $svc->url('https://example.com/', 'en') === 'https://example.com/' && $svc->url('#a', 'en') === '#a' && $svc->url('//cdn.test/x', 'en') === '//cdn.test/x', 'url(): query и якорь сохраняются, внешние адреса без изменений');
check($svc->url('/admin-panel/', 'en') === '/admin-panel/' && $svc->url('/plugin-assets/x/a.css', 'en') === '/plugin-assets/x/a.css' && $svc->url('/api/forms/submit', 'en') === '/api/forms/submit' && $svc->url('/english', 'en') === '/en/english', 'url(): служебные адреса ядра не меняются, /english — не префикс');
check($svc->url('/about', 'xx') === '/about' && $svc->prefixLocale('/fr/about') === null && $svc->stripPrefix('/de') === '/', 'неизвестный язык — без префикса');
check($svc->t('i18n.switcher', null, 'en') === 'Language' && $svc->t('i18n.switcher', null, 'de') === 'Язык' && $svc->t('nope', 'Запасной') === 'Запасной' && $svc->t('nope') === 'nope', 't(): язык → основной → fallback → ключ');
SiteLocale::init(new PluginManager($config), false);
check(SiteLocale::localized(['ru' => 'Ловушка', 'en' => 'Catcher'], 'en') === 'Catcher' && SiteLocale::localized(['ru' => 'Ловушка', 'en' => ''], 'en') === 'Ловушка' && SiteLocale::localized(['en' => 'Only'], 'de') === 'Only' && SiteLocale::localized([], 'en') === null && !method_exists($svc, 'localized'), 'значение по языку — SiteLocale::localized() ядра со службой (своей копии в службе нет)');
SiteLocale::reset();
check($svc->locale() === 'ru', 'locale() вне запроса — основной язык');
file_put_contents($data . '/settings.json', json_encode($settingsFile));

// ---------------------------------------------------------------- определение языка
out("\n[определение языка]\n");
$hooks = new I18n\Hooks($ctx, $s = (new PluginManager($config))->service('i18n'), new I18n\Settings($ctx), new I18n\Dictionary($ctx));
$d = $hooks->decide('GET', '/en/about', [], null, null);
check($d['locale'] === 'en' && $d['path'] === '/about' && $d['redirect'] === null && $d['cookie'] === 'en', 'префикс /en/ → язык en, путь /about, cookie запоминает выбор');
$d = $hooks->decide('GET', '/about', [], null, null);
check($d['locale'] === 'ru' && $d['path'] === '/about' && $d['redirect'] === null && $d['cookie'] === null, 'без префикса — основной язык, cookie не ставится');
check($hooks->decide('GET', '/ru/about', ['a' => 'b'], null, null)['redirect'] === '/about?a=b' && $hooks->decide('GET', '/ru/about', [], null, null)['status'] === 301, '/ru/about → 301 /about (у основного языка префикса нет)');
check($hooks->decide('GET', '/en', [], null, null)['redirect'] === '/en/', '/en → 301 /en/');
check($hooks->decide('GET', '/', [], 'en', null)['redirect'] === '/en/' && $hooks->decide('GET', '/about', [], 'en', null)['redirect'] === null, 'cookie en: главная → /en/, остальные страницы — без перенаправления');
check($hooks->decide('GET', '/about', [], 'en', null)['cookie'] === 'ru', 'страница основного языка обновляет cookie');
check($hooks->decide('GET', '/', [], null, 'en-US,en;q=0.9')['redirect'] === null, 'Accept-Language выключен по умолчанию');
$d = $hooks->decide('GET', '/', ['lang' => 'ru'], 'en', null);
check($d['redirect'] === '/' && $d['cookie'] === 'ru', '/?lang=ru — запоминает ru и открывает главную (ссылка переключателя)');
check($hooks->decide('GET', '/customizer/razor', ['lang' => 'en', 'x' => '1'], null, null)['redirect'] === '/en/customizer/razor?x=1', '?lang=en на любой странице → /en/… (старые ссылки ?lang)');
check($hooks->decide('POST', '/', ['lang' => 'en'], 'en', null)['redirect'] === null, 'POST не перенаправляется');
check($hooks->decide('GET', '/fr/about', [], null, null)['path'] === '/fr/about', 'неизвестный префикс не трогается');
check(I18n\Hooks::negotiate('de-DE,en-US;q=0.8,ru;q=0.5', ['ru', 'en']) === 'en' && I18n\Hooks::negotiate('fr', ['ru', 'en']) === null && I18n\Hooks::negotiate('ru;q=0.1, en;q=0.9', ['ru', 'en']) === 'en' && I18n\Hooks::negotiate('*', ['ru']) === null, 'разбор Accept-Language: q-веса, основной тег');
$saved = $settingsFile;
file_put_contents($data . '/settings.json', json_encode(['accept_language' => true] + $settingsFile));
$hooks2 = new I18n\Hooks($ctx, $s, new I18n\Settings($ctx), new I18n\Dictionary($ctx));
$d = $hooks2->decide('GET', '/', [], null, 'en-US,en;q=0.9,ru;q=0.5');
check($d['redirect'] === '/en/' && $d['cookie'] === 'en', 'Accept-Language включён: первый визит на главную → /en/ и cookie');
$d = $hooks2->decide('GET', '/', [], null, 'ru-RU');
check($d['redirect'] === null && $d['cookie'] === 'ru', 'язык браузера = основной: без перенаправления, cookie чтобы не определять снова');
check($hooks2->decide('GET', '/', [], 'ru', 'en')['redirect'] === null && $hooks2->decide('GET', '/about', [], null, 'en')['redirect'] === null, 'Accept-Language — только первый визит и только главная');
file_put_contents($data . '/settings.json', json_encode($saved));

// ---------------------------------------------------------------- страницы сайта
out("\n[страницы сайта]\n");
[$st, $ru] = $public('/about');
check($st === 200 && str_contains($ru, 'Русский текст.') && str_contains($ru, '<html lang="ru">'), '/about — оригинал, lang=ru');
check(str_contains($ru, 'hreflang="ru" href="http://example.test/about"') && str_contains($ru, 'hreflang="en" href="http://example.test/en/about"') && str_contains($ru, 'hreflang="x-default" href="http://example.test/about"'), 'hreflang ru/en/x-default (перевод есть)');
check(str_contains($ru, '/plugin-assets/i18n/switcher.css?v=') && substr_count($ru, 'data-i18n-switcher') === 1 && !str_contains($ru, 'i18n-switcher--floating') && !str_contains($ru, '<!--i18n:switcher-->'), 'переключатель вместо <!--i18n:switcher--> в тексте, плавающий не добавляется, CSS в <head>');
check(str_contains($ru, '<a href="/en/about" hreflang="en" lang="en">English</a>') && str_contains($ru, 'href="/about" hreflang="ru" lang="ru" aria-current="true"'), 'ссылки переключателя на текущую страницу');
check(str_contains($ru, 'href="/team"') && $cookies() === [], 'основной язык: ссылки без изменений, cookie нет');
[$st, $en] = $public('/en/about');
check($st === 200 && str_contains($en, 'English text.') && str_contains($en, '<html lang="en">') && str_contains($en, '<title>About us</title>'), '/en/about — перевод, lang=en');
check(str_contains($en, 'href="/en/team"') && str_contains($en, 'href="/admin-panel/"') && str_contains($en, 'href="/plugin-assets/i18n/switcher.css"'), 'ссылки в тексте перевода → /en/…, служебные — без изменений');
check(str_contains($en, '>Home</a>') === false && str_contains($en, 'href="/en/"') && str_contains($en, '>About us</a>'), 'меню: ссылки /en/, подпись из перевода страницы');
check(str_contains($en, 'i18n-switcher--floating i18n-switcher--bottom-right') && str_contains($en, '<a href="/about" hreflang="ru" lang="ru">Русский</a>') && str_contains($en, 'aria-label="Language"'), 'плавающий переключатель на странице без вставки, подпись из словаря');
check($cookies() === ['en'], 'cookie site_locale=en');
[$st, $team] = $public('/en/team');
check($st === 200 && str_contains($team, 'Русский текст команды.') && str_contains($team, '<html lang="ru">') && str_contains($team, '<link rel="canonical" href="http://example.test/team">') && !str_contains($team, 'hreflang="en" href'), '/en/team без перевода — оригинал, canonical, без hreflang');
check(str_contains($team, 'aria-current="true" class="is-current">English</a>'), 'на странице без перевода переключатель показывает язык запроса');
[$st] = $public('/about.en');
check($st === 404, 'файл перевода не открывается как /about.en');
[$st, $body] = $public('/en/nope');
check($st === 404 && str_contains($body, 'lang="en"'), '/en/nope — 404');
[$st, $body] = $public('/ru/about');
check($st === 301 && $body === '', '/ru/about → 301');
[$st] = $public('/', [], 'en');
check($st === 302, 'главная с cookie en → 302');
[$st, $home] = $public('/en/');
check($st === 200 && str_contains($home, '<a href="/?lang=ru" hreflang="ru" lang="ru">Русский</a>'), '/en/: ссылка на русскую главную с ?lang=ru (сброс запомненного языка)');

// подписи меню из словаря
file_put_contents($data . '/dictionary.json', json_encode(['i18n.switcher' => ['ru' => 'Язык', 'en' => 'Language'], 'Главная' => ['en' => 'Home'], 'menu.team' => ['en' => 'Our team']], JSON_UNESCAPED_UNICODE));
[, $en] = $public('/en/about');
check(str_contains($en, '>Home</a>') && str_contains($en, '>Our team</a>'), 'меню: подпись по ключу-подписи («Главная») и по menu.{slug}');
[, $ru] = $public('/about');
check(str_contains($ru, '>Главная</a>') && str_contains($ru, '>Команда</a>'), 'меню на основном языке не меняется');

// ручной режим и выключенное переписывание
file_put_contents($data . '/settings.json', json_encode(['switcher' => 'manual', 'rewrite_links' => false] + $settingsFile));
[, $en] = $public('/en/about');
check(!str_contains($en, 'data-i18n-switcher') && str_contains($en, 'href="/team"') && str_contains($en, 'href="/en/"'), 'ручной режим: без плавающего переключателя; без переписывания ссылок в тексте (меню — по-прежнему /en/)');
file_put_contents($data . '/settings.json', json_encode(['locales' => [['code' => 'ru', 'name' => 'Русский']]] + $settingsFile));
[, $ru] = $public('/about');
check(!str_contains($ru, 'data-i18n-switcher') && !str_contains($ru, 'hreflang') && str_contains($ru, 'Русский текст'), 'один язык: ни переключателя, ни hreflang');
[$st] = $public('/en/about');
check($st === 404, 'один язык: /en/… — 404');
file_put_contents($data . '/settings.json', json_encode($settingsFile));

// повреждённые файлы
file_put_contents($data . '/dictionary.json', '"строка"');
[$st, $en] = $public('/en/about');
check($st === 200 && str_contains($en, 'aria-label="Язык"') && file_get_contents($data . '/dictionary.json') === '"строка"', 'словарь не объект: сайт работает, файл не перезаписан');
file_put_contents($data . '/dictionary.json', '{broken');
[$st, $en] = $public('/en/about');
check($st === 200 && str_contains($en, 'aria-label="Язык"') && file_get_contents($data . '/dictionary.json') === '{broken', 'повреждённый словарь: сайт работает, файл не перезаписан');
file_put_contents($data . '/settings.json', '{broken');
[$st, $en] = $public('/en/about');
check($st === 200 && str_contains($en, 'English text.') && file_get_contents($data . '/settings.json') === '{broken', 'повреждённые настройки: настройки по умолчанию, файл не перезаписан');

// ---------------------------------------------------------------- админка
out("\n[админка]\n");
$_SESSION = ['admin_authenticated' => true, 'csrf_token' => 'tok'];
$admin = static fn(string $uri): array => t_capture(fn() => (new AdminRouter($config))->dispatchAdmin($uri));
$call = static function (string $method, string $path, array $post = []) use ($config): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = $method === 'POST' ? $post + ['_csrf' => 'tok'] : [];
    try {
        return t_capture(fn() => (new AdminRouter($config))->dispatchAdmin('/admin-panel/plugins/i18n' . rtrim($path, '/')));
    } finally {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
    }
};
[$st, $body] = $admin('/admin-panel/plugins/i18n');
check($st === 200 && str_contains($body, 'Файл plugin-data/i18n/settings.json повреждён (не JSON') && str_contains($body, 'name="default" value="ru"'), 'страница «Языки»: предупреждение о повреждённом файле, форма с настройками по умолчанию');
check(!str_contains($body, 'data-i18n-switcher') && !str_contains($body, 'switcher.css') && !str_contains($body, 'rel="alternate"'), 'хуки сайта на админку не действуют');
[$st, $body] = $call('POST', '/', ['locales' => [['code' => 'ru', 'name' => 'Русский'], ['code' => 'EN', 'name' => 'English'], ['code' => 'de', 'name' => 'Deutsch', 'remove' => '1'], ['code' => 'pt-br', 'name' => '']], 'default' => 'ru', 'remember' => '1', 'switcher' => 'auto', 'switcher_position' => 'top-left', 'rewrite_links' => '1']);
$saved = json_decode((string) file_get_contents($data . '/settings.json'), true);
check($st === 302 && array_column($saved['locales'], 'code') === ['ru', 'en', 'pt-br'] && $saved['locales'][2]['name'] === 'pt-br' && $saved['switcher_position'] === 'top-left' && $saved['accept_language'] === false, 'сохранение: код в нижний регистр, «убрать», пустое название = код, флажки');
[$st, $body] = $call('POST', '/', ['locales' => [['code' => 'ru', 'name' => 'Русский'], ['code' => 'ru', 'name' => 'x'], ['code' => 'Русский', 'name' => '']], 'default' => 'en', 'switcher' => 'auto', 'switcher_position' => 'top-left']);
check($st === 422 && str_contains($body, 'указан дважды') && str_contains($body, 'должен быть вида') && str_contains($body, 'Основной язык должен быть в списке'), 'ошибки валидации → 422, файл не меняется');
check(array_column(json_decode((string) file_get_contents($data . '/settings.json'), true)['locales'], 'code') === ['ru', 'en', 'pt-br'], 'после ошибки настройки прежние');
$call('POST', '/', ['locales' => [['code' => 'ru', 'name' => 'Русский'], ['code' => 'en', 'name' => 'English']], 'default' => 'ru', 'remember' => '1', 'switcher' => 'auto', 'switcher_position' => 'bottom-right', 'rewrite_links' => '1']);

[$st, $body] = $admin('/admin-panel/plugins/i18n/dictionary');
check($st === 200 && str_contains($body, 'Файл plugin-data/i18n/dictionary.json повреждён (не JSON'), 'словарь: предупреждение о повреждённом файле');
[$st, $body] = $call('POST', '/import', ['json' => '{"a": {"ru": "А"}}', 'mode' => 'merge']);
check($st === 422 && str_contains($body, 'Заменить словарь целиком') && file_get_contents($data . '/dictionary.json') === '{broken', 'импорт с добавлением в повреждённый словарь запрещён');
[$st] = $call('POST', '/import', ['json' => json_encode(['format' => 'motorchk-i18n-dictionary', 'version' => 1, 'strings' => ['cart.add' => ['ru' => 'В корзину', 'en' => 'Add to cart'], 'Главная' => ['en' => 'Home']]], JSON_UNESCAPED_UNICODE), 'mode' => 'replace']);
$dict = json_decode((string) file_get_contents($data . '/dictionary.json'), true);
check($st === 302 && $dict['cart.add']['en'] === 'Add to cart' && count($dict) === 2, 'импорт формата экспорта, «заменить»');
[$st] = $call('POST', '/import', ['json' => '{"cart.add": {"en": "Buy"}, "new": {"ru": "Новый", "de": "Neu"}}', 'mode' => 'merge']);
$dict = json_decode((string) file_get_contents($data . '/dictionary.json'), true);
check($st === 302 && $dict['cart.add'] === ['en' => 'Buy', 'ru' => 'В корзину'] && $dict['new']['de'] === 'Neu' && isset($dict['Главная']), 'импорт простого {"ключ": {...}}, «добавить»: значения заменяются, остальное сохраняется');
[$st, $body] = $call('POST', '/import', ['json' => '{"k": ["list"], "": {"ru": "x"}, "z": {"RU!": "x"}}', 'mode' => 'merge']);
check($st === 422 && str_contains($body, 'ожидается объект') && str_contains($body, 'Недопустимый ключ') && str_contains($body, 'недопустимый код языка'), 'импорт: ошибки формата перечисляются, словарь не меняется');
[$st, $body] = $call('POST', '/import', ['json' => 'not json']);
check($st === 422 && str_contains($body, 'Не JSON'), 'импорт: не JSON');
[$st, $body] = $call('GET', '/export');
$exp = json_decode($body, true);
check($st === 200 && $exp['format'] === 'motorchk-i18n-dictionary' && $exp['locales'] === ['ru', 'en'] && $exp['strings']['new']['de'] === 'Neu' && array_keys($exp['strings']) === ['cart.add', 'new', 'Главная'], 'экспорт: формат, языки, ключи по алфавиту (значения других языков сохраняются)');
[$st, $body] = $admin('/admin-panel/plugins/i18n/dictionary');
check($st === 200 && str_contains($body, 'value="cart.add"') && str_contains($body, '>Buy</textarea>') && str_contains($body, 'name="rows[3][key]"'), 'страница словаря: строки ключей + пустые для новых');
[$st] = $call('POST', '/dictionary', ['rows' => [
    ['key' => 'cart.add', 'orig' => 'cart.add', 'values' => ['ru' => 'В корзину', 'en' => 'Add']],
    ['key' => 'new.renamed', 'orig' => 'new', 'values' => ['ru' => 'Новый', 'en' => '']],
    ['key' => 'Главная', 'orig' => 'Главная', 'values' => ['ru' => '', 'en' => 'Home'], 'delete' => '1'],
    ['key' => '', 'orig' => '', 'values' => ['ru' => '', 'en' => '']],
    ['key' => 'added', 'orig' => '', 'values' => ['ru' => "Две\r\nстроки", 'en' => 'Two']],
]]);
$dict = json_decode((string) file_get_contents($data . '/dictionary.json'), true);
check($st === 302 && $dict === ['added' => ['ru' => "Две\nстроки", 'en' => 'Two'], 'cart.add' => ['ru' => 'В корзину', 'en' => 'Add'], 'new.renamed' => ['ru' => 'Новый', 'de' => 'Neu']], 'сохранение словаря: правка, переименование ключа (de сохранён), удаление, пустые строки пропущены', json_encode($dict, JSON_UNESCAPED_UNICODE));
[$st, $body] = $call('POST', '/dictionary', ['rows' => [['key' => 'a', 'values' => ['ru' => '1']], ['key' => 'a', 'values' => ['ru' => '2']], ['key' => '', 'values' => ['en' => 'orphan']]]]);
check($st === 422 && str_contains($body, 'повторяется') && str_contains($body, 'нет ключа') && count(json_decode((string) file_get_contents($data . '/dictionary.json'), true)) === 3, 'словарь: дубли и строки без ключа → 422, файл не меняется');
[$st, $body] = $admin('/admin-panel/plugins/i18n');
check($st === 200 && str_contains($body, '/plugin-assets/i18n/admin.css') && preg_match('#<td>1</td>#', $body) === 1, 'страница «Языки»: число переводов страниц (about.en.md)');
$_SESSION = []; // вход в админку и CSRF проверяет ядро (core/tests/plugins.php)

ob_end_clean();
t_rm($site);
out("\n{$passed} passed, {$failures} failed\n");
exit($failures === 0 ? 0 : 1);
