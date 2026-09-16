<?php

namespace Shef\LeadFinish;

use Bitrix\Main\Config\Configuration;

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
	 * Список живёт в `.settings.php` модуля, ключ `allowed_users`.
	 *
	 * **Пустой список = всем авторизованным**: так задумано, чтобы после обкатки
	 * на нескольких людях включить доработку на всех, просто очистив список, без
	 * правки кода.
	 *
	 * ⚠ Три случая, которые легко склеить в один, а нельзя:
	 *
	 * - ключа НЕТ вовсе — доработка не настроена, доступа нет ни у кого;
	 * - список пуст — доступ у всех авторизованных;
	 * - список задан, но разобрать нечего («abc») — снова ни у кого.
	 *
	 * Разошлись они намеренно. «Нет ключа» — это, как правило, частичное
	 * обновление: распаковали `lib/`, а `.settings.php` на сервере остался
	 * старый. Считать это за «пусто» значило бы тихо раскатать кнопку на весь
	 * портал. По той же причине мусор — не повод включить её всем: опечатка в
	 * настройке не должна расширять доступ.
	 */
	public static function isAllowedUser(): bool
	{
		global $USER;

		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			return false;
		}

		$raw = Configuration::getInstance(self::MODULE_ID)->get('allowed_users');

		if ($raw === null)
		{
			return false;
		}

		if (self::isEmptyList($raw))
		{
			return true;
		}

		return in_array((int)$USER->GetID(), self::userIds($raw), true);
	}

	/**
	 * Настройка есть, но пуста.
	 *
	 * Пробелы считаем пустотой: `'  '` в файле — это стёртый список, а не список
	 * из одного пробела.
	 */
	private static function isEmptyList($raw): bool
	{
		if (is_string($raw))
		{
			return trim($raw) === '';
		}

		return is_array($raw) && $raw === [];
	}

	/**
	 * ID из настройки: и списком, и строкой через запятую.
	 *
	 * Строку принимаем потому, что файл правят руками, и `'44,562'` — первое,
	 * что там напишут.
	 *
	 * @return int[]
	 */
	private static function userIds($raw): array
	{
		if (is_string($raw))
		{
			$raw = explode(',', $raw);
		}

		if (!is_array($raw))
		{
			return [];
		}

		return array_values(array_filter(array_map('intval', $raw)));
	}
}
