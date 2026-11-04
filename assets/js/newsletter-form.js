/* Novemberkind Produkte, Newsletter-Formular: Blockeditor in der Gestaltung der Mail, Testmail und Rückfrage vor dem Versand. */
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
		`;
	}

	window.novemberkindBlockEditor?.mount(form, controller, canvasCss());

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
