const $ = (selector, root = document) => root.querySelector(selector);
const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

const elements = {
  productGrid: $('#product-grid'),
  resultCount: $('#result-count'),
  emptyState: $('#empty-state'),
  loadMore: $('#load-more'),
  overlay: $('#overlay'),
  cartDrawer: $('#cart-drawer'),
  productModal: $('#product-modal'),
  authModal: $('#auth-modal'),
  profileDrawer: $('#profile-drawer'),
  addProductDrawer: $('#add-product-drawer'),
  toast: $('#toast'),
};

const state = {
  products: [],
  query: '',
  filter: 'Semua',
  sort: 'popular',
  visible: 8,
  cart: new Map(),
  favorites: new Set(),
  favoritesOnly: false,
  user: null,
  authMode: 'login',
  activeLayer: null,
  returnFocus: null,
  productRequest: null,
  ownedProducts: new Map(),
  editingProductId: null,
};

let csrfToken = $('meta[name="csrf-token"]')?.content ?? '';
const currency = new Intl.NumberFormat('id-ID', {
  style: 'currency',
  currency: 'IDR',
  maximumFractionDigits: 0,
});

function node(tag, options = {}, children = []) {
  const element = document.createElement(tag);
  if (options.className) element.className = options.className;
  if (options.text !== undefined) element.textContent = String(options.text);
  if (options.attrs) {
    Object.entries(options.attrs).forEach(([name, value]) => {
      if (value !== null && value !== undefined && value !== false) {
        element.setAttribute(name, value === true ? '' : String(value));
      }
    });
  }
  for (const child of children.flat()) {
    if (child !== null && child !== undefined) element.append(child);
  }
  return element;
}

function button(text, className, attrs = {}) {
  return node('button', { className, text, attrs: { type: 'button', ...attrs } });
}

function safeImageUrl(value) {
  if (!value) return null;
  try {
    const url = new URL(String(value), window.location.origin);
    return ['http:', 'https:'].includes(url.protocol) ? url.href : null;
  } catch {
    return null;
  }
}

function imageOrFallback(source, alt, className = '') {
  const url = safeImageUrl(source);
  if (!url) return node('span', { className: `image-fallback ${className}`.trim(), text: '✦', attrs: { 'aria-hidden': 'true' } });
  const image = node('img', { className, attrs: { src: url, alt: String(alt ?? ''), loading: 'lazy', decoding: 'async' } });
  image.addEventListener('error', () => image.replaceWith(node('span', { className: 'image-fallback', text: '✦', attrs: { 'aria-hidden': 'true' } })), { once: true });
  return image;
}

function valueOf(object, keys, fallback = '') {
  for (const key of keys) {
    if (object?.[key] !== undefined && object[key] !== null) return object[key];
  }
  return fallback;
}

function productId(product) {
  return String(valueOf(product, ['id', 'product_id'], ''));
}

function productName(product) {
  const nested = product?.product && typeof product.product === 'object' ? product.product : product;
  return String(valueOf(nested, ['name', 'title'], 'Produk cosplay'));
}

function productPrice(product) {
  const nested = product?.product && typeof product.product === 'object' ? product.product : product;
  const amount = Number(valueOf(product, ['price', 'unit_price', 'price_at_purchase'], valueOf(nested, ['price', 'amount'], 0)));
  return Number.isFinite(amount) ? amount : 0;
}

function sellerName(product) {
  const seller = valueOf(product, ['seller', 'user', 'owner'], 'CosplayNesia');
  return typeof seller === 'object' ? String(valueOf(seller, ['name', 'email'], 'CosplayNesia')) : String(seller);
}

function productList(payload) {
  const candidate = Array.isArray(payload) ? payload : valueOf(payload, ['data', 'products', 'items'], []);
  if (Array.isArray(candidate)) return candidate;
  return Array.isArray(candidate?.data) ? candidate.data : [];
}

function messageFrom(payload, fallback) {
  if (typeof payload?.message === 'string') return payload.message;
  if (typeof payload?.error === 'string') return payload.error;
  const errors = payload?.errors;
  if (errors && typeof errors === 'object') {
    const first = Object.values(errors).flat().find((message) => typeof message === 'string');
    if (first) return first;
  }
  return fallback;
}

class ApiError extends Error {
  constructor(message, status, payload) {
    super(message);
    this.name = 'ApiError';
    this.status = status;
    this.payload = payload;
  }
}

