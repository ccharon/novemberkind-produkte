/* Novemberkind Produkte, Übersicht: Kategorien auf- und zuklappen, Liste oder Kacheln, Sortierung. */
(() => {
	'use strict';

	const { storage } = window.novemberkindBasis;
	const overview = document.querySelector('[data-nkp-overview]');
	if (!overview) {
		return;
	}

	// Zugeklappte Kategorien merken. Ist der Speicher im Browser gesperrt, bleibt alles offen.
	const groups = document.querySelectorAll('[data-nkp-group]');
	const collapsed = storage.getJSON('nkpCollapsedGroups', []);
	groups.forEach((group) => {
		group.open = !collapsed.includes(group.dataset.nkpGroup);
		group.addEventListener('toggle', () => {
			storage.setJSON('nkpCollapsedGroups', [...groups].filter((g) => !g.open).map((g) => g.dataset.nkpGroup));
		});
	});

	// Ansicht (Liste oder Kacheln) pro Gerät merken, Liste ist Standard. Das Template setzt sie schon vor dem ersten Zeichnen.
	const viewButtons = document.querySelectorAll('[data-nkp-view]');
	function showView(view) {
		overview.classList.toggle('nkp-overview--list', view === 'list');
		overview.classList.toggle('nkp-overview--grid', view === 'grid');
		viewButtons.forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.nkpView === view)));
	}
	showView(overview.classList.contains('nkp-overview--grid') ? 'grid' : 'list');
	viewButtons.forEach((button) => button.addEventListener('click', () => {
		showView(button.dataset.nkpView);
		storage.set('nkpOverviewView', button.dataset.nkpView);
	}));

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
		const sort = storage.getJSON('nkpOverviewSort', { key: 'modified', direction: 'descending' });
		sortProducts(sort.key, sort.direction);
		sortButtons.forEach((button) => button.addEventListener('click', () => {
			const key = button.dataset.nkpSort;
			const current = button.getAttribute('aria-sort');
			// Datum, Preis und Bestand zuerst absteigend, Texte zuerst aufsteigend
			const first = ['modified', 'price', 'stock'].includes(key) ? 'descending' : 'ascending';
			const direction = current ? (current === 'ascending' ? 'descending' : 'ascending') : first;
			sortProducts(key, direction);
			storage.setJSON('nkpOverviewSort', { key, direction });
		}));
	}
})();
