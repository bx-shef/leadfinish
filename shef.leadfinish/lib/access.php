<?php

namespace Shef\LeadFinish;

use Bitrix\Main\Config\Option;

/**
 * Кому доступна кастомизация.
 *
 * Один источник правды и для показа кнопки, и для ajax-действий: иначе кнопку
 * спрятали бы, а действие осталось бы вызываемым по прямому адресу.
 */
class Access
{
	public const MODULE_ID = 'shef.leadfinish';

	/**
	 * Настройка allowed_users — ID пользователей через запятую.
	 *
	 * **Пустое значение = всем авторизованным**: так задумано, чтобы после
	 * обкатки на нескольких людях включить функциональность на всех, просто
	 * очистив поле, без правки кода.
	 */
	public static function isAllowedUser(): bool
	{
		global $USER;

		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			return false;
		}

		$allowed = trim((string)Option::get(self::MODULE_ID, 'allowed_users', ''));
		if ($allowed === '')
		{
			return true;
		}

		$ids = array_filter(array_map('intval', explode(',', $allowed)));

		// Список из одного мусора («abc») даёт пустой массив — это не повод
		// внезапно включить кастомизацию всем, поэтому здесь именно false.
		return in_array((int)$USER->GetID(), $ids, true);
	}
}
