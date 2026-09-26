/* Novemberkind Produkte, einfache Formulare und Listen für Aktionen, Gutscheine und Newsletter: speichern, beenden, ein- und ausschalten. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	if (!base) {
		return;
	}
	const { storage, showToast, post } = base;
	const FLASH = 'nkpFlash';
	const CODE_LENGTH = 8;

	// Meldung über den Seitenwechsel hinweg, weil nach dem Speichern die Liste erscheint
	const flash = storage.get(FLASH, null, 'sessionStorage');
	if (flash) {
		storage.remove(FLASH, 'sessionStorage');
		showToast(flash);
	}

	const form = document.querySelector('[data-nkp-simple-form]');
	const controller = form ? base.formController(form) : null;

	function reloadWith(data) {
		if (controller) {
			controller.dirty = false;
		}
		storage.set(FLASH, data.message, 'sessionStorage');
		window.location.replace(data.url);
	}

	// Abonnenten austragen, außerhalb eines Formulars
	document.querySelectorAll('[data-nkp-remove-subscriber]').forEach((button) => button.addEventListener('click', async () => {
		if (!window.confirm(button.dataset.nkpConfirm)) {
			return;
		}
		const body = new FormData();
		body.append('id', button.dataset.nkpRemoveSubscriber);
		button.disabled = true;
		try {
			reloadWith(await post('novemberkind_produkte_remove_subscriber', body));
		} catch (error) {
			showToast(error.message, 'error');
			button.disabled = false;
		}
	}));

	if (!form) {
		return;
	}
	controller.action = form.dataset.nkpSave;
	controller.onSaved = reloadWith;

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
		const bytes = crypto.getRandomValues(new Uint8Array(CODE_LENGTH));
		form.elements.code.value = [...bytes].map((byte) => alphabet[byte % alphabet.length]).join('');
		controller.dirty = true;
	});

	// Eine Oberkategorie hakt ihre Unterkategorien mit an oder ab; teilweise gewählte zeigen einen Strich
	const categoryBoxes = [...form.querySelectorAll('input[name="categories[]"]')];
	const childrenOf = (box) => categoryBoxes.filter((other) => other.dataset.nkpParent === box.value);
	const descendantsOf = (box) => childrenOf(box).flatMap((child) => [child, ...descendantsOf(child)]);
	function updateIndeterminate() {
		categoryBoxes.forEach((box) => {
			const descendants = descendantsOf(box);
			const checked = descendants.filter((child) => child.checked).length;
			box.indeterminate = checked > 0 && checked < descendants.length;
		});
	}
	categoryBoxes.forEach((box) => box.addEventListener('change', () => {
		descendantsOf(box).forEach((child) => { child.checked = box.checked; });
		updateIndeterminate();
	}));
	updateIndeterminate();

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

})();
