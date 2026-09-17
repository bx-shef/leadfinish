<?php

namespace Shef\LeadFinish;

use Bitrix\Main\Config\Configuration;

/**
 * Приостановка доработки, пока приём выполненных работ не оформлен.
 *
 * Две ступени: `soft` — напоминание с отсчётом, которое само пропускает дальше;
 * `hard` — подбор не открывается. Здесь только арифметика решения: какой сегодня
 * календарный день, что из этого следует и кого это касается.
 *
 * ⚠ Дат в этом файле НЕТ И БЫТЬ НЕ ДОЛЖНО. Расписание приезжает из `.settings.php`
 * модуля: строка «заблокировать их 17-го» в коде — плохой способ вести разговор о
 * приёмке, а снимать приостановку нужно быстро, правкой одного файла на сервере.
 *
 * ⚠ Любая ошибка в настройках уводит в сторону РАБОТАЮЩЕЙ доработки. Негодная дата
 * отбрасывается, мусор в «off» выключателем не считается. Запереть того, кто всё
 * оформил, из-за своей же опечатки хуже, чем не показать напоминание.
 */
class Lock
{
	public const STAGE_NONE = 'none';
	public const STAGE_SOFT = 'soft';
	public const STAGE_HARD = 'hard';

	/**
	 * Сколько секунд показывается мягкий экран, прежде чем пустить дальше.
	 *
	 * ⚠ Константа кода, а не настройка: настройками управляют даты и рубильник,
	 * а длительность паузы — часть характера напоминания, менять её на живом
	 * портале незачем.
	 */
	public const RELEASE_SECONDS = 20;

	/** Порядок строгости: обход из адреса может только ПОВЫСИТЬ ступень. */
	private const STAGE_RANK = [self::STAGE_NONE => 0, self::STAGE_SOFT => 1, self::STAGE_HARD => 2];

	/**
	 * Ступень «здесь и сейчас»: расписание, календарь и обход из адреса вместе.
	 *
	 * Собрано в одну точку намеренно: порядок тут значащий, а разойтись двум
	 * ответам на вопрос «приостанавливать ли» нельзя — оба выглядели бы верными.
	 *
	 * Два запрета стоят ДО обхода из адреса и гасят его: рубильник «оформлено» и
	 * «приостановка не про этого пользователя». Обход умеет только повышать
	 * ступень, поэтому пропусти его вперёд — и он поднимет ступень там, где
	 * приостановки уже или ещё нет.
	 */
	public static function currentStage(?string $override = null): string
	{
		$schedule = self::schedule();

		// ⚠ Рубильник гасит и обход. Без этой проверки старая ссылка с ?lock=hard
		// из переписки показывала бы менеджеру экран приостановки уже после того,
		// как приёмка оформлена, — причём контроллер в этот момент действие
		// РАЗРЕШАЕТ (он зовёт currentStage() без обхода), так что фронт и сервер
		// разошлись бы в ответе на один вопрос.
		if ($schedule['off'])
		{
			return self::STAGE_NONE;
		}

		if (!self::appliesToCurrentUser($schedule['users']))
		{
			return self::STAGE_NONE;
		}

		$stage = self::stageForDay(self::localDayKey(), $schedule);

		return self::stronger($stage, self::parseOverride($override));
	}

	/**
	 * Разобранное расписание из `.settings.php`.
	 *
	 * @return array{soft_from: ?string, hard_from: ?string, off: bool, users: int[]|null}
	 */
	public static function schedule(): array
	{
		$raw = Configuration::getInstance(Access::MODULE_ID)->get('lock');
		$raw = is_array($raw) ? $raw : [];

		return [
			'soft_from' => self::validDayKey($raw['soft_from'] ?? null),
			'hard_from' => self::validDayKey($raw['hard_from'] ?? null),
			'off' => self::truthy($raw['off'] ?? null),
			'users' => UserList::parse($raw['users'] ?? null),
		];
	}

	/**
	 * Ступень на календарный день.
	 *
	 * ⚠ Жёсткая дата проверяется ПЕРВОЙ, поэтому правило не зависит от того, в
	 * каком порядке стоят даты в настройках: перепутанные местами дают «жёстко с
	 * более ранней», а не тихую бессмыслицу. Каждая дата работает и в одиночку:
	 * только `soft_from` — напоминание без последующей блокировки, только
	 * `hard_from` — блокировка без предупреждения. Обе формы осмысленны.
	 */
	public static function stageForDay(string $day, array $schedule): string
	{
		if ($schedule['off'])
		{
			return self::STAGE_NONE;
		}

		if ($schedule['hard_from'] && $day >= $schedule['hard_from'])
		{
			return self::STAGE_HARD;
		}

		if ($schedule['soft_from'] && $day >= $schedule['soft_from'])
		{
			return self::STAGE_SOFT;
		}

		return self::STAGE_NONE;
	}

