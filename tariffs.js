// tariffs.js – Verwaltung der Tarife (CRUD über api.php)

const badgeColors = {
	Strom: '#FF9500', Wasser: '#0071E3', Warmwasser: '#FF3B30', Waerme: '#AF52DE',
};
const labelMap = {
	Strom: 'Strom', Wasser: 'Kaltwasser', Warmwasser: 'Warmwasser', Waerme: 'Wärme',
};

class TariffApp {
	constructor() {
		this.bindEvents();
		this.load();
		document.getElementById('themeToggle').addEventListener('click', () => this.toggleTheme());
	}

	toggleTheme() {
		const current = document.documentElement.getAttribute('data-theme');
		const next = current === 'dark' ? 'light' : 'dark';
		document.documentElement.setAttribute('data-theme', next);
		localStorage.setItem('em-theme', next);
		document.getElementById('themeIcon').innerHTML = icon(next === 'dark' ? 'sun' : 'moon');
	}

	bindEvents() {
		document.getElementById('addTariffBtn').addEventListener('click', () => this.openModal());
		document.getElementById('tariffModalClose').addEventListener('click', () => this.closeModal());
		document.getElementById('tariffModalCancel').addEventListener('click', () => this.closeModal());
		document.getElementById('tariffForm').addEventListener('submit', (e) => this.save(e));
	}

	async load() {
		const res = await fetch('api.php?action=get_tariffs');
		const json = await res.json();
		this.render(json.tariffs || []);
	}

	render(tariffs) {
		const tbody = document.getElementById('tariffTableBody');
		if (!tariffs.length) {
			tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:var(--text-secondary); padding:30px">Noch keine Tarife hinterlegt.</td></tr>';
			return;
		}
		const today = new Date().toISOString().split('T')[0];
		tbody.innerHTML = tariffs.map(t => {
			const isCurrent = t.valid_from <= today && (!t.valid_to || t.valid_to >= today);
			const priceEuro = (t.price_per_unit / 100).toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 4 });
			const baseEuro = (t.base_price_monthly / 100).toLocaleString('de-DE', { minimumFractionDigits: 2 });
			return `
				<tr class="${isCurrent ? 'current-row' : ''}">
					<td><span class="badge" style="background:${badgeColors[t.utility_type]}22; color:${badgeColors[t.utility_type]}">${labelMap[t.utility_type] || t.utility_type}</span></td>
					<td>${this.fmtDate(t.valid_from)}</td>
					<td>${t.valid_to ? this.fmtDate(t.valid_to) : '–'}</td>
					<td><strong>${priceEuro} €</strong></td>
					<td><strong>${baseEuro} €</strong></td>
					<td>${isCurrent ? '✓ Aktuell' : 'Historisch'}</td>
					<td>
						<div class="row-actions">
							<button data-action="edit" data-id="${t.id}">Bearbeiten</button>
							<button data-action="delete" data-id="${t.id}" class="danger">Löschen</button>
						</div>
					</td>
				</tr>`;
		}).join('');

		this._tariffs = tariffs;
		tbody.querySelectorAll('[data-action="edit"]').forEach(btn =>
			btn.addEventListener('click', () => this.openModal(this._tariffs.find(t => t.id == btn.dataset.id))));
		tbody.querySelectorAll('[data-action="delete"]').forEach(btn =>
			btn.addEventListener('click', () => this.remove(btn.dataset.id)));
	}

	openModal(tariff = null) {
		document.getElementById('tariffModalTitle').textContent = tariff ? 'Tarif bearbeiten' : 'Neuer Tarif';
		document.getElementById('tariffId').value = tariff?.id || '';
		document.getElementById('tariffType').value = tariff?.utility_type || 'Strom';
		document.getElementById('tariffValidFrom').value = tariff?.valid_from || new Date().toISOString().split('T')[0];
		document.getElementById('tariffValidTo').value = tariff?.valid_to || '';
		document.getElementById('tariffPricePerUnit').value = tariff?.price_per_unit || '';
		document.getElementById('tariffBasePrice').value = tariff?.base_price_monthly || '';
		document.getElementById('tariffAlert').classList.add('d-none');
		document.getElementById('tariffModal').classList.add('active');
	}

	closeModal() {
		document.getElementById('tariffModal').classList.remove('active');
	}

	async save(e) {
		e.preventDefault();
		const fd = new FormData(e.target);
		const alertBox = document.getElementById('tariffAlert');
		try {
			const res = await fetch('api.php?action=save_tariff', { method: 'POST', body: fd });
			const json = await res.json();
			if (!res.ok || json.error) throw new Error(json.error || 'Speichern fehlgeschlagen');
			this.closeModal();
			this.showToast('Tarif gespeichert');
			this.load();
		} catch (err) {
			alertBox.textContent = err.message;
			alertBox.classList.remove('d-none');
		}
	}

	async remove(id) {
		if (!confirm('Diesen Tarif wirklich löschen?')) return;
		const fd = new FormData();
		fd.append('id', id);
		const res = await fetch('api.php?action=delete_tariff', { method: 'POST', body: fd });
		const json = await res.json();
		if (json.success) {
			this.showToast('Gelöscht');
			this.load();
		} else {
			alert('Fehler: ' + (json.error || 'unbekannt'));
		}
	}

	showToast(msg) {
		const t = document.getElementById('toast');
		t.textContent = msg;
		t.classList.add('show');
		setTimeout(() => t.classList.remove('show'), 3000);
	}

	fmtDate(d) { return new Date(d).toLocaleDateString('de-DE'); }
}

document.addEventListener('DOMContentLoaded', () => new TariffApp());
