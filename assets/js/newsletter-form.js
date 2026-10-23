/* Novemberkind Produkte, Newsletter-Formular: Blockeditor aus WordPress, Testmail und Rückfrage vor dem Versand. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	const form = document.querySelector('[data-nkp-simple-form]');
	if (!base || !form) {
		return;
	}
	const { config, showToast } = base;
	const data = window.novemberkindNewsletter ?? {};
	const i18n = { ...config.i18n, ...data.i18n };
	const controller = base.formController(form);
	const TEXT_BLOCKS = ['core/paragraph', 'core/heading'];
	// Formate im Text, die der Server in die Mail übernimmt
	const FORMATS = ['core/bold', 'core/italic', 'core/link', 'core/strikethrough'];

	// Arbeitsbereich in der Gestaltung der Mail
	function canvasCss() {
		const { colors = {}, font = 'sans-serif', width = 544 } = data;
		return `
			body { background: ${colors.page}; color: ${colors.text}; font-family: ${font}; font-size: 16px; line-height: 1.7; padding: 16px; }
			.is-root-container { max-width: ${width}px; margin: 0 auto; padding-bottom: 48px; }
			p, ul, ol { margin: 0 0 16px; }
			h2, h3 { font-weight: normal; line-height: 1.3; margin: 28px 0 12px; }
			h2 { font-size: 24px; }
			h3 { font-size: 20px; }
			a { color: ${colors.link}; font-weight: bold; font-style: italic; text-decoration: none; }
			.wp-block-image img { display: block; margin: 0 auto; }
			.wp-block-image figcaption { color: ${colors.muted}; font-size: 14px; text-align: center; }
			.wp-block-button__link { background: transparent !important; border: 2px solid ${colors.text}; border-radius: 3px; color: ${colors.text} !important; font-size: 18px; font-style: normal; font-weight: 500; padding: 10px 22px; }
			.wp-block-separator { border: 0; border-top: 1px solid ${colors.sky}; margin: 24px 0; opacity: 1; }
			.wp-block-columns { gap: 24px; margin: 0 0 8px; }
			/* Fotos nur aus der Mediathek, fremde Adressen fallen in der Mail weg */
			.block-editor-media-placeholder__url-input-container { display: none; }
		`;
	}

	// Nur die Einstellungen, die die Mail umsetzt: Ausrichtung von Text, Knöpfen und Spalten. Dazu die Texte auf Deutsch.
	function restrictBlock(settings, name) {
		if (!data.blocks.includes(name)) {
			return settings;
		}
		const supports = { ...settings.supports };
		['anchor', 'background', 'border', 'color', 'dimensions', 'filter', 'shadow', 'spacing', '__experimentalBorder'].forEach((key) => {
			supports[key] = false;
		});
		Object.assign(supports, {
			align: false,
			alignWide: false,
			className: false,
			customClassName: false,
			html: false,
			lock: false,
			typography: TEXT_BLOCKS.includes(name) ? { textAlign: true } : false,
		});
		const attributes = { ...settings.attributes };
		if (name === 'core/heading') {
			attributes.levelOptions = { ...attributes.levelOptions, default: [2, 3] };
		}
		return { ...settings, ...data.blockTexts?.[name], supports, attributes, styles: [] };
	}

	function setupBlocks() {
		const { addFilter } = window.wp.hooks;
		addFilter('blocks.registerBlockType', 'novemberkind/newsletter', restrictBlock);
		window.wp.blockLibrary.registerCoreBlocks();
		const { unregisterFormatType, store } = window.wp.richText;
		window.wp.data.select(store).getFormatTypes().filter((format) => !FORMATS.includes(format.name)).forEach((format) => unregisterFormatType(format.name));
	}

	// Blöcke aus dem Editor ohne Blöcke, die die Mail nicht kennt; deren erlaubte Inhalte bleiben
	function allowedOnly(blocks) {
		return blocks.flatMap((block) => {
			const innerBlocks = allowedOnly(block.innerBlocks ?? []);
			return data.blocks.includes(block.name) ? [{ ...block, innerBlocks }] : innerBlocks;
		});
	}

	function initialBlocks(source) {
		const { parse, rawHandler, createBlock } = window.wp.blocks;
		let blocks = [];
		if (source.blocks) {
			blocks = parse(source.blocks);
		} else if (source.legacy) {
			blocks = rawHandler({ HTML: source.legacy });
		}
		blocks = allowedOnly(blocks);
		return blocks.length ? blocks : [createBlock('core/paragraph')];
	}

	// Fotos werden im Browser verkleinert und landen über die AJAX-Aktion in der Mediathek
	async function mediaUpload({ filesList, onFileChange, onError }) {
		for (const file of [...filesList].filter(base.isImage)) {
			showToast(i18n.uploading, 'info');
			try {
				const result = await controller.upload(file);
				onFileChange([{ id: result.id, url: result.full, alt: '' }]);
				showToast(i18n.uploaded);
			} catch (error) {
				onError?.(error.message);
				showToast(error.message, 'error');
			}
		}
	}

	function editorSettings() {
		const { __experimentalFetchLinkSuggestions: fetchLinks } = window.wp.coreData;
		return {
			allowedBlockTypes: data.blocks,
			mediaUpload,
			// Links auf Produkte und Seiten des Shops über die Suche
			__experimentalFetchLinkSuggestions: (search, options) => fetchLinks(search, options, {}),
			__unstableResolvedAssets: { styles: data.canvasStyles ?? '', scripts: '' },
			__experimentalBlockPatterns: [],
			__experimentalBlockPatternCategories: [],
			__experimentalFeatures: { typography: { dropCap: false } },
			colors: [],
			disableCustomColors: true,
			gradients: [],
			disableCustomGradients: true,
			fontSizes: [],
			disableCustomFontSizes: true,
			imageEditing: false,
			canLockBlocks: false,
			codeEditingEnabled: false,
			enableOpenverseMediaCategory: false,
			inserterMediaCategories: [],
		};
	}

	/**
	 * Editor mit einfachem Verlauf für Rückgängig: Tippen ändert nur den Stand,
	 * abgeschlossene Änderungen kommen in den Verlauf.
	 */
	function Editor({ initial, onUpdate }) {
		const { createElement: el, useState, useEffect, useMemo } = window.wp.element;
		const { BlockEditorProvider, BlockCanvas, BlockInspector, BlockEditorKeyboardShortcuts, Inserter } = window.wp.blockEditor;
		const { SlotFillProvider, Popover, Button } = window.wp.components;
		const settings = useMemo(editorSettings, []);
		const [history, setHistory] = useState({ past: [], blocks: initial, committed: initial, future: [] });

		useEffect(() => onUpdate(history.blocks), [history.blocks]);

		const input = (blocks) => setHistory((state) => ({ ...state, blocks }));
		const change = (blocks) => setHistory((state) => ({ past: [...state.past.slice(-99), state.committed], blocks, committed: blocks, future: [] }));
		const undo = () => setHistory((state) => {
			if (!state.past.length) {
				return state;
			}
			const previous = state.past[state.past.length - 1];
			return { past: state.past.slice(0, -1), blocks: previous, committed: previous, future: [state.blocks, ...state.future] };
		});
		const redo = () => setHistory((state) => {
			if (!state.future.length) {
				return state;
			}
			const [next, ...future] = state.future;
			return { past: [...state.past, state.blocks], blocks: next, committed: next, future };
		});

		// Cmd+Z und Shift+Cmd+Z, auch aus dem iframe, dessen Tastendrücke der Editor nach außen weitergibt
		useEffect(() => {
			const container = document.querySelector('[data-nkp-block-editor]');
			const onKey = (event) => {
				if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'z') {
					event.preventDefault();
					(event.shiftKey ? redo : undo)();
				}
			};
			container.addEventListener('keydown', onKey);
			return () => container.removeEventListener('keydown', onKey);
		}, []);

		return el(SlotFillProvider, null,
			el(BlockEditorProvider, { value: history.blocks, onInput: input, onChange: change, settings },
				el(BlockEditorKeyboardShortcuts.Register),
				el('div', { className: 'nkp-block-editor__bar' },
					el(Inserter, {
						renderToggle: ({ onToggle, disabled, isOpen }) => el(Button, {
							className: 'nkp-button nkp-button--secondary nkp-button--small',
							onClick: onToggle,
							disabled,
							'aria-expanded': isOpen,
						}, '+ ' + i18n.addBlock),
					}),
					el(Button, { className: 'nkp-button nkp-button--secondary nkp-button--small', onClick: undo, disabled: !history.past.length }, i18n.undo),
					el(Button, { className: 'nkp-button nkp-button--secondary nkp-button--small', onClick: redo, disabled: !history.future.length }, i18n.redo),
				),
				el('div', { className: 'nkp-block-editor__main' },
					el('div', { className: 'nkp-block-editor__canvas' },
						el(BlockCanvas, { height: '100%', styles: [{ css: canvasCss() }] }),
					),
					el('aside', { className: 'nkp-block-editor__inspector', 'aria-label': i18n.settings },
						el(BlockInspector),
					),
				),
				el(Popover.Slot),
			),
		);
	}

	const container = form.querySelector('[data-nkp-block-editor]');
	const source = form.querySelector('[data-nkp-block-source]');
	if (container && source && window.wp?.blockEditor) {
		setupBlocks();
		const { serialize } = window.wp.blocks;
		const initial = initialBlocks(JSON.parse(source.textContent));
		const saved = serialize(initial);
		let current = initial;
		const onUpdate = (blocks) => {
			current = blocks;
			if (!controller.dirty && serialize(blocks) !== saved) {
				controller.dirty = true;
			}
		};
		const { createElement: el, createRoot } = window.wp.element;
		createRoot(container).render(el(Editor, { initial, onUpdate }));
		controller.beforeSend(() => {
			form.elements.blocks.value = serialize(current);
		});
	}

	// Testmail mit dem aktuellen Stand, ohne zu speichern
	form.querySelector('[data-nkp-test]')?.addEventListener('click', async (event) => {
		const button = event.currentTarget;
		const result = await controller.send(button.dataset.nkpTest, button, i18n.testSending);
		if (result) {
			showToast(result.message);
		}
	});

	controller.confirmSave(() => !form.dataset.nkpConfirmNow || form.elements.send?.value !== 'now' || window.confirm(form.dataset.nkpConfirmNow));
})();
