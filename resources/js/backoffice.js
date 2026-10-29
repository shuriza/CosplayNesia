/**
 * Operator console.
 *
 * This is a thin client over the existing /api/backoffice/* contract. It deliberately owns no
 * authorization logic: the server decides what an operator may see or do, and this file only
 * renders what comes back. When a sensitive read answers 423 the console asks for a fresh password
 * confirmation and replays the request, because "you may do this" and "prove it is you" are two
 * separate decisions the server makes in that order.
 */
import '../css/app.css';

const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const el = {
  gate: $('#console-gate'),
  gateMessage: $('#console-gate-message'),
  main: $('#console-main'),
  actor: $('#console-actor'),
  stepUpButton: $('#console-stepup'),
  stepUpNote: $('#console-stepup-note'),
  toast: $('#toast'),
  overlay: $('#console-overlay'),
  modal: $('#console-action-modal'),
  modalTitle: $('#console-action-title'),
  modalKicker: $('#console-action-kicker'),
  modalBody: $('#console-action-body'),
  modalForm: $('#console-action-form'),
  modalError: $('#console-action-error'),
  modalClose: $('#console-action-close'),
  privacyExport: $('#privacy-export'),
  privacySubject: $('#privacy-export-subject'),
  privacyBody: $('#privacy-export-body'),
  privacyDownload: $('#privacy-export-download'),
  privacyClose: $('#privacy-export-close'),
};

const csrfToken = $('meta[name="csrf-token"]')?.content ?? '';

const state = {
  actor: null,
  capabilities: { finance: false, listings: false, stores: false, queue: false },
  stepUpActive: false,
  tab: 'reports',
  cursors: {},
  // One pending action at a time; `run` is invoked with the reason the operator typed.
  pendingAction: null,
  filters: { stores: {}, users: {} },
};

const currency = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
});

const dateTime = new Intl.DateTimeFormat('id-ID', {
  dateStyle: 'medium',
  timeStyle: 'short',
});

class ApiError extends Error {
  constructor(message, status, payload) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }
}

function messageFrom(payload, fallback) {
  if (typeof payload?.message === 'string') return payload.message;
  const errors = payload?.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat().find((message) => typeof message === 'string');
    if (first) return first;
  }
  return fallback;
}

async function api(path, options = {}) {
  const method = String(options.method ?? 'GET').toUpperCase();
  const headers = new Headers(options.headers ?? {});
  headers.set('Accept', 'application/json');
  if (!['GET', 'HEAD'].includes(method) && csrfToken) headers.set('X-CSRF-TOKEN', csrfToken);

  let body = options.body;
  if (body !== undefined && typeof body !== 'string') {
    headers.set('Content-Type', 'application/json');
    body = JSON.stringify(body);
  }

  const response = await fetch(path, { ...options, method, headers, body, credentials: 'same-origin' });
  const contentType = response.headers.get('content-type') ?? '';
  const payload = response.status === 204
    ? null
    : contentType.includes('application/json')
      ? await response.json().catch(() => null)
      : null;

  if (!response.ok) {
    throw new ApiError(messageFrom(payload, 'Permintaan gagal.'), response.status, payload);
  }
  return payload;
}

function showToast(message) {
  el.toast.textContent = String(message);
  el.toast.classList.add('show');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => el.toast.classList.remove('show'), 3200);
}

function node(tag, options = {}, children = []) {
  const element = document.createElement(tag);
  Object.entries(options).forEach(([key, value]) => {
    if (value === null || value === undefined || value === false) return;
    if (key === 'class') element.className = value;
    else if (key === 'text') element.textContent = String(value);
    else if (key === 'html') element.innerHTML = value;
    else element.setAttribute(key, value === true ? '' : String(value));
  });
  (Array.isArray(children) ? children : [children])
    .filter(Boolean)
    .forEach((child) => element.append(child instanceof Node ? child : document.createTextNode(String(child))));
  return element;
}

function rows(container, entries) {
  container.replaceChildren(...entries);
}

function emptyRow(text) {
  return node('p', { class: 'console-empty', text });
}

function statusPill(value, modifier) {
  return node('span', { class: `console-pill${modifier ? ` console-pill--${modifier}` : ''}`, text: String(value ?? '-') });
}

