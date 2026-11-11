<?php

namespace I18n;

use LocaleProvider;
use PluginContext;
use SiteLocale;

/**
 * Служба «locale» — $plugin->service('locale') в других плагинах и $localeProvider в шаблонах темы.
 * Имя — SiteLocale::SERVICE. Ядро 0.8 переменную $i18n в тему не передаёт.
 *
 *   $localeProvider->locale()                       текущий язык ('ru', 'en')
 *   $localeProvider->t('cart.add', 'В корзину')     строка словаря на текущем языке → основном → $fallback → ключ
 *   $localeProvider->url('/about', 'en')            адрес на языке: '/en/about'
 *   $localeProvider->switcher()                     HTML переключателя языка
 */
final class Service implements LocaleProvider
{
    /**
     * Пути, которые ядро не считает служебными (isSystemPath() === false), но языковой префикс им не нужен.
     *
     * /uploads — файлы вне каталога медиа ядра (/media/ уже закрывает isSystemPath()).
     *            Ссылка /uploads/a.png с префиксом стала бы /en/uploads/a.png и не нашла бы файл.
     * /vendor  — каталог Composer в корне сайта. /core/vendor/ ядро уже считает служебным,
     *            корень /vendor/ — нет. Страница со slug vendor плагину не нужна.
     *
     * Старый /plugin-assets/ сюда не входит: ядро 0.8 его не обслуживает (404 без редиректа)
     * и не считает служебным. Актуальная статика — /plugins-assets/ (isSystemPath()).
     */
    public const EXCLUDED = ['/uploads', '/vendor'];

    private ?string $routedPath = null;

    public function __construct(private PluginContext $plugin, private Settings $settings, private Dictionary $dictionary)
    {
    }

    // ---------- LocaleProvider (ядро) ----------

    public function locales(): array
    {
        return $this->settings->codes();
    }

    public function defaultLocale(): string
    {
        return $this->settings->defaultLocale();
    }

    // ---------- API ----------

    public function locale(): string
    {
        $current = SiteLocale::isActive() ? SiteLocale::current() : $this->defaultLocale();

        return in_array($current, $this->locales(), true) ? $current : $this->defaultLocale();
    }

    /** @return array<string, string> код → название */
    public function names(): array
    {
        return $this->settings->names();
    }

    public function t(string $key, ?string $fallback = null, ?string $locale = null): string
    {
        $locale ??= $this->locale();

        return $this->dictionary->get($key, $locale)
            ?? $this->dictionary->get($key, $this->defaultLocale())
            ?? $fallback
            ?? $key;
    }

    /** Адрес внутри сайта на языке $locale (по умолчанию — текущем). Внешние и служебные адреса не меняются. */
    public function url(string $path, ?string $locale = null): string
    {
        $locale ??= $this->locale();
        if (!str_starts_with($path, '/') || str_starts_with($path, '//')) {
            return $path;
        }
        $cut = strcspn($path, '?#');
        $rest = substr($path, $cut);
        $bare = $this->stripPrefix(substr($path, 0, $cut));
        if ($this->skipped($bare)) {
            return $path;
        }
        if ($locale === $this->defaultLocale() || !in_array($locale, $this->locales(), true)) {
            return $bare . $rest;
        }

        return '/' . $locale . ($bare === '/' ? '/' : $bare) . $rest;
    }

    /**
     * Служебный путь ядра или собственное исключение плагина (EXCLUDED).
     * Префикс языка такому пути не добавляется.
     */
    private function skipped(string $path): bool
    {
        if ($this->plugin->isSystemPath($path)) {
            return true;
        }
        foreach (self::EXCLUDED as $excluded) {
            if ($path === $excluded || str_starts_with($path, $excluded . '/')) {
                return true;
            }
        }

        return false;
    }

    /** '/en/about' → '/about'; путь без префикса — как есть. */
    public function stripPrefix(string $path): string
    {
        $locale = $this->prefixLocale($path);
        if ($locale === null) {
            return $path === '' ? '/' : $path;
        }
        $rest = substr($path, strlen($locale) + 1);

        return $rest === '' ? '/' : $rest;
    }

    /** Язык из префикса пути ('/en/…' → 'en') или null. */
    public function prefixLocale(string $path): ?string
    {
        if (!preg_match('#^/([a-z]{2,3}(?:-[a-z0-9]{2,8})?)(?:/|$)#', $path, $m)) {
            return null;
        }

        return in_array($m[1], $this->locales(), true) ? $m[1] : null;
    }

    /** Путь текущей страницы без языкового префикса (после хука request). */
    public function currentPath(): string
    {
        return $this->routedPath ?? '/';
    }

    public function setCurrentPath(string $path): void
    {
        $this->routedPath = $path;
    }

    /**
     * HTML переключателя языка: ссылки на текущую страницу на каждом языке.
     *
     * @param array{floating?: bool, position?: string, labels?: 'name'|'code', class?: string, path?: string} $options
     */
    public function switcher(array $options = []): string
    {
        $locales = $this->locales();
        if (count($locales) < 2) {
            return '';
        }
        $e = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');
        $names = $this->names();
        $current = $this->locale();
        $path = $options['path'] ?? $this->currentPath();
        $classes = ['i18n-switcher'];
        if (!empty($options['floating'])) {
            $position = in_array($options['position'] ?? '', Settings::POSITIONS, true) ? $options['position'] : 'bottom-right';
            $classes[] = 'i18n-switcher--floating';
            $classes[] = 'i18n-switcher--' . $position;
        }
        if (!empty($options['class'])) {
            $classes[] = (string) $options['class'];
        }
        $links = [];
        foreach ($locales as $code) {
            $label = ($options['labels'] ?? 'name') === 'code' ? strtoupper($code) : ($names[$code] ?? $code);
            $href = $this->switchHref($path, $code);
            $attrs = 'href="' . $e($href) . '" hreflang="' . $e($code) . '" lang="' . $e($code) . '"';
            if ($code === $current) {
                $attrs .= ' aria-current="true" class="is-current"';
            }
            $links[] = '<a ' . $attrs . '>' . $e($label) . '</a>';
        }

        return '<nav class="' . $e(implode(' ', $classes)) . '" data-i18n-switcher aria-label="' . $e($this->t('i18n.switcher', 'Язык')) . '">' . implode('', $links) . '</nav>';
    }

    /**
     * Ссылка переключателя. На главную основного языка — с ?lang=…, чтобы хук запомнил выбор
     * (иначе запомненный в cookie язык снова перенаправит на /en/).
     */
    private function switchHref(string $path, string $locale): string
    {
        $url = $this->url($path, $locale);
        if ($url === '/' && $locale === $this->defaultLocale() && $this->settings->get('remember')) {
            return '/?lang=' . rawurlencode($locale);
        }

        return $url;
    }
}
