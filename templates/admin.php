<?php
/** @var PluginContext $plugin */
/** @var string $page */
/** @var I18n\Service $service */

$e = [PluginContext::class, 'e'];
$tabs = ['settings' => ['/', 'Языки'], 'dictionary' => ['/dictionary', 'Словарь']];
?>
<div class="i18n-admin">
    <nav class="i18n-tabs" aria-label="Разделы мультиязычности">
        <?php foreach ($tabs as $key => [$path, $label]): ?>
            <a class="i18n-tab<?= $key === $page ? ' is-active' : '' ?>" href="<?= $e($plugin->adminUrl($path)) ?>"<?= $key === $page ? ' aria-current="page"' : '' ?>><?= $e($label) ?></a>
        <?php endforeach; ?>
    </nav>

    <?php if (!empty($fileError)): ?>
        <p class="i18n-alert i18n-alert--error"><?= $e($fileError) ?></p>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="i18n-alert i18n-alert--error" role="alert">
            <b>Не сохранено:</b>
            <ul><?php foreach ($errors as $error): ?><li><?= $e($error) ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

<?php if ($page === 'settings'): ?>
    <?php
    $locales = $values['locales'];
    $locales[] = ['code' => '', 'name' => ''];
    ?>
    <?php if ($saved): ?><p class="i18n-alert i18n-alert--ok">Настройки сохранены.</p><?php endif; ?>
    <form method="post" action="<?= $e($plugin->adminUrl('/')) ?>" class="i18n-form">
        <?= $plugin->csrfField() ?>
        <h1 class="i18n-h1">Языки сайта</h1>
        <table class="i18n-table">
            <thead><tr><th>Основной</th><th>Код</th><th>Название</th><th>Адреса</th><th>Переводов страниц</th><th>Убрать</th></tr></thead>
            <tbody>
            <?php foreach ($locales as $i => $l): $code = (string) ($l['code'] ?? ''); $isDefault = $code !== '' && $code === $values['default']; ?>
                <tr>
                    <td><input type="radio" name="default" value="<?= $e($code) ?>" aria-label="Основной язык"<?= $isDefault ? ' checked' : '' ?><?= $code === '' ? ' disabled' : '' ?>></td>
                    <td><input type="text" name="locales[<?= (int) $i ?>][code]" value="<?= $e($code) ?>" size="6" maxlength="12" pattern="[a-z]{2,3}(-[a-z0-9]{2,8})?" placeholder="<?= $code === '' ? 'de' : '' ?>" aria-label="Код языка"></td>
                    <td><input type="text" name="locales[<?= (int) $i ?>][name]" value="<?= $e((string) ($l['name'] ?? '')) ?>" maxlength="40" placeholder="<?= $code === '' ? 'Новый язык: Deutsch' : '' ?>" aria-label="Название"></td>
                    <td class="i18n-muted"><?= $code === '' ? '' : ($isDefault ? '/about' : '/' . $e($code) . '/about') ?></td>
                    <td><?= $code !== '' && !$isDefault ? (int) ($translations[$code] ?? 0) : '' ?></td>
                    <td><?php if ($code !== ''): ?><input type="checkbox" name="locales[<?= (int) $i ?>][remove]" value="1" aria-label="Убрать язык"><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="i18n-muted">У основного языка префикса нет (<code>/about</code>), у остальных — код языка (<code>/en/about</code>). Новый язык станет основным, только если отметить его и сохранить ещё раз.</p>

        <h2 class="i18n-h2">Определение языка</h2>
        <p class="i18n-muted">Язык задаёт префикс адреса. На главной <code>/</code> без префикса посетителя можно перенаправить:</p>
        <label class="i18n-check"><input type="checkbox" name="remember" value="1"<?= !empty($values['remember']) ? ' checked' : '' ?>> Запоминать выбранный язык (cookie <code>site_locale</code>) и открывать главную на нём</label>
        <label class="i18n-check"><input type="checkbox" name="accept_language" value="1"<?= !empty($values['accept_language']) ? ' checked' : '' ?>> При первом визите на главную — язык браузера (Accept-Language). Выключено по умолчанию: поисковые роботы и общие ссылки на главную тоже попадут под перенаправление</label>

        <h2 class="i18n-h2">Переключатель и ссылки</h2>
        <label class="i18n-check"><input type="radio" name="switcher" value="auto"<?= ($values['switcher'] ?? '') === 'auto' ? ' checked' : '' ?>> Показывать плавающий переключатель на всех страницах</label>
        <label class="i18n-check i18n-indent">Положение
            <select name="switcher_position">
                <?php foreach (['bottom-right' => 'справа внизу', 'bottom-left' => 'слева внизу', 'top-right' => 'справа вверху', 'top-left' => 'слева вверху'] as $pos => $label): ?>
                    <option value="<?= $e($pos) ?>"<?= ($values['switcher_position'] ?? '') === $pos ? ' selected' : '' ?>><?= $e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label class="i18n-check"><input type="radio" name="switcher" value="manual"<?= ($values['switcher'] ?? '') === 'manual' ? ' checked' : '' ?>> Только там, где он вставлен вручную: <code>&lt;!--i18n:switcher--&gt;</code> в тексте страницы или <code>&lt;?= $i18n?-&gt;switcher() ?&gt;</code> в шаблоне темы</label>
        <label class="i18n-check"><input type="checkbox" name="rewrite_links" value="1"<?= !empty($values['rewrite_links']) ? ' checked' : '' ?>> Переписывать внутренние ссылки в тексте страниц на текущий язык (<code>/about</code> → <code>/en/about</code>)</label>

        <p><button type="submit" class="i18n-btn">Сохранить</button></p>
    </form>

    <section class="i18n-help">
        <h2 class="i18n-h2">Переводы страниц</h2>
        <p>Перевод страницы — файл рядом с оригиналом с кодом языка перед <code>.md</code>: <code>content/about.en.md</code> для <code>/en/about</code>, <code>content/index.en.md</code> для <code>/en/</code>. Формат как у обычной страницы. Нет перевода — показывается оригинал (с canonical на него), hreflang выводится только для существующих переводов. Подробнее — в README плагина и <code>core/docs/11-i18n.md</code>.</p>
    </section>