function formatMoney(value, fallback = '-') {
  return typeof value === 'number' || (typeof value === 'string' && value !== '')
    ? currency.format(Number(value))
    : fallback;
}

function formatDate(value) {
  if (!value) return '-';
  const parsed = new Date(value);
  return Number.isNaN(parsed.getTime()) ? String(value) : dateTime.format(parsed);
}

/* ------------------------------------------------------------------ session */

async function refreshSession() {
  try {
    const payload = await api('/api/me');
    state.actor = payload?.user ?? null;
  } catch (error) {
    state.actor = null;
  }
  return state.actor;
}

function roleCapabilities(role) {
  const map = {
    buyer: [],
    seller: [],
    support: ['backoffice'],
    moderator: ['backoffice', 'review_stores', 'review_listings'],
    finance: ['backoffice', 'backoffice_finance', 'finance'],
    admin: ['backoffice', 'backoffice_finance', 'backoffice_queue', 'review_stores', 'review_listings', 'finance', 'administer'],
  };
  return map[role] ?? [];
}

function gate(reason) {
  el.main.hidden = true;
  el.gate.hidden = false;
  el.gateMessage.textContent = reason;
}

function renderActor() {
  const user = state.actor;
  if (!user) {
    el.actor.textContent = 'Belum masuk';
    return;
  }
  const caps = roleCapabilities(user.role);
  state.capabilities = {
    finance: caps.includes('backoffice_finance'),
    listings: caps.includes('review_listings'),
    stores: caps.includes('review_stores'),
    queue: caps.includes('backoffice_queue'),
  };
  el.actor.textContent = `${user.name} · ${user.role}`;
}

async function loadStepUpStatus() {
  try {
    const payload = await api('/api/backoffice/step-up');
    state.stepUpActive = Boolean(payload?.confirmed);
  } catch {
    state.stepUpActive = false;
  }
  renderStepUpNote();
}

function renderStepUpNote() {
  el.stepUpNote.textContent = state.stepUpActive
    ? 'Konfirmasi kata sandi aktif. Tindakan sensitif dapat dijalankan.'
    : 'Konfirmasi kata sandi diperlukan sebelum membuka data sensitif atau menjalankan tindakan.';
  el.stepUpNote.classList.toggle('console-note--ready', state.stepUpActive);
  el.stepUpButton.hidden = state.stepUpActive;
}

async function confirmStepUp() {
  const password = window.prompt('Masukkan kata sandi akunmu untuk konfirmasi:');
  if (!password) return false;
  try {
    await api('/api/backoffice/step-up', { method: 'POST', body: { password } });
    state.stepUpActive = true;
    renderStepUpNote();
    showToast('Konfirmasi berhasil.');
    return true;
  } catch (error) {
    showToast(error.message);
    return false;
  }
}

/**
 * Runs a backoffice request, and on 423 asks for a fresh password before replaying it once.
 * Only replays once: a second 423 means the confirmation itself was rejected.
 */
async function attempt(task) {
  try {
    return await task();
  } catch (error) {
    if (error instanceof ApiError && error.status === 423) {
      state.stepUpActive = false;
      renderStepUpNote();
      if (await confirmStepUp()) return task();
    }
    throw error;
  }
}

async function load(path, onData) {
  try {
    const payload = await attempt(() => api(path));
    onData(payload);
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) {
      showToast('Akunmu tidak memiliki kapabilitas untuk data ini.');
    } else {
      showToast(error.message);
    }
  }
}

/* -------------------------------------------------------------------- tabs */

function showTab(tab) {
  state.tab = tab;
  $$('.console-tab').forEach((button) => {
    const active = button.dataset.consoleTab === tab;
    button.classList.toggle('active', active);
    button.setAttribute('aria-pressed', String(active));
  });
  Object.keys(panels).forEach((key) => {
    $(`#panel-${key}`).hidden = key !== tab;
  });
  loaders[tab]?.();
}

/* ------------------------------------------------------------------ panels */

const panels = {
  reports: $('#panel-reports'),
  stores: $('#panel-stores'),
  users: $('#panel-users'),
  orders: $('#panel-orders'),
  ledger: $('#panel-ledger'),
  audit: $('#panel-audit'),
};

function setBusy(tab, busy) {
  const list = $(`#${tab === 'ledger' ? 'ledger' : tab}-list`) ?? $('#reports-list');
  list?.setAttribute('aria-busy', String(busy));
}

