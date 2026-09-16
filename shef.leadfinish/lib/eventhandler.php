<?php

namespace Shef\LeadFinish;

use Bitrix\Main\Application;
use Bitrix\Main\Page\Asset;

/**
 * Подключение ассетов, подменяющих кнопку в попапе завершения обработки лида.
 */
class EventHandler
{
	private const ASSET_DIR = '/local/modules/' . Access::MODULE_ID;

	/**
	 * Обработчик main::OnEpilog.
	 *
	 * Скрипт вешает глобальный слушатель события попапов, поэтому подключаем его
	 * узко: только там, где он может понадобиться, и только тем, кому кнопка
	 * предназначена. Иначе слушатель висел бы на каждой странице портала.
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
	 * Детальная карточка лида — и обычная, и открытая в слайдере.
	 */
	private static function isLeadPage(): bool
	{
		$request = Application::getInstance()->getContext()->getRequest();
		$path = (string)parse_url((string)$request->getRequestUri(), PHP_URL_PATH);

		return (bool)preg_match('#^/crm/lead/(details|show)/\d+/#', $path);
	}
}
