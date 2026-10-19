#!/usr/bin/env bash
# Сборка архива плагина: dist/i18n-<version>.zip
# Архив распаковывается в public_html/plugins/ и даёт папку plugins/i18n/.
# Собирается из закоммиченного состояния (HEAD) через git archive — незакоммиченные правки в архив не попадают.
set -euo pipefail

cd "$(dirname "$0")"

version=$(sed -n 's/^[[:space:]]*"version":[[:space:]]*"\([^"]*\)".*/\1/p' plugin.json | head -n1)
if [[ ! "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]]; then
    echo "Не удалось прочитать version из plugin.json" >&2
    exit 1
fi

files=(plugin.json plugin.php lib templates assets README.md CHANGELOG.md)
if [[ -n "$(git status --porcelain -- "${files[@]}")" ]]; then
    echo "Внимание: есть незакоммиченные изменения — в архив попадёт состояние HEAD." >&2
fi

mkdir -p dist
out="dist/i18n-${version}.zip"
git archive --format=zip --prefix=i18n/ -o "$out" HEAD -- "${files[@]}"

echo "Готово: $out"
echo "Установка: распакуйте в public_html/plugins/ (получится plugins/i18n/). Нужно ядро motorchk ≥ 0.4.0 (с readJsonStrict)."