/* Reports --------------------------------------------------------------- */

function reportRow(report) {
  const evidence = report.evidence && typeof report.evidence === 'object'
    ? Object.entries(report.evidence).map(([key, value]) => `${key}: ${typeof value === 'string' ? value : JSON.stringify(value)}`)
    : [];

  const card = node('article', { class: 'console-card' }, [
    node('div', { class: 'console-card__heading' }, [
      node('strong', { text: `Laporan #${report.id}` }),
      statusPill(report.status, report.status === 'resolved' ? 'good' : report.status === 'dismissed' ? 'muted' : 'warn'),
    ]),
    node('small', { text: `${report.target_type} #${report.target_id} · ${report.category ?? 'kategori'} · ${formatDate(report.created_at)}` }),
    node('p', { text: report.reason ?? 'Tanpa alasan tertulis.' }),
    evidence.length
      ? node('ul', { class: 'console-evidence' }, evidence.map((line) => node('li', { text: line })))
      : node('p', { class: 'console-empty', text: 'Tidak ada evidence tersimpan.' }),
    report.resolution ? node('p', { class: 'console-resolution', text: `Keputusan: ${report.resolution}` }) : null,
    report.reporter ? node('small', { text: `Pelapor: ${report.reporter.name} (${report.reporter.role ?? '-'})` }) : null,
  ]);

  if (report.status === 'open' || report.status === 'reviewing') {
    const actions = node('div', { class: 'console-card__actions' }, [
      node('button', {
        type: 'button',
        class: 'button button--outline',
        text: 'Selesaikan',
        'data-report': report.id,
        'data-report-status': 'resolved',
      }),
      node('button', {
        type: 'button',
        class: 'button button--outline',
        text: 'Tolak laporan',
        'data-report': report.id,
        'data-report-status': 'dismissed',
      }),
    ]);
    card.append(actions);
  }

  return card;
}

async function loadReports({ append = false } = {}) {
  const list = $('#reports-list');
  setBusy('reports', true);
  const cursor = append ? state.cursors.reports : null;
  const path = `/api/backoffice/reports${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`;

  await load(path, (payload) => {
    state.cursors.reports = payload?.pagination?.next_cursor ?? null;
    const cards = (payload?.data ?? []).map(reportRow);
    if (!cards.length && !append) cards.push(emptyRow('Belum ada laporan masuk.'));
    rows(list, append ? [...list.children, ...cards] : cards);
    $('#load-more-reports').hidden = !payload?.pagination?.has_more;
  });
  setBusy('reports', false);
}

/* Stores ---------------------------------------------------------------- */

function storeRow(store) {
  const card = node('article', { class: 'console-card' }, [
    node('div', { class: 'console-card__heading' }, [
      node('strong', { text: store.name ?? `#${store.id}` }),
      statusPill(store.status, store.status === 'approved' ? 'good' : store.status === 'suspended' ? 'bad' : 'warn'),
    ]),
    node('small', { text: `/${store.slug} · ${store.city ?? 'kota belum diisi'} · pemilik: ${store.owner?.name ?? '-'} (${store.owner?.email ?? '-'})` }),
    store.payout_frozen
      ? node('p', { class: 'console-flag', text: `Pencairan dibekukan: ${store.payout_freeze_reason ?? 'tanpa alasan'}` })
      : null,
  ]);

  const actions = node('div', { class: 'console-card__actions' });
  if (state.capabilities.stores) {
    if (store.status === 'approved') {
      actions.append(node('button', { type: 'button', class: 'button button--outline', text: 'Suspend', 'data-store': store.id, 'data-store-action': 'suspend' }));
    }
    if (store.status === 'suspended') {
      actions.append(node('button', { type: 'button', class: 'button button--outline', text: 'Aktifkan kembali', 'data-store': store.id, 'data-store-action': 'reactivate' }));
    }
  }
  if (state.capabilities.finance) {
    actions.append(store.payout_frozen
      ? node('button', { type: 'button', class: 'button button--outline', text: 'Buka pembekuan', 'data-store': store.id, 'data-store-action': 'unfreeze-payout' })
      : node('button', { type: 'button', class: 'button button--outline', text: 'Bekukan pencairan', 'data-store': store.id, 'data-store-action': 'freeze-payout' }));
  }
  if (actions.children.length) card.append(actions);

  return card;
}

