# leadfinish

Битрикс24 (коробка). В попапе завершения обработки лида менеджер выбирает **уже
существующую** сделку вместо создания новой.

Репозиторий исходников локального модуля `shef.leadfinish`.
Разработчик — **ИП Шевчик И.С.**, [bx-shef.by](http://bx-shef.by/).

## Что где лежит

| Путь | Что это |
| --- | --- |
| `shef.leadfinish/` | исходники модуля — то, что уезжает на портал в `/local/modules/` |
| `shef.leadfinish/README.md` | **описание модуля для человека**: что делает, как выглядит для менеджера, как настроить |
| `shef.leadfinish/CLAUDE.md` | памятка для AI-агента: инварианты, ловушки, решения владельца |
| `docs/module-structure.md` | как устроен локальный модуль Битрикс24: именование, раскладка, грабли |
| `docs/build-and-install.md` | сборка архива, установка на портал, приёмочный чек-лист |
| `CONTRIBUTING.md` | ветки, коммиты, PR, чек-лист перед мержем |

Каталог модуля лежит в корне репозитория целиком и собирается как есть — поэтому
`README.md` и `CLAUDE.md` самого модуля уезжают в поставку вместе с кодом.

## Сборка поставки

Из корня репозитория, подробности — в [`docs/build-and-install.md`](docs/build-and-install.md):

```bash
find shef.leadfinish -name '*.php' -print0 | xargs -0 -n1 php -l
node --check shef.leadfinish/js/lead-finish-button.js

zip -rq shef.leadfinish.zip shef.leadfinish -x '*.DS_Store' '*/.git/*'
unzip -l shef.leadfinish.zip | head    # первым уровнем должен быть shef.leadfinish/
```

Дальше архив распаковывается в `/home/bitrix/www/local/modules/`, модуль ставится
через **Marketplace → Установленные решения**.

Версия — в `shef.leadfinish/install/version.php`, поднимается при каждом
изменении поведения.

## Правила работы

Ветки, коммиты, PR и чек-лист перед мержем — в [`CONTRIBUTING.md`](CONTRIBUTING.md).
Коротко: в `master` не пушим, всё через PR, мержит владелец.

## Лицензия

[MIT](LICENSE).
