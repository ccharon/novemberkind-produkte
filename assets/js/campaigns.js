/* Novemberkind Produkte, Rabattaktionen: Formular speichern und Aktionen beenden. */
(() => {
	'use strict';

	const config = window.novemberkindAktionen;
	const toast = document.querySelector('[data-nkp-toast]');
	const FLASH = 'nkpFlash';

	let toastTimer;
	function showToast(message, type = 'success') {
		if (!toast) {
			return;
		}
		toast.textContent = message;
		toast.className = `nkp-toast nkp-toast--${type}`;
		toast.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => { toast.hidden = true; }, type === 'error' ? 8000 : 4000);
	}

	// Meldung über ein Neuladen hinweg, weil die Seite nach dem Speichern den neuen Stand vom Server zeigt
	try {
		const flash = window.sessionStorage.getItem(FLASH);
		if (flash) {
			window.sessionStorage.removeItem(FLASH);
			showToast(flash);
		}
	} catch {
		// Speicher gesperrt, die Meldung entfällt
	}

	const form = document.querySelector('[data-nkp-campaign-form]');
	if (!config || !form) {
		return;
	}
	const { i18n } = config;
	const submitButton = form.querySelector('[data-nkp-submit]');
	let dirty = false;
	let saving = false;

	async function post(action, body) {
		body.append('action', action);
		body.append('nonce', config.nonce);
		let response;
		try {
			response = await fetch(config.ajaxUrl, { method: 'POST', body, credentials: 'same-origin' });
		} catch {
			throw new Error(i18n.networkError);
		}
		const json = await response.json().catch(() => null);
		if (!json) {
			throw new Error(response.status === 400 ? i18n.loggedOut : i18n.networkError);
		}
		if (!json.success) {
			const error = new Error(json.data?.message ?? i18n.networkError);
			error.fields = json.data?.fields ?? {};
			throw error;
		}
		return json.data;
	}

	function reloadWith(data) {
		dirty = false;
		try {
			window.sessionStorage.setItem(FLASH, data.message);
		} catch {
			// siehe oben
		}
		window.location.replace(data.url);
	}

	function clearFieldErrors() {
		form.querySelectorAll('[data-error-for]').forEach((el) => {
			el.hidden = true;
			el.textContent = '';
		});
		form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
	}

	function showFieldErrors(fields) {
		let firstInput = null;
		let firstError = null;
		Object.entries(fields).forEach(([name, message]) => {
			const error = form.querySelector(`[data-error-for="${name}"]`);
			if (error) {
				error.textContent = message;
				error.hidden = false;
				firstError ??= error;
			}
			const input = form.elements[name];
			if (input instanceof HTMLInputElement) {
				input.setAttribute('aria-invalid', 'true');
				firstInput ??= input;
			}
		});
		// Kategorien und Produkte haben kein einzelnes Eingabefeld, dort zum Hinweis scrollen
		if (firstInput) {
			firstInput.focus();
		} else {
			firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	// Kategorien- oder Produktauswahl nur beim passenden Umfang zeigen
	form.querySelectorAll('input[name="scope"]').forEach((radio) => radio.addEventListener('change', () => {
		form.querySelectorAll('[data-nkp-scope-part]').forEach((part) => {
			part.hidden = part.dataset.nkpScopePart !== form.elements.scope.value;
		});
	}));

	const filter = form.querySelector('[data-nkp-product-filter]');
	filter?.addEventListener('input', () => {
		const needle = filter.value.trim().toLowerCase();
		form.querySelectorAll('[data-nkp-product]').forEach((item) => {
			// Angehakte Produkte bleiben sichtbar, damit die Auswahl nachvollziehbar bleibt
			item.hidden = needle !== '' && !item.dataset.nkpProduct.includes(needle) && !item.querySelector('input').checked;
		});
	});
	// Enter im Suchfeld soll nicht speichern
	filter?.addEventListener('keydown', (event) => {
		if (event.key === 'Enter') {
			event.preventDefault();
		}
	});

	form.addEventListener('input', (event) => {
		if (event.target !== filter) {
			dirty = true;
		}
	});

	async function save() {
		if (saving || !submitButton) {
			return;
		}
		saving = true;
		clearFieldErrors();
		submitButton.disabled = true;
		submitButton.textContent = i18n.saving;
		try {
			reloadWith(await post('novemberkind_produkte_save_campaign', new FormData(form)));
		} catch (error) {
			showToast(error.message, 'error');
			if (error.fields) {
				showFieldErrors(error.fields);
			}
			submitButton.disabled = false;
			submitButton.textContent = i18n.save;
			saving = false;
		}
	}

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		save();
	});

	form.querySelector('[data-nkp-campaign-end]')?.addEventListener('click', async () => {
		if (!window.confirm(i18n.confirmEnd)) {
			return;
		}
		const body = new FormData();
		body.append('campaign_id', form.elements.campaign_id.value);
		try {
			reloadWith(await post('novemberkind_produkte_end_campaign', body));
		} catch (error) {
			showToast(error.message, 'error');
		}
	});

	document.addEventListener('keydown', (event) => {
		if ((event.metaKey || event.ctrlKey) && event.key === 's') {
			event.preventDefault();
			save();
		}
	});

	window.addEventListener('beforeunload', (event) => {
		if (dirty) {
			event.preventDefault();
			event.returnValue = i18n.unsaved;
		}
	});
})();
