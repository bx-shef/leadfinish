<?php

/**
 * Приостановка доработки: кого касается и какая ступень на день.
 *
 * Рантайма Битрикса тут нет — ядро подменено заглушками, поэтому проверяется
 * настоящий Lock, а не его копия. Даты в случаях заведомо прошлые или заведомо
 * далёкие, чтобы результат не зависел от дня запуска.
 *
 * Запуск: php tests/lock_test.php (или ./build.sh --check).
 */

namespace Bitrix\Main\Config;

class Configuration
{
	/** @var array<string, mixed> Содержимое .settings.php для текущего случая. */
	public static array $data = [];

	public static function getInstance($id): self
	{
		return new self();
	}

	public function get($key)
	{
		return self::$data[$key] ?? null;
	}
}

namespace Test;

use Bitrix\Main\Config\Configuration;
use Shef\LeadFinish\Lock;

class UserStub
{
	public function __construct(private int $id, private bool $authorized = true)
	{
	}

	public function IsAuthorized(): bool
	{
		return $this->authorized;
	}

	public function GetID(): int
	{
		return $this->id;
	}
}

require __DIR__ . '/../shef.leadfinish/lib/userlist.php';
require __DIR__ . '/../shef.leadfinish/lib/access.php';
require __DIR__ . '/../shef.leadfinish/lib/lock.php';

const PAST = '2000-01-01';
const FUTURE = '2999-01-01';

$failed = 0;
$total = 0;

function check(string $name, string $expected, string $got): void
{
	global $failed, $total;

	$total++;

	if ($got !== $expected)
	{
		$failed++;
		printf("ПРОВАЛ: %s — получили «%s», ждали «%s»\n", $name, $got, $expected);
	}
}

/** Ступень «сейчас» при заданных настройках и пользователе. */
function stage(array $lock, int $userId = 562, bool $authorized = true, ?string $override = null): string
{
	Configuration::$data = ['lock' => $lock];
	$GLOBALS['USER'] = new UserStub($userId, $authorized);

	return Lock::currentStage($override);
}

// --- Кого касается приостановка -------------------------------------------
// hard_from в прошлом, поэтому ступень зависит ровно от списка users.

$always = ['hard_from' => PAST];

check('списка нет — касается всех', Lock::STAGE_HARD, stage($always));
check('пустой список — всех', Lock::STAGE_HARD, stage($always + ['users' => []]));
check('пустая строка — всех', Lock::STAGE_HARD, stage($always + ['users' => '']));
check('строка из пробелов — всех', Lock::STAGE_HARD, stage($always + ['users' => '   ']));
check('свой в списке', Lock::STAGE_HARD, stage($always + ['users' => [562]], 562));
check('чужой не в списке', Lock::STAGE_NONE, stage($always + ['users' => [562]], 44));
check('список строкой, свой', Lock::STAGE_HARD, stage($always + ['users' => '44,562'], 562));

// ⚠ Из-за этих двух случаев тест и появился: опечатка в users приостанавливала
// доработку всему порталу, хотя докблок обещал обратное.
check('мусор списком — никого', Lock::STAGE_NONE, stage($always + ['users' => ['abc']]));
check('мусор строкой — никого', Lock::STAGE_NONE, stage($always + ['users' => 'abc']));
check('скаляр вместо списка — никого', Lock::STAGE_NONE, stage($always + ['users' => 562]));

// ⚠ Опечатка не должна превращаться в ЧУЖОЙ ID: intval([562]) молча даёт 1,
// intval('5 62') — 5, и приостановка доставалась бы постороннему.
check('лишняя скобка [[562]] — не трогает пользователя 1', Lock::STAGE_NONE,
	stage($always + ['users' => [[562]]], 1));
check('лишняя скобка [[562]] — не трогает и 562', Lock::STAGE_NONE,
	stage($always + ['users' => [[562]]], 562));
