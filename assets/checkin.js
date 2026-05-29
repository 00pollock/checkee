/* Checkee — Check-in page JS */
(function () {
	'use strict';

	var actions  = document.getElementById('ck-actions');
	var statusEl = document.getElementById('ck-status');
	var messageEl = document.getElementById('ck-message');

	if (!actions) return;

	actions.addEventListener('click', function (e) {
		var btn = e.target.closest('.ck-btn');
		if (!btn) return;

		var action = btn.getAttribute('data-action');
		var token  = btn.getAttribute('data-token');
		var nonce  = btn.getAttribute('data-nonce');

		if (!action || !token) return;

		// Build the URL for this action
		var url = CHECKEE.baseUrl
			+ (CHECKEE.baseUrl.indexOf('?') !== -1 ? '&' : '?')
			+ 'action=' + encodeURIComponent(action)
			+ '&nonce=' + encodeURIComponent(nonce);

		btn.disabled = true;
		messageEl.textContent = '';
		messageEl.className = 'ck-message';

		fetch(url, { method: 'GET' })
			.then(function (r) { return r.json(); })
			.then(function (data) {
				if (data.success) {
					var newStatus = data.attendee && data.attendee.status
						? data.attendee.status
						: (action === 'in' ? 'checked_in' : 'checked_out');

					// Update status badge
					statusEl.className = 'ck-status ck-status--' + newStatus.replace(/_/g, '-');
					statusEl.textContent = newStatus === 'checked_in'
						? 'Checked In'
						: newStatus === 'checked_out'
							? 'Checked Out'
							: 'Registered';

					messageEl.textContent = action === 'in'
						? 'Welcome! You are now checked in.'
						: 'You have been checked out.';
					messageEl.className = 'ck-message ck-message--success';

					// Hide all buttons after successful action
					actions.innerHTML = '';
				} else {
					btn.disabled = false;
					messageEl.textContent = (data.message || 'Something went wrong. Please try again.');
					messageEl.className = 'ck-message ck-message--error';
				}
			})
			.catch(function () {
				btn.disabled = false;
				messageEl.textContent = 'Request failed. Check your connection and try again.';
				messageEl.className = 'ck-message ck-message--error';
			});
	});
})();
