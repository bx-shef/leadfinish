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
		$this->InstallFiles();

		// Настроек в базе у модуля нет: список allowed_users живёт в
		// .settings.php, и ставить здесь нечего.
	}

	public function DoUninstall(): void
	{
		$this->UnInstallEvents();
		$this->UnInstallFiles();

		// Чистим настройки, оставшиеся в базе от версий до 1.4.0, когда
		// allowed_users ещё хранился там.
		Option::delete($this->MODULE_ID);
		ModuleManager::unRegisterModule($this->MODULE_ID);
	}

	/**
	 * Раскладка фронта в /bitrix/js/shef.leadfinish/.
	 *
	 * ⚠ Каталог самого модуля браузеру недоступен — ни в `bitrix/modules/`, ни в
	 * `local/modules/`. В поставке nginx стоит
	 * `location ~* ^/bitrix/(modules|local_cache|...) { deny all; }`, и
	 * `/bitrix/modules/...` отдаёт 403. Поэтому JS и CSS копируются туда, откуда
	 * они отдаются, — ровно как это делают штатные модули Битрикса.
	 *
	 * Побочный и полезный итог: путь к ассетам перестал зависеть от того, куда
	 * поставлен сам модуль. `bitrix/modules/` и `local/modules/` работают
	 * одинаково.
	 */
	public function InstallFiles(): bool
	{
		$target = $_SERVER['DOCUMENT_ROOT'] . $this->assetDir();

		CopyDirFiles(__DIR__ . '/../js', $target . '/js', true, true);
		CopyDirFiles(__DIR__ . '/../css', $target . '/css', true, true);

		return true;
	}

	/**
	 * ⚠ Удаляем только свой подкаталог, а не `/bitrix/js/`. Путь собран из
	 * MODULE_ID, а не из внешних данных, и это здесь единственная защита.
	 */
	public function UnInstallFiles(): bool
	{
		DeleteDirFilesEx($this->assetDir());

		return true;
	}

	/**
	 * Куда кладётся фронт — из `Access`, чтобы значение было одно.
	 *
	 * ⚠ Не дублируй строку здесь. Установщик и `EventHandler` обязаны считать
	 * один и тот же путь: разойдутся — установка разложит файлы в одно место, а
	 * страница будет просить их из другого, и выглядеть это будет как «кнопка
	 * пропала», а не как ошибка установки.
	 *
	 * Подключаем файл напрямую: на момент установки namespace модуля ещё не
	 * зарегистрирован, автозагрузка до него не дотянется.
	 */
	private function assetDir(): string
	{
		require_once __DIR__ . '/../lib/access.php';

		return \Shef\LeadFinish\Access::ASSET_DIR;
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
