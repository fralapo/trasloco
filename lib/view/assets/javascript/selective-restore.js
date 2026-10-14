(function ($, window, document) {
	'use strict';

	$(function () {
		var config = window.trasloco_selective_restore;
		var dialog = document.getElementById('trasloco-selective-restore-dialog');
		if (!config || !dialog || !window.Trasloco || !window.Trasloco.Import) {
			return;
		}

		var status = dialog.querySelector('.tr-selective-restore-status');
		var body = dialog.querySelector('tbody');
		var confirm = dialog.querySelector('.tr-selective-restore-confirm');
		var submit = dialog.querySelector('.tr-selective-restore-submit');
		var archive = '';
		var locked = !!config.active;
		var strings = config.strings || {};

		function setStatus(message, isError) {
			status.textContent = message;
			status.classList.toggle('is-error', !!isError);
		}

		function makeCell(text) {
			var cell = document.createElement('td');
			cell.textContent = text;
			return cell;
		}

		function selectedSuffixes() {
			return Array.prototype.slice.call(body.querySelectorAll('input[type="checkbox"]:checked')).map(function (input) {
				return input.value;
			});
		}

		function updateSubmit() {
			submit.disabled = locked || !confirm.checked || selectedSuffixes().length === 0;
		}

		function showDialog() {
			if (typeof dialog.showModal === 'function') {
				dialog.showModal();
			} else {
				dialog.setAttribute('open', 'open');
			}
		}

		function renderOperations(operations) {
			body.textContent = '';
			var safe = operations.filter(function (operation) {
				return operation && typeof operation.source_table === 'string' &&
					operation.source_table.indexOf('SERVMASK_PREFIX_') === 0 &&
					typeof operation.destination_table === 'string' &&
					/^[A-Za-z0-9_$]{1,180}$/.test(operation.source_table.slice('SERVMASK_PREFIX_'.length)) &&
					(operation.expected_effect === 'replace_existing_table' || operation.expected_effect === 'create_table') &&
					operation.actions && Number(operation.actions.drop) > 0 && Number(operation.actions.create) > 0;
			});

			safe.forEach(function (operation) {
				var row = document.createElement('tr');
				var selectCell = document.createElement('td');
				var checkbox = document.createElement('input');
				checkbox.type = 'checkbox';
				checkbox.value = operation.source_table.slice('SERVMASK_PREFIX_'.length);
				checkbox.setAttribute('aria-label', operation.source_table);
				checkbox.addEventListener('change', updateSubmit);
				selectCell.appendChild(checkbox);
				row.appendChild(selectCell);
				row.appendChild(makeCell(operation.source_table));
				row.appendChild(makeCell(operation.destination_table));
				row.appendChild(makeCell(operation.expected_effect === 'replace_existing_table' ? strings.replace : strings.create));
				body.appendChild(row);
			});

			setStatus(safe.length ? '' : strings.no_tables, safe.length === 0);
			updateSubmit();
		}

		function openForBackup(filename) {
			archive = filename;
			body.textContent = '';
			confirm.checked = false;
			submit.disabled = true;
			if (locked) {
				setStatus(strings.active, true);
				showDialog();
				return;
			}
			setStatus(strings.loading, false);
			showDialog();
			fetch(config.rest_base + encodeURIComponent(archive) + '/preview', {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.rest_nonce }
			}).then(function (response) {
				if (!response.ok) {
					throw new Error('preview');
				}
				return response.json();
			}).then(function (report) {
				var preview = report && report.database_preview;
				if (!preview || preview.status !== 'available') {
					throw new Error(preview && preview.status === 'unverified' ? 'unverified' : 'preview');
				}
				renderOperations(Array.isArray(preview.table_operations) ? preview.table_operations : []);
			}).catch(function (error) {
				setStatus(error.message === 'unverified' ? strings.preview_required : strings.preview_failed, true);
			});
		}

		document.querySelectorAll('.trasloco-backup-selective-restore').forEach(function (button) {
			button.addEventListener('click', function () {
				openForBackup(button.getAttribute('data-archive'));
			});
		});

		dialog.querySelector('.tr-selective-restore-cancel').addEventListener('click', function () {
			if (typeof dialog.close === 'function') {
				dialog.close();
			} else {
				dialog.removeAttribute('open');
			}
		});
		confirm.addEventListener('change', updateSubmit);
		submit.addEventListener('click', function () {
			var suffixes = selectedSuffixes();
			if (!suffixes.length) {
				setStatus(strings.select_one, true);
				return;
			}
			if (!confirm.checked || locked) {
				updateSubmit();
				return;
			}

			locked = true;
			setStatus(strings.restoring, false);
			submit.disabled = true;
			var owner = window.Trasloco.Util.random(32);
			var options = window.Trasloco.Util.form('#trasloco-backups-form')
				.filter(function (field) {
					return field.name !== 'trasloco_selective_tables' && field.name !== 'trasloco_selective_owner' && field.name !== 'trasloco_selected_tables[]';
				})
				.concat([
					{ name: 'storage', value: window.Trasloco.Util.random(12) },
					{ name: 'archive', value: archive },
					{ name: 'trasloco_selective_tables', value: '1' },
					{ name: 'trasloco_selective_owner', value: owner }
				]);
			suffixes.forEach(function (suffix) {
				options.push({ name: 'trasloco_selected_tables[]', value: suffix });
			});
			var importer = new window.Trasloco.Import();
			importer.setParams(options);
			var setStatus = importer.setStatus;
			importer.setStatus = function (result) {
				setStatus.call(importer, result);
				if (result && (result.type === 'done' || result.type === 'error')) {
					locked = false;
				}
			};
			if (typeof dialog.close === 'function') {
				dialog.close();
			} else {
				dialog.removeAttribute('open');
			}
			importer.start();
		});
	});
})(jQuery, window, document);
