// tariffs.js – Verwaltung der Tarife (CRUD über api.php)

class TariffApp {
	constructor() {
		this.tariffs = [];
		this.utilities = {};
		this.modal = EM.modal('tariffModal');
		EM.initTheme();
		this.bindEvents();
		this.load();
	}

	bindEvents() {
		const $ = (id) => document.getElementById(id);
		$('addTariffBtn').addEventListener('click', () => this.openModal());
		$('tariffModalClose').addEventListener('click', () => this.modal.close());
		$('tariffModalCancel').addEventListener('click', () => this.modal.close());
		$('tariffForm').addEventListener('submit', (e) => this.save(e));
		$('tariffTableBody').addEventListener('click', (e) => {
			const btn = e.target.closest('button[data-action]');
			if (!btn) return;
			if (btn.dataset.action === 'edit') this.openModal(this.tariffs.find((t) => String(t.id) === btn.dataset.id));
			if (btn.dataset.action === 'delete') this.remove(btn.dataset.id);
		});
	}

	async load() {
		try {
			const res = await EM.api('get_tariffs');
			this.utilities = res.utilities || {};
			this.tariffs = res.tariffs || [];
			this.render();
		} catch (e) {
			EM.toast('Fehler beim Laden: ' + e.message, 6000);
		}
	}

	render() {
		const tbody = document.getElementById('tariffTableBody');
		if (!this.tariffs.length) {
			tbody.innerHTML = '<tr><td colspan="7" style="text-align:center; color:var(--text-secondary); padding:30px">Noch keine Tarife hinterlegt.</td></tr>';
			return;
		}
		const today = EM.localDate();
		const esc = EM.escape;
		tbody.innerHTML = this.tariffs.map((t) => {
			const isCurrent = t.valid_from <= today && (!t.valid_to || t.valid_to >= today);
			const u = this.utilities[t.utility_type] || { label: t.utility_type, color: '#86868B' };
			const priceEuro = EM.fmtFixed(t.price_per_unit / 100, 2, 4);
			return `
				<tr class="${isCurrent ? 'current-row' : ''}">
					<td><span class="badge" style="background:${esc(u.color)}22; color:${esc(u.color)}">${esc(u.label)}</span></td>
					<td>${EM.fmtDate(t.valid_from)}</td>
					<td>${t.valid_to ? EM.fmtDate(t.valid_to) : '–'}</td>
					<td><strong>${priceEuro} €</strong></td>
					<td><strong>${EM.fmtFixed(t.base_price_monthly / 100, 2, 2)} €</strong></td>
					<td>${isCurrent ? '✓ Aktuell' : 'Historisch'}</td>
					<td>
						<div class="row-actions">
							<button data-action="edit" data-id="${esc(t.id)}">Bearbeiten</button>
							<button data-action="delete" data-id="${esc(t.id)}" class="danger">Löschen</button>
						</div>
					</td>
				</tr>`;
		}).join('');
	}

	openModal(tariff = null) {
		const $ = (id) => document.getElementById(id);
		$('tariffModalTitle').textContent = tariff ? 'Tarif bearbeiten' : 'Neuer Tarif';
		$('tariffId').value = tariff?.id || '';
		$('tariffType').value = tariff?.utility_type || Object.keys(this.utilities)[0] || '';
		$('tariffValidFrom').value = tariff?.valid_from || EM.localDate();
		$('tariffValidTo').value = tariff?.valid_to || '';
		$('tariffPricePerUnit').value = tariff?.price_per_unit ?? '';
		$('tariffBasePrice').value = tariff?.base_price_monthly ?? '';
		$('tariffAlert').classList.add('d-none');
		this.modal.open();
	}

	async save(e) {
		e.preventDefault();
		const alertBox = document.getElementById('tariffAlert');
		try {
			await EM.api('save_tariff', { body: new FormData(e.target) });
			this.modal.close();
			EM.toast('Tarif gespeichert');
			this.load();
		} catch (err) {
			alertBox.textContent = err.message;
			alertBox.classList.remove('d-none');
		}
	}

	async remove(id) {
		if (!confirm('Diesen Tarif wirklich löschen?')) return;
		try {
			await EM.api('delete_tariff', { body: { id } });
			EM.toast('Gelöscht');
			this.load();
		} catch (err) {
			EM.toast('Fehler: ' + err.message, 6000);
		}
	}
}

document.addEventListener('DOMContentLoaded', () => new TariffApp());
