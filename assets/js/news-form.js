/* Novemberkind Produkte, Formular einer Neuigkeit: Blockeditor und Beitragsbild. Speichern und Löschen übernimmt forms.js. */
(() => {
	'use strict';

	const base = window.novemberkindBasis;
	const form = document.querySelector('[data-nkp-simple-form]');
	if (!base || !form) {
		return;
	}
	const controller = base.formController(form);
	const colors = window.novemberkindNews?.colors ?? {};

	// Arbeitsbereich ungefähr wie der Text eines Beitrags im Shop
	window.novemberkindBlockEditor?.mount(form, controller, `
		body { background: #fff; color: ${colors.text}; font-family: Helvetica, Arial, sans-serif; font-size: 16px; line-height: 1.7; padding: 16px; }
		.is-root-container { max-width: 680px; margin: 0 auto; padding-bottom: 48px; }
		p, ul, ol { margin: 0 0 16px; }
		h2, h3 { line-height: 1.3; margin: 28px 0 12px; }
		h2 { font-size: 26px; }
		h3 { font-size: 21px; }
		a { color: ${colors.link}; }
		.wp-block-image img { display: block; margin: 0 auto; }
		.wp-block-columns { gap: 24px; }
	`);

	base.onPhotoFiles(form, (dropzone, images) => base.uploadInto(controller, dropzone.closest('.nkp-photo'), images[0]));
	form.querySelector('[data-nkp-main-photo] [data-nkp-remove]')?.addEventListener('click', (event) => {
		base.clearPhoto(event.currentTarget.closest('.nkp-photo'));
		controller.dirty = true;
	});
})();