check('пробел вместо запятой — не трогает пользователя 5', Lock::STAGE_NONE,
	stage($always + ['users' => '5 62'], 5));
check('true вместо ID — не трогает пользователя 1', Lock::STAGE_NONE,
	stage($always + ['users' => [true]], 1));
check('хвост после числа — не трогает 562', Lock::STAGE_NONE,
	stage($always + ['users' => '562abc'], 562));
check('мусор рядом с годным ID — годный работает', Lock::STAGE_HARD,
	stage($always + ['users' => ['562', 'abc']], 562));
check('пробелы вокруг ID', Lock::STAGE_HARD,
	stage($always + ['users' => [' 562 ']], 562));
check('ноль не пользователь', Lock::STAGE_NONE, stage($always + ['users' => [0]]));
check('неавторизованный', Lock::STAGE_NONE, stage($always, 562, false));

// --- Ступень на календарный день ------------------------------------------

function scheduleFor(array $lock): array
{
	Configuration::$data = ['lock' => $lock];

	return Lock::schedule();
}

check('рубильник off гасит даже прошедшие даты', Lock::STAGE_NONE,
	Lock::stageForDay('2026-09-25', scheduleFor(['hard_from' => PAST, 'off' => true])));
check('день до обеих дат', Lock::STAGE_NONE,
	Lock::stageForDay('2026-09-16', scheduleFor(['soft_from' => '2026-09-17', 'hard_from' => '2026-09-21'])));
check('день между датами — мягко', Lock::STAGE_SOFT,
	Lock::stageForDay('2026-09-18', scheduleFor(['soft_from' => '2026-09-17', 'hard_from' => '2026-09-21'])));
check('день после жёсткой — жёстко', Lock::STAGE_HARD,
	Lock::stageForDay('2026-09-25', scheduleFor(['soft_from' => '2026-09-17', 'hard_from' => '2026-09-21'])));
check('только soft', Lock::STAGE_SOFT,
	Lock::stageForDay('2026-09-25', scheduleFor(['soft_from' => '2026-09-17'])));
check('только hard', Lock::STAGE_HARD,
	Lock::stageForDay('2026-09-25', scheduleFor(['hard_from' => '2026-09-21'])));
// Перепутанные местами даты дают «жёстко с более ранней», а не бессмыслицу.
check('даты перепутаны местами', Lock::STAGE_HARD,
	Lock::stageForDay('2026-09-19', scheduleFor(['soft_from' => '2026-09-21', 'hard_from' => '2026-09-17'])));
// strtotime('2026-02-31') не падает, а молча даёт 3 марта — дата отбрасывается.
check('несуществующая дата отбрасывается', Lock::STAGE_NONE,
	Lock::stageForDay('2026-12-31', scheduleFor(['hard_from' => '2026-02-31'])));
check('мусор в off выключателем не считается', Lock::STAGE_HARD,
	Lock::stageForDay('2026-09-25', scheduleFor(['hard_from' => PAST, 'off' => 'потом'])));

// --- Обход из адреса ------------------------------------------------------
// Умеет только ПОВЫШАТЬ ступень: ссылка «снять приостановку» разошлась бы по
// переписке за минуту.

check('обход поднимает с none до hard', Lock::STAGE_HARD, stage([], 562, true, 'hard'));
check('обход поднимает с none до soft', Lock::STAGE_SOFT, stage([], 562, true, 'soft'));
check('обход не понижает hard до soft', Lock::STAGE_HARD, stage($always, 562, true, 'soft'));
check('неизвестная ступень игнорируется', Lock::STAGE_NONE, stage([], 562, true, 'none'));
check('обход не действует на чужого', Lock::STAGE_NONE,
	stage(['hard_from' => FUTURE, 'users' => [562]], 44, true, 'hard'));

if ($failed > 0)
{
	printf("lock_test: провалов %d\n", $failed);
	exit(1);
}

printf("lock_test: %d случаев, все прошли\n", $total);
