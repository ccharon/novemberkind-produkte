/* Novemberkind Produkte, gemeinsame Teile aller Seiten: Speicher im Browser, Meldungen, Anfragen an den Server, Fotos, Fehler am Feld, Editor. */
(() => {
	'use strict';

	const config = window.novemberkindConfig ?? { i18n: {} };
	const TOAST_MS = 4000;
	// Fehler bleiben länger stehen, damit man sie in Ruhe lesen kann
	const TOAST_ERROR_MS = 8000;

	// Speicher im Browser. Ist er gesperrt, etwa im privaten Modus, gilt der Standardwert und nichts wird gemerkt.
	// Werte bleiben im bisherigen Format: Text als Text, Listen und Objekte als JSON.
	const storage = {
		get(key, fallback = null, area = 'localStorage') {
			try {
				return window[area].getItem(key) ?? fallback;
			} catch {
				return fallback;
			}
		},
		set(key, value, area = 'localStorage') {
			try {
				window[area].setItem(key, value);
			} catch {
				// siehe oben
			}
		},
		remove(key, area = 'localStorage') {
			try {
				window[area].removeItem(key);
			} catch {
				// siehe oben
			}
		},
		getJSON(key, fallback, area = 'localStorage') {
			try {
				return JSON.parse(storage.get(key, null, area)) ?? fallback;
			} catch {
				return fallback;
			}
		},
		setJSON(key, value, area = 'localStorage') {
			storage.set(key, JSON.stringify(value), area);
		},
	};

	let toastTimer;
	function showToast(message, type = 'success') {
		const toast = document.querySelector('[data-nkp-toast]');
		if (!toast) {
			return;
		}
		toast.textContent = message;
		toast.className = `nkp-toast nkp-toast--${type}`;
		toast.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => { toast.hidden = true; }, type === 'error' ? TOAST_ERROR_MS : TOAST_MS);
	}

	// Fehler des Servers tragen die Meldung und die Meldungen je Feld (error.fields)
	async function post(action, body) {
		const { i18n } = config;
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
			// admin-ajax.php antwortet ohne Anmeldung mit „0“ und Status 400
			throw new Error(response.status === 400 ? i18n.loggedOut : i18n.networkError);
		}
		if (!json.success) {
			const error = new Error(json.data?.message ?? i18n.networkError);
			error.fields = json.data?.fields ?? {};
			throw error;
		}
		return json.data;
	}

	// Foto im Browser verkleinern und in die Mediathek hochladen; liefert id, url (Vorschau) und full
	async function upload(file) {
		const { blob, name } = await window.novemberkindBilder.resize(file, {
			maxWidth: config.maxWidth,
			maxBytes: config.maxUploadBytes,
			unreadable: config.i18n.unreadable,
		});
		const body = new FormData();
		body.append('file', blob, name);
		return post('novemberkind_produkte_upload', body);
	}

	const isImage = (file) => window.novemberkindBilder.isImage(file);

	function clearFieldErrors(form) {
		form.querySelectorAll('[data-error-for]').forEach((el) => {
			el.hidden = true;
			el.textContent = '';
		});
		form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
	}

	function showFieldErrors(form, fields) {
		let firstInput = null;
		let firstError = null;
		Object.entries(fields ?? {}).forEach(([name, message]) => {
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
		// Auswahlfelder, Kategorien und Produkte haben kein einzelnes Eingabefeld, dort zum Hinweis scrollen
		if (firstInput) {
			firstInput.focus();
		} else {
			firstError?.scrollIntoView({ behavior: 'smooth', block: 'center' });
		}
	}

	function renameElement(element, tag) {
		const replacement = document.createElement(tag);
		replacement.append(...element.childNodes);
		element.replaceWith(replacement);
		return replacement;
	}

	// strong und em statt b, i und Stil-Spans der Browser
	function cleanInline(root) {
		root.querySelectorAll('b').forEach((b) => renameElement(b, 'strong'));
		root.querySelectorAll('i').forEach((i) => renameElement(i, 'em'));
		root.querySelectorAll('span, font').forEach((span) => {
			let inner = [...span.childNodes];
			// Nur echte Formatierung übernehmen, Schriftgrößen stammen vom Zusammenfügen mit einer Überschrift
			if (!span.style.fontSize) {
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
			}
			span.replaceWith(...inner);
		});
	}

	// Auszeichnungen ohne sichtbaren Text entfernen, den Inhalt behalten
	function unwrapEmpty(root, selector) {
		root.querySelectorAll(selector).forEach((element) => {
			if (!element.textContent.trim()) {
				element.replaceWith(...element.childNodes);
			}
		});
	}

	// Geschützte Leerzeichen der Browser als normale Leerzeichen
	function plainSpaces(root) {
		root.normalize();
		const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT);
		while (walker.nextNode()) {
			walker.currentNode.textContent = walker.currentNode.textContent.replace(/ /g, ' ');
		}
	}

	// Editor mit Absätzen, Einfügen als reiner Text, gemerkter Markierung und Knöpfen. Ein Knopf ruft
	// execCommand mit seinem data-nkp-command auf oder den gleichnamigen Eintrag aus commands;
	// liefert der false, ist nichts geändert. Safari verliert die Markierung beim Klick auf einen Knopf trotz preventDefault.
	function initEditor(editor, buttons = [], commands = {}) {
		document.execCommand('defaultParagraphSeparator', false, 'p');
		document.execCommand('styleWithCSS', false, false);

		editor.addEventListener('paste', (event) => {
			event.preventDefault();
			document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
		});

		const state = { range: null };
		document.addEventListener('selectionchange', () => {
			const selection = window.getSelection();
			if (selection.rangeCount && editor.contains(selection.getRangeAt(0).commonAncestorContainer)) {
				state.range = selection.getRangeAt(0).cloneRange();
			}
		});
		state.restore = () => {
			const { range } = state;
			editor.focus();
			if (range) {
				const selection = window.getSelection();
				selection.removeAllRanges();
				selection.addRange(range);
			}
		};

		buttons.forEach((button) => {
			// mousedown statt click, damit der Fokus im Text bleibt
			button.addEventListener('mousedown', (event) => {
				event.preventDefault();
				if (!editor.isContentEditable) {
					return;
				}
				const command = button.dataset.nkpCommand;
				if (commands[command]) {
					if (commands[command](state) === false) {
						return;
					}
				} else {
					state.restore();
					document.execCommand(command);
				}
				editor.dispatchEvent(new Event('input', { bubbles: true }));
			});
		});
		return state;
	}

	// Cmd+S / Strg+S speichert, statt die Seite herunterzuladen
	function onSaveShortcut(save) {
		document.addEventListener('keydown', (event) => {
			if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
				event.preventDefault();
				save();
			}
		});
	}

	function warnUnsaved(isDirty) {
		window.addEventListener('beforeunload', (event) => {
			if (isDirty()) {
				event.preventDefault();
				event.returnValue = config.i18n.unsaved;
			}
		});
	}

	window.novemberkindBasis = {
		config,
		storage,
		showToast,
		post,
		upload,
		isImage,
		clearFieldErrors,
		showFieldErrors,
		renameElement,
		cleanInline,
		unwrapEmpty,
		plainSpaces,
		initEditor,
		onSaveShortcut,
		warnUnsaved,
	};
})();
