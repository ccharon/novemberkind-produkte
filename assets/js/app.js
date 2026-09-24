/* Easy Product, Produktformular: Speichern, Fotos verkleinern und hochladen. */
(() => {
	'use strict';

	// Zugeklappte Kategorien der Übersicht merken. Ist der Speicher im Browser gesperrt, bleibt alles offen.
	const groups = document.querySelectorAll('[data-ep-group]');
	let collapsed = [];
	try {
		collapsed = JSON.parse(window.localStorage.getItem('epCollapsedGroups') || '[]');
	} catch {
		// Speicher gesperrt, z. B. im privaten Modus
	}
	groups.forEach((group) => {
		group.open = !collapsed.includes(group.dataset.epGroup);
		group.addEventListener('toggle', () => {
			const closed = [...groups].filter((g) => !g.open).map((g) => g.dataset.epGroup);
			try {
				window.localStorage.setItem('epCollapsedGroups', JSON.stringify(closed));
			} catch {
				// siehe oben
			}
		});
	});

	const config = window.easyProduct;
	const form = document.querySelector('[data-ep-form]');
	if (!config || !form) {
		return;
	}

	const { i18n } = config;
	const submitButton = form.querySelector('[data-ep-submit]');
	const toast = document.querySelector('[data-ep-toast]');
	const gallery = form.querySelector('[data-ep-gallery]');
	const galleryAdd = form.querySelector('[data-ep-gallery-add]');
	const galleryTemplate = document.querySelector('[data-ep-gallery-item]');

	let dirty = false;
	let pendingUploads = 0;
	let saving = false;

	// ---------------------------------------------------------------- Hinweise

	let toastTimer;
	function showToast(message, type = 'success') {
		toast.textContent = message;
		toast.className = `ep-toast ep-toast--${type}`;
		toast.hidden = false;
		clearTimeout(toastTimer);
		toastTimer = setTimeout(() => { toast.hidden = true; }, type === 'error' ? 8000 : 4000);
	}

	function clearFieldErrors() {
		form.querySelectorAll('[data-error-for]').forEach((el) => {
			el.hidden = true;
			el.textContent = '';
		});
		form.querySelectorAll('[aria-invalid]').forEach((el) => el.removeAttribute('aria-invalid'));
	}

	function showFieldErrors(fields) {
		let first = null;
		Object.entries(fields).forEach(([name, message]) => {
			const error = form.querySelector(`[data-error-for="${name}"]`);
			const input = form.elements[name];
			if (error) {
				error.textContent = message;
				error.hidden = false;
			}
			if (input) {
				input.setAttribute('aria-invalid', 'true');
				first ??= input;
			}
		});
		first?.focus();
	}

	// ---------------------------------------------------------------- Server

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

	// ---------------------------------------------------------------- Fotos

	async function decode(file) {
		try {
			return await createImageBitmap(file, { imageOrientation: 'from-image' });
		} catch {
			// Fallback über <img>, z. B. für ältere Safari-Versionen
			const url = URL.createObjectURL(file);
			try {
				const img = new Image();
				img.src = url;
				await img.decode();
				return img;
			} catch {
				throw new Error(i18n.unreadable);
			} finally {
				URL.revokeObjectURL(url);
			}
		}
	}

	function toBlob(canvas, type, quality) {
		return new Promise((resolve) => canvas.toBlob(resolve, type, quality));
	}

	/**
	 * Verkleinert auf maximal `maxWidth` Pixel Breite und liefert ein verlustfreies PNG.
	 * Nur wenn das PNG über der Upload-Grenze des Servers liegt, wird ein hochwertiges JPEG geschickt.
	 */
	async function resize(file) {
		const image = await decode(file);
		const width = image.width;
		const height = image.height;
		const scale = Math.min(1, config.maxWidth / width);

		const canvas = document.createElement('canvas');
		canvas.width = Math.round(width * scale);
		canvas.height = Math.round(height * scale);
		const context = canvas.getContext('2d');
		context.imageSmoothingQuality = 'high';
		context.drawImage(image, 0, 0, canvas.width, canvas.height);
		image.close?.();

		const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto';
		const limit = config.maxUploadBytes * 0.95;

		const png = await toBlob(canvas, 'image/png');
		if (png && png.size <= limit) {
			return { blob: png, name: `${baseName}.png` };
		}
		const jpeg = await toBlob(canvas, 'image/jpeg', 0.95);
		return { blob: jpeg, name: `${baseName}.jpg` };
	}

	async function uploadInto(photo, file) {
		const image = photo.querySelector('.ep-photo__image');
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
			const data = await post('easy_product_upload', body);
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
		const images = [...files].filter((file) => file.type.startsWith('image/') || /\.hei[cf]$/i.test(file.name));
		if (images.length === 0) {
			return;
		}
		if (dropzone.hasAttribute('data-ep-gallery-add')) {
			addGalleryPhotos(images);
		} else {
			uploadInto(dropzone.closest('.ep-photo'), images[0]);
		}
	}

	form.querySelectorAll('[data-ep-dropzone]').forEach((dropzone) => {
		const input = dropzone.querySelector('[data-ep-file]');
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
		const remove = event.target.closest('[data-ep-remove]');
		if (!remove) {
			return;
		}
		const photo = remove.closest('.ep-photo');
		if (photo.hasAttribute('data-ep-main-photo')) {
			photo.classList.remove('has-image');
			photo.querySelector('input[type="hidden"]').value = '';
			photo.querySelector('.ep-photo__image').hidden = true;
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
	const namePreview = form.querySelector('[data-ep-name-preview]');
	motifInput?.addEventListener('input', () => {
		const motif = motifInput.value.trim();
		namePreview.hidden = motif === '';
		namePreview.querySelector('strong').textContent = motifInput.dataset.epNamePattern.replace('%s', motif);
	});
	form.addEventListener('change', markDirty);

	window.addEventListener('beforeunload', (event) => {
		if (dirty) {
			event.preventDefault();
			event.returnValue = i18n.unsaved;
		}
	});

	function applySaved(data) {
		form.elements.product_id.value = data.id;

		const title = document.querySelector('[data-ep-title]');
		title.textContent = data.name;
		document.title = document.title.replace(/^.*?(?= · )/, data.name);

		const viewLink = document.querySelector('[data-ep-view-link]');
		if (data.viewUrl) {
			viewLink.href = data.viewUrl;
			viewLink.hidden = false;
		}

		window.history.replaceState(null, '', `${config.appUrl}${data.id}/`);
	}

	// ---------------------------------------------------------------- Beschreibung

	const description = form.querySelector('[data-ep-description]');
	const editor = form.querySelector('[data-ep-editor]');
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
			const data = await post('easy_product_preview', new FormData(form));
			// Nur die Antwort auf die letzte Anfrage zählt, und nur ohne eigene Änderung dazwischen
			if (request === previewRequest && description.dataset.custom !== '1') {
				editor.innerHTML = data.html;
			}
		} catch {
			// Die Vorschau ist eine Hilfe. Schlägt sie fehl, bleibt der bisherige Text stehen.
		}
	}

	document.execCommand('defaultParagraphSeparator', false, 'p');

	editor.addEventListener('input', () => setCustom(true));

	editor.addEventListener('paste', (event) => {
		event.preventDefault();
		document.execCommand('insertText', false, event.clipboardData.getData('text/plain'));
	});

	form.querySelectorAll('[data-ep-command]').forEach((button) => {
		// mousedown statt click, damit die Markierung im Text erhalten bleibt
		button.addEventListener('mousedown', (event) => {
			event.preventDefault();
			document.execCommand(button.dataset.epCommand);
			editor.dispatchEvent(new Event('input', { bubbles: true }));
		});
	});

	form.querySelector('[data-ep-description-reset]').addEventListener('click', () => {
		if (description.dataset.custom === '1' && !window.confirm(i18n.resetText)) {
			return;
		}
		setCustom(false);
		markDirty();
		refreshDescription();
	});

	function scheduleRefresh(event) {
		if (event.target.closest('[data-ep-editor]') || event.target.type === 'file') {
			return;
		}
		clearTimeout(previewTimer);
		previewTimer = setTimeout(refreshDescription, 400);
	}

	form.addEventListener('input', scheduleRefresh);
	form.addEventListener('change', scheduleRefresh);

	// ---------------------------------------------------------------- Vorschlag von Claude

	const suggestButton = form.querySelector('[data-ep-suggest]');
	const dialog = document.querySelector('[data-ep-suggestion]');
	let suggestion = null;

	suggestButton?.addEventListener('click', async () => {
		if (pendingUploads > 0) {
			showToast(i18n.waitForUpload, 'info');
			return;
		}
		suggestButton.disabled = true;
		suggestButton.textContent = i18n.suggesting;
		try {
			form.elements.description.value = editor.innerHTML;
			suggestion = await post('easy_product_suggest', new FormData(form));
			dialog.querySelector('[data-ep-suggestion-mode]').textContent = suggestion.mode === 'neu' ? i18n.modeNew : i18n.modeImproved;
			dialog.querySelector('[data-ep-suggestion-title]').textContent = suggestion.title;
			const chips = dialog.querySelector('[data-ep-suggestion-tags]');
			chips.replaceChildren(...suggestion.tags.map((tag) => {
				const chip = document.createElement('span');
				chip.className = 'ep-chip';
				chip.textContent = tag;
				return chip;
			}));
			// Vom Server mit wp_kses_post bereinigt
			dialog.querySelector('[data-ep-suggestion-description]').innerHTML = suggestion.description;
			dialog.querySelectorAll('[data-ep-take]').forEach((box) => { box.checked = true; });
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
		const take = (part) => dialog.querySelector(`[data-ep-take="${part}"]`).checked;
		if (take('description')) {
			setCustom(true);
			editor.innerHTML = suggestion.description;
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
		clearFieldErrors();
		submitButton.disabled = true;
		submitButton.textContent = i18n.saving;

		try {
			form.elements.description.value = editor.innerHTML;
			const data = await post('easy_product_save', new FormData(form));
			dirty = false;
			applySaved(data);
			showToast(data.message);
		} catch (error) {
			showFieldErrors(error.fields ?? {});
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

	// Cmd+S / Strg+S speichert, statt die Seite herunterzuladen
	document.addEventListener('keydown', (event) => {
		if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's') {
			event.preventDefault();
			save();
		}
	});
})();
