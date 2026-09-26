/* Novemberkind Produkte, Produktformular: Speichern, Fotos verkleinern und hochladen. */
(() => {
	'use strict';

	// Wartezeit nach der letzten Eingabe, bevor die Beschreibung neu aus der Vorlage entsteht
	const PREVIEW_DELAY_MS = 400;
	// iOS meldet die neue Breite erst kurz nach dem Drehen
	const RELAYOUT_DELAY_MS = 300;
	const MS_PER_MINUTE = 60 * 1000;

	// Zugeklappte Kategorien der Übersicht merken. Ist der Speicher im Browser gesperrt, bleibt alles offen.
	const groups = document.querySelectorAll('[data-nkp-group]');
	let collapsed = [];
	try {
		collapsed = JSON.parse(window.localStorage.getItem('nkpCollapsedGroups') || '[]');
	} catch {
		// Speicher gesperrt, z. B. im privaten Modus
	}
	groups.forEach((group) => {
		group.open = !collapsed.includes(group.dataset.nkpGroup);
		group.addEventListener('toggle', () => {
			const closed = [...groups].filter((g) => !g.open).map((g) => g.dataset.nkpGroup);
			try {
				window.localStorage.setItem('nkpCollapsedGroups', JSON.stringify(closed));
			} catch {
				// siehe oben
			}
		});
	});

	// Ansicht der Übersicht (Liste oder Kacheln) pro Gerät merken, Liste ist Standard
	const overview = document.querySelector('[data-nkp-overview]');
	const viewButtons = document.querySelectorAll('[data-nkp-view]');
	function showView(view) {
		overview.classList.toggle('nkp-overview--list', view === 'list');
		overview.classList.toggle('nkp-overview--grid', view === 'grid');
		viewButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.nkpView === view)));
	}
	if (overview) {
		showView(overview.classList.contains('nkp-overview--grid') ? 'grid' : 'list');
		viewButtons.forEach((button) => button.addEventListener('click', () => {
			showView(button.dataset.nkpView);
			try {
				window.localStorage.setItem('nkpOverviewView', button.dataset.nkpView);
			} catch {
				// Speicher gesperrt, die Ansicht gilt dann nur bis zum Neuladen
			}
		}));
	}

	// Sortierung der Liste, gilt für alle Kategorien gleichzeitig und wird pro Gerät gemerkt
	const sortButtons = document.querySelectorAll('[data-nkp-sort]');
	const collator = new Intl.Collator('de', { numeric: true, sensitivity: 'base' });
	const numeric = ['price', 'status', 'stock', 'modified'];
	function sortProducts(key, direction) {
		const factor = direction === 'ascending' ? 1 : -1;
		const value = (item) => item.dataset[key];
		document.querySelectorAll('.nkp-grid').forEach((list) => {
			const items = [...list.querySelectorAll('.nkp-card')];
			items.sort((a, b) => {
				const x = value(a);
				const y = value(b);
				// Produkte ohne Wert (z. B. ohne gezählten Bestand) stehen immer am Ende
				if ((x === '') !== (y === '')) {
					return x === '' ? 1 : -1;
				}
				const order = numeric.includes(key) ? Number(x) - Number(y) : collator.compare(x, y);
				return order * factor || collator.compare(a.dataset.name, b.dataset.name);
			});
			list.append(...items);
		});
		sortButtons.forEach((button) => {
			if (button.dataset.nkpSort === key) {
				button.setAttribute('aria-sort', direction);
			} else {
				button.removeAttribute('aria-sort');
			}
		});
	}
	if (sortButtons.length) {
		let sort = { key: 'modified', direction: 'descending' };
		try {
			sort = JSON.parse(window.localStorage.getItem('nkpOverviewSort')) || sort;
		} catch {
			// Speicher gesperrt, Standard gilt
		}
		sortProducts(sort.key, sort.direction);
		sortButtons.forEach((button) => button.addEventListener('click', () => {
			const key = button.dataset.nkpSort;
			const current = button.getAttribute('aria-sort');
			// Datum, Preis und Bestand zuerst absteigend, Texte zuerst aufsteigend
			const first = ['modified', 'price', 'stock'].includes(key) ? 'descending' : 'ascending';
			const direction = current ? (current === 'ascending' ? 'descending' : 'ascending') : first;
			sortProducts(key, direction);
			try {
				window.localStorage.setItem('nkpOverviewSort', JSON.stringify({ key, direction }));
			} catch {
				// siehe oben
			}
		}));
	}

	const base = window.novemberkindBasis;
	const form = document.querySelector('[data-nkp-form]');
	if (!base || !form) {
		return;
	}

	const { config, showToast, post } = base;
	const i18n = { ...config.i18n, ...window.novemberkindProdukte?.i18n };
	const submitButton = form.querySelector('[data-nkp-submit]');
	const gallery = form.querySelector('[data-nkp-gallery]');
	const galleryAdd = form.querySelector('[data-nkp-gallery-add]');
	const galleryTemplate = document.querySelector('[data-nkp-gallery-item]');

	let dirty = false;
	let pendingUploads = 0;
	let saving = false;

	// ---------------------------------------------------------------- Fotos

	function resize(file) {
		return window.novemberkindBilder.resize(file, { maxWidth: config.maxWidth, maxBytes: config.maxUploadBytes, unreadable: i18n.unreadable });
	}

	async function uploadInto(photo, file) {
		const image = photo.querySelector('.nkp-photo__image');
		const idInput = photo.querySelector('input[type="hidden"]');
		const previousSrc = image.getAttribute('src');
		const hadImage = photo.classList.contains('has-image');

		const preview = URL.createObjectURL(file);
		image.removeAttribute('srcset');
		image.src = preview;
		image.hidden = false;
		photo.classList.add('has-image', 'is-uploading');
		pendingUploads++;

		try {
			const { blob, name } = await resize(file);
			const body = new FormData();
			body.append('file', blob, name);
			const data = await post('novemberkind_produkte_upload', body);
			idInput.value = data.id;
			image.src = data.url;
			markDirty();
			return true;
		} catch (error) {
			if (hadImage) {
				image.src = previousSrc;
			} else {
				photo.classList.remove('has-image');
				image.hidden = true;
			}
			showToast(error.message, 'error');
			return false;
		} finally {
			URL.revokeObjectURL(preview);
			photo.classList.remove('is-uploading');
			pendingUploads--;
		}
	}

	function addGalleryPhotos(files) {
		files.forEach((file) => {
			const photo = galleryTemplate.content.firstElementChild.cloneNode(true);
			gallery.insertBefore(photo, galleryAdd);
			uploadInto(photo, file).then((ok) => ok || photo.remove());
		});
	}

	function handleFiles(dropzone, files) {
		const images = [...files].filter(window.novemberkindBilder.isImage);
		if (images.length === 0) {
			return;
		}
		if (dropzone.hasAttribute('data-nkp-gallery-add')) {
			addGalleryPhotos(images);
		} else {
			uploadInto(dropzone.closest('.nkp-photo'), images[0]);
		}
	}

	form.querySelectorAll('[data-nkp-dropzone]').forEach((dropzone) => {
		const input = dropzone.querySelector('[data-nkp-file]');
		input.addEventListener('change', () => {
			handleFiles(dropzone, input.files);
			input.value = '';
		});

		dropzone.addEventListener('dragover', (event) => {
			event.preventDefault();
			dropzone.classList.add('is-dragover');
		});
		dropzone.addEventListener('dragleave', () => dropzone.classList.remove('is-dragover'));
		dropzone.addEventListener('drop', (event) => {
			event.preventDefault();
			dropzone.classList.remove('is-dragover');
			handleFiles(dropzone, event.dataTransfer.files);
		});
	});

	form.addEventListener('click', (event) => {
		const remove = event.target.closest('[data-nkp-remove]');
		if (!remove) {
			return;
		}
		const photo = remove.closest('.nkp-photo');
		if (photo.hasAttribute('data-nkp-main-photo')) {
			photo.classList.remove('has-image');
			photo.querySelector('input[type="hidden"]').value = '';
			photo.querySelector('.nkp-photo__image').hidden = true;
		} else {
			photo.remove();
		}
		markDirty();
	});

	// ---------------------------------------------------------------- Speichern

	function markDirty() {
		dirty = true;
	}

	form.addEventListener('input', markDirty);

	// Vorschau, wie das Produkt im Shop heißen wird
	const motifInput = form.elements.motif;
	const namePreview = form.querySelector('[data-nkp-name-preview]');
	motifInput?.addEventListener('input', () => {
		const motif = motifInput.value.trim();
		namePreview.hidden = motif === '';
		namePreview.querySelector('strong').textContent = motifInput.dataset.nkpNamePattern.replace('%s', motif);
	});
	form.addEventListener('change', markDirty);

	base.warnUnsaved(() => dirty);

	function renderBackups(backups) {
		const section = document.querySelector('[data-nkp-backups]');
		if (!section || !backups) {
			return;
		}
		const link = (href, text, newTab) => {
			const a = document.createElement('a');
			a.href = href;
			a.textContent = text;
			if (newTab) {
				a.target = '_blank';
				a.rel = 'noopener';
			}
			return a;
		};
		section.querySelector('[data-nkp-backup-list]').replaceChildren(...backups.map((backup) => {
			const item = document.createElement('li');
			const date = document.createElement('span');
			date.textContent = backup.date;
			item.append(date, link(backup.view, i18n.view, true), link(backup.download, i18n.download, false));
			return item;
		}));
		section.hidden = backups.length === 0;
	}

	function applySaved(data) {
		renderBackups(data.backups);
		form.elements.product_id.value = data.id;

		const title = document.querySelector('[data-nkp-title]');
		title.textContent = data.name;
		document.title = document.title.replace(/^.*?(?= · )/, data.name);

		const viewLink = document.querySelector('[data-nkp-view-link]');
		if (data.viewUrl) {
			viewLink.href = data.viewUrl;
			viewLink.hidden = false;
		}
		document.querySelector('[data-nkp-another]').hidden = false;

		window.history.replaceState(null, '', `${config.appUrl}${data.id}/`);
	}

	// ---------------------------------------------------------------- Beschreibung

	const description = form.querySelector('[data-nkp-description]');
	const editor = form.querySelector('[data-nkp-editor]');
	let previewTimer;
	let previewRequest = 0;

	function setCustom(custom) {
		description.dataset.custom = custom ? '1' : '0';
		form.elements.description_custom.value = custom ? '1' : '0';
	}

	async function refreshDescription() {
		if (description.dataset.custom === '1') {
			return;
		}
		const request = ++previewRequest;
		try {
			const data = await post('novemberkind_produkte_preview', new FormData(form));
			// Nur die Antwort auf die letzte Anfrage zählt, und nur ohne eigene Änderung dazwischen
			if (request === previewRequest && description.dataset.custom !== '1') {
				setEditorHTML(data.html);
			}
		} catch {
			// Die Vorschau ist eine Hilfe. Schlägt sie fehl, bleibt der bisherige Text stehen.
		}
	}

	const editorState = base.initEditor(editor);

	// Überschriften aus Vorlage oder Vorschlag. Andere entstehen nur versehentlich beim Zusammenfügen von Absätzen.
	let headings = new Set();
	function setEditorHTML(html) {
		editor.innerHTML = html;
		headings = new Set([...editor.querySelectorAll('h3')].map((h) => h.textContent.trim()));
	}
	setEditorHTML(editor.innerHTML);

	// Macht versehentliche Überschriften wieder zu Absätzen, ohne dass die Einfügemarke verloren geht
	function fixHeadings() {
		const strays = [...editor.querySelectorAll('h3')].filter((h) => !headings.has(h.textContent.trim()));
		if (!strays.length) {
			return;
		}
		const selection = window.getSelection();
		const range = selection.rangeCount ? selection.getRangeAt(0) : null;
		const points = range && [[range.startContainer, range.startOffset], [range.endContainer, range.endOffset]];
		strays.forEach((heading) => {
			const paragraph = base.renameElement(heading, 'p');
			points?.forEach((point) => {
				if (point[0] === heading) {
					point[0] = paragraph;
				}
			});
		});
		if (points && points.every(([node]) => editor.contains(node))) {
			selection.setBaseAndExtent(points[0][0], points[0][1], points[1][0], points[1][1]);
		}
	}

	// Einheitliches HTML für den Shop: strong und em ohne Stile der Browser, Überschriften nur aus Vorlage oder Vorschlag
	function cleanHTML() {
		const copy = editor.cloneNode(true);
		copy.querySelectorAll('h3').forEach((h) => {
			if (!headings.has(h.textContent.trim())) {
				base.renameElement(h, 'p');
			}
		});
		base.cleanInline(copy);
		copy.querySelectorAll('[style]').forEach((element) => element.removeAttribute('style'));
		base.unwrapEmpty(copy, 'strong, em');
		base.plainSpaces(copy);
		return copy.innerHTML;
	}

	editor.addEventListener('input', () => {
		fixHeadings();
		setCustom(true);
	});

	form.querySelectorAll('[data-nkp-command]').forEach((button) => {
		// mousedown statt click, damit der Fokus im Text bleibt
		button.addEventListener('mousedown', (event) => {
			event.preventDefault();
			editorState.restore();
			document.execCommand(button.dataset.nkpCommand);
			editor.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});

	form.querySelector('[data-nkp-description-reset]').addEventListener('click', () => {
		if (description.dataset.custom === '1' && !window.confirm(i18n.resetText)) {
			return;
		}
		setCustom(false);
		markDirty();
		refreshDescription();
	});

	function scheduleRefresh(event) {
		if (event.target.closest('[data-nkp-editor]') || event.target.type === 'file') {
			return;
		}
		clearTimeout(previewTimer);
		previewTimer = setTimeout(refreshDescription, PREVIEW_DELAY_MS);
	}

	form.addEventListener('input', scheduleRefresh);
	form.addEventListener('change', scheduleRefresh);

	// ---------------------------------------------------------------- Geplant online stellen

	const schedule = form.querySelector('[data-nkp-schedule]');
	form.querySelectorAll('input[name="status"]').forEach((radio) => radio.addEventListener('change', () => {
		const planned = form.elements.status.value === 'future';
		schedule.hidden = !planned;
		schedule.disabled = !planned;
		if (planned) {
			// Frühestes Datum ist heute in der Zeit des Geräts; den genauen Zeitpunkt prüft der Server
			const today = new Date(Date.now() - new Date().getTimezoneOffset() * MS_PER_MINUTE);
			form.elements.publish_date.min = today.toISOString().split('T')[0];
			form.elements.publish_date.focus();
		}
	}));

	// ---------------------------------------------------------------- Karten in A4

	const a4Toggle = form.querySelector('[data-nkp-a4-toggle]');
	a4Toggle?.addEventListener('change', () => {
		const fields = form.querySelector('[data-nkp-a4-fields]');
		fields.classList.toggle('is-off', !a4Toggle.checked);
		// Gesperrte Angebotspreise aus der WooCommerce-Maske bleiben gesperrt
		fields.querySelectorAll('input:not([data-nkp-locked])').forEach((input) => { input.disabled = !a4Toggle.checked; });
	});

	// ---------------------------------------------------------------- Vorschlag von Claude

	const suggestButton = form.querySelector('[data-nkp-suggest]');
	const dialog = document.querySelector('[data-nkp-suggestion]');
	let suggestion = null;

	suggestButton?.addEventListener('click', async () => {
		if (pendingUploads > 0) {
			showToast(i18n.waitForUpload, 'info');
			return;
		}
		suggestButton.disabled = true;
		suggestButton.textContent = i18n.suggesting;
		try {
			form.elements.description.value = cleanHTML();
			suggestion = await post('novemberkind_produkte_suggest', new FormData(form));
			dialog.querySelector('[data-nkp-suggestion-mode]').textContent = suggestion.mode === 'neu' ? i18n.modeNew : i18n.modeImproved;
			dialog.querySelector('[data-nkp-suggestion-title]').textContent = suggestion.title;
			const chips = dialog.querySelector('[data-nkp-suggestion-tags]');
			chips.replaceChildren(...suggestion.tags.map((tag) => {
				const chip = document.createElement('span');
				chip.className = 'nkp-chip';
				chip.textContent = tag;
				return chip;
			}));
			// Vom Server mit wp_kses_post bereinigt
			dialog.querySelector('[data-nkp-suggestion-description]').innerHTML = suggestion.description;
			dialog.querySelectorAll('[data-nkp-take]').forEach((box) => { box.checked = true; });
			dialog.showModal();
		} catch (error) {
			showToast(error.message, 'error');
		} finally {
			suggestButton.disabled = false;
			suggestButton.textContent = i18n.suggest;
		}
	});

	dialog?.addEventListener('close', () => {
		if (dialog.returnValue !== 'apply' || !suggestion) {
			return;
		}
		const take = (part) => dialog.querySelector(`[data-nkp-take="${part}"]`).checked;
		if (take('description')) {
			setCustom(true);
			setEditorHTML(suggestion.description);
		}
		if (take('tags')) {
			form.elements.tags.value = suggestion.tags.join(', ');
		}
		if (take('title')) {
			motifInput.value = suggestion.title;
			motifInput.dispatchEvent(new Event('input', { bubbles: true }));
		}
		markDirty();
	});

	// ---------------------------------------------------------------- Speichern

	async function save() {
		if (saving) {
			return;
		}
		if (pendingUploads > 0) {
			showToast(i18n.waitForUpload, 'info');
			return;
		}

		saving = true;
		base.clearFieldErrors(form);
		submitButton.disabled = true;
		submitButton.textContent = i18n.saving;

		try {
			form.elements.description.value = cleanHTML();
			const data = await post('novemberkind_produkte_save', new FormData(form));
			dirty = false;
			applySaved(data);
			showToast(data.message);
		} catch (error) {
			base.showFieldErrors(form, error.fields);
			showToast(error.message, 'error');
		} finally {
			saving = false;
			submitButton.disabled = false;
			submitButton.textContent = i18n.save;
		}
	}

	form.addEventListener('submit', (event) => {
		event.preventDefault();
		save();
	});

	// Safari rechnet Felder mit festem Seitenverhältnis nach dem Drehen nicht immer neu
	const relayoutPhotos = () => {
		form.querySelectorAll('.nkp-photo').forEach((photo) => {
			photo.style.aspectRatio = 'auto';
			void photo.offsetWidth;
			photo.style.aspectRatio = '';
		});
	};
	window.addEventListener('orientationchange', () => setTimeout(relayoutPhotos, RELAYOUT_DELAY_MS));
	window.screen.orientation?.addEventListener('change', () => setTimeout(relayoutPhotos, RELAYOUT_DELAY_MS));

	base.onSaveShortcut(save);
})();
