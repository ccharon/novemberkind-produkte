/* Novemberkind Produkte, Newsletter-Formular: Designer (GrapesJS mit MJML), Testmail und Rückfrage vor dem Versand. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	const form = document.querySelector('[data-nkp-simple-form]');
	if (!base || !form) {
		return;
	}
	const { config, showToast } = base;
	const i18n = { ...config.i18n, ...window.novemberkindNewsletter?.i18n };
	const controller = base.formController(form);
	const MJML = 'grapesjs-mjml';

	// Fotos, die schon im Newsletter stehen, damit sie in der Auswahl erscheinen
	function imagesIn(mjml) {
		return [...new Set([...mjml.matchAll(/<mj-image\b[^>]*\ssrc="([^"]+)"/g)].map((match) => match[1]))]
			.filter((src) => /^https?:/.test(src))
			.map((src) => ({ src }));
	}

	function initDesigner(container, mjml) {
		const editor = window.grapesjs.init({
			container,
			height: '100%',
			storageManager: false,
			// Symbole der Oberfläche vom eigenen Server statt von einem CDN
			cssIcons: container.dataset.nkpIcons,
			// Auswahlrahmen im Arbeitsbereich in Terrakotta statt Blau
			canvasCss: '.gjs-selected { outline-color: #b0553a !important; } .gjs-selected-parent { outline-color: #e3a088 !important; }',
			components: mjml,
			i18n: { locale: 'de', detectLocale: false, localeFallback: 'en', messages: { de: window.nkpGrapesLocaleDe } },
			assetManager: {
				assets: imagesIn(mjml),
				// Nur Fotos aus der Mediathek, fremde Adressen fallen beim Versand ohnehin weg
				showUrlInput: false,
				multiUpload: true,
				async uploadFile(event) {
					const files = [...(event.dataTransfer?.files ?? event.target?.files ?? [])].filter(base.isImage);
					if (event.target?.value) {
						event.target.value = '';
					}
					for (const file of files) {
						showToast(i18n.uploading, 'info');
						try {
							const data = await controller.upload(file);
							editor.AssetManager.add({ src: data.full });
							controller.dirty = true;
							showToast(i18n.uploaded);
						} catch (error) {
							showToast(error.message, 'error');
						}
					}
				},
			},
			plugins: [MJML],
			pluginsOpts: {
				[MJML]: {
					i18n: { de: window.nkpMjmlLocaleDe },
					imagePlaceholderSrc: container.dataset.nkpPlaceholder,
					// Farben kommen aus app.css
					useCustomTheme: false,
					// Keine Webfonts von fremden Servern
					fonts: {},
					// Ohne Social-Icons und Hintergrundbilder, die von fremden Servern kämen, und ohne rohes HTML
					blocks: ['mj-1-column', 'mj-2-columns', 'mj-3-columns', 'mj-text', 'mj-button', 'mj-image', 'mj-divider', 'mj-spacer', 'mj-navbar', 'mj-navbar-link', 'mj-wrapper'],
				},
			},
		});

		// Cmd+S speichert auch, wenn der Fokus im Designer liegt
		editor.Keymaps.add('nkp:save', '⌘+s, ctrl+s', () => controller.save(), { force: true, prevent: true });
		editor.on('load', () => {
			editor.on('update', () => {
				controller.dirty = true;
			});
		});
		return editor;
	}

	const container = form.querySelector('[data-nkp-designer]');
	const source = form.querySelector('[data-nkp-designer-source]');
	if (container && source && window.grapesjs) {
		const editor = initDesigner(container, JSON.parse(source.textContent));
		// Die Mail entsteht im Browser, der Server prüft das Ergebnis und behält das MJML zum Weiterbearbeiten
		controller.beforeSend(() => {
			const result = editor.runCommand('mjml-code-to-html') ?? {};
			form.elements.mjml.value = editor.getHtml().trim();
			form.elements.html.value = result.html ?? '';
			if (result.errors?.length) {
				showToast(i18n.compileError, 'info');
			}
		});
	}

	// Testmail mit dem aktuellen Stand, ohne zu speichern
	form.querySelector('[data-nkp-test]')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const data = await controller.send(button.dataset.nkpTest, button, i18n.testSending);
		if (data) {
			showToast(data.message);
		}
	});

	controller.confirmSave(() => !form.dataset.nkpConfirmNow || form.elements.send?.value !== 'now' || window.confirm(form.dataset.nkpConfirmNow));
})();
