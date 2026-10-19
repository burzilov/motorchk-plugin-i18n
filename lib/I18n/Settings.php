<?php

namespace I18n;

use PluginContext;

/**
 * Настройки языков: plugin-data/i18n/settings.json.
 *
 * Повреждённый файл не перезаписывается молча: используются настройки по умолчанию,
 * ошибка показывается в админке, файл меняется только явным сохранением формы.
 */
final class Settings
{
    public const FILE = 'settings.json';
    public const CODE_PATTERN = '/^[a-z]{2,3}(?:-[a-z0-9]{2,8})?$/';
    public const POSITIONS = ['bottom-right', 'bottom-left', 'top-right', 'top-left'];
    public const SWITCHER_MODES = ['auto', 'manual'];

    /** @var array<string, mixed>|null */
    private ?array $data = null;
    private ?string $error = null;

    public function __construct(private PluginContext $plugin)
    {
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return [
            'locales' => [
                ['code' => 'ru', 'name' => 'Русский'],
                ['code' => 'en', 'name' => 'English'],
            ],
            'default' => 'ru',
            'remember' => true,
            'accept_language' => false,
            'switcher' => 'auto',
            'switcher_position' => 'bottom-right',
            'rewrite_links' => true,
        ];
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->data === null) {
            $this->data = self::defaults();
            $raw = $this->plugin->readFile(self::FILE);
            if ($raw !== null) {
                $decoded = json_decode($raw, true);
                if (!is_array($decoded)) {
                    $this->error = 'Файл настроек ' . self::FILE . ' повреждён — действуют настройки по умолчанию. Сохранение формы перезапишет файл.';
                } else {
                    [$clean, $errors] = self::validate($decoded);
                    $this->data = $clean ?? self::defaults();
                    if ($errors !== []) {
                        $this->error = 'Файл настроек содержит ошибки (' . implode('; ', $errors) . ') — действуют настройки по умолчанию.';
                    }
                }
            }
        }

        return $this->data;
    }

    public function error(): ?string
    {
        $this->all();

        return $this->error;
    }

    public function exists(): bool
    {
        return $this->plugin->readFile(self::FILE) !== null;
    }

    /** @return list<string> коды языков, первым — основной */
    public function codes(): array
    {
        $all = $this->all();
        $codes = [$all['default']];
        foreach ($all['locales'] as $l) {
            if (!in_array($l['code'], $codes, true)) {
                $codes[] = $l['code'];
            }
        }

        return $codes;
    }

    /** @return array<string, string> код → название */
    public function names(): array
    {
        $names = [];
        foreach ($this->all()['locales'] as $l) {
            $names[$l['code']] = $l['name'];
        }

        return $names;
    }

    public function defaultLocale(): string
    {
        return $this->all()['default'];
    }

    public function get(string $key): mixed
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * @param array<string, mixed> $input
     * @return array{0: array<string, mixed>|null, 1: list<string>} [очищенные настройки или null, ошибки]
     */
    public static function validate(array $input): array
    {
        $errors = [];
        $locales = [];
        foreach ((array) ($input['locales'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtolower(trim((string) ($row['code'] ?? '')));
            $name = trim((string) ($row['name'] ?? ''));
            if ($code === '' && $name === '') {
                continue;
            }
            if (!preg_match(self::CODE_PATTERN, $code)) {
                $errors[] = 'Код языка «' . $code . '» должен быть вида ru, en, pt-br';
                continue;
            }
            if (isset($locales[$code])) {
                $errors[] = 'Язык «' . $code . '» указан дважды';
                continue;
            }
            if (mb_strlen($name) > 40) {
                $errors[] = 'Название языка «' . $code . '» длиннее 40 символов';
                continue;
            }
            $locales[$code] = ['code' => $code, 'name' => $name !== '' ? $name : $code];
        }
        if ($locales === []) {
            $errors[] = 'Нужен хотя бы один язык';
        }
        $default = strtolower(trim((string) ($input['default'] ?? '')));
        if ($locales !== [] && !isset($locales[$default])) {
            $errors[] = 'Основной язык должен быть в списке языков';
        }
        $switcher = (string) ($input['switcher'] ?? 'auto');
        if (!in_array($switcher, self::SWITCHER_MODES, true)) {
            $errors[] = 'Неизвестный режим переключателя';
        }
        $position = (string) ($input['switcher_position'] ?? 'bottom-right');
        if (!in_array($position, self::POSITIONS, true)) {
            $errors[] = 'Неизвестное положение переключателя';
        }
        if ($errors !== []) {
            return [null, $errors];
        }

        return [[
            'locales' => array_values($locales),
            'default' => $default,
            'remember' => self::bool($input['remember'] ?? false),
            'accept_language' => self::bool($input['accept_language'] ?? false),
            'switcher' => $switcher,
            'switcher_position' => $position,
            'rewrite_links' => self::bool($input['rewrite_links'] ?? false),
        ], []];
    }

    /** @param array<string, mixed> $clean результат validate() */
    public function save(array $clean): void
    {
        $this->plugin->writeJson(self::FILE, $clean);
        $this->data = $clean;
        $this->error = null;
    }

    private static function bool(mixed $v): bool
    {
        return $v === true || $v === 1 || $v === '1' || $v === 'on' || $v === 'true';
    }
}
