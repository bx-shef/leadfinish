<?php

/**
 * Кому доступна кастомизация: матрица решений Access::isAllowedUser().
 *
 * Рантайма Битрикса тут нет — ядро подменено заглушками, поэтому проверяется
 * настоящий класс, а не его копия. Всё остальное в модуле завязано на рантайм
 * и ловится приёмочным чек-листом из shef.leadfinish/CLAUDE.md.
 *
 * Запуск: php tests/access_test.php (или ./build.sh --check).
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
use Shef\LeadFinish\Access;

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

require __DIR__ . '/../shef.leadfinish/lib/access.php';

/** [описание, значение allowed_users или NOT_SET, ID пользователя, ожидание] */
const NOT_SET = '__ключа нет__';

$cases = [
	['ключа нет — доработка не настроена', NOT_SET, 44, false],
	['пустой список — всем', [], 44, true],
	['пустая строка — всем', '', 44, true],
	['строка из пробелов — всем', '   ', 44, true],
	['список, свой', [44, 562], 44, true],
	['список, свой второй', [44, 562], 562, true],
	['список, чужой', [44, 562], 99, false],
	['строка через запятую, свой', '44,562', 562, true],
	['строка с пробелом после запятой', '44, 562', 562, true],
	['мусор списком — никому', ['abc'], 44, false],
	['мусор строкой — никому', 'abc', 44, false],
	['ноль не пользователь', [0], 44, false],
	['не список и не строка', false, 44, false],
];

$failed = 0;

foreach ($cases as [$name, $raw, $userId, $expected])
{
	Configuration::$data = $raw === NOT_SET ? [] : ['allowed_users' => $raw];
	$GLOBALS['USER'] = new UserStub($userId);

	$got = Access::isAllowedUser();

	if ($got !== $expected)
	{
		$failed++;
		printf("ПРОВАЛ: %s (пользователь %d) — получили «%s», ждали «%s»\n",
			$name, $userId, $got ? 'да' : 'нет', $expected ? 'да' : 'нет');
	}
}

// Неавторизованный не проходит ни при каких настройках: проверка авторизации
// стоит ДО чтения списка, иначе пустой список открыл бы кнопку гостю.
Configuration::$data = ['allowed_users' => []];
$GLOBALS['USER'] = new UserStub(44, false);

if (Access::isAllowedUser() !== false)
{
	$failed++;
	echo "ПРОВАЛ: неавторизованный получил доступ при пустом списке\n";
}

$total = count($cases) + 1;

if ($failed > 0)
{
	printf("access_test: провалов %d из %d\n", $failed, $total);
	exit(1);
}

printf("access_test: %d случаев, все прошли\n", $total);
