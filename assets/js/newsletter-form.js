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

	const LAYOUT_BLOCKS = ['mj-1-column', 'mj-2-columns', 'mj-3-columns', 'mj-wrapper'];
	const CHEVRON_UP = '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M7.41,15.41L12,10.83L16.59,15.41L18,14L12,8L6,14L7.41,15.41Z" /></svg>';
	const CHEVRON_DOWN = '<svg viewBox="0 0 24 24"><path fill="currentColor" d="M7.41,8.58L12,13.17L16.59,8.58L18,10L12,16L6,10L7.41,8.58Z" /></svg>';

	// Das Touch-Plugin biegt ohne Touch-Unterstützung alle Event-Listener um, deshalb nur auf Touch-Geräten
	function touchPlugins() {
		const plugin = window['grapesjs-touch'];
		return 'ontouchstart' in window && plugin ? [plugin.default ?? plugin] : [];
	}

	// Vorfahre von component, dessen Elternteil parent ist
	function childOf(parent, component) {
		let child = component;
		while (child && child.parent() !== parent) {
			child = child.parent();
		}
		return child;
	}

	/**
	 * Fügt einen Baustein per Antippen oder Klick ein: Spalten und Bereiche hinter den aktuellen Abschnitt,
	 * Inhalte hinter das gewählte Element oder ans Ende der letzten Spalte.
	 */
	function insertBlock(editor, block) {
		const body = editor.getWrapper()?.findType('mj-body')[0];
		const selected = editor.getSelected();
		const content = block.get('content');
		if (!body) {
			return;
		}
		let parent;
		let after;
		if (LAYOUT_BLOCKS.includes(block.getId())) {
			parent = body;
			after = selected && childOf(body, selected);
		} else if (block.getId() === 'mj-navbar-link') {
			parent = selected?.is('mj-navbar') ? selected : selected?.closestType('mj-navbar');
			after = parent && selected !== parent ? childOf(parent, selected) : undefined;
		} else {
			parent = selected?.is('mj-column') ? selected : selected?.closestType('mj-column');
			after = parent && selected !== parent ? childOf(parent, selected) : undefined;
			if (!parent) {
				const columns = (selected ?? body).findType('mj-column');
				parent = columns.length ? columns[columns.length - 1] : body.findType('mj-column').pop();
				parent ??= body.append('<mj-section><mj-column></mj-column></mj-section>')[0].findType('mj-column')[0];
			}
		}
		if (!parent) {
			return;
		}
		const [added] = parent.append(content, after ? { at: after.index() + 1 } : {});
		if (added) {
			editor.select(added);
		}
	}

	// Verschieben ohne Ziehen, für das iPad
	function moveBy(editor, offset) {
		const component = editor.getSelected();
		const parent = component?.parent();
		const index = component?.index() ?? 0;
		if (!parent || index + offset < 0 || index + offset >= parent.components().length) {
			return;
		}
		component.move(parent, { at: offset < 0 ? index + offset : index + offset + 1 });
		editor.select(component);
	}

	function addMoveButtons(editor, component) {
		component.initToolbar();
		const toolbar = component.get('toolbar') ?? [];
		if (!component.parent() || component.is('mj-body') || toolbar.some((item) => item.id === 'nkp-up')) {
			return;
		}
		component.set('toolbar', [
			{ id: 'nkp-up', label: CHEVRON_UP, attributes: { title: i18n.moveUp }, command: () => moveBy(editor, -1) },
			{ id: 'nkp-down', label: CHEVRON_DOWN, attributes: { title: i18n.moveDown }, command: () => moveBy(editor, 1) },
			...toolbar,
		]);
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
			// Antippen oder Klicken fügt einen Baustein ein, Ziehen geht weiterhin
			blockManager: { appendOnClick: (block, editor) => insertBlock(editor, block) },
			plugins: [MJML, ...touchPlugins()],
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
		editor.on('component:selected', (component) => addMoveButtons(editor, component));
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
