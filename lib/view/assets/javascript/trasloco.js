/*
 * Progress bar and accessibility for the import/export window, plus small
 * helpers for the Export and Backups pages.
 * The server only sends text ("23% complete"): read the number and draw a bar.
 * The bundled scripts build the window without dialog semantics, so they are
 * added here: role, name, announcements, keyboard focus in and out.
 */
(function () {
	'use strict';

	var ui = window.trasloco_ui || {};
	var live = document.createElement('div');
	var wasOpen = false;
	var opener = null;
	var lastKey = '';
	var pendingDelete = null;

	// One polite live region that exists before any text is put in it,
	// so screen readers announce the changes
	live.className = 'screen-reader-text';
	live.setAttribute('role', 'status');
	document.body.appendChild(live);

	function say(text) {
		live.textContent = text;
	}

	function format(text, n) {
		return String(text).replace('%d', n);
	}

	function makeAccessible(box) {
		var title = box.querySelector('h1');
		// a heading with only an icon cannot name the window
		if (title && !title.textContent.trim()) {
			title = null;
		}

		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');

		if (title) {
			title.id = title.id || 'tr-modal-title';
			box.setAttribute('aria-labelledby', title.id);
			box.removeAttribute('aria-label');
		} else {
			// some states (download ready, confirmation) have no text heading: name the window anyway
			box.removeAttribute('aria-labelledby');
			box.setAttribute('aria-label', 'Trasloco');
		}

		// Move keyboard focus into the window when it opens. The title, not a
		// button: during an export the first button is "Stop", and Enter must not hit it.
		if (!box.contains(document.activeElement)) {
			var target = title || box.querySelector('button, a[href]');
			if (title && target === title) {
				title.setAttribute('tabindex', '-1');
			}
			if (target) {
				target.focus({ preventScroll: true });
			}
		}

		// Announce the step when it changes, not every percent
		var section = box.querySelector('section');
		var text = section ? section.innerText.replace(/\s+/g, ' ').trim() : '';
		var key = text.replace(/[\d.,]+\s*%/g, '#');
		if (text && key !== lastKey) {
			lastKey = key;
			say(text);
		}

		return title;
	}

	function opened(box) {
		opener = document.activeElement && document.activeElement !== document.body ? document.activeElement : null;
		var wrap = document.getElementById('wpwrap');
		// keep Tab inside the window: the rest of the admin is inert while it is open
		if (wrap && !wrap.contains(box)) {
			wrap.inert = true;
		}
	}

	function closed() {
		var wrap = document.getElementById('wpwrap');
		if (wrap) {
			wrap.inert = false;
		}
		lastKey = '';
		var back = opener && document.body.contains(opener) ? opener : document.querySelector('.tr-head h1');
		if (back) {
			if (back.tagName === 'H1') {
				back.setAttribute('tabindex', '-1');
			}
			back.focus({ preventScroll: true });
		}
		opener = null;
	}

	function update() {
		var box = document.querySelector('.trasloco-modal-container');
		// getClientRects, not offsetParent: the window is position: fixed
		var visible = !!box && box.getClientRects().length > 0;

		if (visible && !wasOpen) {
			opened(box);
		} else if (!visible && wasOpen) {
			closed();
		}
		wasOpen = visible;

		afterDelete();

		if (!visible) {
			return;
		}

		var title = makeAccessible(box);
		var bar = box.querySelector('.tr-progress');

		// The upload step draws its own bar: do not add a second one
		if (box.querySelector('.trasloco-progress-bar-meter')) {
			if (bar) {
				bar.hidden = true;
			}
			return;
		}

		var match = /(\d{1,3})(?:[.,]\d+)?\s*%/.exec(box.textContent);

		if (!match) {
			if (bar) {
				bar.hidden = true;
			}
			return;
		}

		if (!bar) {
			bar = document.createElement('div');
			bar.className = 'tr-progress';
			bar.setAttribute('role', 'progressbar');
			bar.setAttribute('aria-valuemin', '0');
			bar.setAttribute('aria-valuemax', '100');
			bar.appendChild(document.createElement('span'));

			if (title && title.parentNode) {
				title.parentNode.insertBefore(bar, title.nextSibling);
			} else {
				box.insertBefore(bar, box.firstChild);
			}
		}

		if (title) {
			bar.setAttribute('aria-labelledby', title.id);
		}

		var percent = Math.min(100, parseInt(match[1], 10));
		bar.hidden = false;
		// only write when it changes: a style write is itself a mutation and would call update() again
		if (bar.getAttribute('aria-valuenow') !== String(percent)) {
			bar.setAttribute('aria-valuenow', percent);
			bar.firstChild.style.width = percent + '%';
		}
	}

	// Backups page: after a row is deleted, say so and put the focus somewhere useful
	function afterDelete() {
		if (!pendingDelete || document.body.contains(pendingDelete)) {
			return;
		}
		pendingDelete = null;
		if (ui.deleted) {
			say(ui.deleted);
		}
		var next = document.querySelector('.trasloco-backup-download') || document.querySelector('.trasloco-backups-create a');
		if (next) {
			next.focus();
		}
	}

	new MutationObserver(update).observe(document.body, { childList: true, subtree: true, characterData: true, attributes: true, attributeFilter: ['style'] });

	document.addEventListener('click', function (e) {
		if (!e.target.closest) {
			return;
		}
		var del = e.target.closest('.trasloco-backup-delete');
		if (del) {
			pendingDelete = del;
		}
	}, true);

	// "Leave out all themes/plugins" already covers the inactive ones: grey out the narrower option and say why
	[['trasloco-no-themes', 'trasloco-no-inactive-themes'], ['trasloco-no-plugins', 'trasloco-no-inactive-plugins']].forEach(function (pair) {
		var all = document.getElementById(pair[0]);
		var some = document.getElementById(pair[1]);
		var help = some && document.getElementById(pair[1] + '-help');
		if (!all || !some) {
			return;
		}
		var note = document.createElement('span');
		note.className = 'tr-covered';
		note.textContent = ' ' + (ui.covered || '');
		note.hidden = true;
		if (help) {
			help.appendChild(note);
		}
		function sync() {
			some.disabled = all.checked;
			note.hidden = !all.checked;
			if (all.checked) {
				some.checked = false;
			}
		}
		all.addEventListener('change', sync);
		sync();
	});

	// Find and replace rows: number them for screen readers, focus the new one
	function labelRows() {
		var rows = document.querySelectorAll('#trasloco-queries > li');
		for (var i = 0; i < rows.length; i++) {
			rows[i].setAttribute('role', 'group');
			rows[i].setAttribute('aria-label', format(ui.replacement || 'Replacement %d', i + 1));
		}
		return rows;
	}
	labelRows();

	document.addEventListener('click', function (e) {
		if (!e.target.closest || !e.target.closest('#trasloco-add-new-replace-button')) {
			return;
		}
		setTimeout(function () {
			var rows = labelRows();
			var input = rows.length ? rows[rows.length - 1].querySelector('input') : null;
			if (input) {
				input.focus();
			}
		}, 0);
	});
})();
