(function (window, document) {
	'use strict';

	var config = window.trasloco_crypto_jobs;
	if (!config) {
		return;
	}

	var notice = document.querySelector('.tr-crypto-job-notice');
	if (!notice) {
		return;
	}

	var jobId = notice.getAttribute('data-crypto-job-id');
	var operation = notice.getAttribute('data-crypto-operation');
	var output = notice.getAttribute('data-crypto-output');
	var strings = config.strings || {};
	var stopped = false;

	function finish(job) {
		var url = new URL(window.location.href);
		url.searchParams.delete('trasloco_crypto_job');
		url.searchParams.delete('trasloco_crypto_output');
		if (operation === 'encrypt') {
			url.searchParams.set('trasloco_encrypt_status', 'complete');
			url.searchParams.set('trasloco_encrypted_archive', output);
		} else {
			url.searchParams.set('trasloco_decrypt_status', 'complete');
			url.searchParams.set('trasloco_decrypted_archive', output);
		}
		window.location.replace(url.toString());
	}

	function poll() {
		if (stopped) {
			return;
		}
		fetch(config.rest_base + encodeURIComponent(jobId), {
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.rest_nonce }
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('job-status');
			}
			return response.json();
		}).then(function (job) {
			if (job.state === 'completed') {
				stopped = true;
				finish(job);
				return;
			}
			if (job.state === 'failed' || job.state === 'cancelled') {
				stopped = true;
				notice.classList.remove('notice-info');
				notice.classList.add('notice-error');
				notice.setAttribute('role', 'alert');
				notice.textContent = strings.failed || '';
				return;
			}
			var progress = job && Number.isFinite(Number(job.progress)) ? String(job.progress) : '0';
			notice.textContent = (strings.running || '').replace('{progress}', progress);
			window.setTimeout(poll, document.hidden ? 5000 : 1500);
		}).catch(function () {
			notice.textContent = strings.status_failed || '';
			window.setTimeout(poll, 5000);
		});
	}

	poll();
})(window, document);
