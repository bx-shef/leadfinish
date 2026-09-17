<?php

namespace Shef\LeadFinish;

/**
 * Список ID пользователей из настройки.
 *
 * Общий разбор для `Access` и `Lock`: читаются их списки одинаково, а вот что
 * значит результат — по-разному, и это остаётся в самих классах. У `Access`
 * отсутствие настройки — «никому», у `Lock` — «всех». Сравни их докблоки, прежде
 * чем переносить сюда ещё и трактовку.
 */
class UserList
{
	/**
	 * Разобранный список ID.
	 *
	 * ⚠ `null` и пустой массив значат РАЗНОЕ, и склеивать их нельзя: `null` —
	 * списка нет или он пуст, пустой массив — список задан, но читаемых ID в нём
	 * не нашлось. Что из этого следует, решает вызывающий.
	 *
	 * @return int[]|null
	 */
	public static function parse($raw): ?array
	{
		if ($raw === null)
		{
			return null;
		}

		if (is_string($raw))
		{
			if (trim($raw) === '')
			{
				return null;
			}

			$raw = explode(',', $raw);
		}

		if (!is_array($raw))
		{
			return [];
		}

		if ($raw === [])
		{
			return null;
		}

		$ids = [];

		foreach ($raw as $value)
		{
			$id = self::toId($value);

			if ($id !== null)
			{
				$ids[] = $id;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Одно значение настройки — в ID пользователя.
	 *
	 * ⚠ Проверка строгая, а НЕ `intval()`, и это главное здесь. `intval([562])`
	 * молча даёт `1`, `intval('5 62')` — `5`, `intval(true)` — снова `1`. То есть
	 * обычная опечатка при правке файла руками — лишняя скобка, пробел вместо
	 * запятой — не отбрасывалась бы, а превращалась в ЧУЖОЙ ID, и настройка
	 * начинала действовать не на того: у `Access` это выдача кастомизации
	 * постороннему, у `Lock` — приостановка не тому человеку.
	 *
	 * Нечитаемое значение выбрасывается, а не роняет весь список: `'44,abc'`
	 * оставляет 44. Расширить этим доступ нельзя — 44 в списке и так назван
	 * явно.
	 */
	private static function toId($value): ?int
	{
		if (is_int($value))
		{
			return $value > 0 ? $value : null;
		}

		if (is_string($value) && preg_match('/^\s*\d+\s*$/', $value))
		{
			$id = (int)trim($value);

			return $id > 0 ? $id : null;
		}

		return null;
	}
}
