/* Novemberkind Produkte: wachsende Ranke im Hintergrund. Reine Dekoration ohne Daten und ohne Serveranfragen. */
(() => {
	'use strict';

	const STORAGE_KEY = 'nkpVine';
	const GROW_MS = 5 * 60 * 1000; // bis alle Ränder bewachsen sind
	const PARALLAX = 0.25; // Ranke bewegt sich mit einem Viertel der Scrollgeschwindigkeit
	const STEP = 4; // Länge eines Stielstücks in px
	const LEAF_UNFOLD_MS = 2200;
	const LEAF_SIZE = 1.3;
	const BLOOM_SPREAD_MS = 90 * 1000; // über diese Zeit nach dem Wachsen erscheinen die Blüten
	const BLOOM_OPEN_MS = 10 * 1000;

	// Stiele deckend, die Ebene selbst ist per CSS durchscheinend; sonst werden Überlappungen zu dunklen Punkten
	const COLORS = {
		stem: 'rgb(122 140 110)',
		tendril: 'rgb(150 165 138)',
		leaves: ['rgb(143 165 130 / 55%)', 'rgb(128 152 118 / 55%)', 'rgb(160 178 143 / 50%)'],
		vein: 'rgb(246 241 234 / 45%)',
		bud: 'rgb(176 85 58 / 50%)',
		blossoms: ['rgb(248 242 226)', 'rgb(240 220 150)'], // cremeweiß und gedecktes Gelb
		blossomEdge: 'rgb(196 176 136 / 55%)',
		blossomCenter: 'rgb(204 158 84)',
	};

	const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

	// ------------------------------------------------------------ Zustand im Browser

	function loadState() {
		const today = new Date().toISOString().slice(0, 10);
		let state = {};
		try {
			state = JSON.parse(window.localStorage.getItem(STORAGE_KEY) || '{}');
		} catch {
			// Speicher gesperrt, z. B. im privaten Modus
		}
		if (state.day !== today) {
			// Jeden Tag eine neue Ranke
			state = { day: today, seed: Math.floor(Math.random() * 2 ** 31), progress: 0, off: Boolean(state.off) };
		}
		return state;
	}

	function saveState(state) {
		try {
			window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
		} catch {
			// siehe oben
		}
	}

	// ------------------------------------------------------------ Pflanzenplan

	function random(seed) {
		// mulberry32: kleiner, reproduzierbarer Zufallsgenerator
		let a = seed >>> 0;
		return () => {
			a = (a + 0x6d2b79f5) >>> 0;
			let t = a;
			t = Math.imul(t ^ (t >>> 15), t | 1);
			t ^= t + Math.imul(t ^ (t >>> 7), t | 61);
			return ((t ^ (t >>> 14)) >>> 0) / 4294967296;
		};
	}

	function angleTowards(from, to) {
		return Math.atan2(to.y - from.y, to.x - from.x);
	}

	function turn(current, target, amount) {
		let diff = target - current;
		while (diff > Math.PI) diff -= 2 * Math.PI;
		while (diff < -Math.PI) diff += 2 * Math.PI;
		return current + diff * amount;
	}

	/**
	 * Berechnet alle Stielstücke, Blätter und Knospen mit ihrem Erscheinungszeitpunkt.
	 * Vier Hauptranken wachsen aus den unteren Ecken die Ränder entlang zur Mitte oben und unten.
	 */
	function buildPlan(width, height, seed) {
		const rnd = random(seed);
		const inset = Math.max(14, Math.min(40, Math.min(width, height) * 0.035));
		const leafScale = Math.max(0.8, Math.min(1.4, Math.min(width, height) / 800));
		const segments = [];
		const leaves = [];
		const buds = [];
		const tips = []; // Zweigenden als Plätze für Blüten

		const mainRoutes = [
			[{ x: inset, y: height + 20 }, { x: inset, y: inset }, { x: width / 2, y: inset * 1.2 }],
			[{ x: width - inset, y: height + 20 }, { x: width - inset, y: inset }, { x: width / 2, y: inset * 1.2 }],
			[{ x: -20, y: height - inset }, { x: width / 2, y: height - inset * 1.2 }],
			[{ x: width + 20, y: height - inset }, { x: width / 2, y: height - inset * 1.2 }],
		];
		const routeLength = (route) => route.slice(1).reduce((sum, p, i) => sum + Math.hypot(p.x - route[i].x, p.y - route[i].y), 0);
		const longest = Math.max(...mainRoutes.map(routeLength));
		const msPerPx = (GROW_MS * 0.85) / longest; // alle Hauptranken sind nach 85 % der Zeit an der Mitte

		function addLeaf(x, y, heading, side, birth, size) {
			leaves.push({
				x,
				y,
				angle: heading + side * (0.7 + rnd() * 0.6),
				size: size * leafScale * LEAF_SIZE,
				birth: birth + 300,
				color: COLORS.leaves[Math.floor(rnd() * COLORS.leaves.length)],
				phase: rnd() * Math.PI * 2,
				speed: 0.7 + rnd() * 0.6,
			});
		}

		function grow({ start, heading, route, maxLength, width: lineWidth, birth, depth, curl, taper: tapers = !route }) {
			let x = start.x;
			let y = start.y;
			let angle = heading;
			let waypoint = 0;
			let length = 0;
			let nextLeaf = 10 + rnd() * 14;
			let nextBranch = 60 + rnd() * 90;
			let nextRunner = 250 + rnd() * 250;
			let side = rnd() < 0.5 ? -1 : 1;
			const wobblePhase = rnd() * Math.PI * 2;
			let curve = curl || 0;

			// Obergrenze, falls eine Ranke ihr Ziel umkreist statt es zu erreichen
			while (length < maxLength && length < 20000) {
				let target = null;
				if (route) {
					while (waypoint < route.length && Math.hypot(route[waypoint].x - x, route[waypoint].y - y) < 24) {
						waypoint++;
					}
					if (waypoint >= route.length) {
						break;
					}
					target = route[waypoint];
				}

				const wobble = Math.sin(length * 0.02 + wobblePhase) * 0.45;
				if (target) {
					angle = turn(angle, angleTowards({ x, y }, target) + wobble, 0.12);
				} else {
					curve *= 1.015; // Seitenzweige rollen sich zum Ende hin ein
					angle += curve + wobble * 0.02;
				}

				const nx = x + Math.cos(angle) * STEP;
				const ny = y + Math.sin(angle) * STEP;
				const time = birth + length * msPerPx;
				const taper = tapers ? Math.max(0.35, 1 - length / maxLength) : 1;
				segments.push({ x0: x, y0: y, x1: nx, y1: ny, width: lineWidth * taper, birth: time, color: depth > 1 ? COLORS.tendril : COLORS.stem });
				x = nx;
				y = ny;
				length += STEP;

				if (length >= nextLeaf) {
					addLeaf(x, y, angle, side, time, depth === 0 ? 11 + rnd() * 9 : 8 + rnd() * 7);
					side = -side;
					nextLeaf += 18 + rnd() * 16;
				}

				// Ausläufer von den Rändern in die Bildmitte, die sich dort selbst verzweigen
				if (depth === 0 && length >= nextRunner) {
					const target = { x: width * (0.22 + rnd() * 0.56), y: height * (0.25 + rnd() * 0.45) };
					const distance = Math.hypot(target.x - x, target.y - y);
					grow({
						start: { x, y },
						heading: angleTowards({ x, y }, target) + (rnd() - 0.5) * 0.8,
						route: [target],
						maxLength: distance * 1.3,
						width: lineWidth * 0.75,
						birth: time,
						depth: 1,
						taper: true,
					});
					nextRunner += 320 + rnd() * 300;
				}

				if (depth < 2 && length >= nextBranch) {
					const branchSide = rnd() < 0.5 ? -1 : 1;
					grow({
						start: { x, y },
						heading: angle + branchSide * (0.6 + rnd() * 0.6),
						route: null,
						maxLength: depth === 0 ? 50 + rnd() * 130 : 25 + rnd() * 45,
						width: lineWidth * 0.6,
						birth: time,
						depth: depth + 1,
						curl: branchSide * (0.015 + rnd() * 0.03),
					});
					nextBranch += depth === 0 ? 70 + rnd() * 110 : 40 + rnd() * 50;
				}
			}

			const end = birth + length * msPerPx;
			if (depth > 0) {
				tips.push({ x, y, angle });
			}
			if (!route && rnd() < 0.18) {
				buds.push({ x, y, r: (2 + rnd() * 2) * leafScale, birth: end + 400 });
			}
		}

		for (const route of mainRoutes) {
			grow({
				start: route[0],
				heading: angleTowards(route[0], route[1]),
				route: route.slice(1),
				maxLength: Infinity,
				width: 2.4,
				birth: rnd() * 4000,
				depth: 0,
			});
		}

		segments.sort((a, b) => a.birth - b.birth);
		const grown = Math.max(...segments.map((s) => s.birth), ...leaves.map((l) => l.birth + LEAF_UNFOLD_MS), ...buds.map((b) => b.birth + 1500));

		// Blüten, wenn die Ranke ausgewachsen ist: bevorzugt an Zweigenden, gut verteilt
		const blossoms = [];
		const wanted = Math.max(10, Math.min(28, Math.round((width * height) / 60000)));
		const pool = tips.slice();
		while (blossoms.length < wanted && pool.length > 0) {
			const spot = pool.splice(Math.floor(rnd() * pool.length), 1)[0];
			const minGap = Math.min(width, height) * 0.08;
			if (blossoms.some((b) => Math.hypot(b.x - spot.x, b.y - spot.y) < minGap)) {
				continue;
			}
			blossoms.push({
				x: spot.x,
				y: spot.y,
				r: (9 + rnd() * 6) * leafScale * LEAF_SIZE,
				angle: rnd() * Math.PI * 2,
				color: COLORS.blossoms[Math.floor(rnd() * COLORS.blossoms.length)],
				birth: grown + rnd() * BLOOM_SPREAD_MS,
				phase: rnd() * Math.PI * 2,
			});
		}
		const done = Math.max(grown, ...blossoms.map((b) => b.birth + BLOOM_OPEN_MS));

		return { segments, leaves, buds, blossoms, done };
	}

	// ------------------------------------------------------------ Zeichnen

	function start() {
		const state = loadState();
		const layer = document.createElement('div');
		layer.className = 'nkp-vine';
		layer.setAttribute('aria-hidden', 'true');
		const stemCanvas = document.createElement('canvas');
		stemCanvas.className = 'nkp-vine__stems';
		const leafCanvas = document.createElement('canvas');
		layer.append(stemCanvas, leafCanvas);
		document.body.prepend(layer);

		const stems = stemCanvas.getContext('2d');
		const leavesCtx = leafCanvas.getContext('2d');
		let plan = null;
		let drawnSegments = 0;
		let width = 0;
		let height = 0;
		let running = false;
		let lastFrame = 0;
		let lastLeafFrame = 0;
		let lastSave = 0;

		function setup() {
			const ratio = Math.min(2, window.devicePixelRatio || 1);
			width = window.innerWidth;
			height = Math.round(window.innerHeight * (1 + PARALLAX));
			for (const canvas of [stemCanvas, leafCanvas]) {
				canvas.width = Math.round(width * ratio);
				canvas.height = Math.round(height * ratio);
				canvas.style.width = `${width}px`;
				canvas.style.height = `${height}px`;
				canvas.getContext('2d').setTransform(ratio, 0, 0, ratio, 0, 0);
			}
			plan = buildPlan(width, height, state.seed);
			drawnSegments = 0;
			stems.clearRect(0, 0, width, height);
			stems.lineCap = 'round';
			drawStems(reducedMotion ? Infinity : state.progress);
			drawLeaves(reducedMotion ? Infinity : state.progress, 0);
		}

		function drawStems(time) {
			const { segments } = plan;
			while (drawnSegments < segments.length && segments[drawnSegments].birth <= time) {
				const s = segments[drawnSegments++];
				stems.strokeStyle = s.color;
				stems.lineWidth = s.width;
				stems.beginPath();
				stems.moveTo(s.x0, s.y0);
				stems.lineTo(s.x1, s.y1);
				stems.stroke();
			}
		}

		function drawLeaves(time, clock) {
			leavesCtx.clearRect(0, 0, width, height);
			for (const leaf of plan.leaves) {
				if (leaf.birth > time) {
					continue;
				}
				const grown = Math.min(1, (time - leaf.birth) / LEAF_UNFOLD_MS);
				const size = leaf.size * (1 - (1 - grown) ** 3);
				// Wind: eine langsame Welle über den Bildschirm plus eigenes Zittern je Blatt
				const sway = reducedMotion ? 0 : Math.sin(clock * 0.0011 * leaf.speed + leaf.phase) * 0.09 + Math.sin(clock * 0.0004 - leaf.x * 0.004) * 0.06;
				leavesCtx.save();
				leavesCtx.translate(leaf.x, leaf.y);
				leavesCtx.rotate(leaf.angle + sway);
				leavesCtx.beginPath();
				leavesCtx.moveTo(0, 0);
				leavesCtx.bezierCurveTo(size * 0.3, -size * 0.38, size * 0.78, -size * 0.32, size, 0);
				leavesCtx.bezierCurveTo(size * 0.78, size * 0.32, size * 0.3, size * 0.38, 0, 0);
				leavesCtx.fillStyle = leaf.color;
				leavesCtx.fill();
				if (size > 6) {
					leavesCtx.beginPath();
					leavesCtx.moveTo(size * 0.12, 0);
					leavesCtx.lineTo(size * 0.82, 0);
					leavesCtx.strokeStyle = COLORS.vein;
					leavesCtx.lineWidth = 0.8;
					leavesCtx.stroke();
				}
				leavesCtx.restore();
			}
			for (const blossom of plan.blossoms) {
				if (blossom.birth > time) {
					continue;
				}
				drawBlossom(blossom, Math.min(1, (time - blossom.birth) / BLOOM_OPEN_MS), clock);
			}
			for (const bud of plan.buds) {
				if (bud.birth > time) {
					continue;
				}
				const grown = Math.min(1, (time - bud.birth) / 1500);
				leavesCtx.beginPath();
				leavesCtx.arc(bud.x, bud.y, bud.r * grown, 0, Math.PI * 2);
				leavesCtx.fillStyle = COLORS.bud;
				leavesCtx.fill();
			}
		}

		/**
		 * Erst wächst eine geschlossene Knospe, dann spreizen sich fünf Blütenblätter und die Mitte erscheint.
		 */
		function drawBlossom(blossom, progress, clock) {
			const ease = (v) => 1 - (1 - v) ** 3;
			const bud = ease(Math.min(1, progress / 0.3));
			const open = ease(Math.max(0, (progress - 0.3) / 0.7));
			const sway = reducedMotion ? 0 : Math.sin(clock * 0.0009 + blossom.phase) * 0.12;
			const petalLength = blossom.r * (0.45 + 0.55 * open) * bud;
			const petalWidth = blossom.r * (0.3 + 0.25 * open) * bud;

			leavesCtx.save();
			leavesCtx.translate(blossom.x, blossom.y);
			leavesCtx.rotate(blossom.angle + sway);
			leavesCtx.fillStyle = blossom.color;
			leavesCtx.strokeStyle = COLORS.blossomEdge;
			leavesCtx.lineWidth = 0.8;
			for (let i = 0; i < 5; i++) {
				// Geschlossen liegen die Blätter eng beieinander, geöffnet im Kreis
				const spread = (i / 5) * Math.PI * 2 * (0.15 + 0.85 * open);
				leavesCtx.save();
				leavesCtx.rotate(spread);
				leavesCtx.beginPath();
				leavesCtx.ellipse(petalLength * 0.55, 0, petalLength * 0.55, petalWidth * 0.5, 0, 0, Math.PI * 2);
				leavesCtx.fill();
				leavesCtx.stroke();
				leavesCtx.restore();
			}
			if (open > 0) {
				leavesCtx.beginPath();
				leavesCtx.arc(0, 0, blossom.r * 0.2 * open, 0, Math.PI * 2);
				leavesCtx.fillStyle = COLORS.blossomCenter;
				leavesCtx.fill();
			}
			leavesCtx.restore();
		}

		function frame(now) {
			if (!running) {
				return;
			}
			// Große Sprünge (Tab im Hintergrund) nicht als Wachstum zählen
			const delta = Math.min(100, now - (lastFrame || now));
			lastFrame = now;
			const growing = state.progress < plan.done;
			if (growing) {
				state.progress += delta;
				drawStems(state.progress);
			}
			// Blätter mit höchstens 30 Bildern pro Sekunde beim Wachsen, 20 im Wind
			if (now - lastLeafFrame >= (growing ? 33 : 50)) {
				drawLeaves(state.progress, now);
				lastLeafFrame = now;
			}
			if (now - lastSave > 3000) {
				saveState(state);
				lastSave = now;
			}
			window.requestAnimationFrame(frame);
		}

		function onScroll() {
			const shift = Math.min(window.scrollY * PARALLAX, window.innerHeight * PARALLAX);
			layer.style.transform = `translate3d(0, ${-shift}px, 0)`;
		}

		let resizeTimer;
		window.addEventListener('resize', () => {
			clearTimeout(resizeTimer);
			resizeTimer = setTimeout(setup, 250);
		});
		window.addEventListener('scroll', onScroll, { passive: true });
		window.addEventListener('pagehide', () => saveState(state));
		document.addEventListener('visibilitychange', () => {
			if (document.hidden) {
				saveState(state);
			}
		});

		setup();
		onScroll();

		const control = {
			regrow() {
				state.seed = Math.floor(Math.random() * 2 ** 31);
				state.progress = 0;
				saveState(state);
				setup();
			},
			show() {
				layer.hidden = false;
				if (!reducedMotion && !running) {
					running = true;
					lastFrame = 0;
					window.requestAnimationFrame(frame);
				}
			},
			hide() {
				layer.hidden = true;
				running = false;
				saveState(state);
			},
		};
		return { state, control };
	}

	// ------------------------------------------------------------ Schalter in der Kopfzeile

	document.addEventListener('DOMContentLoaded', () => {
		const { state, control } = start();
		const toggle = document.querySelector('[data-nkp-vine-toggle]');
		const apply = () => {
			if (state.off) {
				control.hide();
			} else {
				control.show();
			}
			if (toggle) {
				toggle.setAttribute('aria-pressed', state.off ? 'false' : 'true');
			}
		};
		if (toggle) {
			toggle.hidden = false;
			toggle.addEventListener('click', () => {
				state.off = !state.off;
				// Wieder eingeschaltet: eine neue Ranke wächst von vorn
				if (!state.off) {
					control.regrow();
				}
				saveState(state);
				apply();
			});
		}
		apply();
	});
})();
