<?php

namespace I18n;

use PluginContext;
use SiteLocale;
use SiteRequest;

/**
 * Хуки сайта (только публичная часть, админку ядро не пропускает через хуки).
 *
 * Язык запроса: префикс адреса (/en/…) → cookie → Accept-Language.
 * Cookie и Accept-Language учитываются только на главной «/» без префикса: там посетителя
 * перенаправляют на запомненный (или предпочитаемый браузером, если включено) язык.
 */
final class Hooks
{
    public const COOKIE = 'site_locale';
    public const PLACEHOLDER = '/<!--\s*i18n:switcher\s*-->/';

    /** @var list<array{name: string, value: string}>|null тесты: перехват setcookie() */
    public static ?array $cookieLog = null;

    public function __construct(private PluginContext $plugin, private Service $service, private Settings $settings, private Dictionary $dictionary)
    {
    }

    public function register(): void
    {
        $this->plugin->on('request', fn(SiteRequest $r) => $this->onRequest($r));
        $this->plugin->filter('url', fn(string $url, array $ctx): string => $this->service->url($url, (string) ($ctx['locale'] ?? $this->service->locale())));
        $this->plugin->filter('menu', fn(array $menus, array $ctx): array => $this->translateMenus($menus, (string) ($ctx['locale'] ?? '')));
        $this->plugin->filter('head', fn(string $head): string => $head . '    <link href="' . htmlspecialchars($this->plugin->assetUrl('switcher.css'), ENT_QUOTES, 'UTF-8') . "\" rel=\"stylesheet\">\n");
        $this->plugin->filter('output', fn(string $html, array $ctx): string => $this->filterOutput($html));
    }

    public function onRequest(SiteRequest $request): void
    {
        $decision = $this->decide(
            $request->method(),
            $request->path(),
            $_GET,
            $request->cookie(self::COOKIE),
            $request->header('Accept-Language')
        );
        if ($decision['cookie'] !== null) {
            $this->setCookie($decision['cookie']);
        }
        if ($decision['redirect'] !== null) {
            $request->redirect($decision['redirect'], $decision['status']);
            return;
        }
        if ($decision['locale'] !== $this->service->defaultLocale()) {
            $request->setLocale($decision['locale']);
        }
        if ($decision['path'] !== $request->path()) {
            $request->setPath($decision['path']);
        }
        $this->service->setCurrentPath($decision['path']);
    }

    /**
     * Чистое решение по запросу (без побочных эффектов) — для хука и тестов.
     *
     * @param array<string, mixed> $query
     * @return array{locale: string, path: string, redirect: ?string, status: int, cookie: ?string}
     */
    public function decide(string $method, string $path, array $query, ?string $cookie, ?string $acceptLanguage): array
    {
        $default = $this->service->defaultLocale();
        $locales = $this->service->locales();
        $remember = (bool) $this->settings->get('remember');
        $accept = (bool) $this->settings->get('accept_language');
        $useCookie = $remember || $accept;
        $cookie = is_string($cookie) && in_array($cookie, $locales, true) ? $cookie : null;
        $result = ['locale' => $default, 'path' => $path, 'redirect' => null, 'status' => 302, 'cookie' => null];
        $isGet = in_array($method, ['GET', 'HEAD'], true);

        // ?lang=en — явный выбор (ссылки переключателя на главную, старые адреса ?lang): запомнить и перейти на чистый адрес
        $lang = $query['lang'] ?? null;
        if ($isGet && is_string($lang) && in_array(strtolower($lang), $locales, true)) {
            $lang = strtolower($lang);
            $rest = $query;
            unset($rest['lang']);
            $target = $this->service->url($this->service->stripPrefix($path), $lang);
            $result['redirect'] = $target . ($rest !== [] ? '?' . http_build_query($rest) : '');
            $result['cookie'] = $useCookie && $cookie !== $lang ? $lang : null;

            return $result;
        }

        $prefix = $this->service->prefixLocale($path);
        if ($prefix !== null) {
            $bare = $this->service->stripPrefix($path);
            $qs = $query !== [] ? '?' . http_build_query($query) : '';
            if ($prefix === $default) {
                // /ru/about → /about: у основного языка префикса нет
                return ['redirect' => $this->service->url($bare, $default) . $qs, 'status' => 301] + $result;
            }
            if ($path === '/' . $prefix && $isGet) {
                return ['redirect' => '/' . $prefix . '/' . $qs, 'status' => 301] + $result;
            }
            $result['locale'] = $prefix;
            $result['path'] = $bare;
            $result['cookie'] = $remember && $isGet && $cookie !== $prefix ? $prefix : null;

            return $result;
        }

        if ($path === '/' && $isGet) {
            if ($remember && $cookie !== null && $cookie !== $default) {
                return ['redirect' => $this->service->url('/', $cookie), 'status' => 302] + $result;
            }
            if ($accept && $cookie === null) {
                // первый визит: язык браузера; cookie — чтобы не определять повторно
                $preferred = self::negotiate((string) $acceptLanguage, $locales) ?? $default;
                $result['cookie'] = $preferred;
                if ($preferred !== $default) {
                    $result['redirect'] = $this->service->url('/', $preferred);
                }

                return $result;
            }
        }
        // страница основного языка: обновить уже выставленную cookie (новую не ставим — посетителям одного языка cookie не нужна)
        if ($remember && $isGet && $cookie !== null && $cookie !== $default) {
            $result['cookie'] = $default;
        }

        return $result;
    }

