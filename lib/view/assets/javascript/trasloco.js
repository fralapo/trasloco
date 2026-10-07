/*
 * Progress bar and accessibility for the import/export window.
 * The server only sends text ("23% complete"): read the number and draw a bar.
 * The bundled scripts build the window without dialog semantics, so they are
 * added here: role, label, live status and keyboard focus.
 */
(function () {
	'use strict';

	function makeAccessible(box) {
		var title = box.querySelector('h1');

		box.setAttribute('role', 'dialog');
		box.setAttribute('aria-modal', 'true');

		if (title) {
			title.id = title.id || 'tr-modal-title';
			box.setAttribute('aria-labelledby', title.id);
		}

		var status = box.querySelector('section p');
		if (status) {
			status.setAttribute('aria-live', 'polite');
		}

		// Move keyboard focus into the window when it opens. The title, not a
		// button: during an export the first button is "Stop", and Enter must not hit it.
		if (!box.contains(document.activeElement)) {
			var target = title || box.querySelector('button, a[href]');
			if (target === title) {
				title.setAttribute('tabindex', '-1');
			}
			if (target) {
				target.focus({ preventScroll: true });
			}
		}

		return title;
	}

	function update() {
		var box = document.querySelector('.trasloco-modal-container');
		// getClientRects, not offsetParent: the window is position: fixed
		if (!box || box.getClientRects().length === 0) {
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
		bar.setAttribute('aria-valuenow', percent);
		bar.firstChild.style.width = percent + '%';
	}

	new MutationObserver(update).observe(document.body, { childList: true, subtree: true, characterData: true });
})();
