/* Novemberkind Produkte, Newsletter-Formular: Editor mit Fotos, Testmail und Rückfrage vor dem Versand. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	const form = document.querySelector('[data-nkp-simple-form]');
	const editor = form?.querySelector('[data-nkp-editor]');
	if (!base || !editor) {
		return;
	}
	const { config, showToast } = base;
	const i18n = { ...config.i18n, ...window.novemberkindNewsletter?.i18n };
	const controller = base.formController(form);

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
		if (form.elements.content) {
			form.elements.content.value = cleanHTML();
		}
	}

	// Ohne Absatz tippt der Browser die erste Zeile als losen Text
	function ensureParagraph() {
		if (editor.isContentEditable && !editor.textContent.trim() && !editor.querySelector('p, h2')) {
			editor.innerHTML = '<p><br></p>';
		}
	}

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
		try {
			const data = await controller.upload(file);
			img.src = data.full;
			img.className = `wp-image-${data.id}`;
			delete img.dataset.nkpUploading;
			controller.dirty = true;
		} catch (error) {
			img.remove();
			showToast(error.message, 'error');
		} finally {
			URL.revokeObjectURL(preview);
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

	// Testmail mit dem aktuellen Stand, ohne zu speichern
	form.querySelector('[data-nkp-test]')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const data = await controller.send(button.dataset.nkpTest, button, i18n.testSending);
		if (data) {
			showToast(data.message);
		}
	});

	controller.beforeSend(syncEditor);
	controller.confirmSave(() => !form.dataset.nkpConfirmNow || form.elements.send?.value !== 'now' || window.confirm(form.dataset.nkpConfirmNow));
})();
