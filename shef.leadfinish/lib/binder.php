<?php

namespace Shef\LeadFinish;

use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\CommentEntry;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use CCrmOwnerType;

/**
 * Привязка существующей сделки к лиду и завершение лида.
 *
 * Порядок намеренно такой: сначала пишем связь в сделку, потом закрываем лид.
 * Если второй шаг упадёт, останется привязанная сделка при открытом лиде —
 * это видно и чинится повторным нажатием. Обратный порядок дал бы закрытый лид
 * без связи, что заметить куда труднее.
 */
class Binder
{
	/** Статус лида «обработан успешно». */
	private const LEAD_STATUS_CONVERTED = 'CONVERTED';

	public static function bind(int $leadId, int $dealId): Result
	{
		$result = new Result();

		$leadFactory = Container::getInstance()->getFactory(CCrmOwnerType::Lead);
		$dealFactory = Container::getInstance()->getFactory(CCrmOwnerType::Deal);
		if (!$leadFactory || !$dealFactory)
		{
			return $result->addError(new Error('CRM недоступен'));
		}

		$lead = $leadFactory->getItem($leadId);
		if (!$lead)
		{
			return $result->addError(new Error('Лид #' . $leadId . ' не найден'));
		}

		$deal = $dealFactory->getItem($dealId);
		if (!$deal)
		{
			return $result->addError(new Error('Сделка #' . $dealId . ' не найдена'));
		}

		$leadTitle = (string)$lead->get(Item::FIELD_NAME_TITLE);
		$dealTitle = (string)$deal->get(Item::FIELD_NAME_TITLE);

		// 1. Связь: перезаписываем LEAD_ID, даже если сделка была привязана к
		//    другому лиду (решение владельца — «перетереть»).
		$deal->set('LEAD_ID', $leadId);

		$dealOperation = $dealFactory->getUpdateOperation($deal);
		$dealOperation->enableCheckAccess();

		$dealResult = $dealOperation->launch();
		if (!$dealResult->isSuccess())
		{
			return $result->addError(new Error(
				'Не удалось привязать сделку: ' . implode('; ', $dealResult->getErrorMessages())
			));
		}

		// 2. Завершаем лид. Статус ставим независимо от текущего — повторное
		//    закрытие уже закрытого лида считаем нормальным (решение владельца).
		$lead->set(Item::FIELD_NAME_STAGE_ID, self::LEAD_STATUS_CONVERTED);

		$leadOperation = $leadFactory->getUpdateOperation($lead);
		$leadOperation->enableCheckAccess();

		$leadResult = $leadOperation->launch();
		if (!$leadResult->isSuccess())
		{
			return $result->addError(new Error(
				'Сделка привязана, но лид не закрылся: ' . implode('; ', $leadResult->getErrorMessages())
			));
		}

		// 3. Таймлайны. Не критично для результата: связь уже есть, поэтому
		//    ошибку записи комментария не поднимаем в интерфейс.
		self::writeTimeline($leadId, $dealId, $leadTitle, $dealTitle);

		$result->setData([
			'dealId' => $dealId,
			'dealTitle' => $dealTitle,
			'dealUrl' => '/crm/deal/details/' . $dealId . '/',
		]);

		return $result;
	}

	private static function writeTimeline(int $leadId, int $dealId, string $leadTitle, string $dealTitle): void
	{
		global $USER;
		$authorId = is_object($USER) ? (int)$USER->GetID() : 0;

		try
		{
			// Привязка комментария к сущности задаётся только через BINDINGS —
			// см. TimelineEntry::fetchParams().
			CommentEntry::create([
				'AUTHOR_ID' => $authorId,
				'TEXT' => 'Лид привязан к сделке «' . $dealTitle . '» (#' . $dealId . ')',
				'BINDINGS' => [
					['ENTITY_TYPE_ID' => CCrmOwnerType::Lead, 'ENTITY_ID' => $leadId],
				],
			]);

			CommentEntry::create([
				'AUTHOR_ID' => $authorId,
				'TEXT' => 'К сделке привязан лид «' . $leadTitle . '» (#' . $leadId . ')',
				'BINDINGS' => [
					['ENTITY_TYPE_ID' => CCrmOwnerType::Deal, 'ENTITY_ID' => $dealId],
				],
			]);
		}
		catch (\Throwable $e)
		{
			// Комментарий — вспомогательная запись; ронять из-за него уже
			// выполненную привязку нельзя.
			if (function_exists('AddMessage2Log'))
			{
				AddMessage2Log('shef.leadfinish: не удалось записать таймлайн — ' . $e->getMessage());
			}
		}
	}
}
