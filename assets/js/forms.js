/* Novemberkind Produkte, einfache Formulare für Aktionen, Gutscheine und Newsletter: speichern, beenden, ein- und ausschalten. */
(() => {
	'use strict';

	const config = window.novemberkindFormulare;
	const toast = document.querySelector('[data-nkp-toast]');
	const FLASH = 'nkpFlash';
	const TOAST_MS = 4000;
	const TOAST_ERROR_MS = 8000;
	const CODE_LENGTH = 8;

	let toastTimer;
	function showToast(message, type = 'success') {
		if (!toast) {
			return;
		}
		toast.textContent = message;
		toast.className = `nkp-toast nkp-toast--${type}`;
		toast.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => { toast.hidden = true; }, type === 'error' ? TOAST_ERROR_MS : TOAST_MS);
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

	if (!config) {
		return;
	}
	const { i18n } = config;
	const form = document.querySelector('[data-nkp-simple-form]');
	let dirty = false;

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
			const input = form.elements[`${name}_date`] ?? form.elements[name];
			if (input instanceof HTMLInputElement && input.type !== 'hidden') {
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

	function renameElement(element, tag) {
		const replacement = document.createElement(tag);
		replacement.append(...element.childNodes);
		element.replaceWith(replacement);
		return replacement;
	}

	// Einheitliches HTML für die Mail: strong, em, h2, p und Links ohne Stile der Browser
	function cleanHTML() {
		const copy = editor.cloneNode(true);
		copy.querySelectorAll('b').forEach((b) => renameElement(b, 'strong'));
		copy.querySelectorAll('i').forEach((i) => renameElement(i, 'em'));
		copy.querySelectorAll('h1, h3, h4, h5, h6').forEach((h) => renameElement(h, 'h2'));
		copy.querySelectorAll('div').forEach((div) => renameElement(div, 'p'));
		copy.querySelectorAll('span, font').forEach((span) => {
			let inner = [...span.childNodes];
			if (/^(bold|[6-9]00)$/.test(span.style.fontWeight)) {
				const strong = document.createElement('strong');
				strong.append(...inner);
				inner = [strong];
			}
			if (span.style.fontStyle === 'italic') {
				const em = document.createElement('em');
				em.append(...inner);
				inner = [em];
			}
			span.replaceWith(...inner);
		});
		copy.querySelectorAll('*').forEach((element) => {
			[...element.attributes].forEach((attribute) => {
				if (!(element.tagName === 'A' && attribute.name === 'href')) {
					element.removeAttribute(attribute.name);
				}
			});
		});
		copy.querySelectorAll('strong, em, a').forEach((element) => {
			if (!element.textContent.trim()) {
				element.replaceWith(...element.childNodes);
			}
		});
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
			if (!block.textContent.trim()) {
				block.remove();
			}
		});
		copy.normalize();
		const walker = document.createTreeWalker(copy, NodeFilter.SHOW_TEXT);
		while (walker.nextNode()) {
			walker.currentNode.textContent = walker.currentNode.textContent.replace(/\u00a0/g, ' ');
		}
		return copy.innerHTML.trim();
	}

	function syncEditor() {
		if (editor && form.elements.content) {
			form.elements.content.value = cleanHTML();
		}
	}

	// Ohne Absatz tippt der Browser die erste Zeile als losen Text
	function ensureParagraph() {
		if (editor.isContentEditable && !editor.textContent.trim() && !editor.querySelector('p, h2')) {
			editor.innerHTML = '<p><br></p>';
		}
	}

	if (editor) {
		ensureParagraph();
		editor.addEventListener('focus', ensureParagraph);
		document.execCommand('defaultParagraphSeparator', false, 'p');
		document.execCommand('styleWithCSS', false, false);

		editor.addEventListener('paste', (event) => {
			event.preventDefault();
			document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
		});

		// Letzte Markierung merken. Safari verliert sie beim Klick auf einen Knopf trotz preventDefault.
		let editorRange = null;
		document.addEventListener('selectionchange', () => {
			const selection = window.getSelection();
			if (selection.rangeCount && editor.contains(selection.getRangeAt(0).commonAncestorContainer)) {
				editorRange = selection.getRangeAt(0).cloneRange();
			}
		});
		const restoreSelection = () => {
			editor.focus();
			if (editorRange) {
				const selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange(editorRange);
			}
		};

		form.querySelectorAll('[data-nkp-command]').forEach((button) => {
			// mousedown statt click, damit der Fokus im Text bleibt
			button.addEventListener('mousedown', (event) => {
				event.preventDefault();
				if (editor.isContentEditable === false) {
					return;
				}
				const command = button.dataset.nkpCommand;
				if (command === 'link') {
					const range = editorRange;
					const current = range?.commonAncestorContainer.parentElement?.closest('a')?.getAttribute('href') ?? 'https://';
					let url = window.prompt(i18n.linkPrompt, current);
					if (url === null) {
						return;
					}
					url = url.trim();
					editorRange = range;
					restoreSelection();
					if (url === '' || url === 'https://') {
						document.execCommand('unlink');
					} else {
						document.execCommand('createLink', false, /^(https?:|mailto:)/i.test(url) ? url : `https://${url}`);
					}
				} else if (command === 'heading') {
					restoreSelection();
					const node = window.getSelection().anchorNode;
					const inHeading = (node instanceof Element ? node : node?.parentElement)?.closest('h2');
					document.execCommand('formatBlock', false, inHeading ? '<p>' : '<h2>');
				} else {
					restoreSelection();
					document.execCommand(command);
				}
				editor.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
	}

	// Testmail mit dem aktuellen Stand, ohne zu speichern
	form.querySelector('[data-nkp-test]')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const label = button.textContent;
		clearFieldErrors();
		syncEditor();
		button.disabled = true;
		button.textContent = i18n.testSending;
		try {
			showToast((await post(button.dataset.nkpTest, new FormData(form))).message);
		} catch (error) {
			showToast(error.message, 'error');
			if (error.fields) {
				showFieldErrors(error.fields);
			}
		} finally {
			button.disabled = false;
			button.textContent = label;
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
		if (form.dataset.nkpConfirmNow && form.elements.send?.value === 'now' && !window.confirm(form.dataset.nkpConfirmNow)) {
			return;
		}
		saving = true;
		clearFieldErrors();
		syncEditor();
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
