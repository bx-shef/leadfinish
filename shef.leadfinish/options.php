<?php

/**
 * Настройки модуля: Marketplace → Установленные решения → «Настроить».
 */

use Bitrix\Main\Config\Option;
use Bitrix\Main\Localization\Loc;

/** @var CMain $APPLICATION */
global $APPLICATION;

Loc::loadMessages(__FILE__);

$module_id = 'shef.leadfinish';
$moduleRight = $APPLICATION->GetGroupRight($module_id);

if ($moduleRight < 'S')
{
	$APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));
}

$allowedUsers = (string)Option::get($module_id, 'allowed_users', '');

if (
	$_SERVER['REQUEST_METHOD'] === 'POST'
	&& $moduleRight >= 'W'
	&& (isset($_POST['save']) || isset($_POST['apply']))
	&& check_bitrix_sessid()
) {
	// Нормализуем ввод: принимаем «44, 562», «44;562», перевод строки — храним
	// в едином виде «44,562», чтобы разбор в обработчике был предсказуемым.
	$raw = (string)($_POST['allowed_users'] ?? '');
	$ids = array_filter(array_map('intval', preg_split('/[^\d]+/', $raw) ?: []));
	$allowedUsers = implode(',', array_unique($ids));

	Option::set($module_id, 'allowed_users', $allowedUsers);

	if (isset($_POST['save']))
	{
		LocalRedirect($APPLICATION->GetCurPageParam('', ['save', 'apply']));
	}
}

$tabControl = new CAdminTabControl(
	'tabControl',
	[
		[
			'DIV' => 'edit1',
			'TAB' => Loc::getMessage('SHEF_LEADFINISH_OPT_TAB'),
			'TITLE' => Loc::getMessage('SHEF_LEADFINISH_OPT_TAB_TITLE'),
		],
	]
);

$tabControl->Begin();
?>
<form method="post" action="<?= htmlspecialcharsbx($APPLICATION->GetCurPage()) ?>?mid=<?= htmlspecialcharsbx($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>">
	<?= bitrix_sessid_post() ?>
	<?php $tabControl->BeginNextTab(); ?>

	<tr>
		<td width="40%" style="vertical-align: top;">
			<label for="allowed_users"><?= Loc::getMessage('SHEF_LEADFINISH_OPT_USERS') ?>:</label>
		</td>
		<td width="60%">
			<input
				type="text"
				size="50"
				id="allowed_users"
				name="allowed_users"
				value="<?= htmlspecialcharsbx($allowedUsers) ?>"
				<?= $moduleRight < 'W' ? 'disabled' : '' ?>
			>
			<div style="margin-top: 6px; color: #6a737d;">
				<?= Loc::getMessage('SHEF_LEADFINISH_OPT_USERS_HINT') ?>
			</div>
		</td>
	</tr>

	<?php
	$tabControl->Buttons(['disabled' => $moduleRight < 'W']);
	$tabControl->End();
	?>
</form>
