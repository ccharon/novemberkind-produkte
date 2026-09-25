/* Novemberkind Produkte, einfache Formulare für Aktionen und Gutscheine: speichern, beenden, ein- und ausschalten. */
(() => {
	'use strict';

	const config = window.novemberkindFormulare;
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

	// Meldung über den Seitenwechsel hinweg, weil nach dem Speichern die Liste erscheint
	try {
		const flash = window.sessionStorage.getItem(FLASH);
		if (flash) {
			window.sessionStorage.removeItem(FLASH);
			showToast(flash);
		}
	} catch {
		// Speicher gesperrt, die Meldung entfällt
	}

	const form = document.querySelector('[data-nkp-simple-form]');
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
			// Datum und Uhrzeit melden Fehler unter dem gemeinsamen Namen, z. B. start für start_date
			const input = form.elements[name] ?? form.elements[`${name}_date`];
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

	// Teile, die nur bei einer bestimmten Auswahl gelten, z. B. data-nkp-show-for="scope:categories"
	const parts = form.querySelectorAll('[data-nkp-show-for]');
	function updateParts() {
		parts.forEach((part) => {
			const [name, value] = part.dataset.nkpShowFor.split(':');
			part.hidden = form.elements[name]?.value !== value;
		});
	}
	form.addEventListener('change', (event) => {
		if (event.target.type === 'radio') {
			updateParts();
		}
	});

	// Zufälliger Code ohne leicht verwechselbare Zeichen wie 0 und O; ob er frei ist, prüft der Server beim Speichern
	form.querySelector('[data-nkp-generate-code]')?.addEventListener('click', () => {
		const alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		const bytes = crypto.getRandomValues(new Uint8Array(8));
		form.elements.code.value = [...bytes].map((byte) => alphabet[byte % alphabet.length]).join('');
		dirty = true;
	});

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
			reloadWith(await post(form.dataset.nkpSave, new FormData(form)));
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

	// Knöpfe wie „Jetzt beenden“ oder „Deaktivieren“ mit eigener Aktion auf dem Server
	form.querySelectorAll('[data-nkp-action]').forEach((button) => button.addEventListener('click', async () => {
		if (button.dataset.nkpConfirm && !window.confirm(button.dataset.nkpConfirm)) {
			return;
		}
		const body = new FormData();
		body.append('id', form.elements.id.value);
		body.append('value', button.dataset.nkpValue ?? '');
		try {
			reloadWith(await post(button.dataset.nkpAction, body));
		} catch (error) {
			showToast(error.message, 'error');
		}
	}));

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