async function api(path, options = {}) {
  const method = String(options.method ?? 'GET').toUpperCase();
  const headers = new Headers(options.headers ?? {});
  headers.set('Accept', 'application/json');
  if (!['GET', 'HEAD'].includes(method) && csrfToken) headers.set('X-CSRF-TOKEN', csrfToken);

  let body = options.body;
  if (body !== undefined && !(body instanceof FormData) && typeof body !== 'string') {
    headers.set('Content-Type', 'application/json');
    body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(path, {
      ...options,
      method,
      headers,
      body,
      credentials: 'same-origin',
    });
  } catch (error) {
    if (error?.name === 'AbortError') throw error;
    throw new ApiError('Tidak dapat terhubung ke server. Periksa koneksimu.', 0, null);
  }

  const contentType = response.headers.get('content-type') ?? '';
  let payload = null;
  if (response.status !== 204) {
    payload = contentType.includes('application/json')
      ? await response.json().catch(() => null)
      : await response.text().catch(() => null);
  }

  if (!response.ok) {
    if (response.status === 419) {
      throw new ApiError('Sesi keamanan berakhir. Muat ulang halaman lalu coba lagi.', 419, payload);
    }
    throw new ApiError(messageFrom(payload, response.status === 401 ? 'Silakan masuk untuk melanjutkan.' : 'Permintaan gagal.'), response.status, payload);
  }
  return payload;
}

function showToast(message) {
  elements.toast.textContent = String(message);
  elements.toast.classList.add('show');
  window.clearTimeout(showToast.timer);
  showToast.timer = window.setTimeout(() => elements.toast.classList.remove('show'), 3200);
}

function setBusy(target, busy) {
  target?.setAttribute('aria-busy', String(Boolean(busy)));
}

function focusableElements(layer) {
  return $$('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])', layer)
    .filter((element) => !element.hidden && element.getClientRects().length > 0);
}

function openLayer(layer, trigger = document.activeElement) {
  if (!layer) return;
  if (state.activeLayer && state.activeLayer !== layer) closeLayer(false);
  state.returnFocus = trigger instanceof HTMLElement ? trigger : null;
  state.activeLayer = layer;
  layer.removeAttribute('inert');
  layer.setAttribute('aria-hidden', 'false');
  elements.overlay.hidden = false;
  requestAnimationFrame(() => {
    layer.classList.add('open');
    elements.overlay.classList.add('open');
    document.body.classList.add('locked');
    (focusableElements(layer)[0] ?? layer).focus?.();
  });
}

function closeLayer(restoreFocus = true) {
  const layer = state.activeLayer;
  if (!layer) return;
  layer.classList.remove('open');
  layer.setAttribute('aria-hidden', 'true');
  layer.setAttribute('inert', '');
  elements.overlay.classList.remove('open');
  document.body.classList.remove('locked');
  state.activeLayer = null;
  window.setTimeout(() => { if (!state.activeLayer) elements.overlay.hidden = true; }, 260);
  if (restoreFocus && state.returnFocus?.isConnected && !state.returnFocus.closest('[inert]') && state.returnFocus.getClientRects().length) {
    state.returnFocus.focus();
  }
}

function visibleAccountTrigger() {
  return [...$$('.profile-action'), $('.mobile-account-action')]
    .find((control) => control && !control.hidden && control.getClientRects().length > 0);
}

function requireAuthentication(trigger) {
  if (state.user) return true;
  showToast('Silakan masuk untuk melanjutkan.');
  setAuthMode('login');
  openLayer(elements.authModal, trigger);
  return false;
}

function handleProtectedError(error, trigger) {
  if (error instanceof ApiError && error.status === 401) {
    state.user = null;
    state.favorites.clear();
    updateAuthUi();
    closeLayer(false);
    requireAuthentication(trigger);
    return;
  }
  showToast(error.message ?? 'Terjadi kesalahan.');
}

function productQuery() {
  const params = new URLSearchParams();
  if (state.query) params.set('q', state.query);
  if (!['Semua', 'Terbaru', 'Terlaris'].includes(state.filter)) params.set('category', state.filter);
  const effectiveSort = state.filter === 'Terbaru' ? 'newest' : state.filter === 'Terlaris' ? 'popular' : state.sort;
  params.set('sort', effectiveSort);
  return params.toString();
}

