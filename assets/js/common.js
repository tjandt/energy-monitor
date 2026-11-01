// common.js – gemeinsame Helfer für Dashboard und Tarifverwaltung

const EM = {

	// ------------------------------------------------------------------
	// API
	// ------------------------------------------------------------------
	async api(action, { params = {}, body = null } = {}) {
		const url = new URL('api.php', window.location.href);
		url.searchParams.set('action', action);
		for (const [k, v] of Object.entries(params)) {
			if (v !== null && v !== undefined) url.searchParams.set(k, v);
		}
		const opts = {};
		if (body !== null) {
			opts.method = 'POST';
			opts.headers = { 'X-Requested-With': 'XMLHttpRequest' };
			opts.body = body instanceof FormData ? body : new URLSearchParams(body);
		}
		const res = await fetch(url, opts);
		const json = await res.json().catch(() => ({}));
		if (res.status === 401 && json.login_required) {
			window.location.href = 'login.php';
			throw new Error('Nicht angemeldet');
		}
		if (!res.ok || json.success === false) {
			const err = new Error(json.error || `Serverfehler (HTTP ${res.status})`);
			err.status = res.status;
			err.needsConfirmation = !!json.needs_confirmation;
			throw err;
		}
		return json;
	},

	// ------------------------------------------------------------------
	// Theme
	// ------------------------------------------------------------------
	initTheme(onChange) {
		let saved = null;
		try { saved = localStorage.getItem('em-theme'); } catch (e) { /* Storage gesperrt */ }
		const theme = saved || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
		document.documentElement.setAttribute('data-theme', theme);
		const apply = (t) => {
			const el = document.getElementById('themeIcon');
			if (el) el.innerHTML = icon(t === 'dark' ? 'sun' : 'moon');
		};
		apply(theme);
		document.getElementById('themeToggle')?.addEventListener('click', () => {
			const next = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
			document.documentElement.setAttribute('data-theme', next);
			try { localStorage.setItem('em-theme', next); } catch (e) { /* ignorieren */ }
			apply(next);
			if (onChange) onChange(next);
		});
	},

	// ------------------------------------------------------------------
	// UI-Helfer
	// ------------------------------------------------------------------
	toast(msg, ms = 3500) {
		const t = document.getElementById('toast');
		t.textContent = msg;
		t.classList.add('show');
		clearTimeout(EM._toastTimer);
		EM._toastTimer = setTimeout(() => t.classList.remove('show'), ms);
	},

	/** Modal öffnen/schließen inkl. Esc- und Overlay-Klick sowie Fokus-Rückgabe. */
	modal(id) {
		const el = document.getElementById(id);
		let opener = null;
		const close = () => {
			el.classList.remove('active');
			opener?.focus?.();
		};
		el.addEventListener('mousedown', (e) => { if (e.target === el) close(); });
		EM._modals.set(el, close);
		return {
			open() { opener = document.activeElement; el.classList.add('active'); },
			close,
		};
	},

	_modals: new Map(),

	escape(s) {
		return String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
	},

	// ------------------------------------------------------------------
	// Formatierung
	// ------------------------------------------------------------------
	fmt(n, digits = 1) { return new Intl.NumberFormat('de-DE', { maximumFractionDigits: digits }).format(n ?? 0); },
	fmtFixed(n, min, max) { return new Intl.NumberFormat('de-DE', { minimumFractionDigits: min, maximumFractionDigits: max }).format(n ?? 0); },
	fmtMoney(n) { return new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(n ?? 0); },

	/** 'YYYY-MM-DD' oder 'YYYY-MM-DD HH:MM:SS' -> lokales Datum (ohne UTC-Verschiebung). */
	fmtDate(s) {
		const [y, m, d] = String(s).slice(0, 10).split('-').map(Number);
		return new Date(y, m - 1, d).toLocaleDateString('de-DE');
	},

	/** Lokales Datum als YYYY-MM-DD (toISOString() würde nach UTC umrechnen). */
	localDate(d = new Date()) {
		const p = (n) => String(n).padStart(2, '0');
		return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
	},
	localTime(d = new Date()) {
		const p = (n) => String(n).padStart(2, '0');
		return `${p(d.getHours())}:${p(d.getMinutes())}`;
	},
};

// Esc schließt nur das oberste (zuletzt im DOM stehende) offene Modal
document.addEventListener('keydown', (e) => {
	if (e.key !== 'Escape') return;
	const open = [...document.querySelectorAll('.modal-overlay.active')];
	const top = open[open.length - 1];
	if (top && EM._modals.has(top)) EM._modals.get(top)();
});
