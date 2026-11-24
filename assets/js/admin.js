(function () {
	'use strict';

	var cfg = window.plpAdmin || {};
	var i18n = cfg.i18n || {};

	var state = {
		scanId: 0,
		total: 0,
		running: false,
		aborted: false
	};

	function $(selector, root) {
		return (root || document).querySelector(selector);
	}

	function $all(selector, root) {
		return Array.prototype.slice.call((root || document).querySelectorAll(selector));
	}

	function sleep(ms) {
		return new Promise(function (resolve) {
			window.setTimeout(resolve, ms);
		});
	}

	function ajax(action, data) {
		var body = new URLSearchParams();
		body.set('action', action);
		body.set('nonce', cfg.nonce || '');

		Object.keys(data || {}).forEach(function (key) {
			var value = data[key];
			if (value === undefined || value === null) {
				return;
			}
			if (Array.isArray(value)) {
				value.forEach(function (item) {
					body.append(key + '[]', item);
				});
				return;
			}
			body.set(key, value);
		});

		return fetch(cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		}).then(function (response) {
			return response.json();
		}).then(function (payload) {
			if (!payload || payload.success !== true) {
				var message = payload && payload.data && payload.data.message ? payload.data.message : (i18n.error || 'Ошибка запроса');
				throw new Error(message);
			}
			return payload.data;
		});
	}

	function showError(message) {
		var box = $('#plp-form-error');
		if (box) {
			box.hidden = false;
			box.textContent = message;
		} else {
			window.alert(message);
		}
	}

	function clearError() {
		var box = $('#plp-form-error');
		if (box) {
			box.hidden = true;
			box.textContent = '';
		}
	}

	function logLine(text, isError) {
		var list = $('#plp-log');
		if (!list) {
			return;
		}
		var item = document.createElement('li');
		item.textContent = text;
		if (isError) {
			item.className = 'plp-log-error';
		}
		list.appendChild(item);
		while (list.children.length > 300) {
			list.removeChild(list.firstChild);
		}
		list.scrollTop = list.scrollHeight;
	}

	function setProgress(index, total, label) {
		var bar = $('#plp-bar');
		var count = $('#plp-progress-count');
		var labelEl = $('#plp-progress-label');
		var percent = Math.max(0, Math.min(100, Math.round(((index + 1) / Math.max(1, total)) * 100)));

		if (bar) {
			bar.style.width = percent + '%';
		}
		if (count) {
			count.textContent = (i18n.progress || '%1$d из %2$d')
				.replace('%1$d', index + 1)
				.replace('%2$d', total);
		}
		if (labelEl && label) {
			labelEl.textContent = label;
		}
	}

	function showProgress() {
		var progress = $('#plp-progress');
		if (progress) {
			progress.hidden = false;
			progress.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
		}
	}

	function runLoop(scanId) {
		if (state.running) {
			return;
		}

		state.running = true;
		state.aborted = false;
		state.scanId = scanId;
		clearError();
		showProgress();

		var startButton = $('#plp-start');
		if (startButton) {
			startButton.disabled = true;
		}

		(function next() {
			if (state.aborted) {
				logLine(i18n.aborted || 'Скан прерван.', true);
				state.running = false;
				return;
			}

			ajax('plp_next_step', { scan_id: scanId }).then(function (step) {
				if (state.aborted) {
					return;
				}

				if (step.done) {
					logLine(i18n.finishedAll || 'Все шаги выполнены.');
					return ajax('plp_finish_scan', { scan_id: scanId }).then(function (finish) {
						window.location.href = finish.redirect;
					});
				}

				var label = (i18n.step || '%1$s · прогон %2$d/%3$d')
					.replace('%1$s', step.target)
					.replace('%2$d', step.run)
					.replace('%3$d', step.runs);
				setProgress(step.index, step.total, label);

				var controller = new AbortController();
				var timer = window.setTimeout(function () {
					controller.abort();
				}, 120000);

				var status = 0;
				var ok = false;
				var error = '';

				return fetch(step.url, {
					credentials: 'omit',
					cache: 'no-store',
					redirect: 'follow',
					signal: controller.signal
				}).then(function (response) {
					status = response.status;
					ok = status >= 200 && status < 400;
				}).catch(function (err) {
					error = err && err.name === 'AbortError' ? 'timeout' : ((err && err.message) || (i18n.network || 'Сетевая ошибка'));
				}).then(function () {
					window.clearTimeout(timer);

					if (state.aborted) {
						return;
					}

					var resultText = status
						? (i18n.http || 'HTTP %s').replace('%s', status)
						: (error || i18n.network || 'Сетевая ошибка');
					logLine('#' + (step.index + 1) + ' · ' + step.target + ' · ' + resultText, !ok && status !== 200);

					return ajax('plp_step_done', {
						scan_id: scanId,
						index: step.index,
						ok: ok ? 1 : 0,
						http_status: status,
						error: error
					}).then(function () {
						return sleep(250);
					}).then(next);
				});
			}).catch(function (err) {
				showError(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'));
				logLine(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'), true);
				state.running = false;
				if (startButton) {
					startButton.disabled = false;
				}
			});
		})();
	}

	/* Старт нового скана */
	var form = $('#plp-scan-form');
	if (form) {
		form.addEventListener('submit', function (event) {
			event.preventDefault();
		});

		var startButton = $('#plp-start');
		if (startButton) {
			startButton.addEventListener('click', function () {
				if (state.running) {
					return;
				}
				clearError();

				var checked = $all('input[name="plugins[]"]:checked', form).map(function (box) {
					return box.value;
				});

				if (!checked.length) {
					showError(i18n.selectOne || 'Отметьте хотя бы один плагин.');
					return;
				}

				var runsInput = $('#plp-runs');
				var urlsInput = $('#plp-urls');

				startButton.disabled = true;
				logLine(i18n.starting || 'Создаю скан…');

				ajax('plp_start_scan', {
					runs: runsInput ? runsInput.value : 3,
					urls: urlsInput ? urlsInput.value : '',
					plugins: checked
				}).then(function (data) {
					state.total = data.total;
					runLoop(data.scan_id);
				}).catch(function (err) {
					showError(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'));
					startButton.disabled = false;
				});
			});
		}

		var checkAll = $('#plp-check-all');
		if (checkAll) {
			checkAll.addEventListener('change', function () {
				$all('input[name="plugins[]"]', form).forEach(function (box) {
					box.checked = checkAll.checked;
				});
			});
		}
	}

	/* Продолжение незавершённого скана */
	var resume = $('#plp-resume');
	if (resume) {
		resume.addEventListener('click', function () {
			resume.disabled = true;
			runLoop(parseInt(resume.getAttribute('data-scan-id'), 10) || 0);
		});
	}

	/* Прерывание */
	$all('#plp-abort, .plp-abort-active').forEach(function (button) {
		button.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmAbort || 'Прервать текущий скан?')) {
				return;
			}
			var scanId = state.scanId || parseInt(button.getAttribute('data-scan-id'), 10) || 0;
			state.aborted = true;
			if (!scanId) {
				window.location.reload();
				return;
			}
			ajax('plp_abort_scan', { scan_id: scanId }).then(function () {
				window.location.reload();
			}).catch(function (err) {
				showError(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'));
			});
		});
	});

	/* Удаление */
	$all('.plp-delete, #plp-delete-scan').forEach(function (link) {
		link.addEventListener('click', function (event) {
			event.preventDefault();
			if (!window.confirm(i18n.confirmDelete || 'Удалить скан и все его результаты?')) {
				return;
			}
			var scanId = parseInt(link.getAttribute('data-scan-id'), 10) || 0;
			ajax('plp_delete_scan', { scan_id: scanId }).then(function (data) {
				window.location.href = data.redirect;
			}).catch(function (err) {
				showError(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'));
			});
		});
	});

	/* mu-plugin: показать код и повторить установку */
	var muToggle = $('#plp-mu-toggle');
	if (muToggle) {
		muToggle.addEventListener('click', function () {
			var code = $('#plp-mu-code');
			if (!code) {
				return;
			}
			code.hidden = !code.hidden;
			muToggle.textContent = code.hidden ? 'Показать код' : 'Скрыть код';
		});
	}

	var muRetry = $('#plp-mu-retry');
	if (muRetry) {
		muRetry.addEventListener('click', function () {
			muRetry.disabled = true;
			ajax('plp_sync_mu', {}).then(function () {
				window.location.reload();
			}).catch(function (err) {
				showError(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'));
				muRetry.disabled = false;
			});
		});
	}

	/* Включение обратно плагинов из прошлого скана */
	var restoreButton = $('#plp-restore');
	if (restoreButton) {
		restoreButton.addEventListener('click', function () {
			if (!window.confirm(i18n.confirmRestore || 'Включить обратно плагины из этого скана?')) {
				return;
			}
			restoreButton.disabled = true;
			var scanId = parseInt(restoreButton.getAttribute('data-scan-id'), 10) || 0;
			ajax('plp_restore_scan', { scan_id: scanId }).then(function (data) {
				var text = (i18n.restored || 'Включено плагинов: %d').replace('%d', data.activated);
				if (data.errors && data.errors.length) {
					text += '\n' + data.errors.join('\n');
				}
				window.alert(text);
				window.location.reload();
			}).catch(function (err) {
				showError(err && err.message ? err.message : (i18n.error || 'Ошибка запроса'));
				restoreButton.disabled = false;
			});
		});
	}
})();
