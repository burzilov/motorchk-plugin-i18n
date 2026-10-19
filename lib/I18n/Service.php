<?php

namespace I18n;

use LocaleProvider;
use PluginContext;
use SiteLocale;

/**
 * Служба «i18n» — $plugin->service('i18n') в других плагинах и $i18n в шаблонах темы.
 *
 *   $i18n->locale()                       текущий язык ('ru', 'en')
 *   $i18n->t('cart.add', 'В корзину')     строка словаря на текущем языке → основном → $fallback → ключ
 *   $i18n->localized(['ru' => …, 'en' => …])  значение на текущем языке → основном → первое непустое
 *   $i18n->url('/about', 'en')            адрес на языке: '/en/about'
 *   $i18n->switcher()                     HTML переключателя языка
 */
final class Service implements LocaleProvider
{
    /** Пути, которые не получают языковой префикс (служебные адреса ядра). */
    public const EXCLUDED = ['/admin-panel', '/plugin-assets', '/core', '/api', '/themes', '/uploads', '/media', '/content', '/cache', '/vendor'];

    private ?string $routedPath = null;

    public function __construct(private Settings $settings, private Dictionary $dictionary)
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

    /**
     * @param array<string, mixed> $values ['ru' => 'Ловушка', 'en' => 'Catcher']
     */
    public function localized(array $values, ?string $locale = null): mixed
    {
        foreach ([$locale ?? $this->locale(), $this->defaultLocale()] as $code) {
            if (isset($values[$code]) && $values[$code] !== '') {
                return $values[$code];
            }
        }
        foreach ($values as $value) {
            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return null;
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
        foreach (self::EXCLUDED as $excluded) {
            if ($bare === $excluded || str_starts_with($bare, $excluded . '/')) {
                return $path;
            }
        }
        if ($locale === $this->defaultLocale() || !in_array($locale, $this->locales(), true)) {
            return $bare . $rest;
        }

        return '/' . $locale . ($bare === '/' ? '/' : $bare) . $rest;
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
    public function switchHref(string $path, string $locale): string
    {
        $url = $this->url($path, $locale);
        if ($url === '/' && $locale === $this->defaultLocale() && $this->settings->get('remember')) {
            return '/?lang=' . rawurlencode($locale);
        }

        return $url;
    }
}
