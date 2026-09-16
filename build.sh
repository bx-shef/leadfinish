#!/usr/bin/env bash
#
# Сборка поставки модуля shef.leadfinish.
#
# Запускать из корня репозитория:
#   ./build.sh           проверки + архив shef.leadfinish.zip
#   ./build.sh --check   только проверки, без архива — то же гоняет CI
#
# Подробности и установка на портал — docs/build-and-install.md.

set -euo pipefail

MODULE='shef.leadfinish'
ARCHIVE="${MODULE}.zip"

cd "$(dirname "$0")"

CHECK_ONLY=false
case "${1:-}" in
	--check) CHECK_ONLY=true ;;
	'') ;;
	*)
		echo "Неизвестный аргумент: $1" >&2
		echo "Использование: $0 [--check]" >&2
		exit 2
		;;
esac

fail()
{
	echo "ОШИБКА: $*" >&2
	exit 1
}

step()
{
	echo "==> $*"
}

[ -d "$MODULE" ] || fail "каталог $MODULE/ не найден — запускай из корня репозитория"
command -v php >/dev/null || fail 'нужен php в PATH'
command -v node >/dev/null || fail 'нужен node в PATH'

# --- Синтаксис -------------------------------------------------------------
# Минимум перед сборкой: иначе на портал уедет то, что даже не парсится.

step "синтаксис PHP ($(php -r 'echo PHP_VERSION;'))"
find "$MODULE" -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null \
	|| fail 'php -l не прошёл'

step "синтаксис JS ($(node --version))"
while IFS= read -r -d '' js
do
	node --check "$js" || fail "node --check не прошёл: $js"
done < <(find "$MODULE/js" -name '*.js' -print0)

# --- Автозагрузка ----------------------------------------------------------
# Loader::registerNamespace ищет файл по имени класса в нижнем регистре.
# Заглавная буква в lib/ ломает модуль только на Linux — локально под Windows
# всё «работает», поэтому проверяем здесь.

step 'имена файлов в lib/ в нижнем регистре'
upper=$(find "$MODULE/lib" -type f | LC_ALL=C grep '[A-Z]' || true)
[ -z "$upper" ] || fail "заглавные буквы в путях lib/:"$'\n'"$upper"

# --- Версия ----------------------------------------------------------------
# Единственный способ понять, что стоит на портале.

version=$(php -r '
	include "'"$MODULE"'/install/version.php";
	echo $arModuleVersion["VERSION"] ?? "";
') || fail 'не читается install/version.php'
[ -n "$version" ] || fail 'в install/version.php не задан VERSION'
step "версия модуля: $version"

if $CHECK_ONLY
then
	echo
	echo "Проверки пройдены. Архив не собирался (--check)."
	exit 0
fi

# --- Архив -----------------------------------------------------------------
# Внутри zip первым уровнем должен лежать каталог модуля целиком, иначе при
# распаковке файлы рассыплются по /local/modules/.

step "сборка $ARCHIVE"
rm -f "$ARCHIVE"
zip -rq "$ARCHIVE" "$MODULE" \
	-x '*.DS_Store' '*/.git/*' '*/node_modules/*' '*.min.js' '*.map'

step 'проверка первого уровня в архиве'
stray=$(unzip -Z1 "$ARCHIVE" | grep -v "^${MODULE}/" || true)
[ -z "$stray" ] || fail "в архиве есть файлы вне ${MODULE}/:"$'\n'"$stray"

files=$(unzip -Z1 "$ARCHIVE" | grep -vc '/$' || true)
echo
echo "Готово: $ARCHIVE — версия $version, файлов: $files, размер: $(du -h "$ARCHIVE" | cut -f1)"
echo "Дальше: распаковать в /home/bitrix/www/local/modules/ и поставить через"
echo "Marketplace → Установленные решения (docs/build-and-install.md)."