async function loadStores({ append = false } = {}) {
  const list = $('#stores-list');
  setBusy('stores', true);
  const params = new URLSearchParams(state.filters.stores);
  const cursor = append ? state.cursors.stores : null;
  if (cursor) params.set('cursor', cursor);
  const query = params.toString();

  await load(`/api/backoffice/stores${query ? `?${query}` : ''}`, (payload) => {
    state.cursors.stores = payload?.pagination?.next_cursor ?? null;
    const cards = (payload?.data ?? []).map(storeRow);
    if (!cards.length && !append) cards.push(emptyRow('Tidak ada toko yang cocok.'));
    rows(list, append ? [...list.children, ...cards] : cards);
    $('#load-more-stores').hidden = !payload?.pagination?.has_more;
  });
  setBusy('stores', false);
}

/* Users ----------------------------------------------------------------- */

function userRow(user) {
  const card = node('article', { class: 'console-card' }, [
    node('div', { class: 'console-card__heading' }, [
      node('strong', { text: user.name ?? `#${user.id}` }),
      statusPill(user.role),
    ]),
    node('small', { text: `${user.email ?? '-'} · ${user.deactivated ? 'dinonaktifkan' : 'aktif'} · ${user.email_verified ? 'email terverifikasi' : 'email belum verifikasi'}` }),
    node('small', { text: `Dibuat ${formatDate(user.created_at)}` }),
  ]);

  // The export endpoint is step-up protected server-side: if the confirmation has gone stale the
  // shared `attempt` wrapper will surface the 423 flow, so the button needs no logic of its own.
  card.append(node('div', { class: 'console-card__actions' }, [
    node('button', {
      type: 'button',
      class: 'button button--outline',
      text: 'Export privasi',
      'data-privacy-export': user.id,
      'data-privacy-name': user.name ?? `akun #${user.id}`,
    }),
  ]));

  return card;
}

async function loadUsers({ append = false } = {}) {
  const list = $('#users-list');
  setBusy('users', true);
  const params = new URLSearchParams(state.filters.users);
  const cursor = append ? state.cursors.users : null;
  if (cursor) params.set('cursor', cursor);
  const query = params.toString();

  await load(`/api/backoffice/users${query ? `?${query}` : ''}`, (payload) => {
    state.cursors.users = payload?.pagination?.next_cursor ?? null;
    const cards = (payload?.data ?? []).map(userRow);
    if (!cards.length && !append) cards.push(emptyRow('Tidak ada akun yang cocok.'));
    rows(list, append ? [...list.children, ...cards] : cards);
    $('#load-more-users').hidden = !payload?.pagination?.has_more;
  });
  setBusy('users', false);
}

/* Orders ---------------------------------------------------------------- */

function orderRow(order) {
  return node('article', { class: 'console-card' }, [
    node('div', { class: 'console-card__heading' }, [
      node('strong', { text: `Pesanan #${order.id}` }),
      statusPill(order.status, order.status === 'cancelled' ? 'bad' : 'warn'),
    ]),
    node('small', { text: `${formatMoney(order.grand_total ?? order.total_amount)} · ${order.buyer?.name ?? '-'} (${order.buyer?.email ?? '-'})` }),
    node('small', { text: `Kebijakan fee ${order.fee_policy_version ?? '-'} · ${formatDate(order.created_at)}` }),
  ]);
}

async function loadOrders({ append = false } = {}) {
  const list = $('#orders-list');
  setBusy('orders', true);
  const cursor = append ? state.cursors.orders : null;
  const path = `/api/backoffice/orders${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`;

  await load(path, (payload) => {
    state.cursors.orders = payload?.pagination?.next_cursor ?? null;
    const cards = (payload?.data ?? []).map(orderRow);
    if (!cards.length && !append) cards.push(emptyRow('Belum ada pesanan.'));
    rows(list, append ? [...list.children, ...cards] : cards);
    $('#load-more-orders').hidden = !payload?.pagination?.has_more;
  });
  setBusy('orders', false);
}

/* Ledger ---------------------------------------------------------------- */