async function fetchProducts({ scroll = false } = {}) {
  state.productRequest?.abort();
  state.productRequest = new AbortController();
  setBusy(elements.productGrid, true);
  elements.resultCount.textContent = 'Memuat produk…';
  elements.productGrid.replaceChildren(node('p', { className: 'loading-message', text: 'Menyiapkan koleksi untukmu…' }));
  elements.emptyState.hidden = true;
  elements.loadMore.hidden = true;

  try {
    const payload = await api(`/api/products?${productQuery()}`, { signal: state.productRequest.signal });
    state.products = productList(payload);
    renderProducts();
    if (scroll) $('#produk')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (error) {
    if (error?.name === 'AbortError') return;
    state.products = [];
    elements.productGrid.replaceChildren(node('p', { className: 'loading-message inline-error', text: error.message }));
    elements.resultCount.textContent = 'Produk gagal dimuat';
    showToast(error.message);
  } finally {
    setBusy(elements.productGrid, false);
  }
}

function displayedProducts() {
  const products = state.favoritesOnly
    ? state.products.filter((product) => state.favorites.has(productId(product)))
    : state.products;
  return products;
}

function productCard(product) {
  const id = productId(product);
  const name = productName(product);
  const favorite = state.favorites.has(id);
  const card = node('article', { className: 'product-card', attrs: { 'data-product-card': id } });
  card.append(node('button', {
    className: 'product-card__button',
    attrs: { type: 'button', 'data-open-product': id, 'aria-label': `Lihat detail ${name}` },
  }));
  const imageWrap = node('div', { className: 'product-card__image' });
  imageWrap.append(imageOrFallback(valueOf(product, ['image', 'image_url']), name));
  const badgeText = String(valueOf(product, ['badge'], Number(valueOf(product, ['stock'], 1)) === 0 ? 'Habis' : ''));
  imageWrap.append(node('span', { className: `badge ${badgeText.toLowerCase() === 'baru' ? 'badge--new' : ''}`, text: badgeText }));
  const favoriteButton = button(favorite ? '♥' : '♡', `favorite ${favorite ? 'active' : ''}`, {
    'data-favorite': id,
    'aria-label': `${favorite ? 'Hapus' : 'Tambah'} ${name} ${favorite ? 'dari' : 'ke'} favorit`,
    'aria-pressed': String(favorite),
  });
  imageWrap.append(favoriteButton);

  const body = node('div', { className: 'product-card__body' });
  body.append(node('span', { className: 'product-card__series', text: valueOf(product, ['series', 'category'], 'Cosplay') }));
  body.append(node('h3', { text: name }));
  const meta = node('div', { className: 'product-card__meta' });
  meta.append(node('span', { className: 'meta-pill', text: valueOf(product, ['type'], 'Sewa') }));
  meta.append(node('span', { className: 'meta-pill', text: valueOf(product, ['size'], 'All size') }));
  meta.append(node('span', { text: `★ ${valueOf(product, ['rating'], 'Baru')}` }));
  body.append(meta);
  const seller = sellerName(product);
  body.append(node('div', { className: 'seller' }, [
    node('span', { className: 'seller__avatar', text: seller.charAt(0).toUpperCase() || 'C' }),
    node('span', { text: seller }),
    node('span', { className: 'verified', text: '✓', attrs: { title: 'Terverifikasi', 'aria-label': 'Terverifikasi' } }),
  ]));
  const price = node('div', { className: 'product-card__price' });
  price.append(node('span', {}, [
    node('strong', { text: currency.format(productPrice(product)) }),
    node('small', { text: valueOf(product, ['type'], 'Sewa') === 'Sewa' ? '/ 3 hari' : 'Harga jual' }),
  ]));
  const stock = Number(valueOf(product, ['stock'], 1));
  const add = button('+', 'mini-add', { 'data-add': id, 'aria-label': `Tambah ${name} ke keranjang` });
  if (stock === 0) add.disabled = true;
  price.append(add);
  body.append(price);
  card.append(imageWrap, body);
  return card;
}

function renderProducts() {
  const filtered = displayedProducts();
  const visible = filtered.slice(0, state.visible);
  elements.productGrid.replaceChildren(...visible.map(productCard));
  elements.resultCount.textContent = state.favoritesOnly ? `${filtered.length} favorit ditampilkan` : `${filtered.length} produk`;
  elements.emptyState.hidden = filtered.length > 0;
  elements.productGrid.hidden = filtered.length === 0;
  elements.loadMore.hidden = filtered.length === 0 || filtered.length <= state.visible;
}

function setFilter(filter) {
  state.filter = filter;
  state.favoritesOnly = false;
  state.visible = 8;
  $$('[data-filter]').forEach((control) => control.classList.toggle('active', control.dataset.filter === filter));
  fetchProducts({ scroll: true });
}

function submitSearch(value) {
  state.query = String(value).trim();
  state.favoritesOnly = false;
  state.visible = 12;
  $('#search-input').value = state.query;
  $('#mobile-search-input').value = state.query;
  fetchProducts({ scroll: true });
}

function findProduct(id) {
  return state.products.find((product) => productId(product) === String(id))
    ?? state.cart.get(String(id));
}

function openProduct(id, trigger) {
  const product = findProduct(id);
  if (!product) return;
  const name = productName(product);
  const content = $('#modal-content');
  const layout = node('div', { className: 'modal-product' });
  layout.append(node('div', { className: 'modal-product__image' }, [imageOrFallback(valueOf(product, ['image', 'image_url']), name)]));
  const info = node('div', { className: 'modal-product__info' });
  info.append(node('span', { className: 'section-kicker', text: valueOf(product, ['series', 'category'], 'Cosplay') }));
  info.append(node('h2', { text: name, attrs: { id: 'modal-product-title' } }));
  const seller = sellerName(product);
  info.append(node('div', { className: 'seller' }, [
    node('span', { className: 'seller__avatar', text: seller.charAt(0).toUpperCase() || 'C' }),
    node('span', { text: seller }), node('span', { className: 'verified', text: '✓', attrs: { 'aria-label': 'Terverifikasi' } }),
    node('span', { text: valueOf(product, ['city', 'location'], '') ? `· ${valueOf(product, ['city', 'location'])}` : '' }),
  ]));
  info.append(node('div', { className: 'modal-product__price' }, [
    node('strong', { text: currency.format(productPrice(product)) }),
    node('span', { text: valueOf(product, ['type'], 'Sewa') === 'Sewa' ? ' / 3 hari' : '' }),
  ]));
  const stock = Number(valueOf(product, ['stock'], 1));
  info.append(node('p', { className: 'stock-copy', text: stock > 0 ? `Stok tersedia: ${stock}` : 'Stok habis' }));
  info.append(node('p', { className: 'product-description', text: valueOf(product, ['description'], 'Kostum terawat dan siap dipakai untuk event berikutnya. Checkout demo mencatat pesanan dan memperbarui stok tanpa memproses pembayaran.') }));
  info.append(node('div', { className: 'modal-meta' }, [
    node('span', {}, [node('strong', { text: valueOf(product, ['size'], 'All size') }), node('br'), document.createTextNode('Ukuran')]),
    node('span', {}, [node('strong', { text: `★ ${valueOf(product, ['rating'], 'Baru')}` }), node('br'), document.createTextNode('Rating')]),
    node('span', {}, [node('strong', { text: valueOf(product, ['type'], 'Sewa') }), node('br'), document.createTextNode('Tipe')]),
  ]));
  const add = button(stock === 0 ? 'Stok habis' : 'Tambah ke keranjang', 'button button--primary button--full', { 'data-modal-add': id });
  add.disabled = stock === 0;
  info.append(add);
  layout.append(info);
  content.replaceChildren(layout);
  openLayer(elements.productModal, trigger);
}

function addToCart(id) {
  const product = findProduct(id);
  if (!product) return;
  const key = productId(product);
  if (state.cart.has(key)) {
    showToast(`${productName(product)} sudah ada di keranjang.`);
    return;
  }
  state.cart.set(key, product);
  renderCart();
  showToast(`${productName(product)} ditambahkan ke keranjang.`);
}

function renderCart() {
  const products = [...state.cart.values()];
  const count = products.length;
  $('#cart-count').textContent = String(count);
  $('#mobile-cart-count').textContent = String(count);
  $('#cart-button').setAttribute('aria-label', `Buka keranjang, ${count} produk`);
  const items = products.map((product) => {
    const id = productId(product);
    const item = node('article', { className: 'cart-item' });
    item.append(imageOrFallback(valueOf(product, ['image', 'image_url']), productName(product)));
    item.append(node('div', {}, [
      node('h3', { text: productName(product) }),
      node('p', { text: currency.format(productPrice(product)) }),
      node('small', { text: `${valueOf(product, ['type'], 'Sewa')} · ${valueOf(product, ['size'], 'All size')} · Jumlah 1` }),
    ]));
    item.append(button('×', 'cart-item__remove', { 'data-remove': id, 'aria-label': `Hapus ${productName(product)} dari keranjang` }));
    return item;
  });
  $('#cart-items').replaceChildren(...items);
  $('#cart-empty').hidden = count > 0;
  $('#cart-summary').hidden = count === 0;
  $('#cart-subtotal').textContent = currency.format(products.reduce((sum, product) => sum + productPrice(product), 0));
}

function reconcileCartProduct(product, id = productId(product)) {
  const key = String(id);
  if (!state.cart.has(key)) return;
  if (product && Boolean(valueOf(product, ['is_active'], true))) state.cart.set(key, product);
  else state.cart.delete(key);
  renderCart();
}

function favoriteIds(payload) {
  const list = Array.isArray(payload) ? payload : valueOf(payload, ['data', 'favorites', 'items'], []);
  return Array.isArray(list)
    ? list.map((favorite) => String(typeof favorite === 'object' ? valueOf(favorite, ['product_id'], valueOf(favorite?.product, ['id'], valueOf(favorite, ['id'], ''))) : favorite)).filter(Boolean)
    : [];
}

async function loadFavorites() {
  if (!state.user) {
    state.favorites.clear();
    renderProducts();
    return;
  }
  try {
    state.favorites = new Set(favoriteIds(await api('/api/favorites')));
    renderProducts();
  } catch (error) {
    handleProtectedError(error);
  }
}

async function toggleFavorite(id, trigger) {
  if (!requireAuthentication(trigger)) return;
  const key = String(id);
  const wasFavorite = state.favorites.has(key);
  if (wasFavorite) state.favorites.delete(key); else state.favorites.add(key);
  renderProducts();
  try {
    if (wasFavorite) await api(`/api/favorites/${encodeURIComponent(key)}`, { method: 'DELETE' });
    else {
      const productIdValue = Number(key) || key;
      await api('/api/favorites', { method: 'POST', body: { product_id: productIdValue, productId: productIdValue } });
    }
    showToast(wasFavorite ? 'Dihapus dari favorit.' : 'Disimpan ke favorit.');
  } catch (error) {
    if (wasFavorite) state.favorites.add(key); else state.favorites.delete(key);
    renderProducts();
    handleProtectedError(error, trigger);
  }
}

function setAuthMode(mode) {
  state.authMode = mode === 'register' ? 'register' : 'login';
  const registering = state.authMode === 'register';
  $('#auth-title').textContent = registering ? 'Buat akun CosplayNesia' : 'Masuk ke CosplayNesia';
  $('#name-field').hidden = !registering;
  $('#password-confirmation-field').hidden = !registering;
  $('#auth-name').required = registering;
  $('#auth-password-confirmation').required = registering;
  $('#auth-password').autocomplete = registering ? 'new-password' : 'current-password';
  $('#auth-form button[type="submit"]').textContent = registering ? 'Daftar' : 'Masuk';
  $$('[data-switch-auth]').forEach((control) => control.setAttribute('aria-pressed', String(control.dataset.switchAuth === state.authMode)));
  $('#auth-error').hidden = true;
}

function normalizedUser(payload) {
  if (!payload || typeof payload !== 'object' || Array.isArray(payload)) return null;
  if (payload.user && typeof payload.user === 'object') return payload.user;
  if (payload.data?.user && typeof payload.data.user === 'object') return payload.data.user;
  if (payload.data && typeof payload.data === 'object' && !Array.isArray(payload.data)) return payload.data;
  return ['id', 'email', 'name'].some((key) => payload[key] !== undefined) ? payload : null;
}

async function refreshSession() {
  try {
    state.user = normalizedUser(await api('/api/me'));
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401) showToast(error.message);
    state.user = null;
  }
  updateAuthUi();
  if (state.user) await loadFavorites();
}

