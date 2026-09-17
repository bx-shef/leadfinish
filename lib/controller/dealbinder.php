<?php

namespace Shef\LeadFinish\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Shef\LeadFinish\Access;
use Shef\LeadFinish\Binder;
use Shef\LeadFinish\DealSearch;
use Shef\LeadFinish\Lock;

/**
 * Ajax подбора и привязки сделки к лиду.
 *
 * Действия с фронта:
 *   shef:leadfinish.DealBinder.search
 *   shef:leadfinish.DealBinder.bind
 */
class DealBinder extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		// Оставляем штатную защиту: авторизация + проверка csrf-токена.
		return [
			new ActionFilter\Authentication(),
			new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			new ActionFilter\Csrf(),
		];
	}

	/**
	 * Подбор сделок по части названия.
	 *
	 * @return array{items: array, period: string}|null
	 */
	public function searchAction(string $query = ''): ?array
	{
		if (!$this->prepare())
		{
			return null;
		}

		return [
			'items' => DealSearch::find($query),
			'minLength' => DealSearch::MIN_QUERY_LENGTH,
		];
	}

	/**
	 * Привязать сделку к лиду и завершить лид.
	 */
	public function bindAction(int $leadId = 0, int $dealId = 0): ?array
	{
		if (!$this->prepare())
		{
			return null;
		}

		if ($leadId <= 0 || $dealId <= 0)
		{
			$this->addError(new Error('Не переданы лид или сделка'));

			return null;
		}

		$result = Binder::bind($leadId, $dealId);
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError($error);
			}

			return null;
		}

		return $result->getData();
	}

	/**
	 * Общая часть: модуль CRM и тот же белый список, по которому показывается
	 * кнопка. Без этой проверки действия остались бы доступны любому
	 * авторизованному — кнопки нет, но адрес-то известен.
	 */
	private function prepare(): bool
	{
		if (!Loader::includeModule('crm'))
		{
			$this->addError(new Error('Модуль CRM не установлен'));

			return false;
		}

		if (!Access::isAllowedUser())
		{
			$this->addError(new Error('Действие недоступно'));

			return false;
		}

		// Жёсткая ступень запрещается ЗДЕСЬ, а не только экраном на фронте:
		// фронт рисует, сервер решает. Иначе приостановка снималась бы правкой
		// JS в консоли браузера, а адреса действий известны из кода модуля.
		if (Lock::currentStage() === Lock::STAGE_HARD)
		{
			$this->addError(new Error(
				'Подбор сделки временно недоступен: приём выполненных работ по доработке не оформлен.'
			));

			return false;
		}

		return true;
	}
}