async function loadLedger({ append = false } = {}) {
  const list = $('#ledger-list');
  const summary = $('#ledger-summary');
  setBusy('ledger', true);

  if (!state.capabilities.finance) {
    rows(summary, [emptyRow('Akunmu tidak memiliki kapabilitas finance.')]);
    setBusy('ledger', false);
    return;
  }

  const cursor = append ? state.cursors.ledger : null;

  try {
    const [transactions, reconciliation] = await attempt(async () => Promise.all([
      api(`/api/backoffice/ledger/transactions${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`),
      api('/api/backoffice/ledger/reconciliation'),
    ]));

    if (!append) {
      rows(summary, [
        node('div', { class: 'console-stat' }, [node('span', { text: 'Transaksi' }), node('strong', { text: String(reconciliation?.transactions ?? 0) })]),
        node('div', { class: 'console-stat' }, [node('span', { text: 'Total debit' }), node('strong', { text: formatMoney(reconciliation?.total_debit ?? 0) })]),
        node('div', { class: 'console-stat' }, [node('span', { text: 'Total kredit' }), node('strong', { text: formatMoney(reconciliation?.total_credit ?? 0) })]),
        node('div', { class: 'console-stat' }, [node('span', { text: 'Seimbang' }), node('strong', { text: reconciliation?.balanced ? 'Ya' : 'TIDAK' })]),
      ]);
    }

    state.cursors.ledger = transactions?.pagination?.next_cursor ?? null;
    const cards = (transactions?.data ?? []).map(transactionRow);
    if (!cards.length && !append) cards.push(emptyRow('Belum ada transaksi ledger.'));
    rows(list, append ? [...list.children, ...cards] : cards);
    $('#load-more-ledger').hidden = !transactions?.pagination?.has_more;
  } catch (error) {
    showToast(error.message);
  }
  setBusy('ledger', false);
}

function transactionRow(transaction) {
  const card = node('article', { class: 'console-card' }, [
    node('div', { class: 'console-card__heading' }, [
      node('strong', { text: transaction.event_key ?? `#${transaction.id}` }),
      transaction.reverses_transaction_id
        ? statusPill(`koreksi #${transaction.reverses_transaction_id}`, 'muted')
        : null,
    ]),
    node('small', { text: transaction.description ?? 'Tanpa deskripsi' }),
    node('ul', { class: 'console-evidence' }, (transaction.entries ?? []).map((entry) => node('li', {
      text: `${entry.direction === 'debit' ? 'D' : 'C'} ${entry.account_code ?? '-'} ${entry.account_name ?? ''} · ${formatMoney(entry.amount)}`,
    }))),
    node('small', { text: formatDate(transaction.occurred_at) }),
  ]);

  // A correction of a correction would restore the mistake, so an already-reversed transaction
  // offers nothing.
  const alreadyReversed = Boolean(transaction.reverses_transaction_id || transaction.reversed_by_id);
  if (state.capabilities.finance && !alreadyReversed) {
    card.append(node('div', { class: 'console-card__actions' }, [
      node('button', {
        type: 'button',
        class: 'button button--outline',
        text: 'Koreksi (balik transaksi)',
        'data-reverse': transaction.id,
      }),
    ]));
  }
  return card;
}

/* Audit ----------------------------------------------------------------- */

function auditRow(log) {
  const changes = log.context?.changes ?? null;
  return node('article', { class: 'console-card' }, [
    node('div', { class: 'console-card__heading' }, [
      node('strong', { text: log.action ?? 'aksi' }),
      statusPill(log.actor_role, 'muted'),
    ]),
    node('small', { text: `${log.actor?.name ?? 'sistem'} · ${log.target_type ?? '-'} #${log.target_id ?? '-'} · ${formatDate(log.occurred_at)}` }),
    log.reason ? node('p', { text: `Alasan: ${log.reason}` }) : null,
    changes
      ? node('ul', { class: 'console-evidence' }, Object.entries(changes).map(([key, value]) => node('li', {
        text: `${key}: ${value?.from ?? 'kosong'} → ${value?.to ?? 'kosong'}`,
      })))
      : null,
  ]);
}