function updateAuthUi() {
  const authenticated = Boolean(state.user);
  $$('.auth-action').forEach((control) => { control.hidden = authenticated; });
  $$('.profile-action').forEach((control) => { control.hidden = !authenticated; });
  $('.mobile-account-label').textContent = authenticated ? 'Profil' : 'Akun';
}

async function submitAuth(form) {
  const errorBox = $('#auth-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const submit = $('button[type="submit"]', form);
  submit.disabled = true;
  try {
    const payload = {
      email: $('#auth-email').value.trim(),
      password: $('#auth-password').value,
    };
    if (state.authMode === 'register') {
      payload.name = $('#auth-name').value.trim();
      payload.password_confirmation = $('#auth-password-confirmation').value;
    }
    const response = await api(`/api/auth/${state.authMode}`, { method: 'POST', body: payload });
    state.user = normalizedUser(response);
    if (!state.user) state.user = normalizedUser(await api('/api/me'));
    updateAuthUi();
    await loadFavorites();
    form.reset();
    closeLayer();
    showToast(state.authMode === 'register' ? 'Akun berhasil dibuat.' : 'Berhasil masuk.');
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
    if (error.status === 419) showToast(error.message);
  } finally {
    submit.disabled = false;
  }
}

function listMessage(message) {
  return node('p', { className: 'list-message', text: message });
}

function renderMyProducts(payload) {
  const products = productList(payload);
  state.ownedProducts = new Map(products.map((product) => [productId(product), product]));
  const target = $('#my-products-list');
  if (!products.length) {
    target.replaceChildren(listMessage('Belum ada produk di tokomu.'));
    return;
  }
  target.replaceChildren(...products.map((product) => {
    const id = productId(product);
    const name = productName(product);
    const active = Boolean(valueOf(product, ['is_active'], true));
    const card = node('article', { className: 'profile-card' });
    card.append(node('div', { className: 'profile-card__row profile-card__heading' }, [
      node('strong', { text: productName(product) }),
      node('span', { className: `listing-status ${active ? '' : 'listing-status--inactive'}`.trim(), text: active ? 'Aktif' : 'Nonaktif' }),
    ]));
    card.append(node('small', { text: `${valueOf(product, ['category'], 'Cosplay')} · ${currency.format(productPrice(product))} · Stok ${valueOf(product, ['stock'], 0)}` }));
    card.append(node('div', { className: 'profile-card__actions' }, [
      button('Edit', '', { 'data-edit-product': id, 'aria-label': `Edit ${name}` }),
      button(active ? 'Nonaktifkan' : 'Aktifkan', '', { 'data-toggle-product': id, 'data-next-active': String(!active), 'aria-label': `${active ? 'Nonaktifkan' : 'Aktifkan'} ${name}` }),
      button('Hapus', 'danger-action', { 'data-delete-product': id, 'aria-label': `Hapus ${name}` }),
    ]));
    return card;
  }));
}

function orderItems(order) {
  const items = valueOf(order, ['items', 'order_items'], []);
  return Array.isArray(items) ? items : [];
}

function renderOrders(payload) {
  const orders = Array.isArray(payload) ? payload : valueOf(payload, ['data', 'orders'], []);
  const target = $('#orders-list');
  if (!Array.isArray(orders) || !orders.length) {
    target.replaceChildren(listMessage('Belum ada riwayat pesanan.'));
    return;
  }
  target.replaceChildren(...orders.map((order) => {
    const created = valueOf(order, ['created_at', 'createdAt'], '');
    const date = created ? new Date(created) : null;
    const validDate = date && !Number.isNaN(date.valueOf()) ? date.toLocaleString('id-ID') : '';
    const total = Number(valueOf(order, ['total_amount', 'total'], 0));
    const card = node('article', { className: 'profile-card' });
    card.append(node('div', { className: 'profile-card__row' }, [
      node('strong', { text: `Pesanan #${valueOf(order, ['id'], '—')}` }),
      node('strong', { text: currency.format(Number.isFinite(total) ? total : 0) }),
    ]));
    card.append(node('small', { text: `${valueOf(order, ['status'], 'Diproses')}${validDate ? ` · ${validDate}` : ''}` }));
    const items = orderItems(order);
    if (items.length) {
      card.append(node('div', { className: 'order-items' }, items.map((item) => node('span', {
        text: `${productName(item)} × ${valueOf(item, ['quantity'], 1)} — ${currency.format(productPrice(item))}`,
      }))));
    }
    return card;
  }));
}

async function loadProfileData() {
  $('#my-products-list').replaceChildren(listMessage('Memuat produk…'));
  $('#orders-list').replaceChildren(listMessage('Memuat pesanan…'));
  const results = await Promise.allSettled([api('/api/my-products'), api('/api/orders')]);
  if (results[0].status === 'fulfilled') renderMyProducts(results[0].value);
  else $('#my-products-list').replaceChildren(listMessage(results[0].reason?.message ?? 'Produk gagal dimuat.'));
  if (results[1].status === 'fulfilled') renderOrders(results[1].value);
  else $('#orders-list').replaceChildren(listMessage(results[1].reason?.message ?? 'Pesanan gagal dimuat.'));
  const unauthorized = results.find((result) => result.status === 'rejected' && result.reason?.status === 401);
  if (unauthorized) handleProtectedError(unauthorized.reason);
}

function openProfile(trigger) {
  if (!requireAuthentication(trigger)) return;
  $('#profile-name').textContent = String(valueOf(state.user, ['name'], 'Cosplayer'));
  $('#profile-email').textContent = String(valueOf(state.user, ['email'], ''));
  openLayer(elements.profileDrawer, trigger);
  loadProfileData();
}

async function logout(trigger) {
  const control = trigger;
  control.disabled = true;
  try {
    const response = await api('/api/auth/logout', { method: 'POST' });
    if (response?.csrf_token) {
      csrfToken = response.csrf_token;
      $('meta[name="csrf-token"]').content = csrfToken;
    }
    state.user = null;
    state.favorites.clear();
    state.favoritesOnly = false;
    updateAuthUi();
    renderProducts();
    closeLayer(false);
    showToast('Berhasil keluar.');
  } catch (error) {
    if (error.status === 401) {
      state.user = null;
      updateAuthUi();
      closeLayer(false);
    }
    showToast(error.message);
  } finally {
    control.disabled = false;
  }
}

async function checkout(trigger) {
  if (!state.cart.size || !requireAuthentication(trigger)) return;
  trigger.disabled = true;
  try {
    const response = await api('/api/checkout', {
      method: 'POST',
      body: { items: [...state.cart.keys()].map((id) => ({ id: Number(id) || id, quantity: 1 })) },
    });
    state.cart.clear();
    renderCart();
    closeLayer(false);
    showToast(`Checkout berhasil${valueOf(response, ['order_id', 'orderId'], '') ? `! Pesanan #${valueOf(response, ['order_id', 'orderId'])}` : '.'}`);
    await fetchProducts();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function submitProduct(form) {
  const errorBox = $('#product-form-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const submit = $('button[type="submit"]', form);
  submit.disabled = true;
  const data = Object.fromEntries(new FormData(form).entries());
  data.price = Number(data.price);
  data.stock = Number(data.stock);
  if (state.editingProductId) data.is_active = form.elements.is_active.checked;
  if (!state.editingProductId) Object.keys(data).forEach((key) => { if (data[key] === '') delete data[key]; });
  try {
    const editing = state.editingProductId;
    const savedProduct = await api(editing ? `/api/products/${encodeURIComponent(editing)}` : '/api/products', { method: editing ? 'PATCH' : 'POST', body: data });
    if (editing) reconcileCartProduct(savedProduct, editing);
    form.reset();
    closeLayer(false);
    showToast(editing ? 'Produk berhasil diperbarui.' : 'Produk berhasil ditambahkan.');
    state.editingProductId = null;
    await fetchProducts();
    window.setTimeout(() => openProfile(visibleAccountTrigger()), 120);
  } catch (error) {
    if (error.status === 401) {
      handleProtectedError(error, submit);
    } else {
      errorBox.textContent = error.message;
      errorBox.hidden = false;
      if (error.status === 419) showToast(error.message);
    }
  } finally {
    submit.disabled = false;
  }
}

function openProductForm(product = null, trigger = document.activeElement) {
  const form = $('#add-product-form');
  state.editingProductId = product ? productId(product) : null;
  form.reset();
  $('#product-form-error').hidden = true;
  $('#product-form-kicker').textContent = product ? 'Kelola listing' : 'Mulai berjualan';
  $('#add-product-title').textContent = product ? 'Edit Produk' : 'Tambah Produk';
  $('#product-submit-button').textContent = product ? 'Simpan perubahan' : 'Simpan produk';
  $('#product-active-field').hidden = !product;

  if (product) {
    ['name', 'price', 'stock', 'category', 'series', 'type', 'size', 'city', 'image'].forEach((field) => {
      form.elements[field].value = valueOf(product, [field], '');
    });
    form.elements.is_active.checked = Boolean(valueOf(product, ['is_active'], true));
  }
  openLayer(elements.addProductDrawer, trigger);
}

async function updateProductStatus(id, active, trigger) {
  trigger.disabled = true;
  try {
    const product = await api(`/api/products/${encodeURIComponent(id)}`, { method: 'PATCH', body: { is_active: active } });
    reconcileCartProduct(product, id);
    showToast(active ? 'Produk diaktifkan.' : 'Produk dinonaktifkan.');
    await Promise.all([loadProfileData(), fetchProducts()]);
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function deleteProduct(id, trigger) {
  const product = state.ownedProducts.get(String(id));
  if (!product || !window.confirm(`Hapus ${productName(product)}? Riwayat pesanan yang sudah ada tetap tersimpan.`)) return;
  trigger.disabled = true;
  try {
    await api(`/api/products/${encodeURIComponent(id)}`, { method: 'DELETE' });
    reconcileCartProduct(null, id);
    showToast('Produk berhasil dihapus.');
    await Promise.all([loadProfileData(), fetchProducts()]);
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

$('#search-form').addEventListener('submit', (event) => { event.preventDefault(); submitSearch($('#search-input').value); });
$('#mobile-search-form').addEventListener('submit', (event) => { event.preventDefault(); submitSearch($('#mobile-search-input').value); });
$('#search-input').addEventListener('search', (event) => { if (!event.target.value) submitSearch(''); });
$('#mobile-search-input').addEventListener('search', (event) => { if (!event.target.value) submitSearch(''); });
$('#sort-select').addEventListener('change', (event) => { state.sort = event.target.value; state.visible = 8; fetchProducts({ scroll: true }); });
$$('[data-filter]').forEach((control) => control.addEventListener('click', () => setFilter(control.dataset.filter)));
$$('[data-filter-trigger]').forEach((control) => control.addEventListener('click', () => setFilter(control.dataset.filterTrigger)));
$('#reset-search').addEventListener('click', () => { state.query = ''; state.filter = 'Semua'; submitSearch(''); });
elements.loadMore.addEventListener('click', () => { state.visible += 4; renderProducts(); });

elements.productGrid.addEventListener('click', (event) => {
  const favorite = event.target.closest('[data-favorite]');
  if (favorite) { event.preventDefault(); event.stopPropagation(); toggleFavorite(favorite.dataset.favorite, favorite); return; }
  const add = event.target.closest('[data-add]');
  if (add) { event.preventDefault(); event.stopPropagation(); addToCart(add.dataset.add); return; }
  const open = event.target.closest('[data-open-product]');
  if (open) openProduct(open.dataset.openProduct, open);
});

$('#modal-content').addEventListener('click', (event) => {
  const add = event.target.closest('[data-modal-add]');
  if (!add) return;
  addToCart(add.dataset.modalAdd);
  closeLayer(false);
  window.setTimeout(() => openLayer(elements.cartDrawer, add), 120);
});

$('#cart-items').addEventListener('click', (event) => {
  const remove = event.target.closest('[data-remove]');
  if (!remove) return;
  state.cart.delete(String(remove.dataset.remove));
  renderCart();
});

$('#cart-button').addEventListener('click', (event) => openLayer(elements.cartDrawer, event.currentTarget));
$('#mobile-cart-button').addEventListener('click', (event) => openLayer(elements.cartDrawer, event.currentTarget));
$('#shop-now').addEventListener('click', () => { closeLayer(); $('#produk')?.scrollIntoView({ behavior: 'smooth' }); });
$('#checkout-button').addEventListener('click', (event) => checkout(event.currentTarget));
$$('[data-close-layer]').forEach((control) => control.addEventListener('click', () => closeLayer()));
elements.overlay.addEventListener('click', () => closeLayer());

$$('.auth-action').forEach((control) => control.addEventListener('click', (event) => { setAuthMode(control.dataset.authMode); openLayer(elements.authModal, event.currentTarget); }));
$$('.profile-action').forEach((control) => control.addEventListener('click', (event) => openProfile(event.currentTarget)));
$('.mobile-account-action').addEventListener('click', (event) => state.user ? openProfile(event.currentTarget) : (setAuthMode('login'), openLayer(elements.authModal, event.currentTarget)));
$$('[data-switch-auth]').forEach((control) => control.addEventListener('click', () => setAuthMode(control.dataset.switchAuth)));
$('#auth-form').addEventListener('submit', (event) => { event.preventDefault(); submitAuth(event.currentTarget); });
$('#logout-button').addEventListener('click', (event) => logout(event.currentTarget));
$('#refresh-profile').addEventListener('click', loadProfileData);
$('#add-product-button').addEventListener('click', (event) => {
  const trigger = visibleAccountTrigger() ?? event.currentTarget;
  closeLayer(false);
  window.setTimeout(() => openProductForm(null, trigger), 120);
});
$('.seller-action').addEventListener('click', (event) => state.user ? openProductForm(null, event.currentTarget) : requireAuthentication(event.currentTarget));
$('#add-product-form').addEventListener('submit', (event) => { event.preventDefault(); submitProduct(event.currentTarget); });

$('#my-products-list').addEventListener('click', (event) => {
  const edit = event.target.closest('[data-edit-product]');
  if (edit) {
    const product = state.ownedProducts.get(String(edit.dataset.editProduct));
    if (!product) return;
    const trigger = visibleAccountTrigger() ?? edit;
    closeLayer(false);
    window.setTimeout(() => openProductForm(product, trigger), 120);
    return;
  }
  const toggle = event.target.closest('[data-toggle-product]');
  if (toggle) {
    updateProductStatus(toggle.dataset.toggleProduct, toggle.dataset.nextActive === 'true', toggle);
    return;
  }
  const remove = event.target.closest('[data-delete-product]');
  if (remove) deleteProduct(remove.dataset.deleteProduct, remove);
});

$('#mobile-favorites-button').addEventListener('click', (event) => {
  if (!requireAuthentication(event.currentTarget)) return;
  state.favoritesOnly = !state.favoritesOnly;
  event.currentTarget.classList.toggle('active', state.favoritesOnly);
  event.currentTarget.setAttribute('aria-pressed', String(state.favoritesOnly));
  renderProducts();
  $('#produk')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
});

document.addEventListener('keydown', (event) => {
  if (!state.activeLayer) return;
  if (event.key === 'Escape') { event.preventDefault(); closeLayer(); return; }
  if (event.key !== 'Tab') return;
  const focusable = focusableElements(state.activeLayer);
  if (!focusable.length) { event.preventDefault(); return; }
  const first = focusable[0];
  const last = focusable[focusable.length - 1];
  if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
  else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
});

setAuthMode('login');
renderCart();
Promise.allSettled([fetchProducts(), refreshSession()]);
