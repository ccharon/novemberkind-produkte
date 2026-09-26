/* Novemberkind Produkte, einfache Formulare für Aktionen, Gutscheine und Newsletter: speichern, beenden, ein- und ausschalten. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	if (!base) {
		return;
	}
	const { config, storage, showToast, post } = base;
	const i18n = { ...config.i18n, ...window.novemberkindFormulare?.i18n };
	const FLASH = 'nkpFlash';
	const CODE_LENGTH = 8;

	// Meldung über den Seitenwechsel hinweg, weil nach dem Speichern die Liste erscheint
	const flash = storage.get(FLASH, null, 'sessionStorage');
	if (flash) {
		storage.remove(FLASH, 'sessionStorage');
		showToast(flash);
	}

	const form = document.querySelector('[data-nkp-simple-form]');
	let dirty = false;

	function reloadWith(data) {
		dirty = false;
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
	const submitButton = form.querySelector('[data-nkp-submit]');
	let saving = false;

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
		dirty = true;
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

	// Editor für den Newsletter-Text: fett, kursiv, Zwischenüberschrift, Link
	const editor = form.querySelector('[data-nkp-editor]');

	// Einheitliches HTML für die Mail: strong, em, h2, p und Links ohne Stile der Browser
	function cleanHTML() {
		const copy = editor.cloneNode(true);
		copy.querySelectorAll('h1, h3, h4, h5, h6').forEach((h) => base.renameElement(h, 'h2'));
		copy.querySelectorAll('div').forEach((div) => base.renameElement(div, 'p'));
		base.cleanInline(copy);
		const keep = { A: ['href'], IMG: ['src', 'alt', 'class'] };
		copy.querySelectorAll('*').forEach((element) => {
			[...element.attributes].forEach((attribute) => {
				if (!keep[element.tagName]?.includes(attribute.name)) {
					element.removeAttribute(attribute.name);
				}
			});
		});
		// Nur Fotos aus der Mediathek, an der Klasse wp-image-<ID> erkennt der Server die JPEG-Fassung für die Mail
		copy.querySelectorAll('img').forEach((img) => {
			if (!/^wp-image-\d+$/.test(img.className)) {
				img.remove();
			}
		});
		base.unwrapEmpty(copy, 'strong, em, a');
		// Lose Textstücke auf oberster Ebene in Absätze fassen, br trennt dabei Absätze
		let paragraph = null;
		[...copy.childNodes].forEach((node) => {
			if (node.nodeType === Node.ELEMENT_NODE && ['P', 'H2'].includes(node.tagName)) {
				paragraph = null;
			} else if (node.nodeName === 'BR') {
				paragraph = null;
				node.remove();
			} else {
				paragraph ??= node.parentNode.insertBefore(document.createElement('p'), node);
				paragraph.append(node);
			}
		});
		copy.querySelectorAll('p, h2').forEach((block) => {
			if (!block.textContent.trim() && !block.querySelector('img')) {
				block.remove();
			}
		});
		base.plainSpaces(copy);
		return copy.innerHTML.trim();
	}

	function syncEditor() {
		if (editor && form.elements.content) {
			form.elements.content.value = cleanHTML();
		}
	}

	let pendingUploads = 0;

	// Ohne Absatz tippt der Browser die erste Zeile als losen Text
	function ensureParagraph() {
		if (editor.isContentEditable && !editor.textContent.trim() && !editor.querySelector('p, h2')) {
			editor.innerHTML = '<p><br></p>';
		}
	}

	if (editor) {
		ensureParagraph();
		editor.addEventListener('focus', ensureParagraph);
		const editorState = base.initEditor(editor, form.querySelectorAll('[data-nkp-command]:not([data-nkp-command="image"])'), {
			link(state) {
				const { range } = state;
				const current = range?.commonAncestorContainer.parentElement?.closest('a')?.getAttribute('href') ?? 'https://';
				let url = window.prompt(i18n.linkPrompt, current);
				if (url === null) {
					return false;
				}
				url = url.trim();
				state.range = range;
				state.restore();
				if (url === '' || url === 'https://') {
					document.execCommand('unlink');
				} else {
					document.execCommand('createLink', false, /^(https?:|mailto:)/i.test(url) ? url : `https://${url}`);
				}
				return true;
			},
			heading(state) {
				state.restore();
				const node = window.getSelection().anchorNode;
				const inHeading = (node instanceof Element ? node : node?.parentElement)?.closest('h2');
				document.execCommand('formatBlock', false, inHeading ? '<p>' : '<h2>');
				return true;
			},
		});
		const restoreSelection = editorState.restore;

		// Foto an der Cursorposition: erst die Vorschau, nach dem Upload die Adresse aus der Mediathek
		async function insertImage(file) {
			const preview = URL.createObjectURL(file);
			const img = document.createElement('img');
			img.src = preview;
			img.alt = '';
			img.dataset.nkpUploading = '1';
			restoreSelection();
			const range = window.getSelection().rangeCount ? window.getSelection().getRangeAt(0) : null;
			if (range && editor.contains(range.commonAncestorContainer)) {
				range.deleteContents();
				range.insertNode(img);
				range.setStartAfter(img);
				range.collapse(true);
			} else {
				const paragraph = document.createElement('p');
				paragraph.append(img);
				editor.append(paragraph);
			}
			editor.dispatchEvent(new Event('input', { bubbles: true }));
			pendingUploads++;
			try {
				const data = await base.upload(file);
				img.src = data.full;
				img.className = `wp-image-${data.id}`;
				delete img.dataset.nkpUploading;
				dirty = true;
			} catch (error) {
				img.remove();
				showToast(error.message, 'error');
			} finally {
				URL.revokeObjectURL(preview);
				pendingUploads--;
			}
		}

		const imageInput = form.querySelector('[data-nkp-image-file]');
		imageInput?.addEventListener('change', () => {
			[...imageInput.files].filter(base.isImage).forEach(insertImage);
			imageInput.value = '';
		});
		// click statt mousedown, weil iOS die Fotoauswahl nur direkt nach einem Tipp öffnet; mousedown hält den Fokus im Text
		const imageButton = form.querySelector('[data-nkp-command="image"]');
		imageButton?.addEventListener('mousedown', (event) => event.preventDefault());
		imageButton?.addEventListener('click', () => imageInput?.click());

	}

	// Testmail mit dem aktuellen Stand, ohne zu speichern
	form.querySelector('[data-nkp-test]')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const label = button.textContent;
		if (pendingUploads > 0) {
			showToast(i18n.waitForUpload, 'info');
			return;
		}
		base.clearFieldErrors(form);
		syncEditor();
		button.disabled = true;
		button.textContent = i18n.testSending;
		try {
			showToast((await post(button.dataset.nkpTest, new FormData(form))).message);
		} catch (error) {
			showToast(error.message, 'error');
			if (error.fields) {
				base.showFieldErrors(form, error.fields);
			}
		} finally {
			button.disabled = false;
			button.textContent = label;
		}
	});

	form.addEventListener('input', (event) => {
		if (event.target !== filter && !event.target.hasAttribute('data-nkp-not-dirty')) {
			dirty = true;
		}
	});

	async function save() {
		if (saving || !submitButton) {
			return;
		}
		if (pendingUploads > 0) {
			showToast(i18n.waitForUpload, 'info');
			return;
		}
		if (form.dataset.nkpConfirmNow && form.elements.send?.value === 'now' && !window.confirm(form.dataset.nkpConfirmNow)) {
			return;
		}
		saving = true;
		base.clearFieldErrors(form);
		syncEditor();
		submitButton.disabled = true;
		submitButton.textContent = i18n.saving;
		try {
			reloadWith(await post(form.dataset.nkpSave, new FormData(form)));
		} catch (error) {
			showToast(error.message, 'error');
			if (error.fields) {
				base.showFieldErrors(form, error.fields);
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

	base.onSaveShortcut(save);
	base.warnUnsaved(() => dirty);
})();
