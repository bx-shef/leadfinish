#!/usr/bin/env bash
#
# Сборка поставки модуля shef.leadfinish.
#
# Запускать из корня репозитория:
#   ./build.sh            проверки + архив shef.leadfinish.zip
#   ./build.sh --check    только проверки, без архива — то же гоняет CI
#   ./build.sh --version  напечатать версию модуля и выйти
#
# Подробности и установка на портал — docs/build-and-install.md.
#
# ⚠ Файлы модуля лежат в КОРНЕ репозитория: этого требует Composer, который
# разворачивает в целевой каталог корень пакета целиком. Поэтому здесь два
# списка — что уезжает на портал и что остаётся для разработки, — а всё, что не
# попало ни в один, роняет сборку. Молча уехать на портал не должно ничего.

set -euo pipefail

MODULE='shef.leadfinish'
ARCHIVE="${MODULE}.zip"

cd "$(dirname "$0")"

# Что уезжает на портал. Добавил файл модуля — впиши сюда, иначе на портале его
# не окажется.
SHIP=(
	'.settings.php'
	'CLAUDE.md'
	'LICENSE'
	'README.md'
	'composer.json'
	'css'
	'include.php'
	'install'
	'js'
	'lang'
	'lib'
)

# Что остаётся в репозитории. Добавил файл для разработки — впиши сюда, иначе
# сборка упадёт и подскажет.
KEEP=(
	'.editorconfig'
	'.git'
	'.gitattributes'
	'.github'
	'.gitignore'
	'CONTRIBUTING.md'
	'build.sh'
	'docs'
	'tests'
	"$ARCHIVE"
)

CHECK_ONLY=false
PRINT_VERSION=false
case "${1:-}" in
	--check) CHECK_ONLY=true ;;
	--version) PRINT_VERSION=true ;;
	'') ;;
	*)
		echo "Неизвестный аргумент: $1" >&2
		echo "Использование: $0 [--check | --version]" >&2
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

[ -f install/version.php ] || fail 'нет install/version.php — запускай из корня репозитория'

# Версия модуля — единственный источник правды и для сборки, и для тега релиза.
read_version()
{
	php -r '
		include "install/version.php";
		echo $arModuleVersion["VERSION"] ?? "";
	'
}

if $PRINT_VERSION
then
	command -v php >/dev/null || { echo "ОШИБКА: нужен php в PATH" >&2; exit 1; }
	version=$(read_version) && [ -n "$version" ] || { echo "ОШИБКА: в install/version.php не задан VERSION" >&2; exit 1; }
	echo "$version"
	exit 0
fi
command -v php >/dev/null || fail 'нужен php в PATH'
command -v node >/dev/null || fail 'нужен node в PATH'

# --- Раскладка ---------------------------------------------------------------
# Каждый элемент верхнего уровня обязан быть либо в поставке, либо в
# разработке. Неизвестный — это забытое решение, а не мелочь: по умолчанию он
# либо уедет на портал, либо потеряется в поставке.

step 'раскладка верхнего уровня'
unknown=''
while IFS= read -r entry
do
	name="${entry#./}"
	known=false

	for item in "${SHIP[@]}" "${KEEP[@]}"
	do
		[ "$name" = "$item" ] && { known=true; break; }
	done

	$known || unknown="${unknown}  ${name}"$'\n'
done < <(find . -mindepth 1 -maxdepth 1)

[ -z "$unknown" ] || fail "в корне лежит неизвестное — впиши в SHIP или KEEP в build.sh:"$'\n'"$unknown"

for item in "${SHIP[@]}"
do
	[ -e "$item" ] || fail "в SHIP указан несуществующий $item"
done

# --- Синтаксис -------------------------------------------------------------
# Минимум перед сборкой: иначе на портал уедет то, что даже не парсится.

step "синтаксис PHP ($(php -r 'echo PHP_VERSION;'))"
find . -name '*.php' -not -path './.git/*' -print0 | xargs -0 -n1 php -l >/dev/null \
	|| fail 'php -l не прошёл'

step "синтаксис JS ($(node --version))"
while IFS= read -r -d '' js
do
	node --check "$js" || fail "node --check не прошёл: $js"
done < <(find js -name '*.js' -print0)

# --- Автозагрузка ----------------------------------------------------------
# Loader::registerNamespace ищет файл по имени класса в нижнем регистре.
# Заглавная буква в lib/ ломает модуль только на Linux — локально под Windows
# всё «работает», поэтому проверяем здесь.

step 'имена файлов в lib/ в нижнем регистре'
upper=$(find lib -type f | LC_ALL=C grep '[A-Z]' || true)
[ -z "$upper" ] || fail "заглавные буквы в путях lib/:"$'\n'"$upper"

# --- Тесты -----------------------------------------------------------------
# Здесь только то, что проверяется без рантайма Битрикса: разбор настроек,
# нормализация, матрицы решений. Всё остальное ловит приёмочный чек-лист из
# CLAUDE.md — заменить его тестами нельзя, а дополнить нужно.

step 'тесты чистой логики'
shopt -s nullglob
tests=(tests/*_test.php)
shopt -u nullglob

if [ ${#tests[@]} -eq 0 ]
then
	echo '    тестов нет'
else
	for test in "${tests[@]}"
	do
		php "$test" || fail "тест не прошёл: $test"
	done
fi

# --- Версия ----------------------------------------------------------------
# Единственный способ понять, что стоит на портале.

version=$(read_version) || fail 'не читается install/version.php'
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
# распаковке файлы рассыплются по /local/modules/. В репозитории такого каталога
# нет — собираем его во временном месте из списка SHIP.

command -v zip >/dev/null || fail 'нужен zip в PATH'

step "сборка $ARCHIVE"
rm -f "$ARCHIVE"

root=$(pwd)
staging=$(mktemp -d)
trap 'rm -rf "$staging"' EXIT

mkdir "${staging}/${MODULE}"
for item in "${SHIP[@]}"
do
	cp -R "$item" "${staging}/${MODULE}/"
done

(cd "$staging" && zip -rq "${root}/${ARCHIVE}" "$MODULE" -x '*.DS_Store' '*.min.js' '*.map')

step 'проверка первого уровня в архиве'
stray=$(unzip -Z1 "$ARCHIVE" | grep -v "^${MODULE}/" || true)
[ -z "$stray" ] || fail "в архиве есть файлы вне ${MODULE}/:"$'\n'"$stray"

for item in "${KEEP[@]}"
do
	case "$item" in .git|"$ARCHIVE") continue ;; esac
	unzip -Z1 "$ARCHIVE" | grep -q "^${MODULE}/${item}" \
		&& fail "в архив попало то, что должно остаться в репозитории: ${item}"
done

files=$(unzip -Z1 "$ARCHIVE" | grep -vc '/$' || true)
echo
echo "Готово: $ARCHIVE — версия $version, файлов: $files, размер: $(du -h "$ARCHIVE" | cut -f1)"
echo "Дальше: распаковать в /home/bitrix/www/local/modules/ и поставить через"
echo "Marketplace → Установленные решения (docs/build-and-install.md)."