    /**
     * Лучший язык из Accept-Language среди $locales (точное совпадение или по основному тегу: en-US → en).
     *
     * @param list<string> $locales
     */
    public static function negotiate(string $header, array $locales): ?string
    {
        $candidates = [];
        foreach (explode(',', $header) as $i => $part) {
            $bits = explode(';', trim($part));
            $tag = strtolower(trim($bits[0]));
            if ($tag === '' || $tag === '*') {
                continue;
            }
            $q = 1.0;
            foreach (array_slice($bits, 1) as $param) {
                if (preg_match('/^\s*q=([0-9.]+)\s*$/', $param, $m)) {
                    $q = (float) $m[1];
                }
            }
            if ($q > 0) {
                $candidates[] = [$tag, $q, $i];
            }
        }
        usort($candidates, static fn($a, $b) => [$b[1], $a[2]] <=> [$a[1], $b[2]]);
        foreach ($candidates as [$tag]) {
            if (in_array($tag, $locales, true)) {
                return $tag;
            }
            $primary = explode('-', $tag)[0];
            if (in_array($primary, $locales, true)) {
                return $primary;
            }
        }

        return null;
    }

    /**
     * Подписи меню на языке $locale: ключ словаря «menu.{slug}», затем ключ, равный подписи.
     * На основном языке подписи не меняются.
     *
     * @param array<string, mixed> $menus
     * @return array<string, mixed>
     */
    public function translateMenus(array $menus, string $locale): array
    {
        if ($locale === '' || $locale === $this->service->defaultLocale()) {
            return $menus;
        }
        $walk = function (array $items) use (&$walk, $locale): array {
            foreach ($items as $i => $item) {
                if (!is_array($item)) {
                    continue;
                }
                $label = (string) ($item['label'] ?? '');
                $slug = $item['slug'] ?? null;
                $translated = (is_string($slug) && $slug !== '' ? $this->dictionary->get('menu.' . $slug, $locale) : null)
                    ?? ($label !== '' ? $this->dictionary->get($label, $locale) : null);
                if ($translated !== null) {
                    $items[$i]['label'] = $translated;
                }
                if (!empty($item['children']) && is_array($item['children'])) {
                    $items[$i]['children'] = $walk($item['children']);
                }
            }

            return $items;
        };
        foreach ($menus as $key => $items) {
            if (is_array($items)) {
                $menus[$key] = $walk($items);
            }
        }

        return $menus;
    }

    public function filterOutput(string $html): string
    {
        $locale = $this->service->locale();
        if ($this->settings->get('rewrite_links') && $locale !== $this->service->defaultLocale()) {
            $html = $this->rewriteLinks($html, $locale);
        }
        if (preg_match(self::PLACEHOLDER, $html)) {
            $switcher = $this->service->switcher();
            $html = (string) preg_replace_callback(self::PLACEHOLDER, static fn() => $switcher, $html);
        }
        if ($this->settings->get('switcher') === 'auto' && !str_contains($html, 'data-i18n-switcher')) {
            $switcher = $this->service->switcher(['floating' => true, 'position' => (string) $this->settings->get('switcher_position')]);
            $pos = strripos($html, '</body>');
            if ($switcher !== '' && $pos !== false) {
                $html = substr($html, 0, $pos) . $switcher . "\n" . substr($html, $pos);
            }
        }

        return $html;
    }

    /**
     * Внутренние ссылки <a href="/…"> в HTML страницы → на язык $locale (ссылки из текста страниц, шаблонов).
     * Не трогает ссылки с hreflang (переключатель, alternate) и data-i18n-keep.
     */
    public function rewriteLinks(string $html, string $locale): string
    {
        return (string) preg_replace_callback('/<a\b[^>]*>/i', function (array $m) use ($locale): string {
            $tag = $m[0];
            if (preg_match('/\s(?:hreflang|data-i18n-keep)\b/i', $tag)) {
                return $tag;
            }

            return (string) preg_replace_callback('/(\shref=)(["\'])(\/(?!\/)[^"\']*)\2/i', function (array $h) use ($locale): string {
                $url = html_entity_decode($h[3], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $new = $this->service->url($url, $locale);

                return $new === $url ? $h[0] : $h[1] . $h[2] . htmlspecialchars($new, ENT_QUOTES, 'UTF-8') . $h[2];
            }, $tag, 1);
        }, $html);
    }

    private function setCookie(string $locale): void
    {
        if (self::$cookieLog !== null) {
            self::$cookieLog[] = ['name' => self::COOKIE, 'value' => $locale];
            return;
        }
        if (!headers_sent()) {
            setcookie(self::COOKIE, $locale, [
                'expires' => time() + 365 * 86400,
                'path' => '/',
                'secure' => ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off',
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
        }
    }
}