async function loadAudit({ append = false } = {}) {
  const list = $('#audit-list');
  setBusy('audit', true);
  const cursor = append ? state.cursors.audit : null;
  const path = `/api/backoffice/audit${cursor ? `?cursor=${encodeURIComponent(cursor)}` : ''}`;

  await load(path, (payload) => {
    state.cursors.audit = payload?.pagination?.next_cursor ?? null;
    const cards = (payload?.data ?? []).map(auditRow);
    if (!cards.length && !append) cards.push(emptyRow('Belum ada entri audit.'));
    rows(list, append ? [...list.children, ...cards] : cards);
    $('#load-more-audit').hidden = !payload?.pagination?.has_more;
  });
  setBusy('audit', false);
}

const loaders = {
  reports: () => loadReports(),
  stores: () => loadStores(),
  users: () => loadUsers(),
  orders: () => loadOrders(),
  ledger: () => loadLedger(),
  audit: () => loadAudit(),
};

/* --------------------------------------------------------- privacy export */

function showPrivacyExport(subject, exportPayload) {
  el.privacySubject.textContent = `${subject} · dibaca ${formatDate(exportPayload.exported_at ?? new Date().toISOString())}`;
  el.privacyBody.textContent = JSON.stringify(exportPayload, null, 2);
  el.privacyExport.hidden = false;
  el.privacyExport.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

el.privacyClose.addEventListener('click', () => {
  el.privacyExport.hidden = true;
  el.privacyBody.textContent = '';
  el.privacySubject.textContent = '';
});

el.privacyDownload.addEventListener('click', () => {
  const subject = (el.privacySubject.textContent || 'akun').replace(/[^\w-]+/g, '-').slice(0, 40);
  const blob = new Blob([el.privacyBody.textContent], { type: 'application/json' });
  const url = URL.createObjectURL(blob);
  const anchor = node('a', { attrs: { href: url, download: `privacy-export-${subject}.json` } });
  document.body.append(anchor);
  anchor.click();
  anchor.remove();
  URL.revokeObjectURL(url);
  showToast('Berkas JSON diunduh.');
});

/* ----------------------------------------------------------------- modal */

let lastFocused = null;

function openModal(config) {
  state.pendingAction = config.run;
  el.modalKicker.textContent = config.kicker ?? 'Tindakan operator';
  el.modalTitle.textContent = config.title;
  el.modalBody.textContent = config.body ?? '';
  el.modalError.hidden = true;
  el.modalForm.reset();
  const input = el.modalForm.elements.reason;
  input.minLength = String(config.minLength ?? 10);
  lastFocused = document.activeElement;
  el.overlay.hidden = false;
  el.modal.removeAttribute('inert');
  el.modal.setAttribute('aria-hidden', 'false');
  requestAnimationFrame(() => {
    el.modal.classList.add('open');
    el.overlay.classList.add('open');
    input.focus();
  });
}

function closeModal() {
  state.pendingAction = null;
  el.modal.classList.remove('open');
  el.overlay.classList.remove('open');
  el.modal.setAttribute('aria-hidden', 'true');
  el.modal.setAttribute('inert', '');
  el.overlay.hidden = true;
  lastFocused?.focus?.();
}

el.modalClose.addEventListener('click', closeModal);
el.overlay.addEventListener('click', closeModal);

el.modalForm.addEventListener('submit', async (event) => {
  event.preventDefault();
  const run = state.pendingAction;
  if (!run) return;
  const reason = el.modalForm.elements.reason.value.trim();
  if (reason.length < Number(el.modalForm.elements.reason.minLength || 10)) {
    el.modalError.textContent = 'Alasan terlalu pendek.';
    el.modalError.hidden = false;
    return;
  }
  const button = el.modalForm.querySelector('button[type="submit"]');
  button.disabled = true;
  try {
    await attempt(() => run(reason));
    showToast('Tindakan berhasil.');
    closeModal();
    loaders[state.tab]?.();
  } catch (error) {
    el.modalError.textContent = error.message;
    el.modalError.hidden = false;
  } finally {
    button.disabled = false;
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape' && el.modal.classList.contains('open')) closeModal();
});

/* ---------------------------------------------------------------- events */

$$('.console-tab').forEach((button) => {
  button.addEventListener('click', () => showTab(button.dataset.consoleTab));
});

$$('[data-console-load]').forEach((button) => {
  button.addEventListener('click', () => loaders[button.dataset.consoleLoad]?.());
});

el.stepUpButton.addEventListener('click', () => confirmStepUp());

$('#stores-filter').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  state.filters.stores = Object.fromEntries([...form].filter(([, value]) => value !== ''));
  state.cursors.stores = null;
  loadStores();
});

