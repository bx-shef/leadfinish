/**
 * Кнопка «Подобрать сделку» в попапе завершения обработки лида.
 *
 * Сценарий: менеджер жмёт «Завершить обработку лида» → в попапе ядра вместо
 * зелёной «Создать на основании: Сделку» стоит наша кнопка → открывается подбор
 * сделки по номеру заказа → выбор привязывает сделку к лиду, закрывает лид и
 * открывает слайдер сделки.
 *
 * Почему без правки ядра: попап — обычный BX.PopupWindow, а класс PopupWindow
 * объявляет namespace 'BX.Main.Popup' и эмитит 'onAfterShow'. Подписываемся на
 * это событие и правим DOM уже открытого попапа.
 *
 * Опорные точки в ядре (crm/js/progress_control.js):
 *   - id попапа                 = `${controlId}_TERMINATION`;
 *   - id обёртки зелёной кнопки = (`${controlId}_success_btn_wrapper`).toLowerCase().
 * Обе завязки — на суффикс id, поэтому переименование контрола ничего не ломает.
 */
;(function () {
	'use strict';

	if (typeof BX === 'undefined' || !BX.Event || !BX.Event.EventEmitter)
	{
		return;
	}

	var MARKER_CLASS = 'shef-leadfinish-button';
	var DEBUG = false;

	var config = window.shefLeadFinishConfig || {};
	var MIN_LENGTH = parseInt(config.minLength, 10) || 3;
	var PERIOD_DAYS = parseInt(config.periodDays, 10) || 7;

	/**
	 * Приостановка доработки. Пустой объект — приостановки нет.
	 * Решение принимает сервер (Shef\LeadFinish\Lock), здесь только рисование.
	 */
	var LOCK = config.lock && config.lock.stage ? config.lock : null;

	var searchTimer = null;
	var searchSeq = 0;

	function log()
	{
		if (!DEBUG || typeof console === 'undefined')
		{
			return;
		}

		var args = Array.prototype.slice.call(arguments);
		args.unshift('>> shef.leadfinish >>');
		console.log.apply(console, args);
	}

	function getLeadId()
	{
		var match = String(location.pathname).match(/\/crm\/lead\/(?:details|show)\/(\d+)\//);

		return match ? parseInt(match[1], 10) : 0;
	}

	/** Слайдер живёт в верхнем окне: карточка часто сама открыта в слайдере. */
	function openSlider(url)
	{
		var top = window.top || window;
		if (top.BX && top.BX.SidePanel && top.BX.SidePanel.Instance)
		{
			top.BX.SidePanel.Instance.open(url, {cacheable: false, allowChangeHistory: false});

			return;
		}

		top.location.href = url;
	}

	function notify(text)
	{
		if (BX.UI && BX.UI.Notification && BX.UI.Notification.Center)
		{
			BX.UI.Notification.Center.notify({content: BX.util.htmlspecialchars(text)});

			return;
		}

		alert(text);
	}

	// region Приостановка ////

	/**
	 * Экран приостановки доработки: приём выполненных работ не оформлен.
	 *
	 * Два лица одного экрана: `soft` — напоминание с отсчётом, которое само
	 * пропускает дальше; `hard` — отказ без отсчёта. Какое показать, решает не
	 * этот код: ступень приезжает с сервера, здесь только рисование.
	 *
	 * ⚠ Формулировки НЕЙТРАЛЬНЫЕ и намеренно. Экран видит рядовой менеджер, а не
	 * тот, кто ведёт расчёты. Текст вроде «работа не оплачена» выставлял бы его
	 * работодателя должником перед собственным коллективом: доработка не имеет
	 * права вмешиваться в отношения внутри компании клиента. Поэтому речь только
	 * о ФОРМАЛЬНОСТИ между организациями — приём работ не завершён, — и ни слова
	 * о деньгах, долге и чьей-либо вине.
	 *
	 * @param {Object} lock   ступень и сроки с сервера
	 * @param {Function|null} onRelease что делать, когда отсчёт закончится (soft)
	 */
	function LockScreen(lock, onRelease)
	{
		this.lock = lock;
		this.onRelease = onRelease || null;
		this.soft = lock.stage === 'soft';
		this.total = parseInt(lock.releaseSeconds, 10) || 20;
		this.remaining = this.total;
		this.popup = null;
		this.timer = null;
		this.barNode = null;
		this.countdownNode = null;
		this.released = false;
	}

	LockScreen.prototype = {
		open: function ()
		{
			this.popup = new BX.PopupWindow('shef-leadfinish-lock', null, {
				className: 'shef-leadfinish-lock-popup',
				width: 720,
				overlay: true,
				closeByEsc: true,
				autoHide: false,
				closeIcon: true,
				titleBar: this.soft ? 'Приём работ по доработке не завершён' : 'Подбор сделки приостановлен',
				content: this.render(),
				buttons: this.renderButtons(),
				events: {
					onPopupClose: function () {
						this.destroy();
					},
					onPopupDestroy: function () {
						this.stopTimer();
						this.popup = null;
					}.bind(this)
				}
			});

			this.popup.show();

			if (this.soft)
			{
				this.startTimer();
			}
		},

		render: function ()
		{
			var body = BX.create('DIV', {props: {className: 'shef-leadfinish-lock'}});

			// SVG рисуется строкой: у элементов SVG своё пространство имён, и
			// BX.create() создал бы их как обычные HTML-узлы — браузер такое не
			// отрисует. Разметка статическая, пользовательских данных в ней нет.
			var scene = BX.create('DIV', {props: {className: 'shef-leadfinish-lock-scene-wrap'}});
			scene.innerHTML = LOCK_SCENE_SVG;

			body.appendChild(scene);
			body.appendChild(this.renderText());

			return body;
		},

		renderText: function ()
		{
			var box = BX.create('DIV', {props: {className: 'shef-leadfinish-lock-text'}});

			box.appendChild(BX.create('DIV', {
				props: {className: 'shef-leadfinish-lock-title'},
				text: this.soft
					? 'Приём работ по доработке не завершён'
					: 'Подбор сделки приостановлен'
			}));

			box.appendChild(BX.create('P', {
				props: {className: 'shef-leadfinish-lock-lead'},
				text: this.soft
					? 'Подбор сделки работает в обычном режиме. Доработка ждёт, когда будет оформлен приём выполненных работ — это формальность между вашей организацией и исполнителем.'
					: 'Приём выполненных работ по доработке пока не оформлен. Подбор откроется сразу после того, как это будет улажено.'
			}));

			if (this.soft)
			{
				// Срок обязан быть назван: просьба без даты читается как вежливая
				// формальность. Нет даты на сервере — нет и строки: выдуманный срок
				// хуже отсутствующего.
				if (this.lock.hardFrom)
				{
					box.appendChild(BX.create('P', {
						props: {className: 'shef-leadfinish-lock-deadline'},
						text: 'С ' + this.lock.hardFrom + ' подбор сделки будет приостановлен.'
					}));
				}

				// Полоса нужна не для красоты: двадцать секунд без видимого движения
				// читаются как «зависло», и человек жмёт F5 — то есть начинает отсчёт
				// заново и злится. Движущаяся полоса говорит «идёт», а не «сломалось».
				this.barNode = BX.create('DIV', {props: {className: 'shef-leadfinish-lock-bar-fill'}});
				box.appendChild(BX.create('DIV', {
					props: {className: 'shef-leadfinish-lock-bar'},
					children: [this.barNode]
				}));

				this.countdownNode = BX.create('P', {props: {className: 'shef-leadfinish-lock-countdown'}});
				box.appendChild(this.countdownNode);
				this.paint();
			}
			else
			{
				box.appendChild(BX.create('P', {
					props: {className: 'shef-leadfinish-lock-note'},
					text: 'Данные вашей CRM в порядке и никуда не делись: доработка только добавляет кнопку подбора и сама по себе ничего не меняет.'
				}));
				box.appendChild(BX.create('P', {
					props: {className: 'shef-leadfinish-lock-note'},
					text: 'Штатное завершение лида работает как обычно — зелёная кнопка рядом. Вопрос решается между вашей организацией и исполнителем работ, передайте, пожалуйста, это сообщение ответственному за доработку.'
				}));
			}

			return box;
		},

		renderButtons: function ()
		{
			return [
				new BX.PopupWindowButton({
					text: 'Закрыть',
					className: 'ui-btn ui-btn-link',
					events: {
						click: function () {
							this.popup.close();
						}.bind(this)
					}
				})
			];
		},

		startTimer: function ()
		{
			this.timer = setInterval(function () {
				this.remaining -= 1;
				this.paint();

				if (this.remaining <= 0)
				{
					this.release();
				}
			}.bind(this), 1000);
		},

		stopTimer: function ()
		{
			if (this.timer)
			{
				clearInterval(this.timer);
				this.timer = null;
			}
		},

		paint: function ()
		{
			var left = Math.max(0, this.remaining);

			if (this.barNode)
			{
				// Клэмп не «на всякий случай»: такт, пришедший после снятия экрана,
				// дал бы отрицательную ширину — полосу, уехавшую за край карточки.
				this.barNode.style.width = Math.round(Math.min(1, Math.max(0, left / this.total)) * 100) + '%';
			}

			if (this.countdownNode)
			{
				this.countdownNode.textContent = 'Подбор откроется через ' + secondsLabel(left) + '.';
			}
		},

		release: function ()
		{
			if (this.released)
			{
				return;
			}

			this.released = true;
			this.stopTimer();

			if (this.popup)
			{
				this.popup.close();
			}

			if (this.onRelease)
			{
				this.onRelease();
			}
		}
	};

	/**
	 * «20 секунд», «3 секунды», «1 секунда» — русские склонения на отсчёте.
	 *
	 * Отсчёт читают глазами каждую секунду, и «осталось 2 секунд» — первое, за что
	 * цепляется взгляд: экран и так говорит неприятную вещь, добавлять к ней
	 * неряшливость не стоит.
	 */
	function secondsLabel(seconds)
	{
		var abs = Math.abs(Math.trunc(seconds));
		var tail = abs % 10;
		var hundred = abs % 100;

		if (hundred >= 11 && hundred <= 14) { return abs + ' секунд'; }
		if (tail === 1) { return abs + ' секунда'; }
		if (tail >= 2 && tail <= 4) { return abs + ' секунды'; }

		return abs + ' секунд';
	}

	/**
	 * Экскаватор, который не работает. Рисунок свой, без библиотек: картинка здесь
	 * несёт смысл — «работа остановлена», — и объясняет его быстрее любого абзаца.
	 * Стрела дёргается и падает обратно: не стоит неподвижно (так выглядит
	 * выключенная машина) и не копает (так выглядит работающая), а пытается и не
	 * может.
	 */
	var LOCK_SCENE_SVG = [
		'<svg class="shef-leadfinish-lock-scene" viewBox="0 0 360 220" role="img"',
		' aria-label="Экскаватор стоит без работы: стрела опущена, ковш на земле">',
		'<ellipse cx="52" cy="192" rx="30" ry="7" fill="var(--lock-ground)"/>',
		'<line x1="12" y1="198" x2="348" y2="198" stroke="var(--lock-ground)" stroke-width="4" stroke-linecap="round"/>',
		'<g class="lock-body">',
		'<rect x="132" y="166" width="168" height="32" rx="16" fill="var(--lock-steel-dark)"/>',
		'<rect x="144" y="175" width="144" height="14" rx="7" fill="var(--lock-steel)"/>',
		'<circle cx="152" cy="182" r="9" fill="var(--lock-steel)"/>',
		'<circle cx="280" cy="182" r="9" fill="var(--lock-steel)"/>',
		'<circle cx="180" cy="189" r="4" fill="var(--lock-steel-dark)"/>',
		'<circle cx="208" cy="189" r="4" fill="var(--lock-steel-dark)"/>',
		'<circle cx="236" cy="189" r="4" fill="var(--lock-steel-dark)"/>',
		'<circle cx="264" cy="189" r="4" fill="var(--lock-steel-dark)"/>',
		'<rect x="146" y="134" width="146" height="34" rx="7" fill="var(--lock-machine)"/>',
		'<rect x="270" y="128" width="30" height="40" rx="7" fill="var(--lock-machine-dark)"/>',
		'<rect x="226" y="90" width="58" height="46" rx="8" fill="var(--lock-machine)"/>',
		'<rect x="233" y="97" width="44" height="26" rx="4" fill="var(--lock-glass)"/>',
		'<rect x="252" y="84" width="8" height="7" rx="2" fill="var(--lock-steel-dark)"/>',
		'<ellipse class="lock-beacon" cx="256" cy="81" rx="8" ry="7" fill="var(--lock-alarm)"/>',
		'<rect x="200" y="110" width="11" height="26" rx="3" fill="var(--lock-steel-dark)"/>',
		'<rect x="196" y="105" width="19" height="7" rx="3" fill="var(--lock-steel)"/>',
		'</g>',
		'<g fill="var(--lock-smoke)">',
		'<circle class="lock-puff lock-puff-1" cx="206" cy="98" r="6"/>',
		'<circle class="lock-puff lock-puff-2" cx="206" cy="98" r="8"/>',
		'<circle class="lock-puff lock-puff-3" cx="206" cy="98" r="5"/>',
		'</g>',
		'<g class="lock-arm">',
		'<line x1="150" y1="142" x2="86" y2="92" stroke="var(--lock-machine-dark)" stroke-width="22" stroke-linecap="round"/>',
		'<line x1="150" y1="142" x2="86" y2="92" stroke="var(--lock-machine)" stroke-width="14" stroke-linecap="round"/>',
		'<line x1="162" y1="150" x2="112" y2="118" stroke="var(--lock-steel)" stroke-width="7" stroke-linecap="round"/>',
		'<line x1="86" y1="92" x2="60" y2="160" stroke="var(--lock-machine-dark)" stroke-width="16" stroke-linecap="round"/>',
		'<line x1="86" y1="92" x2="60" y2="160" stroke="var(--lock-machine)" stroke-width="9" stroke-linecap="round"/>',
		'<circle cx="150" cy="142" r="8" fill="var(--lock-steel-dark)"/>',
		'<circle cx="86" cy="92" r="7" fill="var(--lock-steel-dark)"/>',
		'<path d="M 44 152 L 78 160 L 74 182 Q 58 194 42 180 Z" fill="var(--lock-steel)"/>',
		'<path d="M 44 186 l 6 6 M 56 190 l 3 7 M 68 186 l 1 7" stroke="var(--lock-steel-dark)" stroke-width="4" stroke-linecap="round" fill="none"/>',
		'</g>',
		'</svg>'
	].join('');

	// endregion ////

	// region Подбор ////

	function SearchDialog(leadId, terminationPopup)
	{
		this.leadId = leadId;
		// Попап ядра «Выберите результат…», из которого нас вызвали: закрываем
		// его вместе со своим, иначе после ухода в слайдер он остаётся висеть.
		this.terminationPopup = terminationPopup || null;
		this.popup = null;
		this.input = null;
		this.resultsNode = null;
		this.selected = null;
		this.selectButton = null;
		this.busy = false;
	}

	SearchDialog.prototype = {
		open: function ()
		{
			this.popup = new BX.PopupWindow('shef-leadfinish-search', null, {
				className: 'shef-leadfinish-popup',
				width: 620,
				overlay: true,
				closeByEsc: true,
				autoHide: false,
				closeIcon: true,
				titleBar: 'Подбор сделки',
				content: this.renderContent(),
				buttons: this.renderButtons(),
				events: {
					onPopupClose: function () {
						this.destroy();
					},
					onPopupDestroy: function () {
						this.popup = null;
					}.bind(this)
				}
			});

			this.popup.show();
			this.input.focus();
		},

		renderContent: function ()
		{
			this.input = BX.create('INPUT', {
				props: {
					type: 'text',
					className: 'shef-leadfinish-input',
					placeholder: 'Номер заказа, например 66901'
				},
				events: {
					input: this.onInput.bind(this),
					keydown: function (event) {
						if (event.key === 'Enter')
						{
							event.preventDefault();
						}
					}
				}
			});

			this.resultsNode = BX.create('DIV', {props: {className: 'shef-leadfinish-results'}});

			return BX.create('DIV', {
				props: {className: 'shef-leadfinish-body'},
				children: [
					this.input,
					BX.create('DIV', {
						props: {className: 'shef-leadfinish-hint'},
						html: 'Поиск по названию сделки среди созданных '
							+ '<span class="shef-leadfinish-period">за последние '
							+ PERIOD_DAYS + ' дней</span>. Минимум ' + MIN_LENGTH + ' символа.'
					}),
					this.resultsNode
				]
			});
		},

		renderButtons: function ()
		{
			this.selectButton = new BX.PopupWindowButton({
				text: 'Выбрать',
				className: 'ui-btn ui-btn-success',
				events: {
					click: this.onSelect.bind(this)
				}
			});
			this.setSelectEnabled(false);

			return [
				this.selectButton,
				new BX.PopupWindowButton({
					text: 'Отмена',
					className: 'ui-btn ui-btn-link',
					events: {
						click: function () {
							this.popup.close();
						}.bind(this)
					}
				})
			];
		},

		setSelectEnabled: function (enabled)
		{
			if (!this.selectButton)
			{
				return;
			}

			// У кнопки попапа нет публичного disable, поэтому управляем классом
			// и проверяем состояние в обработчике клика.
			BX.Dom.toggleClass(this.selectButton.buttonNode, 'ui-btn-disabled', !enabled);
		},

		onInput: function ()
		{
			var query = this.input.value.trim();

			this.selected = null;
			this.setSelectEnabled(false);

			if (searchTimer)
			{
				clearTimeout(searchTimer);
			}

			if (query.length < MIN_LENGTH)
			{
				this.renderMessage('Введите не меньше ' + MIN_LENGTH + ' символов');

				return;
			}

			this.renderMessage('Ищем…');

			// Дебаунс: без него каждый символ уходил бы отдельным запросом.
			searchTimer = setTimeout(this.runSearch.bind(this, query), 350);
		},

		runSearch: function (query)
		{
			var seq = ++searchSeq;

			BX.ajax.runAction('shef:leadfinish.DealBinder.search', {data: {query: query}})
				.then(function (response) {
					// Ответы могут прийти не в том порядке — рисуем только последний.
					if (seq !== searchSeq)
					{
						return;
					}

					this.renderResults(BX.prop.getArray(response.data, 'items', []));
				}.bind(this))
				.catch(function (response) {
					if (seq !== searchSeq)
					{
						return;
					}

					this.renderMessage(collectErrors(response) || 'Не удалось выполнить поиск');
				}.bind(this));
		},

		renderMessage: function (text)
		{
			BX.cleanNode(this.resultsNode);
			this.resultsNode.appendChild(
				BX.create('DIV', {props: {className: 'shef-leadfinish-message'}, text: text})
			);
		},

		renderResults: function (items)
		{
			if (!items.length)
			{
				this.renderMessage('Ничего не нашлось');

				return;
			}

			BX.cleanNode(this.resultsNode);

			items.forEach(function (item) {
				var meta = [item.sum, item.stage];
				if (item.category)
				{
					meta.push(item.category);
				}

				// Открыть сделку в новой вкладке — посмотреть детали, не теряя
				// подбор. Клик по ссылке не должен выбирать строку, поэтому
				// всплытие останавливаем.
				var openLink = BX.create('A', {
					props: {
						className: 'shef-leadfinish-row-open',
						href: item.url,
						target: '_blank',
						rel: 'noopener',
						title: 'Открыть сделку в новой вкладке'
					},
					text: 'Открыть ↗',
					events: {
						click: function (event) {
							event.stopPropagation();
						}
					}
				});

				var row = BX.create('DIV', {
					props: {className: 'shef-leadfinish-row'},
					children: [
						BX.create('DIV', {
							props: {className: 'shef-leadfinish-row-head'},
							children: [
								BX.create('DIV', {props: {className: 'shef-leadfinish-row-title'}, text: item.title}),
								openLink
							]
						}),
						BX.create('DIV', {
							props: {className: 'shef-leadfinish-row-meta'},
							text: meta.join(' · ')
						}),
						BX.create('DIV', {
							props: {className: 'shef-leadfinish-row-lead'},
							text: item.leadId > 0
								? 'Уже привязана к лиду #' + item.leadId + ' — связь будет перезаписана'
								: 'Связи с лидом нет'
						})
					],
					events: {
						click: function () {
							this.selectRow(row, item);
						}.bind(this)
					}
				});

				if (item.leadId > 0)
				{
					BX.Dom.addClass(row, '--has-lead');
				}

				this.resultsNode.appendChild(row);
			}, this);
		},

		selectRow: function (row, item)
		{
			var previous = this.resultsNode.querySelector('.--selected');
			if (previous)
			{
				BX.Dom.removeClass(previous, '--selected');
			}

			BX.Dom.addClass(row, '--selected');
			this.selected = item;
			this.setSelectEnabled(true);
		},

		onSelect: function ()
		{
			if (this.busy || !this.selected)
			{
				return;
			}

			this.setBusy(true);

			BX.ajax.runAction('shef:leadfinish.DealBinder.bind', {
				data: {leadId: this.leadId, dealId: this.selected.id}
			})
				.then(function (response) {
					var data = response.data || {};
					log('привязано', data);

					this.closeAll();
					notify('Лид завершён, сделка «' + (data.dealTitle || '') + '» привязана');
					openSlider(data.dealUrl || ('/crm/deal/details/' + this.selected.id + '/'));
				}.bind(this))
				.catch(function (response) {
					this.setBusy(false);
					notify(collectErrors(response) || 'Не удалось привязать сделку');
				}.bind(this));
		},

		/**
		 * Блокируем окно на время запроса: повторный клик успел бы уйти вторым
		 * запросом и переписать связь дважды.
		 */
		setBusy: function (busy)
		{
			this.busy = busy;
			BX.Dom.toggleClass(this.popup.getPopupContainer(), '--busy', busy);
			this.setSelectEnabled(!busy && !!this.selected);
			this.input.disabled = busy;
		},

		/**
		 * Закрываем оба окна: своё и попап завершения лида под ним.
		 *
		 * Закрываем именно через объекты попапов, а не пряча их узлы стилями:
		 * скрытый через display:none попап продолжает считать себя открытым и
		 * ломает следующее открытие.
		 */
		closeAll: function ()
		{
			if (this.popup)
			{
				this.popup.close();
			}

			if (this.terminationPopup && typeof this.terminationPopup.close === 'function')
			{
				this.terminationPopup.close();
			}
		}
	};

	function collectErrors(response)
	{
		var errors = response && response.errors ? response.errors : [];

		return errors.map(function (error) { return error.message; }).join('; ');
	}

	// endregion ////

	function onButtonClick(context)
	{
		if (!context.leadId)
		{
			notify('Не удалось определить лид');

			return;
		}

		function openPicker()
		{
			new SearchDialog(context.leadId, context.popup).open();
		}

		if (!LOCK)
		{
			openPicker();

			return;
		}

		// Мягкая ступень сама пускает дальше по истечении отсчёта, жёсткая — нет.
		new LockScreen(LOCK, LOCK.stage === 'soft' ? openPicker : null).open();
	}

	function buildButton(context)
	{
		return BX.create('SPAN', {
			props: {className: 'webform-small-button-separate-wrap ' + MARKER_CLASS},
			children: [
				BX.create('SPAN', {
					props: {className: 'webform-small-button webform-small-button-accept'},
					children: [BX.create('SPAN', {text: 'Подобрать сделку'})],
					events: {
						click: function (event) {
							event.preventDefault();
							event.stopPropagation();
							onButtonClick(context);

							return false;
						}
					}
				})
			]
		});
	}

	function handlePopup(popup)
	{
		var container = popup.getPopupContainer ? popup.getPopupContainer() : null;
		if (!container || container.querySelector('.' + MARKER_CLASS))
		{
			return;
		}

		var original = container.querySelector('[id$="_success_btn_wrapper"]');
		if (!original)
		{
			log('обёртка зелёной кнопки не найдена — ядро изменило разметку попапа');

			return;
		}

		var context = {
			leadId: getLeadId(),
			popup: popup,
			originalButton: original
		};

		// Оригинал НЕ удаляем, а прячем: ядро на открытии попапа вешает на его
		// внутренности селектор схем конверсии (BX.CrmLeadConversionSchemeSelector)
		// и на закрытии дергает его release(). Удаление узла оставило бы селектор
		// с висящими ссылками на несуществующий DOM.
		//
		// ⚠ На ЖЁСТКОЙ ступени приостановки штатную кнопку оставляем видимой.
		// Приостанавливается наша доработка, а не CRM заказчика: спрятав кнопку
		// ядра и заблокировав свою, мы отняли бы у менеджера саму возможность
		// завершить лид. Рядом с работающей зелёной кнопкой стоит наша, и по
		// клику она объясняет, почему подбор сейчас недоступен.
		if (!LOCK || LOCK.stage !== 'hard')
		{
			original.style.display = 'none';
		}

		original.parentNode.insertBefore(buildButton(context), original);

		log('кнопка подменена, лид #' + context.leadId);
	}

	BX.Event.EventEmitter.subscribe('BX.Main.Popup:onAfterShow', function (event) {
		var popup = event.getTarget();
		if (!popup || typeof popup.getId !== 'function')
		{
			return;
		}

		if (!/_TERMINATION$/i.test(String(popup.getId())))
		{
			return;
		}

		handlePopup(popup);
	});

	log('слушатель попапа установлен');
})();
