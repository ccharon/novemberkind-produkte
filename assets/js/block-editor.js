/* Novemberkind Produkte, Blockeditor aus WordPress für Newsletter und Neuigkeiten: erlaubte Blöcke, Fotos, Verlauf. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	if (!base) {
		return;
	}
	const { config, showToast } = base;
	const data = window.novemberkindBlockEditor ?? {};
	const i18n = { ...config.i18n, ...data.i18n };
	const TEXT_BLOCKS = ['core/paragraph', 'core/heading'];
	// Formate im Text, die der Server übernimmt
	const FORMATS = ['core/bold', 'core/italic', 'core/link', 'core/strikethrough'];
	// Fotos nur aus der Mediathek, fremde Adressen fallen beim Speichern weg
	const BASE_CSS = '.block-editor-media-placeholder__url-input-container { display: none; }';

	// Nur die Einstellungen, die der Server umsetzt: Ausrichtung von Text, Knöpfen und Spalten. Dazu die Texte auf Deutsch.
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
		addFilter('blocks.registerBlockType', 'novemberkind/block-editor', restrictBlock);
		window.wp.blockLibrary.registerCoreBlocks();
		const { unregisterFormatType, store } = window.wp.richText;
		window.wp.data.select(store).getFormatTypes().filter((format) => !FORMATS.includes(format.name)).forEach((format) => unregisterFormatType(format.name));
	}

	// Blöcke ohne die, die der Editor nicht anbietet; deren erlaubte Inhalte bleiben
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
	function mediaUploader(controller) {
		return async ({ filesList, onFileChange, onError }) => {
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
		};
	}

	function editorSettings(controller) {
		const { __experimentalFetchLinkSuggestions: fetchLinks } = window.wp.coreData;
		return {
			allowedBlockTypes: data.blocks,
			mediaUpload: mediaUploader(controller),
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
	function Editor({ initial, onUpdate, controller, container, canvasCss }) {
		const { createElement: el, useState, useEffect, useMemo } = window.wp.element;
		const { BlockEditorProvider, BlockCanvas, BlockInspector, BlockEditorKeyboardShortcuts, Inserter } = window.wp.blockEditor;
		const { SlotFillProvider, Popover, Button } = window.wp.components;
		const settings = useMemo(() => editorSettings(controller), []);
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
						el(BlockCanvas, { height: '100%', styles: [{ css: BASE_CSS + canvasCss }] }),
					),
					el('aside', { className: 'nkp-block-editor__inspector', 'aria-label': i18n.settings },
						el(BlockInspector),
					),
				),
				el(Popover.Slot),
			),
		);
	}

	let blocksReady = false;

	/**
	 * Baut den Editor in `[data-nkp-block-editor]` des Formulars auf. Die Blöcke kommen aus `[data-nkp-block-source]`
	 * und gehen vor dem Senden in das Feld `blocks`. `canvasCss` gestaltet den Arbeitsbereich.
	 */
	function mount(form, controller, canvasCss) {
		const container = form.querySelector('[data-nkp-block-editor]');
		const source = form.querySelector('[data-nkp-block-source]');
		if (!container || !source || !window.wp?.blockEditor) {
			return;
		}
		if (!blocksReady) {
			setupBlocks();
			blocksReady = true;
		}
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
		createRoot(container).render(el(Editor, { initial, onUpdate, controller, container, canvasCss }));
		controller.beforeSend(() => {
			form.elements.blocks.value = serialize(current);
		});
	}

	window.novemberkindBlockEditor = { ...data, mount };
})();