$('#users-filter').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  state.filters.users = Object.fromEntries([...form].filter(([, value]) => value !== ''));
  state.cursors.users = null;
  loadUsers();
});

const loadMoreTargets = {
  'load-more-reports': () => loadReports({ append: true }),
  'load-more-stores': () => loadStores({ append: true }),
  'load-more-users': () => loadUsers({ append: true }),
  'load-more-orders': () => loadOrders({ append: true }),
  'load-more-ledger': () => loadLedger({ append: true }),
  'load-more-audit': () => loadAudit({ append: true }),
};
Object.entries(loadMoreTargets).forEach(([id, run]) => {
  $(`#${id}`)?.addEventListener('click', run);
});

document.addEventListener('click', async (event) => {
  const exportTrigger = event.target.closest('[data-privacy-export]');
  if (exportTrigger) {
    const userId = exportTrigger.dataset.privacyExport;
    const subject = exportTrigger.dataset.privacyName ?? `akun #${userId}`;
    exportTrigger.disabled = true;
    try {
      const payload = await attempt(() => api(`/api/backoffice/users/${userId}/privacy-export`));
      showPrivacyExport(subject, payload?.export ?? {});
    } finally {
      exportTrigger.disabled = false;
    }
    return;
  }

  const storeAction = event.target.closest('[data-store-action]');
  if (storeAction) {
    const storeId = storeAction.dataset.store;
    const action = storeAction.dataset.storeAction;
    const labels = {
      suspend: ['Suspend toko', 'Toko tidak akan menerima pesanan baru; kewajiban yang sudah ada tetap berjalan.'],
      reactivate: ['Aktifkan kembali toko', 'Toko kembali dapat menerima pesanan.'],
      'freeze-payout': ['Bekukan pencairan', 'Pencairan dana dihentikan. Saldo ledger tidak berubah — ini hanya menahan pembayaran keluar.'],
      'unfreeze-payout': ['Buka pembekuan pencairan', 'Pencairan dana dapat berjalan kembali.'],
    };
    const [title, body] = labels[action] ?? ['Tindakan', ''];
    openModal({
      title,
      body,
      run: (reason) => api(`/api/backoffice/stores/${storeId}/${action}`, { method: 'POST', body: { reason } }),
    });
    return;
  }

  const reportAction = event.target.closest('[data-report-status]');
  if (reportAction) {
    const reportId = reportAction.dataset.report;
    const status = reportAction.dataset.reportStatus;
    const dismissed = status === 'dismissed';
    openModal({
      title: dismissed ? 'Tolak laporan' : 'Selesaikan laporan',
      // Dismissing cannot carry a punitive action, so the operator is told before typing.
      body: dismissed
        ? 'Laporan ditutup tanpa tindakan pada target. Penolakan tidak dapat menurunkan listing.'
        : 'Pilih tindakan untuk target laporan ini.',
      minLength: 5,
      run: (reason) => api(`/api/backoffice/reports/${reportId}/resolve`, {
        method: 'POST',
        body: { status, resolution: reason, action: dismissed ? 'none' : 'takedown' },
      }),
    });
    return;
  }

  const reverse = event.target.closest('[data-reverse]');
  if (reverse) {
    const transactionId = reverse.dataset.reverse;
    openModal({
      title: 'Koreksi transaksi ledger',
      body: 'Transaksi baru akan diposting dengan arah terbalik. Entri asli tidak pernah diubah, sehingga kesalahan dan koreksinya tetap terbaca.',
      run: (reason) => api(`/api/backoffice/ledger/transactions/${transactionId}/reverse`, {
        method: 'POST',
        body: { reason },
      }),
    });
  }
});

/* ------------------------------------------------------------------ boot */

(async function boot() {
  const user = await refreshSession();
  if (!user) {
    gate('Masuk dengan akun yang memiliki kapabilitas backoffice.');
    return;
  }
  if (!roleCapabilities(user.role).includes('backoffice')) {
    gate('Akun ini tidak memiliki kapabilitas backoffice. Data konsol tidak dimuat.');
    return;
  }
  el.gate.hidden = true;
  el.main.hidden = false;
  renderActor();
  await loadStepUpStatus();
  showTab('reports');
})();
