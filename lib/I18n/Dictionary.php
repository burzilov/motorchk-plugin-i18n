<?php

namespace I18n;

use PluginContext;

/**
 * Словарь строк интерфейса: plugin-data/i18n/dictionary.json, {"ключ": {"ru": "…", "en": "…"}}.
 * Повреждённый файл не перезаписывается молча (см. Settings).
 */
final class Dictionary
{
    public const FILE = 'dictionary.json';
    public const FORMAT = 'motorchk-i18n-dictionary';
    public const MAX_KEY = 200;
    public const MAX_VALUE = 5000;

    /** @var array<string, array<string, string>>|null */
    private ?array $strings = null;
    private ?string $error = null;

    public function __construct(private PluginContext $plugin)
    {
    }

    /** @return array<string, array<string, string>> */
    public function all(): array
    {
        if ($this->strings === null) {
            $this->strings = [];
            $raw = $this->plugin->readFile(self::FILE);
            if ($raw !== null) {
                $decoded = json_decode($raw, true);
                [$clean, $errors] = is_array($decoded) ? self::parse($decoded) : [null, ['не JSON']];
                if ($clean === null) {
                    $this->error = 'Файл словаря ' . self::FILE . ' повреждён (' . implode('; ', $errors) . ') — словарь пуст. Сохранение или импорт перезапишут файл.';
                } else {
                    $this->strings = $clean;
                }
            }
        }

        return $this->strings;
    }

    public function error(): ?string
    {
        $this->all();

        return $this->error;
    }

    public function get(string $key, string $locale): ?string
    {
        $value = $this->all()[$key][$locale] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string, array<string, string>> $strings */
    public function save(array $strings): void
    {
        ksort($strings, SORT_STRING);
        $this->plugin->writeJson(self::FILE, (object) $strings);
        $this->strings = $strings;
        $this->error = null;
    }

    /**
     * Разбор словаря: {"ключ": {"ru": "…"}} или экспорт {"format": "motorchk-i18n-dictionary", "strings": {…}}.
     *
     * @param array<mixed> $data
     * @return array{0: array<string, array<string, string>>|null, 1: list<string>}
     */
    public static function parse(array $data): array
    {
        if (($data['format'] ?? null) === self::FORMAT) {
            $data = is_array($data['strings'] ?? null) ? $data['strings'] : [];
        }
        $errors = [];
        $out = [];
        foreach ($data as $key => $values) {
            $key = trim((string) $key);
            if (!self::validKey($key)) {
                $errors[] = 'Недопустимый ключ «' . mb_substr($key, 0, 40) . '»';
                continue;
            }
            if (!is_array($values) || ($values !== [] && array_is_list($values))) {
                $errors[] = 'Ключ «' . $key . '»: ожидается объект {"ru": "…", "en": "…"}';
                continue;
            }
            $clean = [];
            foreach ($values as $locale => $value) {
                $locale = strtolower(trim((string) $locale));
                if (!preg_match(Settings::CODE_PATTERN, $locale)) {
                    $errors[] = 'Ключ «' . $key . '»: недопустимый код языка «' . $locale . '»';
                    continue 2;
                }
                if (!is_string($value) || mb_strlen($value) > self::MAX_VALUE) {
                    $errors[] = 'Ключ «' . $key . '», язык ' . $locale . ': ожидается строка до ' . self::MAX_VALUE . ' символов';
                    continue 2;
                }
                if ($value !== '') {
                    $clean[$locale] = $value;
                }
            }
            $out[$key] = $clean;
        }

        return $errors === [] ? [$out, []] : [null, $errors];
    }

    public static function validKey(string $key): bool
    {
        return $key !== '' && mb_strlen($key) <= self::MAX_KEY && !preg_match('/[\x00-\x1F\x7F]/u', $key) && mb_check_encoding($key, 'UTF-8');
    }

    /**
     * @param array<string, array<string, string>> $current
     * @param array<string, array<string, string>> $import
     * @return array<string, array<string, string>>
     */
    public static function merge(array $current, array $import): array
    {
        foreach ($import as $key => $values) {
            $current[$key] = $values + ($current[$key] ?? []);
        }

        return $current;
    }

    /**
     * @param list<string> $locales
     * @return array<string, mixed>
     */
    public function export(array $locales): array
    {
        $strings = $this->all();
        ksort($strings, SORT_STRING);

        return ['format' => self::FORMAT, 'version' => 1, 'locales' => $locales, 'strings' => (object) $strings];
    }
}