	/**
	 * Календарный день «сейчас» по времени портала, строкой `YYYY-MM-DD`.
	 *
	 * ⚠ Местное время, а не UTC: «с 17 сентября» человек говорит про календарь у
	 * себя на стене. При UTC+3 сравнение по UTC включило бы приостановку в три
	 * часа ночи 18-го вместо полуночи 17-го — на сутки позже обещанного.
	 *
	 * ⚠ Сравнивать такие строки можно ровно потому, что `YYYY-MM-DD`
	 * лексикографически совпадает с хронологическим порядком. Это свойство
	 * формата, а не удача: смена формата сломает сравнение молча, поэтому разбор
	 * и сравнение живут в одном классе.
	 */
	public static function localDayKey(?int $timestamp = null): string
	{
		return date('Y-m-d', $timestamp ?? time());
	}

	/**
	 * Данные для фронта. Пустой массив — приостановки нет и рисовать нечего.
	 */
	public static function payload(?string $override = null): array
	{
		$stage = self::currentStage($override);
		if ($stage === self::STAGE_NONE)
		{
			return [];
		}

		$schedule = self::schedule();

		return [
			'stage' => $stage,
			'releaseSeconds' => self::RELEASE_SECONDS,
			// Срок обязан быть назван: просьба без даты читается как формальность.
			// Нет даты в настройках — нет и строки; выдуманный срок хуже отсутствующего.
			'hardFrom' => $schedule['hard_from'] ? self::formatDay($schedule['hard_from']) : '',
		];
	}

	/**
	 * Обход из адреса (`?lock=soft`, `?lock=hard`) — чтобы ПОСМОТРЕТЬ экран, не
	 * дожидаясь даты.
	 *
	 * ⛔ Понимаются только «soft» и «hard». Значения «выключить» здесь нет и не
	 * будет: `?lock=none` стал бы универсальным ключом от приостановки, а такая
	 * ссылка расходится по переписке за минуту. Снимают приостановку в
	 * `.settings.php`, а не строкой в адресе.
	 */
	private static function parseOverride(?string $value): string
	{
		return in_array($value, [self::STAGE_SOFT, self::STAGE_HARD], true) ? $value : self::STAGE_NONE;
	}

	/** Более строгая из двух ступеней. */
	private static function stronger(string $a, string $b): string
	{
		return self::STAGE_RANK[$a] >= self::STAGE_RANK[$b] ? $a : $b;
	}

	/**
	 * Приостановка касается текущего пользователя.
	 *
	 * `null` — списка нет или он пуст, и тогда приостановка касается всех, у кого
	 * доработка включена.
	 *
	 * ⚠ Заданный, но нечитаемый список («abc») приходит сюда пустым массивом — и
	 * это НЕ повод приостановить всем: опечатка в настройке не должна запирать
	 * портал. Именно поэтому `UserList::parse()` разводит «пусто» и «нечитаемо»,
	 * а не возвращает пустой массив в обоих случаях.
	 */
	private static function appliesToCurrentUser(?array $users): bool
	{
		global $USER;

		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			return false;
		}

		if ($users === null)
		{
			return true;
		}

		return in_array((int)$USER->GetID(), $users, true);
	}

	/**
	 * Строка — настоящий календарный день, а не «2026-13-45».
	 *
	 * ⚠ Проверка обратным разбором: `strtotime('2026-02-31')` не падает, а молча
	 * даёт 3 марта — приостановка включилась бы на два дня раньше объявленного.
	 */
	private static function validDayKey($value): ?string
	{
		if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))
		{
			return null;
		}

		$timestamp = strtotime($value . ' 00:00:00 UTC');

		return $timestamp && gmdate('Y-m-d', $timestamp) === $value ? $value : null;
	}

	/**
	 * Рубильник из настроек.
	 *
	 * ⚠ Разрешены только явные «да». Всё остальное — включая `'false'`, `'0'` и
	 * опечатку — это «не выключено»: рубильник обязан требовать осознанного
	 * действия, а не срабатывать от мусора.
	 */
	private static function truthy($value): bool
	{
		if ($value === true)
		{
			return true;
		}

		return is_string($value) && in_array(mb_strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
	}

	/** `2026-09-21` → `21.09.2026`: в интерфейсе дата читается привычно. */
	private static function formatDay(string $day): string
	{
		$timestamp = strtotime($day . ' 00:00:00 UTC');

		return $timestamp ? gmdate('d.m.Y', $timestamp) : $day;
	}
}
