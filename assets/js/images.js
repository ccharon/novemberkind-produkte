/* Novemberkind Produkte: Fotos im Browser verkleinern, gemeinsam für Produkte und Newsletter. */
(() => {
	'use strict';

	async function decode(file, unreadable) {
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
				throw new Error(unreadable);
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
	async function resize(file, { maxWidth, maxBytes, unreadable }) {
		const image = await decode(file, unreadable);
		const scale = Math.min(1, maxWidth / image.width);

		const canvas = document.createElement('canvas');
		canvas.width = Math.round(image.width * scale);
		canvas.height = Math.round(image.height * scale);
		const context = canvas.getContext('2d');
		context.imageSmoothingQuality = 'high';
		context.drawImage(image, 0, 0, canvas.width, canvas.height);
		image.close?.();

		const baseName = file.name.replace(/\.[^.]+$/, '') || 'foto';
		const png = await toBlob(canvas, 'image/png');
		if (png && png.size <= maxBytes * 0.95) {
			return { blob: png, name: `${baseName}.png` };
		}
		const jpeg = await toBlob(canvas, 'image/jpeg', 0.95);
		return { blob: jpeg, name: `${baseName}.jpg` };
	}

	function isImage(file) {
		return file.type.startsWith('image/') || /\.hei[cf]$/i.test(file.name);
	}

	window.novemberkindBilder = { resize, isImage };
})();
