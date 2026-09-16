<?php

namespace Shef\LeadFinish;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Type\DateTime;
use CCrmCurrency;
use CCrmOwnerType;

/**
 * Подбор сделок для привязки к лиду.
 *
 * Менеджер вводит номер заказа («66901»), а сделка называется
 * «Заказ покупателя 00КА-66901» — поэтому ищем подстроку в названии.
 */
class DealSearch
{
	/** Минимум символов в запросе: на одном-двух выборка бессмысленна и тяжела. */
	public const MIN_QUERY_LENGTH = 3;

	/** Сколько сделок показываем. */
	public const LIMIT = 20;

	/** Ищем среди сделок не старше этого срока (дней). */
	public const PERIOD_DAYS = 7;

	/**
	 * @return array<int, array<string, mixed>> строки для списка подбора
	 */
	public static function find(string $query): array
	{
		$query = trim($query);
		if (mb_strlen($query) < self::MIN_QUERY_LENGTH)
		{
			return [];
		}

		$factory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if (!$factory)
		{
			return [];
		}

		// getItemsFilteredByPermissions сам подмешивает в запрос ограничения прав
		// текущего пользователя — чужие сделки в подбор не попадут.
		$items = $factory->getItemsFilteredByPermissions([
			'select' => [
				Item::FIELD_NAME_ID,
				Item::FIELD_NAME_TITLE,
				Item::FIELD_NAME_OPPORTUNITY,
				Item::FIELD_NAME_CURRENCY_ID,
				Item::FIELD_NAME_STAGE_ID,
				Item::FIELD_NAME_CATEGORY_ID,
				Item::FIELD_NAME_CREATED_TIME,
				'LEAD_ID',
			],
			'filter' => [
				'%' . Item::FIELD_NAME_TITLE => $query,
				'>=' . Item::FIELD_NAME_CREATED_TIME => self::periodStart(),
			],
			'order' => [Item::FIELD_NAME_ID => 'DESC'],
			'limit' => self::LIMIT,
		]);

		$result = [];
		foreach ($items as $item)
		{
			$result[] = self::formatItem($item, $factory);
		}

		return $result;
	}

	/**
	 * Начало периода поиска — показываем его пользователю рядом с полем,
	 * чтобы «не нашлось» не выглядело поломкой.
	 */
	public static function periodStart(): DateTime
	{
		return DateTime::createFromTimestamp(time() - self::PERIOD_DAYS * 86400);
	}

	/**
	 * Сумма с валютой в виде обычного текста.
	 *
	 * CCrmCurrency::MoneyToString() вырезает теги, но **оставляет HTML-сущности**:
	 * в результате в списке буквально виднелось «1&nbsp;500 BYN». Фронт рисует
	 * строку как текст, поэтому сущности раскрываем здесь, а неразрывный пробел
	 * заменяем обычным.
	 */
	private static function formatMoney(float $sum, string $currencyId): string
	{
		$formatted = CCrmCurrency::MoneyToString($sum, $currencyId);
		$formatted = html_entity_decode($formatted, ENT_QUOTES, 'UTF-8');

		return trim(str_replace("\xC2\xA0", ' ', $formatted));
	}

	private static function formatItem(Item $item, $factory): array
	{
		$categoryId = (int)$item->get(Item::FIELD_NAME_CATEGORY_ID);
		$stageId = (string)$item->get(Item::FIELD_NAME_STAGE_ID);
		$currencyId = (string)$item->get(Item::FIELD_NAME_CURRENCY_ID);
		$leadId = (int)$item->get('LEAD_ID');

		$stage = $factory->getStage($stageId);
		$category = $factory->isCategoriesSupported() ? $factory->getCategory($categoryId) : null;

		return [
			'id' => $item->getId(),
			'title' => (string)$item->get(Item::FIELD_NAME_TITLE),
			'sum' => self::formatMoney(
				(float)$item->get(Item::FIELD_NAME_OPPORTUNITY),
				$currencyId !== '' ? $currencyId : CCrmCurrency::GetBaseCurrencyID()
			),
			'stage' => $stage ? $stage->getName() : $stageId,
			'category' => $category ? $category->getName() : '',
			'leadId' => $leadId,
			'url' => '/crm/deal/details/' . $item->getId() . '/',
		];
	}
}
