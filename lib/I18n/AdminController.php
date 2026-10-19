<?php

namespace I18n;

use JsonException;
use PluginContext;
use PluginRequest;

/**
 * Админка: /admin-panel/plugins/i18n/ — языки и параметры, словарь, импорт/экспорт.
 * Обычные HTML-формы (POST → редирект), CSRF проверяет ядро (поле _csrf).
 */
final class AdminController
{
    /** Пустых строк для новых ключей в форме словаря. */
    private const NEW_ROWS = 3;

    public function __construct(private PluginContext $plugin, private Service $service, private Settings $settings, private Dictionary $dictionary)
    {
    }

    public function register(): void
    {
        $p = $this->plugin;
        $p->adminRoute('GET', '/', fn(PluginRequest $r) => $this->settingsPage($this->settings->all(), [], $r->query('saved') === '1'));
        $p->adminRoute('POST', '/', fn(PluginRequest $r) => $this->saveSettings($r));
        $p->adminRoute('GET', '/dictionary', fn(PluginRequest $r) => $this->dictionaryPage(null, [], (string) $r->query('saved', '')));
        $p->adminRoute('POST', '/dictionary', fn(PluginRequest $r) => $this->saveDictionary($r));
        $p->adminRoute('GET', '/export', fn() => $this->export());
        $p->adminRoute('POST', '/import', fn(PluginRequest $r) => $this->import($r));
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string> $errors
     */
    private function settingsPage(array $values, array $errors, bool $saved): void
    {
        if ($errors !== []) {
            http_response_code(422);
        }
        $this->page('settings', 'Языки', [
            'values' => $values,
            'errors' => $errors,
            'saved' => $saved,
            'fileError' => $this->settings->error(),
            'translations' => $this->countTranslations(),
        ]);
    }

    private function saveSettings(PluginRequest $r): void
    {
        $input = $r->allInput();
        $rows = [];
        foreach ((array) ($input['locales'] ?? []) as $row) {
            if (is_array($row) && empty($row['remove'])) {
                $rows[] = $row;
            }
        }
        $input['locales'] = $rows;
        [$clean, $errors] = Settings::validate($input);
        if ($clean === null) {
            $this->settingsPage([
                'locales' => $rows,
                'default' => (string) ($input['default'] ?? ''),
                'remember' => !empty($input['remember']),
                'accept_language' => !empty($input['accept_language']),
                'switcher' => (string) ($input['switcher'] ?? 'auto'),
                'switcher_position' => (string) ($input['switcher_position'] ?? 'bottom-right'),
                'rewrite_links' => !empty($input['rewrite_links']),
            ], $errors, false);
            return;
        }
        $this->settings->save($clean);
        $this->plugin->redirect($this->plugin->adminUrl('/?saved=1'));
    }

    /**
     * @param list<array{key: string, values: array<string, string>}>|null $rows
     * @param list<string> $errors
     */
    private function dictionaryPage(?array $rows, array $errors, string $notice): void
    {
        if ($errors !== []) {
            http_response_code(422);
        }
        if ($rows === null) {
            $rows = [];
            foreach ($this->dictionary->all() as $key => $values) {
                $rows[] = ['key' => (string) $key, 'values' => $values];
            }
        }
        for ($i = 0; $i < self::NEW_ROWS; $i++) {
            $rows[] = ['key' => '', 'values' => []];
        }
        $this->page('dictionary', 'Словарь', [
            'rows' => $rows,
            'locales' => $this->dictionaryLocales(),
            'names' => $this->service->names(),
            'errors' => $errors,
            'notice' => match ($notice) {
                'saved' => 'Словарь сохранён.',
                default => preg_match('/^imported-(\d+)$/', $notice, $m) ? 'Импортировано ключей: ' . $m[1] . '.' : '',
            },
            'fileError' => $this->dictionary->error(),
        ]);
    }

    private function saveDictionary(PluginRequest $r): void
    {
        $locales = $this->dictionaryLocales();
        $rows = [];
        $strings = [];
        $errors = [];
        // языки, которых нет в форме (удалённые из настроек), сохраняются как были
        $existing = $this->dictionary->all();
        foreach ((array) $r->input('rows', []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $origKey = trim((string) ($row['orig'] ?? ''));
            $key = trim((string) ($row['key'] ?? ''));
            $values = [];
            foreach ($locales as $code) {
                $v = $row['values'][$code] ?? '';
                $values[$code] = is_string($v) ? str_replace("\r\n", "\n", $v) : '';
            }
            if (!empty($row['delete'])) {
                continue;
            }
            $hasValues = implode('', $values) !== '';
            if ($key === '' && !$hasValues) {
                continue;
            }
            $rows[] = ['key' => $key, 'values' => $values, 'orig' => $origKey];
            if (!Dictionary::validKey($key)) {
                $errors[] = $key === '' ? 'У строки со значениями «' . mb_substr(implode(' / ', array_filter($values)), 0, 40) . '» нет ключа' : 'Недопустимый ключ «' . mb_substr($key, 0, 40) . '» (до ' . Dictionary::MAX_KEY . ' символов, без управляющих)';
                continue;
            }
            if (isset($strings[$key])) {
                $errors[] = 'Ключ «' . $key . '» повторяется';
                continue;
            }
            foreach ($values as $code => $v) {
                if (mb_strlen($v) > Dictionary::MAX_VALUE) {
                    $errors[] = 'Ключ «' . $key . '», ' . $code . ': длиннее ' . Dictionary::MAX_VALUE . ' символов';
                }
            }
            $kept = array_diff_key($existing[$origKey !== '' ? $origKey : $key] ?? [], array_flip($locales));
            $strings[$key] = array_filter($values, static fn($v) => $v !== '') + $kept;
        }
        if ($errors !== []) {
            $this->dictionaryPage($rows, $errors, '');
            return;
        }
        $this->dictionary->save($strings);
        $this->plugin->redirect($this->plugin->adminUrl('/dictionary?saved=saved'));
    }

    private function export(): void
    {
        $json = json_encode($this->dictionary->export($this->service->locales()), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!headers_sent()) {
            header('Content-Disposition: attachment; filename="i18n-dictionary-' . date('Y-m-d') . '.json"');
        }
        $this->plugin->raw((string) $json . "\n", 'application/json; charset=UTF-8');
    }

    private function import(PluginRequest $r): void
    {
        $raw = trim((string) $r->input('json', ''));
        $file = $r->file('file');
        if ($file !== null && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file((string) $file['tmp_name'])) {
            if ((int) $file['size'] > 2 * 1024 * 1024) {
                $this->dictionaryPage(null, ['Файл больше 2 МБ'], '');
                return;
            }
            $raw = (string) file_get_contents((string) $file['tmp_name']);
        }
        if ($raw === '') {
            $this->dictionaryPage(null, ['Выберите JSON-файл или вставьте JSON в поле'], '');
            return;
        }
        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            $this->dictionaryPage(null, ['Не JSON: ' . $e->getMessage()], '');
            return;
        }
        [$strings, $errors] = is_array($data) ? Dictionary::parse($data) : [null, ['Ожидается JSON-объект']];
        if ($strings === null) {
            $this->dictionaryPage(null, array_slice($errors, 0, 20), '');
            return;
        }
        if ($this->dictionary->error() !== null && $r->input('mode') !== 'replace') {
            $this->dictionaryPage(null, ['Текущий файл словаря повреждён — выберите «Заменить словарь целиком»'], '');
            return;
        }
        $result = $r->input('mode') === 'replace' ? $strings : Dictionary::merge($this->dictionary->all(), $strings);
        $this->dictionary->save($result);
        $this->plugin->redirect($this->plugin->adminUrl('/dictionary?saved=imported-' . count($strings)));
    }

    /** @return list<string> языки настроек и встречающиеся в словаре */
    private function dictionaryLocales(): array
    {
        return $this->service->locales();
    }

    /** @return array<string, int> язык → число файлов перевода *.{язык}.md в content/ */
    private function countTranslations(): array
    {
        $counts = [];
        $root = (string) $this->plugin->path('content_path');
        foreach (array_slice($this->service->locales(), 1) as $code) {
            $counts[$code] = 0;
        }
        if ($counts === [] || !is_dir($root)) {
            return $counts;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            if (preg_match('/\.([a-z]{2,3}(?:-[a-z0-9]{2,8})?)\.md$/', $file->getFilename(), $m) && isset($counts[$m[1]])) {
                $counts[$m[1]]++;
            }
        }

        return $counts;
    }

    /** @param array<string, mixed> $data */
    private function page(string $page, string $title, array $data): void
    {
        $this->plugin->render('admin', ['page' => $page, 'service' => $this->service] + $data, [
            'title' => $title,
            'styles' => [$this->plugin->assetUrl('admin.css')],
        ]);
    }
}
