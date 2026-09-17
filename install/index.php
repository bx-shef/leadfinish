<?php

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

Loc::loadMessages(__FILE__);

if (class_exists('shef_leadfinish'))
{
	return;
}

/**
 * Модуль «Своя кнопка завершения лида».
 *
 * Подменяет зелёную кнопку «Создать на основании: …» в попапе завершения
 * обработки лида на свою. Файлы поставки Битрикса не трогает: попап ловится
 * через штатное событие ядра BX.Main.Popup:onAfterShow.
 *
 * Установка регистрирует обработчик main::OnEpilog, который подключает JS
 * только на детальной лида и только пользователям из списка allowed_users
 * в .settings.php модуля.
 */
class shef_leadfinish extends CModule
{
	public $MODULE_ID = 'shef.leadfinish';
	public $MODULE_VERSION;
	public $MODULE_VERSION_DATE;
	public $MODULE_NAME;
	public $MODULE_DESCRIPTION;
	public $PARTNER_NAME;

	public function __construct()
	{
		$version = [];
		include __DIR__ . '/version.php';

		$this->MODULE_VERSION = $arModuleVersion['VERSION'] ?? '1.0.0';
		$this->MODULE_VERSION_DATE = $arModuleVersion['VERSION_DATE'] ?? '';
		$this->MODULE_NAME = Loc::getMessage('SHEF_LEADFINISH_MODULE_NAME');
		$this->MODULE_DESCRIPTION = Loc::getMessage('SHEF_LEADFINISH_MODULE_DESC');
		$this->PARTNER_NAME = Loc::getMessage('SHEF_LEADFINISH_PARTNER_NAME');
	}

	public function DoInstall(): void
	{
		ModuleManager::registerModule($this->MODULE_ID);
		$this->InstallEvents();

		// Настроек в базе у модуля нет: список allowed_users живёт в
		// .settings.php, и ставить здесь нечего.
	}

	public function DoUninstall(): void
	{
		$this->UnInstallEvents();

		// Чистим настройки, оставшиеся в базе от версий до 1.4.0, когда
		// allowed_users ещё хранился там.
		Option::delete($this->MODULE_ID);
		ModuleManager::unRegisterModule($this->MODULE_ID);
	}

	public function InstallEvents(): void
	{
		RegisterModuleDependences(
			'main',
			'OnEpilog',
			$this->MODULE_ID,
			'\\Shef\\LeadFinish\\EventHandler',
			'onEpilog'
		);
	}

	public function UnInstallEvents(): void
	{
		UnRegisterModuleDependences(
			'main',
			'OnEpilog',
			$this->MODULE_ID,
			'\\Shef\\LeadFinish\\EventHandler',
			'onEpilog'
		);
	}
}
