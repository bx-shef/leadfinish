<?php

namespace Shef\LeadFinish;

use Bitrix\Main\Application;
use Bitrix\Main\Page\Asset;

/**
 * Подключение ассетов, подменяющих кнопку в попапе завершения обработки лида.
 */
class EventHandler
{
	/** Путь один на весь модуль — см. докблок у Access::ASSET_DIR. */
	private const ASSET_DIR = Access::ASSET_DIR;

	/**
	 * Обработчик main::OnEpilog.
	 *
	 * Скрипт вешает глобальный слушатель события попапов, поэтому подключаем его
	 * узко: только там, где попап завершения существует, и только тем, кому
	 * кнопка предназначена. Иначе слушатель висел бы на каждой странице портала.
	 *
	 * Список страниц — в `matchesLeadPath()`.
	 */
	public static function onEpilog(): void
	{
		if (!Access::isAllowedUser() || !self::isLeadPage())
		{
			return;
		}

		$asset = Asset::getInstance();
		$asset->addCss(self::ASSET_DIR . '/css/lead-finish.css');
		$asset->addJs(self::ASSET_DIR . '/js/lead-finish-button.js');

		// Параметры, которые фронту неоткуда взять: период поиска для подсказки
		// у поля, минимальная длина запроса и ступень приостановки.
		$asset->addString(
			'<script>window.shefLeadFinishConfig = '
			. \Bitrix\Main\Web\Json::encode([
				'minLength' => DealSearch::MIN_QUERY_LENGTH,
				'periodDays' => DealSearch::PERIOD_DAYS,
				'lock' => Lock::payload(self::lockOverride()),
			])
			. ';</script>'
		);
	}

	/**
	 * Обход ступени из адреса (`?lock=soft`), чтобы посмотреть экран до даты.
	 * Понижать им ступень нельзя — см. Lock::parseOverride().
	 */
	private static function lockOverride(): ?string
	{
		$request = Application::getInstance()->getContext()->getRequest();
		$value = $request->get('lock');

		return is_string($value) ? $value : null;
	}

	/**
	 * Страница, на которой попап завершения обработки лида вообще существует.
	 */
	private static function isLeadPage(): bool
	{
		$request = Application::getInstance()->getContext()->getRequest();
		$path = (string)parse_url((string)$request->getRequestUri(), PHP_URL_PATH);

		return self::matchesLeadPath($path);
	}

	/**
	 * Путь страницы — из тех, где менеджер может завершить обработку лида.
	 *
	 * Карточка (обычная и открытая в слайдере) и список устроены одинаково:
	 * попап завершения ядро строит в обоих местах через
	 * `CCrmViewHelper::RenderProgressControl()`.
	 *
	 * Канбан — третье место и устроен иначе: окно там `kanban_column_popup`, без
	 * `_TERMINATION` в id и без обёртки зелёной кнопки, а лид берётся из
	 * состояния `BX.Crm.KanbanComponent`. Разбирается это на фронте; здесь
	 * достаточно отдать ассеты и на нём.
	 *
	 * ⚠ Вынесено из `isLeadPage()` и не трогает запрос, чтобы правило
	 * проверялось тестом — `tests/eventhandler_test.php`.
	 */
	private static function matchesLeadPath(string $path): bool
	{
		return (bool)preg_match('#^/crm/lead/(?:details|show)/\d+/#', $path)
			|| (bool)preg_match('#^/crm/lead/(?:list|kanban)(?:/|$)#', $path);
	}
}
