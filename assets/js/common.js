/* Novemberkind Produkte, gemeinsame Teile aller Formulare: Meldungen, Anfragen an den Server, Fehler am Feld, Editor. */
(() => {
	'use strict';

	const config = window.novemberkindConfig ?? { i18n: {} };
	const TOAST_MS = 4000;
	// Fehler bleiben länger stehen, damit man sie in Ruhe lesen kann
	const TOAST_ERROR_MS = 8000;

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

	// Editor mit Absätzen, Einfügen als reiner Text und gemerkter Markierung.
	// Safari verliert die Markierung beim Klick auf einen Knopf trotz preventDefault.
	function initEditor(editor) {
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
		showToast,
		post,
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