<?php else: ?>
    <?php if (!empty($notice)): ?><p class="i18n-alert i18n-alert--ok"><?= $e($notice) ?></p><?php endif; ?>
    <form method="post" action="<?= $e($plugin->adminUrl('/dictionary')) ?>" class="i18n-form">
        <?= $plugin->csrfField() ?>
        <h1 class="i18n-h1">Словарь интерфейса</h1>
        <p class="i18n-muted">Строки для шаблонов и плагинов: <code>$i18n-&gt;t('ключ', 'запасной текст')</code>. Пустое значение — берётся основной язык. Подписи меню: ключ <code>menu.{slug}</code> или сама подпись (например, «Главная»).</p>
        <table class="i18n-table i18n-dict">
            <thead><tr><th>Ключ</th><?php foreach ($locales as $code): ?><th><?= $e($names[$code] ?? $code) ?> <span class="i18n-muted">(<?= $e($code) ?>)</span></th><?php endforeach; ?><th>Удалить</th></tr></thead>
            <tbody>
            <?php foreach ($rows as $i => $row): ?>
                <tr>
                    <td><input type="text" name="rows[<?= (int) $i ?>][key]" value="<?= $e($row['key']) ?>" maxlength="200" aria-label="Ключ"<?= $row['key'] === '' ? ' placeholder="новый.ключ"' : '' ?>><input type="hidden" name="rows[<?= (int) $i ?>][orig]" value="<?= $e((string) ($row['orig'] ?? $row['key'])) ?>"></td>
                    <?php foreach ($locales as $code): ?>
                        <td><textarea name="rows[<?= (int) $i ?>][values][<?= $e($code) ?>]" rows="1" aria-label="<?= $e($code) ?>"><?= $e((string) ($row['values'][$code] ?? '')) ?></textarea></td>
                    <?php endforeach; ?>
                    <td><?php if ($row['key'] !== ''): ?><input type="checkbox" name="rows[<?= (int) $i ?>][delete]" value="1" aria-label="Удалить ключ"><?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p class="i18n-muted">Для больших словарей (сотни ключей) удобнее импорт: у формы есть предел числа полей PHP (<code>max_input_vars</code>).</p>
        <p><button type="submit" class="i18n-btn">Сохранить словарь</button></p>
    </form>

    <section class="i18n-io">
        <h2 class="i18n-h2">Импорт и экспорт</h2>
        <p><a class="i18n-btn i18n-btn--ghost" href="<?= $e($plugin->adminUrl('/export')) ?>">Скачать словарь (JSON)</a></p>
        <form method="post" action="<?= $e($plugin->adminUrl('/import')) ?>" enctype="multipart/form-data" class="i18n-form">
            <?= $plugin->csrfField() ?>
            <p><label>JSON-файл <input type="file" name="file" accept="application/json,.json"></label></p>
            <p><label>или JSON текстом<br><textarea name="json" rows="5" cols="70" placeholder='{"cart.add": {"ru": "В корзину", "en": "Add to cart"}}'></textarea></label></p>
            <p>
                <label class="i18n-check"><input type="radio" name="mode" value="merge" checked> Добавить к словарю (значения из файла заменяют существующие)</label>
                <label class="i18n-check"><input type="radio" name="mode" value="replace"> Заменить словарь целиком</label>
            </p>
            <p><button type="submit" class="i18n-btn">Импортировать</button></p>
        </form>
    </section>
<?php endif; ?>
</div>
