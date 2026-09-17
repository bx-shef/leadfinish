<?php

/**
 * Где подключаются ассеты: правило EventHandler::matchesLeadPath().
 *
 * Рантайма Битрикса тут нет — проверяется настоящий метод, а не его копия.
 * Обращения к запросу в нём намеренно нет, поэтому правило и проверяемо.
 *
 * Запуск: php tests/eventhandler_test.php (или ./build.sh --check).
 */

namespace Test;

use ReflectionMethod;
use Shef\LeadFinish\EventHandler;

require __DIR__ . '/../lib/userlist.php';
require __DIR__ . '/../lib/access.php';
require __DIR__ . '/../lib/eventhandler.php';

$matches = new ReflectionMethod(EventHandler::class, 'matchesLeadPath');
$matches->setAccessible(true);

/** [описание, путь, ожидание] */
$cases = [
	// Карточка: обычная и открытая в слайдере.
	['карточка лида', '/crm/lead/details/12/', true],
	['карточка лида, многозначный id', '/crm/lead/details/104857/', true],
	['старый адрес карточки', '/crm/lead/show/12/', true],

	// Список: попап завершения ядро рисует и там, просто id лида лежит не в
	// адресе, а в id попапа.
	['список лидов', '/crm/lead/list/', true],
	['список без завершающего слеша', '/crm/lead/list', true],

	// Канбан: окно там своё, но ассеты нужны те же.
	['канбан лидов', '/crm/lead/kanban/', true],
	['канбан без завершающего слеша', '/crm/lead/kanban', true],

	// ⚠ Завязка на префикс не должна цеплять соседей по имени.
	['не список, а похожий путь', '/crm/lead/listing/', false],
	['не канбан, а похожий путь', '/crm/lead/kanbanboard/', false],
	['импорт лидов', '/crm/lead/import/', false],
	['редактирование лида', '/crm/lead/edit/12/', false],
	['конвертация лида', '/crm/lead/convert/12/', false],
	['корень раздела лидов', '/crm/lead/', false],

	// Карточка без id — не карточка: разбирать там нечего.
	['карточка без id', '/crm/lead/details/', false],
	['id не число', '/crm/lead/details/abc/', false],

	// Соседние сущности CRM: попап завершения бывает только у лида.
	['сделка', '/crm/deal/details/12/', false],
	['список сделок', '/crm/deal/list/', false],
	['контакт', '/crm/contact/details/12/', false],

	// Путь приходит из parse_url(), то есть уже без строки запроса. Пустая
	// строка — обычный случай для консольных и служебных запросов.
	['пустой путь', '', false],
	['произвольная страница портала', '/company/personal/', false],

	// ⚠ Адрес не должен совпадать по середине строки: совпадение только с
	// начала пути, иначе подошёл бы любой префикс.
	['лид в середине пути', '/some/crm/lead/list/', false],
	['лид в середине пути, карточка', '/x/crm/lead/details/12/', false],
];

$failed = 0;

foreach ($cases as [$name, $path, $expected])
{
	$got = $matches->invoke(null, $path);

	if ($got !== $expected)
	{
		$failed++;
		printf("ПРОВАЛ: %s («%s») — получили «%s», ждали «%s»\n",
			$name, $path, $got ? 'да' : 'нет', $expected ? 'да' : 'нет');
	}
}

$total = count($cases);

if ($failed > 0)
{
	printf("eventhandler_test: провалов %d из %d\n", $failed, $total);
	exit(1);
}

printf("eventhandler_test: %d случаев, все прошли\n", $total);
