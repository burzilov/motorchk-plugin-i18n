<?php

/**
 * Плагин «Мультиязычность» (i18n) для motorchk ≥ 0.4.
 * Служба «i18n» (языки, словарь, адреса, переключатель) и хуки сайта; настройки — в админке.
 */

use I18n\AdminController;
use I18n\Dictionary;
use I18n\Hooks;
use I18n\Service;
use I18n\Settings;

return static function (PluginContext $plugin): void {
    $settings = new Settings($plugin);
    $dictionary = new Dictionary($plugin);
    $service = new Service($settings, $dictionary);

    $plugin->provide('i18n', $service);

    // первая установка: настройки по умолчанию (ru — основной, en), существующий файл не трогаем
    $plugin->onInstall(static function (?string $from, string $to, PluginContext $plugin): void {
        if ($plugin->readFile(Settings::FILE) === null) {
            $plugin->writeJson(Settings::FILE, Settings::defaults());
        }
        if ($plugin->readFile(Dictionary::FILE) === null) {
            $plugin->writeJson(Dictionary::FILE, (object) [
                'i18n.switcher' => ['ru' => 'Язык', 'en' => 'Language'],
            ]);
        }
    });

    (new Hooks($plugin, $service, $settings, $dictionary))->register();
    (new AdminController($plugin, $service, $settings, $dictionary))->register();
};
