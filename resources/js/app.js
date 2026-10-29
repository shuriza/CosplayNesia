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
  rentalBlockDrawer: $('#rental-block-drawer'),
  notificationDrawer: $('#notification-drawer'),
  toast: $('#toast'),
};

const state = {
  products: [],
  query: '',
  filter: 'Semua',
  sort: 'popular',
  discovery: { type: '', size: '', city: '', condition: '', min_price: '', max_price: '', min_rating: '', start_date: '', end_date: '', quantity: '1' },
  savedSearches: [],
  lastAppliedFilters: null,
  nextProductCursor: null,
  hasMoreProducts: false,
  cart: new Map(),
  rentalDates: new Map(),
  cartRevision: 0,
  cartSync: Promise.resolve(),
  addresses: [],
  checkoutKey: null,
  favorites: new Set(),
  favoritesOnly: false,
  user: null,
  authMode: 'login',
  activeLayer: null,
  returnFocus: null,
  productRequest: null,
  ownedProducts: new Map(),
  ownedProductsCursor: null,
  hasMoreOwnedProducts: false,
  reviewFeed: { productId: null, entries: [], cursor: null, hasMore: false, summary: {} },
  incomingFulfillments: [],
  incomingFulfillmentsCursor: null,
  hasMoreIncomingFulfillments: false,
  sellerReviews: [],
  sellerReviewsCursor: null,
  hasMoreSellerReviews: false,
  sellerReviewsUnanswered: false,
  notifications: [],
  notificationsCursor: null,
  hasMoreNotifications: false,
  notificationsUnreadOnly: false,
  unreadNotifications: 0,
  threads: new Map(),
  orders: [],
  ordersCursor: null,
  hasMoreOrders: false,
  editingProductId: null,
  variantProductId: null,
  editingVariantId: null,
  store: null,
  pendingStores: [],
  pendingProducts: [],
  rentalCalendar: {
    productId: null,
    product: null,
    startDate: '',
    endDate: '',
    days: [],
    blocks: [],
    nextCursor: null,
    hasMore: false,
    loading: false,
    loadingMore: false,
    request: null,
    loadMoreRequest: null,
    version: 0,
  },
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

function productVariants(product) {
  return Array.isArray(product?.variants) ? product.variants : [];
}

function defaultProductVariant(product) {
  const variants = productVariants(product);
  return variants.find((variant) => variant.is_default) ?? variants[0] ?? null;
}

function selectedProductVariant(product) {
  const variants = productVariants(product);
  return variants.find((variant) => String(variant.id) === String(product?.selected_variant_id)) ?? defaultProductVariant(product);
}

function variantOptionLabel(variant) {
  return [variant.label, variant.size, variant.color, variant.sku, `Stok ${variant.stock}`].filter(Boolean).join(' · ');
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

function productRatingLabel(product) {
  const rating = Number(valueOf(product, ['rating'], NaN));
  const count = Number(valueOf(product, ['review_count'], 0));
  if (!Number.isFinite(rating) || count < 1) return 'Baru';
  return `${rating.toFixed(1)} (${count})`;
}

function jakartaCalendarDate(offset = 0) {
  const parts = new Intl.DateTimeFormat('en-GB', {
    timeZone: 'Asia/Jakarta',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(new Date());
  const values = Object.fromEntries(parts.map(({ type, value }) => [type, value]));
  const calendar = new Date(Date.UTC(Number(values.year), Number(values.month) - 1, Number(values.day) + offset));
  const pad = (value) => String(value).padStart(2, '0');
  return String(calendar.getUTCFullYear()) + '-' + pad(calendar.getUTCMonth() + 1) + '-' + pad(calendar.getUTCDate());
}

function invalidateCheckoutKey() {
  state.checkoutKey = null;
}

function rentalDateIsAfterToday(value) {
  return typeof value === 'string' && value > jakartaCalendarDate();
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
  if (layer === elements.rentalBlockDrawer) resetRentalCalendarState({ resetForms: true });
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
    invalidateCheckoutKey();
    state.user = null;
    state.cart.clear();
    state.rentalDates.clear();
    $('#checkout-handoff-form')?.reset();
    state.favorites.clear();
    resetRentalCalendarState({ resetForms: true });
    updateAuthUi();
    closeLayer(false);
    requireAuthentication(trigger);
    return;
  }
  showToast(error.message ?? 'Terjadi kesalahan.');
}

function productQuery(cursor = null) {
  const params = new URLSearchParams();
  if (state.query) params.set('q', state.query);
  if (!['Semua', 'Terbaru', 'Terlaris'].includes(state.filter)) params.set('category', state.filter);
  const effectiveSort = state.filter === 'Terbaru' ? 'newest' : state.filter === 'Terlaris' ? 'popular' : state.sort;
  params.set('sort', effectiveSort);
  params.set('per_page', '8');
  if (state.favoritesOnly) params.set('favorites', '1');
  // Only send what the shopper actually set: an empty filter must stay absent so the catalog
  // endpoint keeps its default behaviour instead of matching empty strings.
  Object.entries(state.discovery).forEach(([key, value]) => {
    const normalized = String(value ?? '').trim();
    if (normalized !== '' && !(key === 'quantity' && normalized === '1' && !state.discovery.start_date && !state.discovery.end_date)) {
      params.set(key, normalized);
    }
  });
  if (cursor) params.set('cursor', cursor);
  return params.toString();
}

async function fetchProducts({ scroll = false, append = false } = {}) {
  if (append && (!state.hasMoreProducts || !state.nextProductCursor)) return;
  state.productRequest?.abort();
  state.productRequest = new AbortController();
  setBusy(elements.productGrid, true);
  elements.loadMore.disabled = true;
  if (!append) {
    state.nextProductCursor = null;
    state.hasMoreProducts = false;
    elements.resultCount.textContent = 'Memuat produk…';
    elements.productGrid.replaceChildren(node('p', { className: 'loading-message', text: 'Menyiapkan koleksi untukmu…' }));
    elements.emptyState.hidden = true;
    elements.loadMore.hidden = true;
  }

  try {
    const payload = await api(`/api/products?${productQuery(append ? state.nextProductCursor : null)}`, { signal: state.productRequest.signal });
    const page = productList(payload);
    if (!append) state.favorites.clear();
    page.forEach((product) => {
      const id = productId(product);
      if (valueOf(product, ['is_favorite'], false)) state.favorites.add(id);
      else state.favorites.delete(id);
    });
    if (append) {
      const products = new Map(state.products.map((product) => [productId(product), product]));
      page.forEach((product) => products.set(productId(product), product));
      state.products = [...products.values()];
    } else {
      state.products = page;
    }
    const pagination = valueOf(payload, ['pagination'], {});
    state.nextProductCursor = valueOf(pagination, ['next_cursor'], null);
    state.hasMoreProducts = Boolean(valueOf(pagination, ['has_more'], false));
    renderProducts();
    if (scroll) $('#produk')?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  } catch (error) {
    if (error?.name === 'AbortError') return;
    if (!append) {
      state.products = [];
      elements.productGrid.replaceChildren(node('p', { className: 'loading-message inline-error', text: error.message }));
      elements.resultCount.textContent = 'Produk gagal dimuat';
    }
    showToast(error.message);
  } finally {
    setBusy(elements.productGrid, false);
    elements.loadMore.disabled = false;
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
  meta.append(node('span', { text: `★ ${productRatingLabel(product)}` }));
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
  elements.productGrid.replaceChildren(...filtered.map(productCard));
  const noun = state.favoritesOnly ? 'favorit' : 'produk';
  elements.resultCount.textContent = state.hasMoreProducts
    ? `${filtered.length} ${noun} dimuat`
    : `${filtered.length} ${noun}`;
  elements.emptyState.hidden = filtered.length > 0;
  elements.productGrid.hidden = filtered.length === 0;
  elements.loadMore.hidden = filtered.length === 0 || !state.hasMoreProducts;
}

function setFilter(filter) {
  state.filter = filter;
  state.favoritesOnly = false;
  $$('[data-filter]').forEach((control) => control.classList.toggle('active', control.dataset.filter === filter));
  fetchProducts({ scroll: true });
}

function submitSearch(value) {
  state.query = String(value).trim();
  state.favoritesOnly = false;
  $('#search-input').value = state.query;
  $('#mobile-search-input').value = state.query;
  fetchProducts({ scroll: true });
}

function findProduct(id) {
  return state.products.find((product) => productId(product) === String(id))
    ?? state.cart.get(String(id))
    ?? state.ownedProducts.get(String(id));
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
  const storeSlug = product?.store && typeof product.store === 'object'
    ? String(valueOf(product.store, ['slug'], ''))
    : '';
  if (storeSlug) info.append(button('Lihat toko', 'text-link', { 'data-open-store': storeSlug }));
  info.append(node('div', { className: 'modal-product__price' }, [
    node('strong', { text: currency.format(productPrice(product)) }),
    node('span', { text: valueOf(product, ['type'], 'Sewa') === 'Sewa' ? ' / hari' : '' }),
  ]));
  if (valueOf(product, ['type'], 'Sewa') === 'Sewa') {
    info.append(node('p', { className: 'pricing-terms', text: `Deposit ${currency.format(valueOf(product, ['rental_deposit'], 0))} · Durasi ${valueOf(product, ['rental_min_days'], 1)}–${valueOf(product, ['rental_max_days'], 30)} hari` }));
  }
  const variants = productVariants(product);
  const initialVariant = selectedProductVariant(product);
  const stock = Number(initialVariant?.stock ?? 0);
  const stockCopy = node('p', { className: 'stock-copy', text: stock > 0 ? `Stok tersedia: ${stock}` : 'Stok habis' });
  info.append(stockCopy);
  let variantSelect = null;
  if (variants.length) {
    variantSelect = node('select', { attrs: { id: 'modal-product-variant', 'aria-label': 'Pilih variasi produk' } });
    variants.forEach((variant) => {
      const option = node('option', { text: variantOptionLabel(variant), attrs: { value: variant.id, disabled: Number(variant.stock) < 1 } });
      option.selected = variant.id === initialVariant?.id;
      variantSelect.append(option);
    });
    info.append(node('label', { className: 'variant-picker', text: 'Variasi' }, [variantSelect]));
  }
  info.append(node('p', { className: 'product-description', text: valueOf(product, ['description'], 'Kostum terawat dan siap dipakai untuk event berikutnya. Checkout demo mencatat pesanan dan memperbarui stok tanpa memproses pembayaran.') }));
  info.append(node('div', { className: 'modal-meta' }, [
    node('span', {}, [node('strong', { text: valueOf(product, ['size'], 'All size') }), node('br'), document.createTextNode('Ukuran')]),
    node('span', {}, [node('strong', { text: `★ ${productRatingLabel(product)}` }), node('br'), document.createTextNode('Rating terverifikasi')]),
    node('span', {}, [node('strong', { text: valueOf(product, ['type'], 'Sewa') }), node('br'), document.createTextNode('Tipe')]),
  ]));
  const attributes = valueOf(product, ['category_attributes'], {});
  const includes = valueOf(product, ['costume_includes'], []);
  const measurements = valueOf(product, ['measurements'], {});
  const detailLines = [
    attributes.character ? `Karakter: ${attributes.character}` : null,
    attributes.material ? `Material: ${attributes.material}` : null,
    Array.isArray(includes) && includes.length ? `Kelengkapan: ${includes.join(', ')}` : null,
    Object.keys(measurements).length ? `Ukuran detail: ${Object.entries(measurements).filter(([, value]) => value).map(([key, value]) => `${key} ${value} cm`).join(', ')}` : null,
    product.care_instructions ? `Perawatan: ${product.care_instructions}` : null,
  ].filter(Boolean);
  if (detailLines.length) info.append(node('ul', { className: 'listing-detail-list' }, detailLines.map((line) => node('li', { text: line }))));
  const add = button(stock === 0 ? 'Stok habis' : 'Tambah ke keranjang', 'button button--primary button--full', { 'data-modal-add': id });
  add.disabled = stock === 0;
  variantSelect?.addEventListener('change', () => {
    const selected = variants.find((variant) => String(variant.id) === variantSelect.value);
    const available = Number(selected?.stock ?? 0);
    stockCopy.textContent = available > 0 ? `Stok tersedia: ${available}` : 'Stok habis';
    add.textContent = available > 0 ? 'Tambah ke keranjang' : 'Stok habis';
    add.disabled = available < 1;
  });
  info.append(add);
  layout.append(info);
  const reviews = node('section', { className: 'review-feed', attrs: { id: 'review-feed', 'aria-label': 'Ulasan pembeli terverifikasi', 'aria-busy': 'true' } }, [
    listMessage('Memuat ulasan…'),
  ]);
  content.replaceChildren(layout, reviews);
  openLayer(elements.productModal, trigger);
  loadProductReviews(id);
}

async function openStore(slug, trigger) {
  const content = $('#modal-content');
  content.replaceChildren(listMessage('Memuat toko…'));
  openLayer(elements.productModal, trigger);
  try {
    const payload = await api('/api/stores/' + encodeURIComponent(slug));
    const store = payload.store ?? {};
    const section = node('section', { className: 'storefront' });
    section.append(node('span', { className: 'section-kicker', text: 'Toko terverifikasi' }));
    section.append(node('h2', { text: String(store.name ?? 'Toko'), attrs: { id: 'modal-product-title' } }));
    section.append(node('p', { text: `${store.city ?? 'Online'}${store.operating_hours ? ` · ${store.operating_hours}` : ''}` }));
    if (store.description) section.append(node('p', { className: 'product-description', text: store.description }));
    if (store.policy) section.append(node('p', {}, [node('strong', { text: 'Kebijakan toko: ' }), document.createTextNode(store.policy)]));
    const products = Array.isArray(payload.products) ? payload.products : [];
    section.append(node('div', { className: 'product-grid storefront__products' }, products.map(productCard)));
    content.replaceChildren(section);
  } catch (error) {
    content.replaceChildren(listMessage(error.message));
  }
}

function reviewDateLabel(value) {
  if (!value) return '';
  const date = new Date(value);
  return Number.isNaN(date.valueOf())
    ? ''
    : date.toLocaleDateString('id-ID', { day: 'numeric', month: 'short', year: 'numeric' });
}

function reviewSummary(summary) {
  const count = Number(valueOf(summary, ['review_count'], 0));
  const rating = Number(valueOf(summary, ['rating'], NaN));
  const heading = node('div', { className: 'review-feed__summary' }, [
    node('strong', { text: count && Number.isFinite(rating) ? `★ ${rating.toFixed(1)}` : '★ —' }),
    node('span', { text: count === 1 ? '1 ulasan terverifikasi' : `${count} ulasan terverifikasi` }),
  ]);
  const distribution = valueOf(summary, ['distribution'], {});
  const bars = node('ul', { className: 'review-distribution' });
  [5, 4, 3, 2, 1].forEach((star) => {
    const total = Number(valueOf(distribution, [String(star)], 0)) || 0;
    const share = count > 0 ? Math.round((total / count) * 100) : 0;
    bars.append(node('li', {}, [
      node('span', { className: 'review-distribution__star', text: `${star}★` }),
      node('span', { className: 'review-distribution__track' }, [
        node('span', { className: 'review-distribution__fill', attrs: { style: `width:${share}%` } }),
      ]),
      node('span', { className: 'review-distribution__count', text: String(total) }),
    ]));
  });

  return node('div', { className: 'review-feed__head' }, [heading, count > 0 ? bars : null]);
}

function renderReviewFeed() {
  const container = $('#review-feed');
  if (!container) return;
  const { entries, hasMore } = state.reviewFeed;
  const children = [
    node('h3', { text: 'Ulasan pembeli terverifikasi' }),
    reviewSummary(state.reviewFeed.summary),
  ];

  if (!entries.length) {
    children.push(listMessage('Belum ada ulasan untuk produk ini.'));
  } else {
    children.push(node('ul', { className: 'review-list' }, entries.map((entry) => node('li', { className: 'review-list__item' }, [
      node('div', { className: 'review-list__header' }, [
        node('strong', { text: valueOf(entry, ['reviewer_label'], 'Cosplayer') }),
        node('span', { className: 'review-list__rating', text: `★ ${valueOf(entry, ['rating'], '—')}` }),
      ]),
      node('small', { text: reviewDateLabel(valueOf(entry, ['created_at'], '')) }),
      valueOf(entry, ['body'], '') ? node('p', { text: valueOf(entry, ['body']) }) : null,
      valueOf(entry, ['seller_reply'], '') ? node('div', { className: 'review-list__reply' }, [
        node('strong', { text: 'Balasan penjual' }),
        node('p', { text: valueOf(entry, ['seller_reply']) }),
      ]) : null,
    ]))));
  }

  if (hasMore) {
    children.push(button('Muat ulasan lainnya', 'button button--outline review-feed__more', { 'data-load-reviews': state.reviewFeed.productId }));
  }

  container.replaceChildren(...children.filter(Boolean));
  container.setAttribute('aria-busy', 'false');
}

async function loadProductReviews(id, { append = false } = {}) {
  const key = String(id);
  if (append && (!state.reviewFeed.hasMore || !state.reviewFeed.cursor || state.reviewFeed.productId !== key)) return;
  if (!append) state.reviewFeed = { productId: key, entries: [], cursor: null, hasMore: false, summary: {} };
  const trigger = $('[data-load-reviews]');
  if (trigger) trigger.disabled = true;
  try {
    const params = new URLSearchParams();
    if (append) params.set('cursor', state.reviewFeed.cursor);
    const payload = await api('/api/products/' + encodeURIComponent(key) + '/reviews?' + params.toString());
    if (state.reviewFeed.productId !== key) return;
    const page = valueOf(payload, ['data'], []);
    const pagination = valueOf(payload, ['pagination'], {});
    state.reviewFeed.entries = append ? [...state.reviewFeed.entries, ...page] : page;
    state.reviewFeed.summary = valueOf(payload, ['summary'], {});
    state.reviewFeed.cursor = valueOf(pagination, ['next_cursor'], null);
    state.reviewFeed.hasMore = Boolean(valueOf(pagination, ['has_more'], false));
    renderReviewFeed();
  } catch {
    const container = $('#review-feed');
    if (container && state.reviewFeed.productId === key) {
      container.replaceChildren(listMessage('Ulasan tidak dapat dimuat saat ini.'));
      container.setAttribute('aria-busy', 'false');
    }
  } finally {
    if (trigger) trigger.disabled = false;
  }
}

function addToCart(id, variantId = null) {
  const product = findProduct(id);
  if (!product) return;
  const key = productId(product);
  const variant = productVariants(product).find((entry) => String(entry.id) === String(variantId)) ?? defaultProductVariant(product);
  if (!variant || Number(variant.stock) < 1) {
    showToast('Variasi yang dipilih tidak tersedia.');
    return;
  }
  if (state.cart.has(key)) {
    showToast(`${productName(product)} sudah ada di keranjang.`);
    return;
  }
  state.cart.set(key, { ...product, selected_variant_id: variant.id, cart_updated_at: new Date().toISOString() });
  invalidateCheckoutKey();
  if (valueOf(product, ['type'], 'Sewa') === 'Sewa' && !state.rentalDates.has(key)) {
    state.rentalDates.set(key, {
      start_date: jakartaCalendarDate(1),
      end_date: jakartaCalendarDate(3),
    });
  }
  renderCart();
  persistCart();
  showToast(`${productName(product)} ditambahkan ke keranjang.`);
}

function cartEntries() {
  return [...state.cart.values()].map((product) => {
    const id = productId(product);
    const dates = state.rentalDates.get(id) ?? {};
    return {
      product_id: Number(id),
      product_variant_id: selectedProductVariant(product)?.id,
      quantity: 1,
      rental_start_date: dates.start_date ?? null,
      rental_end_date: dates.end_date ?? null,
      client_updated_at: product.cart_updated_at ?? new Date().toISOString(),
    };
  }).filter((entry) => entry.product_id && entry.product_variant_id);
}

function applyCartItems(items) {
  state.cart.clear();
  state.rentalDates.clear();
  for (const item of Array.isArray(items) ? items : []) {
    const product = item.product;
    if (!product) continue;
    const key = String(item.product_id);
    state.cart.set(key, {
      ...product,
      selected_variant_id: item.product_variant_id,
      cart_updated_at: item.client_updated_at ?? new Date().toISOString(),
    });
    if (item.rental_start_date || item.rental_end_date) {
      state.rentalDates.set(key, {
        start_date: item.rental_start_date ?? '',
        end_date: item.rental_end_date ?? '',
      });
    }
  }
  invalidateCheckoutKey();
  renderCart();
}

function guestCartEntries() {
  try {
    const value = JSON.parse(localStorage.getItem('cosplaynesia.guest-cart') ?? '[]');
    return Array.isArray(value) ? value : [];
  } catch {
    return [];
  }
}

async function restoreCart({ mergeGuest = false } = {}) {
  const guestItems = guestCartEntries();
  if (state.user) {
    const payload = mergeGuest && guestItems.length
      ? await api('/api/cart', { method: 'PUT', body: { items: guestItems } })
      : await api('/api/cart');
    if (mergeGuest) localStorage.removeItem('cosplaynesia.guest-cart');
    applyCartItems(payload.items);
    return;
  }
  if (!guestItems.length) { applyCartItems([]); return; }
  const payload = await api('/api/cart/resolve', { method: 'POST', body: { items: guestItems } });
  applyCartItems(payload.items);
  localStorage.setItem('cosplaynesia.guest-cart', JSON.stringify(cartEntries()));
}

function persistCart() {
  state.cartRevision += 1;
  const revision = state.cartRevision;
  const items = cartEntries();
  if (!state.user) {
    localStorage.setItem('cosplaynesia.guest-cart', JSON.stringify(items));
    return;
  }
  state.cartSync = state.cartSync.then(async () => {
    const payload = await api('/api/cart', { method: 'PUT', body: { replace: true, items } });
    if (revision === state.cartRevision) applyCartItems(payload.items);
  }).catch((error) => showToast(error.message));
}

function renderAddressBook() {
  const fieldset = $('#checkout-handoff-form fieldset');
  if (!fieldset) return;
  let row = $('#checkout-address-book');
  if (!row) {
    row = node('div', { attrs: { id: 'checkout-address-book' } }, [
      node('label', { text: 'Alamat tersimpan' }, [node('select', { attrs: { id: 'checkout-address-select', name: 'address_id' } })]),
      button('Simpan alamat ini', 'text-link', { id: 'save-checkout-address' }),
      button('Hapus alamat', 'text-link', { id: 'delete-checkout-address' }),
    ]);
    fieldset.prepend(row);
  }
  const select = $('#checkout-address-select');
  select.replaceChildren(node('option', { text: 'Gunakan alamat baru', attrs: { value: '' } }), ...state.addresses.map((address) => node('option', {
    text: `${address.label}${address.is_default ? ' · Utama' : ''}`,
    attrs: { value: address.id },
  })));
  const preferred = state.addresses.find((address) => address.is_default) ?? state.addresses[0];
  if (preferred) { select.value = String(preferred.id); fillCheckoutAddress(preferred); }
  $('#delete-checkout-address').hidden = !preferred;
}

function fillCheckoutAddress(address) {
  if (!address) return;
  const form = $('#checkout-handoff-form');
  const values = { recipient_name: address.recipient_name, recipient_phone: address.recipient_phone, address_line1: address.address_line1, address_line2: address.address_line2 ?? '', city: address.city, province: address.province, postal_code: address.postal_code };
  for (const [name, value] of Object.entries(values)) if (form.elements[name]) form.elements[name].value = value;
}

async function loadAddresses() {
  if (!state.user) { state.addresses = []; renderAddressBook(); return; }
  const payload = await api('/api/addresses');
  state.addresses = Array.isArray(payload.data) ? payload.data : [];
  renderAddressBook();
}

async function saveCheckoutAddress(trigger) {
  const form = $('#checkout-handoff-form');
  if (!form.reportValidity()) return;
  trigger.disabled = true;
  try {
    const fields = new FormData(form);
    const label = window.prompt('Nama alamat:', `Alamat ${state.addresses.length + 1}`)?.trim();
    if (!label) return;
    await api('/api/addresses', { method: 'POST', body: { label, recipient_name: fields.get('recipient_name'), recipient_phone: fields.get('recipient_phone'), address_line1: fields.get('address_line1'), address_line2: fields.get('address_line2') || null, city: fields.get('city'), province: fields.get('province'), postal_code: fields.get('postal_code'), is_default: !state.addresses.length } });
    await loadAddresses();
    showToast('Alamat tersimpan.');
  } catch (error) { showToast(error.message); } finally { trigger.disabled = false; }
}

function renderCart() {
  const products = [...state.cart.values()];
  const count = products.length;
  $('#cart-count').textContent = String(count);
  $('#mobile-cart-count').textContent = String(count);
  $('#cart-button').setAttribute('aria-label', `Buka keranjang, ${count} produk`);
  const items = products.map((product) => {
    const id = productId(product);
    const variants = productVariants(product);
    const selectedVariant = selectedProductVariant(product);
    const item = node('article', { className: 'cart-item' });
    item.append(imageOrFallback(valueOf(product, ['image', 'image_url']), productName(product)));
    item.append(node('div', {}, [
      node('h3', { text: productName(product) }),
      node('p', { text: currency.format(productPrice(product)) }),
      node('small', { text: `${valueOf(product, ['type'], 'Sewa')} · ${selectedVariant?.label ?? valueOf(product, ['size'], 'All size')} · Jumlah 1` }),
    ]));
    if (variants.length > 1) {
      const select = node('select', { attrs: { 'data-cart-variant': id, 'aria-label': `Variasi ${productName(product)}` } });
      variants.forEach((variant) => {
        const option = node('option', { text: variantOptionLabel(variant), attrs: { value: variant.id, disabled: Number(variant.stock) < 1 } });
        option.selected = variant.id === selectedVariant?.id;
        select.append(option);
      });
      item.append(select);
    }
    item.append(button('×', 'cart-item__remove', { 'data-remove': id, 'aria-label': `Hapus ${productName(product)} dari keranjang` }));
    if (valueOf(product, ['type'], 'Sewa') === 'Sewa') item.append(rentalDateFields(id));
    return item;
  });
  $('#cart-items').replaceChildren(...items);
  $('#cart-empty').hidden = count > 0;
  $('#cart-summary').hidden = count === 0;
  $('#cart-subtotal').textContent = currency.format(products.reduce((sum, product) => sum + productPrice(product), 0));
  renderPickupOptions(products);
}

function renderPickupOptions(products) {
  const fieldset = $('#checkout-handoff-form fieldset');
  if (!fieldset) return;
  $('#checkout-pickup-options')?.remove();
  const sellers = new Map();
  for (const product of products) {
    const sellerId = Number(valueOf(product, ['seller_id'], 0));
    const store = valueOf(product, ['store'], null);
    if (sellerId && store?.pickup_enabled) sellers.set(sellerId, store);
  }
  if (!sellers.size) return;
  const group = node('fieldset', { attrs: { id: 'checkout-pickup-options' } }, [node('legend', { text: 'Metode penyerahan per toko' })]);
  for (const [sellerId, store] of sellers) {
    group.append(node('label', {}, [
      node('input', { attrs: { type: 'checkbox', name: 'pickup_seller_ids[]', value: sellerId } }),
      ' Pickup langsung dari ' + store.name,
    ]));
  }
  fieldset.before(group);
}

function rentalDateFields(id) {
  const dates = state.rentalDates.get(id) ?? {};
  return node('div', { className: 'cart-rental-fields' }, [
    node('label', { text: 'Mulai' }, [node('input', { attrs: { type: 'date', value: dates.start_date ?? '', required: true, 'data-rental-date': id, 'data-date-kind': 'start_date' } })]),
    node('label', { text: 'Selesai' }, [node('input', { attrs: { type: 'date', value: dates.end_date ?? '', required: true, 'data-rental-date': id, 'data-date-kind': 'end_date' } })]),
    node('small', { text: 'Ketersediaan akan dicek saat tanggal berubah.', attrs: { 'data-availability': id } }),
  ]);
}

async function refreshRentalAvailability(id) {
  const dates = state.rentalDates.get(id);
  const status = document.querySelector('[data-availability="' + id + '"]');
  if (!dates?.start_date || !dates?.end_date || !status) return;
  try {
    const query = new URLSearchParams(dates);
    const variant = selectedProductVariant(state.cart.get(String(id)));
    if (!variant) return;
    query.set('variant_id', variant.id);
    const result = await api('/api/products/' + encodeURIComponent(id) + '/availability?' + query.toString());
    status.textContent = result.available
      ? 'Tanggal tersedia.'
      : 'Tanggal tidak tersedia untuk jumlah ini.';
    status.classList.toggle('is-unavailable', !result.available);
  } catch (error) {
    status.textContent = error?.status === 404 ? 'Listing tidak tersedia.' : 'Ketersediaan belum dapat dicek.';
    status.classList.add('is-unavailable');
  }
}

function reconcileCartProduct(product, id = productId(product)) {
  const key = String(id);
  if (!state.cart.has(key)) return;
  if (product && valueOf(product, ['status'], 'draft') === 'published') {
    const currentVariant = selectedProductVariant(state.cart.get(key));
    const stillAvailable = productVariants(product).find((variant) => variant.id === currentVariant?.id);
    state.cart.set(key, { ...product, selected_variant_id: (stillAvailable ?? defaultProductVariant(product))?.id });
  } else {
    state.cart.delete(key);
    state.rentalDates.delete(key);
  }
  invalidateCheckoutKey();
  renderCart();
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
  $('#registration-consent').hidden = !registering;
  $$('input', $('#registration-consent')).forEach((input) => { input.required = registering; });
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
  const previousUserId = state.user?.id ?? null;
  try {
    const nextUser = normalizedUser(await api('/api/me'));
    if (String(state.user?.id ?? '') !== String(nextUser?.id ?? '')) invalidateCheckoutKey();
    state.user = nextUser;
  } catch (error) {
    if (!(error instanceof ApiError) || error.status !== 401) showToast(error.message);
    invalidateCheckoutKey();
    state.user = null;
  }
  updateAuthUi();
  prefillCheckoutHandoff();
  await Promise.all([restoreCart({ mergeGuest: Boolean(state.user && !previousUserId) }), loadAddresses()]);
  if (state.user) refreshUnreadCount();
}

async function refreshUnreadCount() {
  try {
    const payload = await api('/api/notifications?per_page=1');
    state.unreadNotifications = Number(valueOf(payload, ['unread_count'], 0)) || 0;
    updateNotificationBadge();
  } catch {
    // A failed badge refresh must never block the catalog; the drawer reports errors itself.
  }
}

function prefillCheckoutHandoff() {
  if (!state.user) return;
  const name = $('#checkout-recipient-name');
  const email = $('#checkout-recipient-email');
  if (name && !name.value) name.value = String(valueOf(state.user, ['name'], ''));
  if (email && !email.value) email.value = String(valueOf(state.user, ['email'], ''));
}

function updateAuthUi() {
  const authenticated = Boolean(state.user);
  $$('.auth-action').forEach((control) => { control.hidden = authenticated; });
  $$('.profile-action').forEach((control) => { control.hidden = !authenticated; });
  $('#notification-button').hidden = !authenticated;
  if (!authenticated) {
    state.notifications = [];
    state.notificationsCursor = null;
    state.hasMoreNotifications = false;
    state.unreadNotifications = 0;
    resetRentalCalendarState({ resetForms: true });
  }
  updateNotificationBadge();
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
      payload.accept_terms = Boolean(form.elements.accept_terms?.checked);
      payload.accept_privacy = Boolean(form.elements.accept_privacy?.checked);
      payload.accept_rental_policy = Boolean(form.elements.accept_rental_policy?.checked);
    }
    const response = await api(`/api/auth/${state.authMode}`, { method: 'POST', body: payload });
    invalidateCheckoutKey();
    state.user = normalizedUser(response);
    if (!state.user) state.user = normalizedUser(await api('/api/me'));
    updateAuthUi();
    prefillCheckoutHandoff();
    refreshUnreadCount();
    await Promise.all([restoreCart({ mergeGuest: true }), loadAddresses(), fetchProducts()]);
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

function renderTimeline(payload) {
  const entries = Array.isArray(payload) ? payload : valueOf(payload, ['data', 'timeline'], []);
  if (!Array.isArray(entries) || !entries.length) {
    return node('p', { className: 'list-message', text: 'Belum ada riwayat aktivitas.' });
  }

  const timeline = node('ol', { className: 'activity-timeline' });
  entries.forEach((entry) => {
    const metadata = valueOf(entry, ['metadata'], null);
    const meta = metadata && typeof metadata === 'object' ? metadata : {};
    const summary = [];
    const fromStatus = valueOf(entry, ['from_status'], '');
    const toStatus = valueOf(entry, ['to_status'], '');
    if (fromStatus || toStatus) {
      summary.push(fromStatus ? fromStatus + ' → ' + (toStatus || fromStatus) : toStatus);
    }
    const metaBits = [];
    if (typeof meta.item_count === 'number') metaBits.push(meta.item_count + ' item');
    if (typeof meta.sale_count === 'number') metaBits.push(meta.sale_count + ' beli');
    if (typeof meta.rental_count === 'number') metaBits.push(meta.rental_count + ' sewa');
    if (typeof meta.total_amount === 'number') metaBits.push(currency.format(meta.total_amount));
    if (metaBits.length) summary.push(metaBits.join(' · '));
    const when = valueOf(entry, ['occurred_at'], '');
    const date = when ? new Date(when) : null;
    const timestamp = date && !Number.isNaN(date.valueOf()) ? date.toLocaleString('id-ID') : '';

    timeline.append(node('li', { className: 'activity-timeline__item' }, [
      node('div', { className: 'activity-timeline__header' }, [
        node('strong', { text: valueOf(entry, ['event_label'], valueOf(entry, ['event_type'], 'Aktivitas')) }),
        node('span', { className: 'activity-timeline__actor', text: valueOf(entry, ['actor_label'], 'Sistem') }),
      ]),
      node('small', { text: timestamp || 'Waktu tidak tersedia' }),
      summary.length ? node('p', { text: summary.join(' · ') }) : node('p', { text: ' ' }),
    ]));
  });

  return node('section', { className: 'activity-timeline-wrap', attrs: { 'aria-label': 'Riwayat aktivitas' } }, [
    node('h4', { text: 'Riwayat aktivitas' }),
    timeline,
  ]);
}

function renderMyProducts() {
  const products = [...state.ownedProducts.values()];
  const target = $('#my-products-list');
  $('#my-products-count').textContent = state.hasMoreOwnedProducts ? `${products.length} dimuat` : String(products.length);
  $('#load-more-my-products').hidden = !state.hasMoreOwnedProducts;
  if (!products.length) {
    target.replaceChildren(listMessage('Belum ada produk di tokomu.'));
    return;
  }
  target.replaceChildren(...products.map((product) => {
    const id = productId(product);
    const name = productName(product);
    const status = valueOf(product, ['status'], 'draft');
    const pendingReview = status === 'draft' && Boolean(valueOf(product, ['submitted_for_review_at'], null));
    const statusLabels = { draft: 'Draft', published: 'Terbit', paused: 'Dijeda', rejected: 'Ditolak', archived: 'Diarsipkan', taken_down: 'Diturunkan operator' };
    const active = status === 'published';
    // A taken-down listing is operator-frozen exactly like an archived one: the seller may read it
    // and its history, but no edit action is offered because the API would refuse it anyway.
    const locked = status === 'archived' || status === 'taken_down';
    const card = node('article', { className: 'profile-card' });
    card.append(node('div', { className: 'profile-card__row profile-card__heading' }, [
      node('strong', { text: productName(product) }),
      node('span', { className: `listing-status ${active ? '' : 'listing-status--inactive'}`.trim(), text: pendingReview ? 'Menunggu review' : (statusLabels[status] ?? status) }),
    ]));
    card.append(node('small', { text: `${valueOf(product, ['category'], 'Cosplay')} · ${currency.format(productPrice(product))} · Stok ${valueOf(product, ['stock'], 0)}` }));
    if (valueOf(product, ['rejection_reason'], null)) card.append(node('small', { text: `Alasan penolakan: ${product.rejection_reason}` }));
    if (status === 'taken_down' && valueOf(product, ['takedown_reason'], null)) card.append(node('small', { text: `Alasan penurunan oleh operator: ${product.takedown_reason}` }));
    const images = Array.isArray(product.images) ? product.images : [];
    if (images.length) {
      const gallery = node('div', { className: 'listing-gallery', attrs: { 'aria-label': `Galeri ${name}` } });
      images.forEach((image, position) => {
        const item = node('div', { className: 'listing-gallery__item' });
        item.append(imageOrFallback(image.url, `${name}, gambar ${position + 1}`, 'listing-gallery__image'));
        if (!locked) {
          item.append(node('div', { className: 'listing-gallery__actions' }, [
            button('Naik', 'text-link', { 'data-image-move': image.id, 'data-product-id': id, 'data-direction': '-1', disabled: position === 0 }),
            button('Turun', 'text-link', { 'data-image-move': image.id, 'data-product-id': id, 'data-direction': '1', disabled: position === images.length - 1 }),
            button('Hapus gambar', 'danger-action', { 'data-image-delete': image.id, 'data-product-id': id }),
          ]));
        }
        gallery.append(item);
      });
      card.append(gallery);
    }
    const variants = Array.isArray(product.variants) ? product.variants : [];
    if (variants.length) {
      const conditionLabels = { new: 'Baru', like_new: 'Seperti baru', good: 'Baik', fair: 'Cukup' };
      const variantList = node('div', { className: 'listing-variants', attrs: { 'aria-label': `Variasi ${name}` } });
      variants.forEach((variant) => {
        const detail = [variant.size, variant.color, conditionLabels[variant.condition]].filter(Boolean).join(' · ');
        const row = node('div', { className: 'listing-variant' }, [
          node('div', {}, [
            node('strong', { text: variant.label }),
            node('small', { text: `${variant.sku}${detail ? ` · ${detail}` : ''} · Stok ${variant.stock}` }),
          ]),
        ]);
        if (!locked) {
          row.append(node('div', { className: 'listing-variant__actions' }, [
            button('Edit variasi', 'text-link', { 'data-edit-variant': variant.id, 'data-product-id': id }),
            button('Hapus variasi', 'danger-action', { 'data-delete-variant': variant.id, 'data-product-id': id, disabled: variants.length === 1 }),
          ]));
        }
        variantList.append(row);
      });
      card.append(variantList);
    }
    const readiness = valueOf(product, ['readiness'], null);
    if (readiness?.items) {
      card.append(node('div', { className: `listing-readiness ${readiness.ready ? 'listing-readiness--ready' : ''}`.trim() }, [
        node('strong', { text: readiness.ready ? 'Siap diajukan' : 'Checklist sebelum terbit' }),
        node('ul', {}, readiness.items.map((item) => node('li', { className: item.complete ? 'is-complete' : '', text: `${item.complete ? '✓' : '○'} ${item.label}` }))),
      ]));
    }
    const actions = locked ? [] : [
      button('Edit', '', { 'data-edit-product': id, 'aria-label': `Edit ${name}` }),
      button('Tambah variasi', '', { 'data-add-variant': id, 'aria-label': `Tambah variasi ${name}` }),
      button('Preview', '', { 'data-preview-product': id, 'aria-label': `Preview ${name}` }),
    ];
    if ((status === 'draft' && !pendingReview) || status === 'paused') {
      actions.push(button(status === 'draft' ? 'Ajukan review' : 'Terbitkan', '', { 'data-toggle-product': id, 'data-next-status': 'published', 'aria-label': `Terbitkan ${name}` }));
    } else if (status === 'published') {
      actions.push(button('Jeda', '', { 'data-toggle-product': id, 'data-next-status': 'paused', 'aria-label': `Jeda ${name}` }));
    } else if (status === 'rejected') {
      actions.push(button('Kembalikan ke draft', '', { 'data-toggle-product': id, 'data-next-status': 'draft', 'aria-label': `Kembalikan ${name} ke draft` }));
    }
    if (isRentalProduct(product) && !locked) {
      actions.push(button('Jadwal sewa', '', { 'data-open-rental-calendar': id, 'aria-label': `Kelola jadwal sewa ${name}` }));
    }
    if (!locked) actions.push(button('Arsipkan', 'danger-action', { 'data-delete-product': id, 'aria-label': `Arsipkan ${name}` }));
    card.append(node('div', { className: 'profile-card__actions' }, actions));
    return card;
  }));
}

async function loadOwnedProducts({ append = false } = {}) {
  if (append && (!state.hasMoreOwnedProducts || !state.ownedProductsCursor)) return;
  const button = $('#load-more-my-products');
  button.disabled = true;
  try {
    const params = new URLSearchParams({ per_page: '5' });
    if (append) params.set('cursor', state.ownedProductsCursor);
    const payload = await api('/api/my-products?' + params.toString());
    const products = productList(payload);
    if (!append) state.ownedProducts.clear();
    products.forEach((product) => state.ownedProducts.set(productId(product), product));
    const pagination = valueOf(payload, ['pagination'], {});
    state.ownedProductsCursor = valueOf(pagination, ['next_cursor'], null);
    state.hasMoreOwnedProducts = Boolean(valueOf(pagination, ['has_more'], false));
    renderMyProducts();
  } finally {
    button.disabled = false;
  }
}

function isRentalProduct(product) {
  return String(valueOf(product, ['type', 'product_type'], '')).trim().toLowerCase() === 'sewa';
}

function rentalDateOffset(value, offset = 0) {
  const matched = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(value ?? ''));
  if (!matched) return '';
  const year = Number(matched[1]);
  const month = Number(matched[2]);
  const day = Number(matched[3]);
  const date = new Date(Date.UTC(year, month - 1, day));
  if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return '';
  date.setUTCDate(date.getUTCDate() + Number(offset));
  const pad = (part) => String(part).padStart(2, '0');
  return `${date.getUTCFullYear()}-${pad(date.getUTCMonth() + 1)}-${pad(date.getUTCDate())}`;
}

function rentalDateSpan(startDate, endDate) {
  const start = rentalDateOffset(startDate);
  const end = rentalDateOffset(endDate);
  if (!start || !end) return null;
  const [startYear, startMonth, startDay] = start.split('-').map(Number);
  const [endYear, endMonth, endDay] = end.split('-').map(Number);
  return Math.round((Date.UTC(endYear, endMonth - 1, endDay) - Date.UTC(startYear, startMonth - 1, startDay)) / 86400000);
}

function rentalDateLabel(value) {
  const normalized = rentalDateOffset(value);
  if (!normalized) return 'Tanggal tidak valid';
  return new Intl.DateTimeFormat('id-ID', {
    timeZone: 'UTC',
    weekday: 'short',
    day: 'numeric',
    month: 'short',
    year: 'numeric',
  }).format(new Date(`${normalized}T00:00:00Z`));
}

function rentalQuantity(value) {
  const quantity = Number(value);
  return Number.isFinite(quantity) ? String(quantity) : '—';
}

function rentalBlockApiPath(productId) {
  return `/api/products/${encodeURIComponent(String(productId))}/rental-blocks`;
}

function rentalCalendarMatches({ productId, startDate, endDate, version }) {
  const calendar = state.rentalCalendar;
  return Boolean(state.user)
    && calendar.version === version
    && calendar.productId === String(productId)
    && calendar.startDate === startDate
    && calendar.endDate === endDate;
}

function rentalCalendarRequestMatches({ productId, startDate, endDate, version, controller, append }) {
  const calendar = state.rentalCalendar;
  return rentalCalendarMatches({ productId, startDate, endDate, version })
    && (append ? calendar.loadMoreRequest === controller : calendar.request === controller);
}

function rentalBlockForms() {
  return [$('#rental-block-window-form'), $('#rental-block-form')].filter(Boolean);
}

function setRentalBlockError(target, message = '') {
  if (!target) return;
  target.textContent = String(message);
  target.hidden = !message;
}

function setRentalBlockSuccess(message = '') {
  const target = $('#rental-block-success');
  if (!target) return;
  target.textContent = String(message);
  target.hidden = !message;
}

function syncRentalBlockDateBounds(form) {
  if (!form) return;
  const start = form.elements.start_date;
  const end = form.elements.end_date;
  if (!(start instanceof HTMLInputElement) || !(end instanceof HTMLInputElement)) return;

  const today = jakartaCalendarDate();
  const selectedStart = rentalDateOffset(start.value);
  let endMin = today;

  if (selectedStart && selectedStart >= today) {
    endMin = selectedStart;
  }

  const latest = rentalDateOffset(today, 29);
  start.min = today;
  end.min = endMin;
  start.max = latest;
  end.max = latest;
}

function rentalDateRangeError(startDate, endDate) {
  const today = jakartaCalendarDate();
  const span = rentalDateSpan(startDate, endDate);
  if (!rentalDateOffset(startDate) || !rentalDateOffset(endDate)) return 'Masukkan tanggal mulai dan selesai yang valid.';
  if (startDate < today || endDate < today) return 'Tanggal hanya dapat dipilih mulai hari ini (WIB).';
  if (span < 0) return 'Tanggal selesai harus sama dengan atau setelah tanggal mulai.';
  if (span > 29) return 'Periode maksimal 30 hari, termasuk tanggal mulai dan selesai.';
  return '';
}

function renderRentalCalendarHeader() {
  const calendar = state.rentalCalendar;
  const product = calendar.product ?? {};
  $('#rental-block-product-name').textContent = productName(product);
  const stock = Number(valueOf(product, ['stock'], NaN));
  $('#rental-block-product-stock').textContent = Number.isFinite(stock) ? `Stok total: ${stock}` : '';
}

function rentalCalendarTable(days) {
  const table = node('table', { className: 'rental-capacity-table' });
  table.append(node('caption', { text: 'Kapasitas sewa setiap hari pada periode yang dipilih.' }));
  const head = node('thead');
  head.append(node('tr', {}, [
    node('th', { text: 'Tanggal', attrs: { scope: 'col' } }),
    node('th', { text: 'Direservasi', attrs: { scope: 'col' } }),
    node('th', { text: 'Diblokir', attrs: { scope: 'col' } }),
    node('th', { text: 'Tersedia', attrs: { scope: 'col' } }),
  ]));
  table.append(head);
  const body = node('tbody');
  days.forEach((day) => {
    const date = String(valueOf(day, ['date'], ''));
    body.append(node('tr', {}, [
      node('th', { attrs: { scope: 'row' } }, [node('time', { text: rentalDateLabel(date), attrs: { datetime: date || null } })]),
      node('td', { text: rentalQuantity(valueOf(day, ['reserved_quantity'], 0)) }),
      node('td', { text: rentalQuantity(valueOf(day, ['blocked_quantity'], 0)) }),
      node('td', { className: 'rental-capacity-table__available', text: rentalQuantity(valueOf(day, ['available_quantity'], 0)) }),
    ]));
  });
  table.append(body);
  return node('div', { className: 'rental-capacity-table-wrap', attrs: { tabindex: '0', 'aria-label': 'Geser untuk melihat seluruh tabel kapasitas harian' } }, [table]);
}

function renderRentalCalendar() {
  const calendar = state.rentalCalendar;
  const target = $('#rental-block-calendar');
  const loading = $('#rental-block-loading');
  if (!target || !loading) return;

  target.setAttribute('aria-busy', String(calendar.loading));
  loading.textContent = calendar.loading ? 'Memuat…' : '';
  if (!calendar.days.length) {
    target.replaceChildren(listMessage(calendar.loading ? 'Memuat kapasitas harian…' : 'Tidak ada data kapasitas untuk periode ini.'));
    return;
  }
  target.replaceChildren(rentalCalendarTable(calendar.days));
}

function rentalBlockCard(block) {
  const id = String(valueOf(block, ['id'], ''));
  const startDate = String(valueOf(block, ['start_date'], ''));
  const endDate = String(valueOf(block, ['end_date'], ''));
  const quantity = rentalQuantity(valueOf(block, ['quantity'], 0));
  const range = startDate === endDate
    ? rentalDateLabel(startDate)
    : `${rentalDateLabel(startDate)} – ${rentalDateLabel(endDate)}`;
  const card = node('article', { className: 'rental-block-card' });
  card.append(node('div', { className: 'rental-block-card__heading' }, [
    node('strong', { text: range }),
    node('span', { className: 'rental-block-quantity', text: `${quantity} diblokir` }),
  ]));
  const reason = String(valueOf(block, ['reason'], '') ?? '').trim();
  if (reason) card.append(node('p', { className: 'rental-block-reason', text: reason }));
  card.append(node('div', { className: 'profile-card__actions rental-block-card__actions' }, [
    button('Batalkan blok', 'danger-action', {
      'data-cancel-rental-block': id,
      'data-rental-block-product': state.rentalCalendar.productId ?? '',
      'aria-label': `Batalkan blok ${range}`,
    }),
  ]));
  return card;
}

function renderRentalBlockList() {
  const calendar = state.rentalCalendar;
  const target = $('#rental-block-list');
  const more = $('#load-more-rental-blocks');
  const count = $('#rental-block-list-count');
  if (!target || !more || !count) return;

  target.setAttribute('aria-busy', String(calendar.loading || calendar.loadingMore));
  count.textContent = calendar.blocks.length
    ? (calendar.hasMore ? `${calendar.blocks.length} dimuat` : `${calendar.blocks.length} blok`)
    : '';
  more.hidden = !calendar.hasMore;
  more.disabled = calendar.loadingMore;
  more.textContent = calendar.loadingMore ? 'Memuat…' : 'Muat blok lainnya';
  if (!calendar.blocks.length) {
    target.replaceChildren(listMessage(calendar.loading ? 'Memuat blok aktif…' : 'Tidak ada blok aktif pada periode ini.'));
    return;
  }
  target.replaceChildren(...calendar.blocks.map(rentalBlockCard));
}

function clearRentalBlockPresentation() {
  $('#rental-block-product-name').textContent = 'Produk sewa';
  $('#rental-block-product-stock').textContent = '';
  $('#rental-block-calendar').replaceChildren();
  $('#rental-block-calendar').setAttribute('aria-busy', 'false');
  $('#rental-block-list').replaceChildren();
  $('#rental-block-list').setAttribute('aria-busy', 'false');
  $('#rental-block-list-count').textContent = '';
  $('#rental-block-loading').textContent = '';
  $('#load-more-rental-blocks').hidden = true;
  setRentalBlockError($('#rental-block-window-error'));
  setRentalBlockError($('#rental-block-form-error'));
  setRentalBlockError($('#rental-block-results-error'));
  setRentalBlockSuccess();
}

function resetRentalCalendarState({ resetForms = false, clearPresentation = true } = {}) {
  const calendar = state.rentalCalendar;
  calendar.version += 1;
  calendar.request?.abort();
  calendar.loadMoreRequest?.abort();
  Object.assign(calendar, {
    productId: null,
    product: null,
    startDate: '',
    endDate: '',
    days: [],
    blocks: [],
    nextCursor: null,
    hasMore: false,
    loading: false,
    loadingMore: false,
    request: null,
    loadMoreRequest: null,
  });
  if (resetForms) {
    rentalBlockForms().forEach((form) => {
      form.reset();
      const submit = $('button[type="submit"]', form);
      if (submit) submit.disabled = false;
      setBusy(form, false);
      syncRentalBlockDateBounds(form);
    });
  }
  if (clearPresentation) clearRentalBlockPresentation();
}

function setRentalCalendarWindow(startDate, endDate) {
  const calendar = state.rentalCalendar;
  calendar.version += 1;
  calendar.request?.abort();
  calendar.loadMoreRequest?.abort();
  Object.assign(calendar, {
    startDate,
    endDate,
    days: [],
    blocks: [],
    nextCursor: null,
    hasMore: false,
    loading: false,
    loadingMore: false,
    request: null,
    loadMoreRequest: null,
  });
}

function mergeRentalBlocks(currentBlocks, receivedBlocks) {
  const indexed = new Map(currentBlocks.map((block) => [String(valueOf(block, ['id'], '')), block]));
  receivedBlocks.forEach((block) => indexed.set(String(valueOf(block, ['id'], '')), block));
  return [...indexed.values()];
}

async function loadRentalCalendar({ append = false, resetBlocks = false } = {}) {
  const calendar = state.rentalCalendar;
  if (!calendar.productId || !calendar.startDate || !calendar.endDate || !state.user) return;
  if (append && (!calendar.hasMore || !calendar.nextCursor || calendar.loadingMore)) return;

  const productId = calendar.productId;
  const startDate = calendar.startDate;
  const endDate = calendar.endDate;
  const version = calendar.version;
  const controller = new AbortController();
  if (append) {
    calendar.loadMoreRequest?.abort();
    calendar.loadMoreRequest = controller;
    calendar.loadingMore = true;
  } else {
    calendar.request?.abort();
    calendar.loadMoreRequest?.abort();
    calendar.request = controller;
    calendar.loading = true;
    calendar.loadingMore = false;
    calendar.loadMoreRequest = null;
    if (resetBlocks) {
      calendar.blocks = [];
      calendar.nextCursor = null;
      calendar.hasMore = false;
    }
  }
  renderRentalCalendar();
  renderRentalBlockList();

  const params = new URLSearchParams({ start_date: startDate, end_date: endDate, per_page: '10' });
  if (append) params.set('cursor', calendar.nextCursor);
  try {
    const payload = await api(`${rentalBlockApiPath(productId)}?${params.toString()}`, { signal: controller.signal });
    if (!rentalCalendarRequestMatches({ productId, startDate, endDate, version, controller, append })) return;
    const responseProduct = valueOf(payload, ['product'], null);
    if (!responseProduct || String(valueOf(responseProduct, ['id'], '')) !== productId) {
      throw new ApiError('Data jadwal tidak cocok dengan produk yang dipilih.', 0, payload);
    }
    const days = Array.isArray(valueOf(payload, ['days'], [])) ? valueOf(payload, ['days'], []) : [];
    const blocks = Array.isArray(valueOf(payload, ['data'], [])) ? valueOf(payload, ['data'], []) : [];
    const pagination = valueOf(payload, ['pagination'], {});
    calendar.product = { ...(calendar.product ?? {}), ...responseProduct };
    calendar.days = days;
    calendar.blocks = append ? mergeRentalBlocks(calendar.blocks, blocks) : blocks;
    calendar.nextCursor = valueOf(pagination, ['next_cursor'], null);
    calendar.hasMore = Boolean(valueOf(pagination, ['has_more'], false));
    setRentalBlockError($('#rental-block-results-error'));
    renderRentalCalendarHeader();
  } catch (error) {
    if (error?.name === 'AbortError' || !rentalCalendarRequestMatches({ productId, startDate, endDate, version, controller, append })) return;
    if (error instanceof ApiError && error.status === 401) {
      handleProtectedError(error);
      return;
    }
    setRentalBlockError($('#rental-block-results-error'), error.message ?? 'Jadwal sewa tidak dapat dimuat.');
  } finally {
    if (!rentalCalendarRequestMatches({ productId, startDate, endDate, version, controller, append })) return;
    if (append) {
      calendar.loadingMore = false;
      calendar.loadMoreRequest = null;
    } else {
      calendar.loading = false;
      calendar.request = null;
    }
    renderRentalCalendar();
    renderRentalBlockList();
  }
}

function openRentalCalendar(product, trigger = document.activeElement) {
  if (!requireAuthentication(trigger) || !isRentalProduct(product)) return;
  resetRentalCalendarState({ clearPresentation: false });
  const calendar = state.rentalCalendar;
  const productIdValue = productId(product);
  const startDate = jakartaCalendarDate();
  const endDate = jakartaCalendarDate(13);
  Object.assign(calendar, {
    productId: productIdValue,
    product,
    startDate,
    endDate,
  });

  const windowForm = $('#rental-block-window-form');
  const blockForm = $('#rental-block-form');
  windowForm.reset();
  blockForm.reset();
  $('button[type="submit"]', windowForm).disabled = false;
  $('button[type="submit"]', blockForm).disabled = false;
  setBusy(windowForm, false);
  setBusy(blockForm, false);
  windowForm.elements.start_date.value = startDate;
  windowForm.elements.end_date.value = endDate;
  blockForm.elements.start_date.value = startDate;
  blockForm.elements.end_date.value = endDate;
  blockForm.elements.quantity.value = '1';
  rentalBlockForms().forEach(syncRentalBlockDateBounds);
  setRentalBlockError($('#rental-block-window-error'));
  setRentalBlockError($('#rental-block-form-error'));
  setRentalBlockError($('#rental-block-results-error'));
  setRentalBlockSuccess();
  renderRentalCalendarHeader();
  renderRentalCalendar();
  renderRentalBlockList();
  openLayer(elements.rentalBlockDrawer, trigger);
  loadRentalCalendar();
}

async function submitRentalCalendarWindow(form) {
  const calendar = state.rentalCalendar;
  const errorBox = $('#rental-block-window-error');
  setRentalBlockError(errorBox);
  if (!calendar.productId || !form.reportValidity()) return;
  const fields = new FormData(form);
  const startDate = String(fields.get('start_date') ?? '');
  const endDate = String(fields.get('end_date') ?? '');
  const rangeError = rentalDateRangeError(startDate, endDate);
  if (rangeError) {
    setRentalBlockError(errorBox, rangeError);
    return;
  }
  if (calendar.startDate === startDate && calendar.endDate === endDate) {
    const submit = $('button[type="submit"]', form);
    submit.disabled = true;
    setBusy(form, true);
    try {
      await loadRentalCalendar({ resetBlocks: true });
    } finally {
      if (calendar.productId) {
        submit.disabled = false;
        setBusy(form, false);
      }
    }
    return;
  }
  setRentalCalendarWindow(startDate, endDate);
  setRentalBlockError($('#rental-block-results-error'));
  setRentalBlockSuccess();
  renderRentalCalendar();
  renderRentalBlockList();
  const submit = $('button[type="submit"]', form);
  submit.disabled = true;
  setBusy(form, true);
  try {
    await loadRentalCalendar();
  } finally {
    if (calendar.productId && calendar.startDate === startDate && calendar.endDate === endDate) {
      submit.disabled = false;
      setBusy(form, false);
    }
  }
}

async function submitRentalBlock(form) {
  const calendar = state.rentalCalendar;
  const errorBox = $('#rental-block-form-error');
  setRentalBlockError(errorBox);
  setRentalBlockSuccess();
  if (!calendar.productId || !form.reportValidity()) return;
  const fields = new FormData(form);
  const startDate = String(fields.get('start_date') ?? '');
  const endDate = String(fields.get('end_date') ?? '');
  const quantity = Number(fields.get('quantity'));
  const rangeError = rentalDateRangeError(startDate, endDate);
  if (rangeError) {
    setRentalBlockError(errorBox, rangeError);
    return;
  }
  if (!Number.isInteger(quantity) || quantity < 1 || quantity > 10000) {
    setRentalBlockError(errorBox, 'Jumlah yang diblokir harus berupa bilangan bulat antara 1 dan 10.000.');
    return;
  }

  const productId = calendar.productId;
  const requestContext = {
    productId,
    startDate: calendar.startDate,
    endDate: calendar.endDate,
    version: calendar.version,
  };
  const submit = $('button[type="submit"]', form);
  submit.disabled = true;
  setBusy(form, true);
  try {
    const reason = String(fields.get('reason') ?? '').trim();
    await api(rentalBlockApiPath(productId), {
      method: 'POST',
      body: { start_date: startDate, end_date: endDate, quantity, reason: reason || null },
    });
    if (!rentalCalendarMatches(requestContext)) return;
    form.elements.reason.value = '';
    setRentalBlockSuccess('Blok tanggal berhasil disimpan.');
    await loadRentalCalendar({ resetBlocks: true });
  } catch (error) {
    if (!rentalCalendarMatches(requestContext)) return;
    if (error instanceof ApiError && error.status === 401) {
      handleProtectedError(error, submit);
      return;
    }
    const message = error?.status === 409
      ? `Kapasitas tidak mencukupi untuk blok ini. ${error.message ?? ''}`.trim()
      : (error.message ?? 'Blok tanggal tidak dapat disimpan.');
    setRentalBlockError(errorBox, message);
  } finally {
    if (rentalCalendarMatches(requestContext)) {
      submit.disabled = false;
      setBusy(form, false);
    }
  }
}

async function cancelRentalBlock(id, trigger) {
  const calendar = state.rentalCalendar;
  const productId = calendar.productId;
  const block = calendar.blocks.find((entry) => String(valueOf(entry, ['id'], '')) === String(id));
  if (!productId || !block) return;
  const startDate = String(valueOf(block, ['start_date'], ''));
  const endDate = String(valueOf(block, ['end_date'], ''));
  const range = startDate === endDate ? rentalDateLabel(startDate) : `${rentalDateLabel(startDate)} – ${rentalDateLabel(endDate)}`;
  if (!window.confirm(`Batalkan blok tanggal ${range}? Kapasitas akan tersedia kembali untuk pembeli.`)) return;

  const requestContext = {
    productId,
    startDate: calendar.startDate,
    endDate: calendar.endDate,
    version: calendar.version,
  };
  trigger.disabled = true;
  setRentalBlockError($('#rental-block-results-error'));
  setRentalBlockSuccess();
  try {
    await api(`${rentalBlockApiPath(productId)}/${encodeURIComponent(String(id))}`, { method: 'DELETE' });
    if (!rentalCalendarMatches(requestContext)) return;
    setRentalBlockSuccess('Blok tanggal dibatalkan.');
    await loadRentalCalendar({ resetBlocks: true });
  } catch (error) {
    if (!rentalCalendarMatches(requestContext)) return;
    if (error instanceof ApiError && error.status === 401) {
      handleProtectedError(error, trigger);
      return;
    }
    setRentalBlockError($('#rental-block-results-error'), error.message ?? 'Blok tanggal tidak dapat dibatalkan.');
  } finally {
    if (rentalCalendarMatches(requestContext)) trigger.disabled = false;
  }
}

function orderItems(order) {
  const items = valueOf(order, ['items', 'order_items'], []);
  return Array.isArray(items) ? items : [];
}

function renderOrders() {
  const orders = state.orders;
  const target = $('#orders-list');
  $('#orders-count').textContent = state.hasMoreOrders ? `${orders.length} dimuat` : String(orders.length);
  $('#load-more-orders').hidden = !state.hasMoreOrders;
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
    const orderStatus = valueOf(order, ['status'], 'Diproses');
    card.append(node('small', { text: fulfillmentStatusLabel(orderStatus) + (validDate ? ' · ' + validDate : '') }));
    const items = orderItems(order);
    if (items.length) {
      card.append(node('div', { className: 'order-items' }, items.map((item) => {
        const row = node('div', { className: 'order-item-review-row' });
        row.append(node('span', {
          text: productName(item) + ' × ' + valueOf(item, ['quantity'], 1) + ' — ' + currency.format(productPrice(item))
            + (valueOf(item, ['fulfillment_status'], '') ? ' · ' + fulfillmentStatusLabel(valueOf(item, ['fulfillment_status'])) : ''),
        }));
        const review = valueOf(item, ['review'], null);
        if (review) {
          row.append(node('small', { className: 'review-complete', text: `Dinilai ★ ${valueOf(review, ['rating'], '—')}` }));
          if (valueOf(review, ['body'], '')) {
            row.append(node('p', { className: 'review-complete__body', text: valueOf(review, ['body']) }));
          }
          if (valueOf(review, ['seller_reply'], '')) {
            row.append(node('div', { className: 'review-list__reply' }, [
              node('strong', { text: 'Balasan penjual' }),
              node('p', { text: valueOf(review, ['seller_reply']) }),
            ]));
          }
        } else if (valueOf(item, ['can_review'], false)) {
          const label = node('label', { className: 'review-field', text: 'Nilai produk' });
          const select = node('select', { attrs: { 'data-review-rating': valueOf(item, ['id'], ''), 'aria-label': `Rating untuk ${productName(item)}` } });
          [5, 4, 3, 2, 1].forEach((rating) => select.append(node('option', { text: `${rating} bintang`, attrs: { value: rating } })));
          label.append(select);
          const bodyLabel = node('label', { className: 'review-field review-field--body', text: 'Ulasan (opsional)' });
          bodyLabel.append(node('textarea', {
            attrs: {
              'data-review-body': valueOf(item, ['id'], ''),
              maxlength: 500,
              rows: 2,
              placeholder: 'Ceritakan pengalamanmu memakai kostum ini.',
              'aria-label': `Ulasan untuk ${productName(item)}`,
            },
          }));
          row.append(node('div', { className: 'review-action' }, [
            label,
            bodyLabel,
            button('Kirim penilaian', 'text-link', {
              'data-review-order': valueOf(order, ['id'], ''),
              'data-review-item': valueOf(item, ['id'], ''),
            }),
          ]));
        }
        return row;
      })));
    }
    card.append(button('Lihat detail penyerahan', 'text-link', {
      'data-order-detail': valueOf(order, ['id'], ''),
      'aria-label': 'Lihat detail penyerahan pesanan ' + valueOf(order, ['id'], ''),
    }));
    const threads = valueOf(order, ['fulfillments'], []);
    (Array.isArray(threads) ? threads : []).forEach((fulfillment) => {
      const unread = Number(valueOf(fulfillment, ['unread_messages'], 0)) || 0;
      card.append(button(
        unread > 0 ? `Percakapan ${valueOf(fulfillment, ['seller_name'], 'penjual')} (${unread} baru)` : `Percakapan ${valueOf(fulfillment, ['seller_name'], 'penjual')}`,
        unread > 0 ? 'text-link has-unread-messages' : 'text-link',
        { 'data-thread-open': valueOf(fulfillment, ['id'], '') },
      ));
    });
    items.filter((item) => valueOf(item, ['product_type'], '') === 'Sewa').forEach((item) => {
      const start = valueOf(item, ['rental_start_date'], '—');
      const end = valueOf(item, ['rental_end_date'], '—');
      const status = valueOf(item, ['rental_status'], '—');
      card.append(node('p', {
        className: 'rental-order-detail',
        text: 'Sewa: ' + start + ' sampai ' + end + ' · Status: ' + status,
      }));
      if (status === 'reserved' && rentalDateIsAfterToday(start)) {
        card.append(button('Batalkan sewa', 'text-link', {
          'data-cancel-order': valueOf(order, ['id'], ''),
          'data-cancel-item': valueOf(item, ['id'], ''),
          'aria-label': 'Batalkan sewa ' + productName(item),
        }));
      }
    });
    return card;
  }));
}

function handoffDetails(handoff, seller = false) {
  const detail = node('div', { className: 'handoff-detail' });
  const address = [valueOf(handoff, ['address_line1'], ''), valueOf(handoff, ['address_line2'], ''), valueOf(handoff, ['city'], ''), valueOf(handoff, ['province'], ''), valueOf(handoff, ['postal_code'], '')].filter(Boolean).join(', ');
  const values = [
    ['Penerima', valueOf(handoff, ['recipient_name'], 'Belum tersedia')],
    ['Telepon', valueOf(handoff, ['recipient_phone'], 'Belum tersedia')],
    ['Alamat', address || 'Belum tersedia'],
  ];
  if (!seller) values.splice(2, 0, ['Email', valueOf(handoff, ['recipient_email'], 'Belum tersedia')]);
  values.forEach(([label, value]) => detail.append(node('p', {}, [node('strong', { text: label }), node('span', { text: value })])));
  if (valueOf(handoff, ['handoff_note'], '')) detail.append(node('p', {}, [node('strong', { text: 'Catatan' }), node('span', { text: valueOf(handoff, ['handoff_note']) })]));
  return detail;
}

async function loadOrderDetail(id, trigger) {
  trigger.disabled = true;
  try {
    const detail = await api('/api/orders/' + encodeURIComponent(id));
    const card = trigger.closest('.profile-card');
    card?.querySelector('.handoff-detail')?.remove();
    card?.querySelector('.activity-timeline-wrap')?.remove();
    card?.append(handoffDetails(detail.handoff));
    card?.append(renderTimeline(detail.timeline));
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

function fulfillmentStatusLabel(status) {
  return {
    processing: 'Diproses',
    partially_fulfilled: 'Sebagian dipenuhi',
    fulfilled: 'Selesai',
    partially_cancelled: 'Sebagian dibatalkan',
    demo_confirmed: 'Dikonfirmasi demo',
    received: 'Menunggu konfirmasi',
    accepted: 'Diterima penjual',
    ready: 'Siap diserahkan',
    completed: 'Selesai',
    cancelled: 'Dibatalkan',
  }[status] ?? status;
}

function fulfillmentActionLabel(status) {
  return {
    accepted: 'Terima pesanan',
    ready: 'Tandai siap',
    completed: 'Tandai selesai',
    cancelled: 'Batalkan',
  }[status] ?? status;
}

function renderIncomingFulfillments() {
  const target = $('#incoming-orders-list');
  $('#incoming-orders-count').textContent = state.hasMoreIncomingFulfillments
    ? `${state.incomingFulfillments.length} dimuat`
    : String(state.incomingFulfillments.length);
  $('#load-more-incoming-orders').hidden = !state.hasMoreIncomingFulfillments;
  if (!state.incomingFulfillments.length) {
    target.replaceChildren(listMessage('Belum ada pesanan masuk.'));
    return;
  }
  target.replaceChildren(...state.incomingFulfillments.map((fulfillment) => {
    const card = node('article', { className: 'profile-card fulfillment-card' });
    const status = String(valueOf(fulfillment, ['status'], 'received'));
    card.append(node('div', { className: 'profile-card__row profile-card__heading' }, [
      node('strong', { text: 'Pesanan #' + valueOf(fulfillment, ['order_id'], '—') }),
      node('span', { className: 'fulfillment-status fulfillment-status--' + status, text: fulfillmentStatusLabel(status) }),
    ]));
    card.append(node('small', { text: 'Pembeli: ' + valueOf(fulfillment.buyer, ['name'], 'Pembeli') + ' · ' + currency.format(Number(valueOf(fulfillment, ['subtotal'], 0))) }));
    const items = Array.isArray(fulfillment.items) ? fulfillment.items : [];
    if (items.length) {
      card.append(node('div', { className: 'order-items' }, items.map((item) => {
        const rental = valueOf(item, ['rental_start_date'], null) && valueOf(item, ['rental_end_date'], null)
          ? ' · ' + valueOf(item, ['rental_start_date']) + '–' + valueOf(item, ['rental_end_date']) : '';
        return node('span', { text: valueOf(item, ['product_name'], 'Produk') + ' × ' + valueOf(item, ['quantity'], 1) + rental });
      })));
    }
    card.append(button('Lihat detail penyerahan', 'text-link', {
      'data-fulfillment-detail': valueOf(fulfillment, ['id'], ''),
      'aria-label': 'Lihat detail penyerahan pesanan ' + valueOf(fulfillment, ['order_id'], ''),
    }));
    const unreadMessages = Number(valueOf(fulfillment, ['unread_messages'], 0)) || 0;
    card.append(button(
      unreadMessages > 0 ? `Percakapan pembeli (${unreadMessages} baru)` : 'Percakapan pembeli',
      unreadMessages > 0 ? 'text-link has-unread-messages' : 'text-link',
      { 'data-thread-open': valueOf(fulfillment, ['id'], '') },
    ));
    const transitions = Array.isArray(fulfillment.available_transitions) ? fulfillment.available_transitions : [];
    if (transitions.length) {
      card.append(node('div', { className: 'profile-card__actions' }, transitions.map((nextStatus) => button(
        fulfillmentActionLabel(nextStatus), nextStatus === 'cancelled' ? 'danger-action' : '',
        { 'data-fulfillment-id': valueOf(fulfillment, ['id'], ''), 'data-next-fulfillment-status': nextStatus },
      ))));
    }
    return card;
  }));
}

function mergeHistory(current, next) {
  const records = new Map(current.map((record) => [String(valueOf(record, ['id'], '')), record]));
  next.forEach((record) => records.set(String(valueOf(record, ['id'], '')), record));
  return [...records.values()];
}

async function loadOrders({ append = false } = {}) {
  if (append && (!state.hasMoreOrders || !state.ordersCursor)) return;
  const button = $('#load-more-orders');
  button.disabled = true;
  try {
    const params = new URLSearchParams({ per_page: '5' });
    if (append) params.set('cursor', state.ordersCursor);
    const payload = await api('/api/orders?' + params.toString());
    const orders = valueOf(payload, ['data'], []);
    state.orders = append ? mergeHistory(state.orders, orders) : orders;
    const pagination = valueOf(payload, ['pagination'], {});
    state.ordersCursor = valueOf(pagination, ['next_cursor'], null);
    state.hasMoreOrders = Boolean(valueOf(pagination, ['has_more'], false));
    renderOrders();
  } finally {
    button.disabled = false;
  }
}

async function loadIncomingFulfillments({ append = false } = {}) {
  if (append && (!state.hasMoreIncomingFulfillments || !state.incomingFulfillmentsCursor)) return;
  const button = $('#load-more-incoming-orders');
  button.disabled = true;
  try {
    const params = new URLSearchParams({ per_page: '5' });
    if (append) params.set('cursor', state.incomingFulfillmentsCursor);
    const payload = await api('/api/seller/fulfillments?' + params.toString());
    const fulfillments = valueOf(payload, ['data'], []);
    state.incomingFulfillments = append ? mergeHistory(state.incomingFulfillments, fulfillments) : fulfillments;
    const pagination = valueOf(payload, ['pagination'], {});
    state.incomingFulfillmentsCursor = valueOf(pagination, ['next_cursor'], null);
    state.hasMoreIncomingFulfillments = Boolean(valueOf(pagination, ['has_more'], false));
    renderIncomingFulfillments();
  } finally {
    button.disabled = false;
  }
}

function renderSellerReviews() {
  const target = $('#seller-reviews-list');
  const reviews = state.sellerReviews;
  $('#seller-reviews-count').textContent = state.hasMoreSellerReviews ? `${reviews.length} dimuat` : String(reviews.length);
  $('#load-more-seller-reviews').hidden = !state.hasMoreSellerReviews;
  if (!reviews.length) {
    target.replaceChildren(listMessage(state.sellerReviewsUnanswered
      ? 'Semua ulasan sudah dibalas.'
      : 'Belum ada ulasan untuk produkmu.'));
    return;
  }

  target.replaceChildren(...reviews.map((review) => {
    const id = valueOf(review, ['id'], '');
    const reply = valueOf(review, ['seller_reply'], '');
    const card = node('article', { className: 'profile-card' });
    card.append(node('div', { className: 'profile-card__row profile-card__heading' }, [
      node('strong', { text: valueOf(review, ['product_name'], 'Produk') }),
      node('span', { className: 'fulfillment-status', text: `★ ${valueOf(review, ['rating'], '—')}` }),
    ]));
    card.append(node('small', {
      text: valueOf(review, ['reviewer_label'], 'Cosplayer') + ' · ' + reviewDateLabel(valueOf(review, ['created_at'], '')),
    }));
    if (valueOf(review, ['body'], '')) card.append(node('p', { className: 'seller-review__body', text: valueOf(review, ['body']) }));

    const label = node('label', { className: 'review-field review-field--body', text: reply ? 'Balasanmu' : 'Balas ulasan' });
    label.append(node('textarea', {
      attrs: {
        'data-reply-body': id,
        maxlength: 500,
        rows: 2,
        placeholder: 'Tanggapi ulasan pembeli secara sopan.',
        'aria-label': `Balasan untuk ulasan ${valueOf(review, ['product_name'], 'produk')}`,
      },
      text: reply,
    }));
    const actions = node('div', { className: 'review-action' }, [
      label,
      button(reply ? 'Perbarui balasan' : 'Kirim balasan', 'text-link', { 'data-reply-review': id }),
      reply ? button('Hapus balasan', 'text-link danger-action', { 'data-delete-reply': id }) : null,
    ]);
    card.append(actions);
    if (reply) {
      card.append(node('small', { className: 'seller-review__replied', text: 'Dibalas ' + reviewDateLabel(valueOf(review, ['seller_replied_at'], '')) }));
    }
    return card;
  }));
}

async function loadSellerReviews({ append = false } = {}) {
  if (append && (!state.hasMoreSellerReviews || !state.sellerReviewsCursor)) return;
  const button = $('#load-more-seller-reviews');
  button.disabled = true;
  try {
    const params = new URLSearchParams({ per_page: '5' });
    if (state.sellerReviewsUnanswered) params.set('unanswered', '1');
    if (append) params.set('cursor', state.sellerReviewsCursor);
    const payload = await api('/api/seller/reviews?' + params.toString());
    const reviews = valueOf(payload, ['data'], []);
    state.sellerReviews = append ? mergeHistory(state.sellerReviews, reviews) : reviews;
    const pagination = valueOf(payload, ['pagination'], {});
    state.sellerReviewsCursor = valueOf(pagination, ['next_cursor'], null);
    state.hasMoreSellerReviews = Boolean(valueOf(pagination, ['has_more'], false));
    renderSellerReviews();
  } finally {
    button.disabled = false;
  }
}

async function submitReviewReply(id, trigger, remove = false) {
  const card = trigger.closest('.profile-card');
  const field = card?.querySelector('[data-reply-body]');
  if (!remove && !field?.value.trim()) {
    showToast('Tulis balasan sebelum mengirim.');
    return;
  }
  trigger.disabled = true;
  try {
    await api('/api/seller/reviews/' + encodeURIComponent(id) + '/reply', remove
      ? { method: 'DELETE' }
      : { method: 'PATCH', body: { reply: field.value.trim() } });
    showToast(remove ? 'Balasan dihapus.' : 'Balasan tersimpan.');
    await loadSellerReviews();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

function notificationSummary(entry) {
  const payload = valueOf(entry, ['payload'], null);
  const meta = payload && typeof payload === 'object' ? payload : {};
  const parts = [];
  if (valueOf(meta, ['product_name'], '')) parts.push(valueOf(meta, ['product_name']));
  if (valueOf(meta, ['seller_name'], '')) parts.push(valueOf(meta, ['seller_name']));
  const rating = valueOf(meta, ['rating'], null);
  if (rating !== null) parts.push(`★ ${rating}`);
  const itemCount = valueOf(meta, ['item_count'], null);
  if (itemCount !== null) parts.push(`${itemCount} item`);
  const subtotal = valueOf(meta, ['subtotal'], null);
  if (subtotal !== null) parts.push(currency.format(Number(subtotal) || 0));
  const orderId = valueOf(entry, ['order_id'], null);
  if (orderId !== null) parts.push(`Pesanan #${orderId}`);
  return parts.join(' · ');
}

function updateNotificationBadge() {
  const count = Number(state.unreadNotifications) || 0;
  const badge = $('#notification-count');
  const trigger = $('#notification-button');
  badge.textContent = String(count);
  badge.hidden = count === 0;
  trigger.setAttribute('aria-label', `Buka notifikasi, ${count} belum dibaca`);
  trigger.classList.toggle('has-unread', count > 0);
}

function renderNotifications() {
  const target = $('#notification-list');
  const entries = state.notifications;
  $('#load-more-notifications').hidden = !state.hasMoreNotifications;
  updateNotificationBadge();
  if (!entries.length) {
    target.replaceChildren(listMessage(state.notificationsUnreadOnly
      ? 'Tidak ada notifikasi belum dibaca.'
      : 'Belum ada notifikasi.'));
    return;
  }

  target.replaceChildren(...entries.map((entry) => {
    const unread = Boolean(valueOf(entry, ['is_unread'], false));
    const card = node('article', { className: `profile-card notification-card${unread ? ' notification-card--unread' : ''}` });
    card.append(node('div', { className: 'profile-card__row profile-card__heading' }, [
      node('strong', { text: valueOf(entry, ['title'], 'Notifikasi') }),
      unread ? node('span', { className: 'notification-dot', text: 'Baru' }) : null,
    ]));
    const summary = notificationSummary(entry);
    if (summary) card.append(node('small', { text: summary }));
    card.append(node('small', { text: reviewDateLabel(valueOf(entry, ['created_at'], '')) }));
    if (unread) {
      card.append(node('div', { className: 'profile-card__actions' }, [
        button('Tandai dibaca', 'text-link', { 'data-read-notification': valueOf(entry, ['id'], '') }),
      ]));
    }
    return card;
  }));
}

async function loadNotifications({ append = false } = {}) {
  if (append && (!state.hasMoreNotifications || !state.notificationsCursor)) return;
  const button = $('#load-more-notifications');
  button.disabled = true;
  try {
    const params = new URLSearchParams({ per_page: '8' });
    if (state.notificationsUnreadOnly) params.set('unread', '1');
    if (append) params.set('cursor', state.notificationsCursor);
    const payload = await api('/api/notifications?' + params.toString());
    const entries = valueOf(payload, ['data'], []);
    state.notifications = append ? mergeHistory(state.notifications, entries) : entries;
    state.unreadNotifications = Number(valueOf(payload, ['unread_count'], 0)) || 0;
    const pagination = valueOf(payload, ['pagination'], {});
    state.notificationsCursor = valueOf(pagination, ['next_cursor'], null);
    state.hasMoreNotifications = Boolean(valueOf(pagination, ['has_more'], false));
    renderNotifications();
  } finally {
    button.disabled = false;
  }
}

async function markNotificationRead(id, trigger) {
  trigger.disabled = true;
  try {
    const response = await api('/api/notifications/' + encodeURIComponent(id) + '/read', { method: 'PATCH' });
    state.unreadNotifications = Number(valueOf(response, ['unread_count'], 0)) || 0;
    await loadNotifications();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function markAllNotificationsRead(trigger) {
  trigger.disabled = true;
  try {
    const response = await api('/api/notifications/read-all', { method: 'PATCH' });
    showToast(messageFrom(response, 'Semua notifikasi ditandai dibaca.'));
    state.unreadNotifications = 0;
    await loadNotifications();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

function openNotifications(trigger) {
  if (!requireAuthentication(trigger)) return;
  $('#notification-list').replaceChildren(listMessage('Memuat notifikasi…'));
  openLayer(elements.notificationDrawer, trigger);
  loadNotifications().catch((error) => handleProtectedError(error));
}

function messageTimestamp(value) {
  if (!value) return '';
  const date = new Date(value);
  return Number.isNaN(date.valueOf()) ? '' : date.toLocaleString('id-ID');
}

function renderThread(id, container) {
  const thread = state.threads.get(String(id));
  if (!thread || !container) return;
  const wrap = node('section', { className: 'message-thread', attrs: { 'aria-label': 'Percakapan pesanan' } });
  wrap.append(node('h4', { text: 'Percakapan' }));

  if (!thread.messages.length) {
    wrap.append(listMessage('Belum ada pesan. Mulai percakapan dengan pihak lain.'));
  } else {
    const list = node('ol', { className: 'message-list' });
    // The API returns newest-first for cursor stability; a conversation reads oldest-first.
    [...thread.messages].reverse().forEach((entry) => {
      const mine = Boolean(valueOf(entry, ['is_mine'], false));
      list.append(node('li', { className: `message-bubble${mine ? ' message-bubble--mine' : ''}` }, [
        node('div', { className: 'message-bubble__meta' }, [
          node('strong', { text: mine ? 'Anda' : valueOf(entry, ['sender_label'], 'Pihak lain') }),
          node('small', { text: messageTimestamp(valueOf(entry, ['created_at'], '')) }),
        ]),
        node('p', { text: valueOf(entry, ['body'], '') }),
      ]));
    });
    wrap.append(list);
  }

  if (thread.hasMore) {
    wrap.append(button('Muat pesan lama', 'text-link', { 'data-thread-more': id }));
  }

  if (thread.canSend) {
    const label = node('label', { className: 'review-field review-field--body', text: 'Tulis pesan' });
    label.append(node('textarea', {
      attrs: {
        'data-thread-body': id,
        maxlength: 1000,
        rows: 2,
        placeholder: 'Contoh: apakah bisa diambil langsung hari Sabtu?',
        'aria-label': 'Tulis pesan untuk pesanan ini',
      },
    }));
    wrap.append(node('div', { className: 'review-action' }, [
      label,
      button('Kirim pesan', 'text-link', { 'data-thread-send': id }),
    ]));
  } else {
    wrap.append(node('p', { className: 'list-message', text: 'Pesanan dibatalkan, percakapan ditutup.' }));
  }

  container.querySelector('.message-thread')?.remove();
  container.append(wrap);
}

async function loadThread(id, container, { append = false } = {}) {
  const key = String(id);
  const existing = state.threads.get(key);
  if (append && (!existing?.hasMore || !existing?.cursor)) return;
  const params = new URLSearchParams({ per_page: '10' });
  if (append) params.set('cursor', existing.cursor);
  const payload = await api('/api/fulfillments/' + encodeURIComponent(key) + '/messages?' + params.toString());
  const page = valueOf(payload, ['data'], []);
  const pagination = valueOf(payload, ['pagination'], {});
  state.threads.set(key, {
    messages: append ? [...(existing?.messages ?? []), ...page] : page,
    cursor: valueOf(pagination, ['next_cursor'], null),
    hasMore: Boolean(valueOf(pagination, ['has_more'], false)),
    canSend: Boolean(valueOf(payload, ['can_send'], false)),
    viewerRole: valueOf(payload, ['viewer_role'], ''),
  });
  renderThread(key, container);
}

async function openThread(id, trigger) {
  const card = trigger.closest('.profile-card');
  if (!card) return;
  trigger.disabled = true;
  try {
    await loadThread(id, card);
    // Opening a thread is an explicit read, so the badge must not linger.
    await api('/api/fulfillments/' + encodeURIComponent(id) + '/messages/read', { method: 'PATCH' });
    refreshUnreadCount();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function sendThreadMessage(id, trigger) {
  const card = trigger.closest('.profile-card');
  const field = card?.querySelector('[data-thread-body]');
  const body = field ? field.value.trim() : '';
  if (!body) {
    showToast('Tulis pesan sebelum mengirim.');
    return;
  }
  trigger.disabled = true;
  field.disabled = true;
  try {
    await api('/api/fulfillments/' + encodeURIComponent(id) + '/messages', { method: 'POST', body: { body } });
    showToast('Pesan terkirim.');
    await loadThread(id, card);
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
    if (field) field.disabled = false;
  }
}

async function loadFulfillmentDetail(id, trigger) {
  trigger.disabled = true;
  try {
    const detail = await api('/api/seller/fulfillments/' + encodeURIComponent(id));
    const card = trigger.closest('.profile-card');
    card?.querySelector('.handoff-detail')?.remove();
    card?.querySelector('.activity-timeline-wrap')?.remove();
    card?.append(handoffDetails(detail.handoff, true));
    card?.append(renderTimeline(detail.timeline));
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function loadProfileData() {
  $('#my-products-list').replaceChildren(listMessage('Memuat produk…'));
  $('#incoming-orders-list').replaceChildren(listMessage('Memuat pesanan masuk…'));
  $('#seller-reviews-list').replaceChildren(listMessage('Memuat ulasan…'));
  $('#orders-list').replaceChildren(listMessage('Memuat pesanan…'));
  loadSavedSearches();
  const results = await Promise.allSettled([loadOwnedProducts(), loadIncomingFulfillments(), loadSellerReviews(), loadOrders(), loadStore()]);
  if (results[0].status === 'rejected') $('#my-products-list').replaceChildren(listMessage(results[0].reason?.message ?? 'Produk gagal dimuat.'));
  if (results[1].status === 'rejected') $('#incoming-orders-list').replaceChildren(listMessage(results[1].reason?.message ?? 'Pesanan masuk gagal dimuat.'));
  if (results[2].status === 'rejected') $('#seller-reviews-list').replaceChildren(listMessage(results[2].reason?.message ?? 'Ulasan gagal dimuat.'));
  if (results[3].status === 'rejected') $('#orders-list').replaceChildren(listMessage(results[3].reason?.message ?? 'Pesanan gagal dimuat.'));
  if (results[4].status === 'rejected') $('#store-summary').replaceChildren(listMessage(results[4].reason?.message ?? 'Status toko gagal dimuat.'));
  const unauthorized = results.find((result) => result.status === 'rejected' && result.reason?.status === 401);
  if (unauthorized) handleProtectedError(unauthorized.reason);
  refreshUnreadCount();
}

function storeStatusLabel(status) {
  return { draft: 'Draft', submitted: 'Menunggu review', approved: 'Disetujui', rejected: 'Ditolak', suspended: 'Ditangguhkan' }[status] ?? 'Belum diajukan';
}

function renderStore() {
  const store = state.store;
  $('#store-status').textContent = storeStatusLabel(store?.status);
  const summary = $('#store-summary');
  summary.replaceChildren();
  const form = $('#store-onboarding-form');
  const editable = !store || ['draft', 'rejected'].includes(store.status);
  form.hidden = !editable;
  $('#add-product-button').hidden = store?.status !== 'approved';
  if (!store) {
    summary.append(listMessage('Ajukan verifikasi toko untuk mulai menerbitkan listing.'));
    return;
  }
  summary.append(node('article', { className: 'profile-card' }, [
    node('strong', { text: store.name }),
    node('span', { text: `${store.city} · ${storeStatusLabel(store.status)}` }),
    store.review_note ? node('small', { text: `Catatan review: ${store.review_note}` }) : null,
  ].filter(Boolean)));
  if (store.status === 'approved') {
    const logistics = node('form', { className: 'account-form', attrs: { id: 'store-logistics-form' } }, [
      node('strong', { text: 'Asal pengiriman & pickup' }),
      node('label', { text: 'Alamat asal' }, [node('input', { attrs: { name: 'origin_address_line1', required: true, value: store.origin_address_line1 ?? '' } })]),
      node('label', { text: 'Detail alamat (opsional)' }, [node('input', { attrs: { name: 'origin_address_line2', value: store.origin_address_line2 ?? '' } })]),
      node('label', { text: 'Provinsi' }, [node('input', { attrs: { name: 'origin_province', required: true, value: store.origin_province ?? '' } })]),
      node('label', { text: 'Kode pos' }, [node('input', { attrs: { name: 'origin_postal_code', required: true, pattern: '[0-9]{5}', value: store.origin_postal_code ?? '' } })]),
      node('label', {}, [node('input', { attrs: { type: 'checkbox', name: 'pickup_enabled', checked: Boolean(store.pickup_enabled) } }), ' Izinkan pickup langsung']),
      node('label', { text: 'Instruksi pickup' }, [node('textarea', { attrs: { name: 'pickup_instructions', maxlength: 500 }, text: store.pickup_instructions ?? '' })]),
      button('Simpan pengaturan pengiriman', 'button button--outline', { type: 'submit' }),
    ]);
    summary.append(logistics);
  }
  if (editable) {
    for (const field of ['name', 'description', 'city', 'operating_hours', 'policy']) {
      if (form.elements[field]) form.elements[field].value = String(store[field] ?? '');
    }
  }
}

async function loadStore() {
  const payload = await api('/api/me/store');
  state.store = payload?.store ?? null;
  renderStore();
  const canReviewStores = ['moderator', 'admin'].includes(String(valueOf(state.user, ['role'], 'buyer')));
  $('#admin-store-review-section').hidden = !canReviewStores;
  $('#admin-product-review-section').hidden = !canReviewStores;
  if (canReviewStores) await Promise.all([loadPendingStores(), loadPendingProducts()]);
}

async function submitStoreOnboarding(form) {
  const errorBox = $('.form-error', form);
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const submit = $('button[type="submit"]', form);
  submit.disabled = true;
  try {
    const response = await api('/api/me/store/onboarding', { method: 'POST', body: new FormData(form) });
    state.store = response.store;
    renderStore();
    showToast('Pengajuan toko berhasil dikirim.');
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  } finally { submit.disabled = false; }
}

async function loadPendingStores() {
  const payload = await api('/api/admin/stores');
  state.pendingStores = Array.isArray(payload?.data) ? payload.data : [];
  const list = $('#admin-store-review-list');
  list.replaceChildren(...state.pendingStores.map((store) => {
    const card = node('article', { className: 'profile-card' });
    card.append(node('strong', { text: store.name }), node('span', { text: `${store.legal_name} · ${store.city} · ${storeStatusLabel(store.status)}` }));
    if (store.status === 'submitted') {
      card.append(button('Setujui', 'text-link', { 'data-store-review': store.id, 'data-review-status': 'approved' }));
      card.append(button('Tolak', 'text-link', { 'data-store-review': store.id, 'data-review-status': 'rejected' }));
    } else if (store.status === 'approved') {
      card.append(button('Tangguhkan', 'danger-action', { 'data-store-review': store.id, 'data-review-status': 'suspended' }));
    }
    return card;
  }));
  if (!state.pendingStores.length) list.append(listMessage('Tidak ada toko yang perlu dikelola.'));
}

async function reviewStore(id, status, trigger) {
  const needsReason = ['rejected', 'suspended'].includes(status);
  const note = needsReason ? window.prompt(status === 'suspended' ? 'Alasan penangguhan:' : 'Alasan penolakan:')?.trim() : '';
  if (needsReason && !note) return;
  trigger.disabled = true;
  try {
    await api(`/api/admin/stores/${encodeURIComponent(id)}/review`, { method: 'PATCH', body: { status, note } });
    await loadPendingStores();
    showToast({ approved: 'Toko disetujui.', rejected: 'Toko ditolak.', suspended: 'Toko ditangguhkan.' }[status]);
  } catch (error) { handleProtectedError(error, trigger); }
  finally { trigger.disabled = false; }
}

async function loadPendingProducts() {
  const payload = await api('/api/admin/products');
  state.pendingProducts = Array.isArray(payload?.data) ? payload.data : [];
  const list = $('#admin-product-review-list');
  list.replaceChildren(...state.pendingProducts.map((product) => {
    const card = node('article', { className: 'profile-card' });
    card.append(
      node('strong', { text: product.name }),
      node('span', { text: `${product.store?.name ?? product.seller} · ${product.category} · ${currency.format(productPrice(product))}` }),
      button('Preview', 'text-link', { 'data-moderation-preview': product.id }),
      button('Setujui', 'text-link', { 'data-product-review': product.id, 'data-review-status': 'published' }),
      button('Tolak', 'danger-action', { 'data-product-review': product.id, 'data-review-status': 'rejected' }),
    );
    return card;
  }));
  if (!state.pendingProducts.length) list.append(listMessage('Tidak ada listing yang menunggu moderasi.'));
}

async function reviewProduct(id, status, trigger) {
  const reason = status === 'rejected' ? window.prompt('Alasan penolakan listing:')?.trim() : null;
  if (status === 'rejected' && !reason) return;
  trigger.disabled = true;
  try {
    await api(`/api/admin/products/${encodeURIComponent(id)}/review`, { method: 'PATCH', body: { status, reason } });
    await Promise.all([loadPendingProducts(), fetchProducts()]);
    showToast(status === 'published' ? 'Listing disetujui dan diterbitkan.' : 'Listing ditolak dengan alasan.');
  } catch (error) { handleProtectedError(error, trigger); }
  finally { trigger.disabled = false; }
}

async function updateFulfillmentStatus(id, status, trigger) {
  trigger.disabled = true;
  try {
    await api('/api/seller/fulfillments/' + encodeURIComponent(id) + '/status', { method: 'PATCH', body: { status } });
    showToast('Status pesanan diperbarui.');
    await loadProfileData();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function submitProductReview(orderId, itemId, trigger) {
  const action = trigger.closest('.review-action');
  const select = action?.querySelector('[data-review-rating]');
  if (!select) return;
  const bodyField = action.querySelector('[data-review-body]');
  const body = bodyField ? bodyField.value.trim() : '';
  trigger.disabled = true;
  select.disabled = true;
  if (bodyField) bodyField.disabled = true;
  try {
    await api('/api/orders/' + encodeURIComponent(orderId) + '/items/' + encodeURIComponent(itemId) + '/review', {
      method: 'POST',
      body: { rating: Number(select.value), body: body || null },
    });
    showToast('Terima kasih atas penilaianmu.');
    await Promise.all([loadProfileData(), fetchProducts()]);
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
    select.disabled = false;
    if (bodyField) bodyField.disabled = false;
  }
}

function fillAccountSettings() {
  $('#profile-name-input').value = String(valueOf(state.user, ['name'], ''));
  $('#profile-email-input').value = String(valueOf(state.user, ['email'], ''));
  const pending = String(valueOf(state.user, ['pending_email'], ''));
  const verified = Boolean(valueOf(state.user, ['email_verified_at'], null));
  $('#email-verification-status').textContent = pending
    ? `Email aktif tetap ${valueOf(state.user, ['email'], '')}. Konfirmasi telah dikirim ke ${pending}.`
    : verified ? 'Email telah terverifikasi.' : 'Email belum terverifikasi. Verifikasi diperlukan untuk checkout dan berjualan.';
  $('#resend-verification').hidden = verified;
  $('#accept-legal-consent').hidden = Boolean(valueOf(state.user, ['transaction_ready'], false) || !verified);
}

async function submitForgotPassword(form) {
  const errorBox = $('.form-error', form);
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  try {
    const response = await api('/api/auth/forgot-password', { method: 'POST', body: { email: form.elements.email.value.trim() } });
    showToast(response.message);
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  }
}

async function submitPasswordReset(form) {
  const errorBox = $('.form-error', form);
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  try {
    const fields = new FormData(form);
    const response = await api('/api/auth/reset-password', { method: 'POST', body: Object.fromEntries(fields) });
    showToast(response.message);
    form.reset();
    form.hidden = true;
    setAuthMode('login');
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  }
}

async function loadAccountSessions() {
  const list = $('#account-sessions-list');
  list.replaceChildren(listMessage('Memuat perangkat…'));
  const payload = await api('/api/me/sessions');
  const entries = Array.isArray(payload?.data) ? payload.data : [];
  list.replaceChildren(...entries.map((entry) => {
    const card = node('article', { className: 'profile-card' });
    card.append(node('strong', { text: entry.is_current ? 'Perangkat ini' : 'Perangkat lain' }));
    card.append(node('span', { text: String(entry.user_agent || 'Browser tidak dikenal') }));
    card.append(node('small', { text: `${entry.ip_address || 'IP tidak tersedia'} · ${messageTimestamp(entry.last_seen_at)}` }));
    if (!entry.is_current) card.append(button('Cabut sesi', 'text-link', { 'data-revoke-session': entry.id }));
    return card;
  }));
}

async function resendVerification(trigger) {
  trigger.disabled = true;
  try {
    const response = await api('/api/me/email-verification', { method: 'POST' });
    showToast(response.message);
  } catch (error) { handleProtectedError(error, trigger); }
  finally { trigger.disabled = false; }
}

async function acceptLegalConsent(trigger) {
  trigger.disabled = true;
  try {
    state.user = normalizedUser(await api('/api/me/legal-consent', { method: 'POST' }));
    fillAccountSettings();
    showToast('Kebijakan terbaru disetujui.');
  } catch (error) { handleProtectedError(error, trigger); }
  finally { trigger.disabled = false; }
}

async function revokeSession(id, trigger) {
  trigger.disabled = true;
  try {
    await api('/api/me/sessions/' + encodeURIComponent(id), { method: 'DELETE' });
    await loadAccountSessions();
  } catch (error) { handleProtectedError(error, trigger); }
}

async function revokeAllSessions(trigger) {
  trigger.disabled = true;
  try {
    await api('/api/me/sessions', { method: 'DELETE' });
    state.user = null;
    state.cart.clear();
    state.rentalDates.clear();
    updateAuthUi();
    closeLayer();
    showToast('Semua sesi telah dicabut.');
  } catch (error) { handleProtectedError(error, trigger); }
  finally { trigger.disabled = false; }
}

async function deactivateAccount(form) {
  const errorBox = $('.form-error', form);
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  try {
    const response = await api('/api/me', { method: 'DELETE', body: { current_password: form.elements.current_password.value } });
    state.user = null;
    state.cart.clear();
    updateAuthUi();
    renderCart();
    closeLayer();
    showToast(response.message);
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  }
}

async function submitIdentity(form) {
  const errorBox = $('#profile-identity-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const submit = $('button[type="submit"]', form);
  const fields = new FormData(form);
  submit.disabled = true;
  try {
    const response = await api('/api/me', {
      method: 'PATCH',
      body: {
        name: fields.get('name'),
        email: fields.get('email'),
        current_password: fields.get('current_password'),
      },
    });
    state.user = normalizedUser(response);
    if (!state.user) state.user = normalizedUser(await api('/api/me'));
    $('#profile-name').textContent = String(valueOf(state.user, ['name'], 'Cosplayer'));
    $('#profile-email').textContent = String(valueOf(state.user, ['email'], ''));
    fillAccountSettings();
    form.elements.current_password.value = '';
    showToast('Profil berhasil diperbarui.');
    await Promise.all([loadProfileData(), fetchProducts()]);
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  } finally {
    submit.disabled = false;
  }
}

async function submitPassword(form) {
  const errorBox = $('#profile-password-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const submit = $('button[type="submit"]', form);
  const fields = new FormData(form);
  submit.disabled = true;
  try {
    await api('/api/me/password', {
      method: 'PATCH',
      body: {
        current_password: fields.get('current_password'),
        password: fields.get('password'),
        password_confirmation: fields.get('password_confirmation'),
      },
    });
    form.reset();
    showToast('Kata sandi berhasil diperbarui.');
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  } finally {
    submit.disabled = false;
  }
}

function openProfile(trigger) {
  if (!requireAuthentication(trigger)) return;
  $('#profile-name').textContent = String(valueOf(state.user, ['name'], 'Cosplayer'));
  $('#profile-email').textContent = String(valueOf(state.user, ['email'], ''));
  fillAccountSettings();
  $('#profile-identity-error').hidden = true;
  $('#profile-password-error').hidden = true;
  openLayer(elements.profileDrawer, trigger);
  loadProfileData();
  loadAccountSessions().catch((error) => handleProtectedError(error, trigger));
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
    invalidateCheckoutKey();
    state.user = null;
    state.cart.clear();
    state.rentalDates.clear();
    $('#checkout-handoff-form')?.reset();
    state.favorites.clear();
    state.favoritesOnly = false;
    resetRentalCalendarState({ resetForms: true });
    updateAuthUi();
    renderCart();
    await fetchProducts();
    closeLayer(false);
    showToast('Berhasil keluar.');
  } catch (error) {
    if (error.status === 401) {
      invalidateCheckoutKey();
      state.user = null;
      state.cart.clear();
      state.rentalDates.clear();
      $('#checkout-handoff-form')?.reset();
      resetRentalCalendarState({ resetForms: true });
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
  const form = $('#checkout-handoff-form');
  const errorBox = $('#checkout-handoff-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const fields = new FormData(form);
  trigger.disabled = true;
  try {
    state.checkoutKey ??= crypto.randomUUID();
    const response = await api('/api/checkout', {
      method: 'POST',
      headers: { 'Idempotency-Key': state.checkoutKey },
      body: {
        address_id: fields.get('address_id') || null,
        pickup_seller_ids: fields.getAll('pickup_seller_ids[]').map(Number),
        recipient: {
          name: fields.get('recipient_name'),
          phone: fields.get('recipient_phone'),
          email: fields.get('recipient_email'),
        },
        address: {
          line1: fields.get('address_line1'),
          line2: fields.get('address_line2'),
          city: fields.get('city'),
          province: fields.get('province'),
          postal_code: fields.get('postal_code'),
        },
        handoff_note: fields.get('handoff_note'),
        items: [...state.cart.keys()].map((id) => ({
          id: Number(id) || id,
          variant_id: selectedProductVariant(state.cart.get(id))?.id,
          quantity: 1,
          ...(valueOf(state.cart.get(id), ['type'], 'Sewa') === 'Sewa' ? state.rentalDates.get(id) : {}),
        })),
      },
    });
    state.cart.clear();
    state.rentalDates.clear();
    localStorage.removeItem('cosplaynesia.guest-cart');
    state.checkoutKey = null;
    renderCart();
    closeLayer(false);
    showToast(`Checkout berhasil${valueOf(response, ['order_id', 'orderId'], '') ? `! Pesanan #${valueOf(response, ['order_id', 'orderId'])}` : '.'}`);
    await fetchProducts();
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
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
  const fields = new FormData(form);
  const images = fields.getAll('images[]').filter((entry) => entry instanceof File && entry.size > 0);
  fields.delete('images[]');
  const data = Object.fromEntries(fields.entries());
  data.category_attributes = { character: data.character || null, material: data.material || null, fit: data.fit || null };
  data.costume_includes = String(data.costume_includes || '').split(',').map((item) => item.trim()).filter(Boolean);
  data.measurements = {
    chest: data.measurement_chest ? Number(data.measurement_chest) : null,
    waist: data.measurement_waist ? Number(data.measurement_waist) : null,
    hips: data.measurement_hips ? Number(data.measurement_hips) : null,
    height: data.measurement_height ? Number(data.measurement_height) : null,
  };
  ['character', 'material', 'fit', 'measurement_chest', 'measurement_waist', 'measurement_hips', 'measurement_height'].forEach((key) => delete data[key]);
  data.stock = Number(data.stock);
  ['sale_price', 'rental_price_per_day', 'rental_deposit', 'rental_min_days', 'rental_max_days'].forEach((key) => {
    data[key] = data[key] === '' ? null : Number(data[key]);
  });
  if (data.type === 'Beli') {
    ['rental_price_per_day', 'rental_deposit', 'rental_min_days', 'rental_max_days'].forEach((key) => delete data[key]);
  } else {
    delete data.sale_price;
  }
  if (!state.editingProductId) Object.keys(data).forEach((key) => { if (data[key] === '') delete data[key]; });
  try {
    const editing = state.editingProductId;
    let savedProduct = await api(editing ? `/api/products/${encodeURIComponent(editing)}` : '/api/products', { method: editing ? 'PATCH' : 'POST', body: data });
    if (images.length) {
      const upload = new FormData();
      images.forEach((image) => upload.append('images[]', image));
      try {
        savedProduct = await api(`/api/products/${encodeURIComponent(productId(savedProduct))}/images`, { method: 'POST', body: upload });
      } catch (error) {
        if (!editing) await api(`/api/products/${encodeURIComponent(productId(savedProduct))}`, { method: 'DELETE' }).catch(() => {});
        throw error;
      }
    }
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

  if (product) {
    ['name', 'stock', 'category', 'series', 'type', 'size', 'city', 'sale_price', 'rental_price_per_day', 'rental_deposit', 'rental_min_days', 'rental_max_days'].forEach((field) => {
      form.elements[field].value = valueOf(product, [field], '');
    });
    const attributes = valueOf(product, ['category_attributes'], {});
    form.elements.character.value = attributes.character ?? '';
    form.elements.material.value = attributes.material ?? '';
    form.elements.fit.value = attributes.fit ?? 'unisex';
    form.elements.costume_includes.value = valueOf(product, ['costume_includes'], []).join(', ');
    const measurements = valueOf(product, ['measurements'], {});
    ['chest', 'waist', 'hips', 'height'].forEach((key) => { form.elements[`measurement_${key}`].value = measurements[key] ?? ''; });
    form.elements.care_instructions.value = valueOf(product, ['care_instructions'], '');
  }
  syncProductPricingFields(form);
  openLayer(elements.addProductDrawer, trigger);
}

function syncProductPricingFields(form = $('#add-product-form')) {
  const rental = form.elements.type.value === 'Sewa';
  $('#sale-price-field').hidden = rental;
  $('#rental-pricing-fields').hidden = !rental;
  form.elements.sale_price.required = !rental;
  ['rental_price_per_day', 'rental_deposit', 'rental_min_days', 'rental_max_days'].forEach((field) => {
    form.elements[field].required = rental;
  });
}

function openVariantForm(product, variant = null, trigger = document.activeElement) {
  const form = $('#variant-form');
  state.variantProductId = productId(product);
  state.editingVariantId = variant?.id ?? null;
  form.reset();
  $('#variant-form-error').hidden = true;
  $('#variant-title').textContent = variant ? 'Edit Variasi' : 'Tambah Variasi';
  $('#variant-submit-button').textContent = variant ? 'Simpan perubahan' : 'Simpan variasi';
  if (variant) {
    ['sku', 'label', 'size', 'color', 'condition', 'stock'].forEach((field) => {
      form.elements[field].value = valueOf(variant, [field], '');
    });
  }
  openLayer($('#variant-drawer'), trigger);
}

async function submitVariant(form) {
  const errorBox = $('#variant-form-error');
  errorBox.hidden = true;
  if (!form.reportValidity()) return;
  const submit = $('#variant-submit-button');
  submit.disabled = true;
  const data = Object.fromEntries(new FormData(form).entries());
  data.stock = Number(data.stock);
  Object.keys(data).forEach((key) => { if (data[key] === '') data[key] = null; });
  try {
    const editing = state.editingVariantId;
    const path = `/api/products/${encodeURIComponent(state.variantProductId)}/variants${editing ? `/${encodeURIComponent(editing)}` : ''}`;
    const product = await api(path, { method: editing ? 'PATCH' : 'POST', body: data });
    reconcileCartProduct(product, state.variantProductId);
    closeLayer(false);
    showToast(editing ? 'Variasi berhasil diperbarui.' : 'Variasi berhasil ditambahkan.');
    await Promise.all([loadOwnedProducts(), fetchProducts()]);
    state.variantProductId = null;
    state.editingVariantId = null;
  } catch (error) {
    errorBox.textContent = error.message;
    errorBox.hidden = false;
  } finally { submit.disabled = false; }
}

async function deleteVariant(productIdValue, variantId, trigger) {
  trigger.disabled = true;
  try {
    const product = await api(`/api/products/${encodeURIComponent(productIdValue)}/variants/${encodeURIComponent(variantId)}`, { method: 'DELETE' });
    reconcileCartProduct(product, productIdValue);
    showToast('Variasi berhasil dihapus.');
    await Promise.all([loadOwnedProducts(), fetchProducts()]);
  } catch (error) { handleProtectedError(error, trigger); }
}

async function reorderProductImage(productIdValue, imageId, direction, trigger) {
  const product = state.ownedProducts.get(String(productIdValue));
  const images = Array.isArray(product?.images) ? [...product.images] : [];
  const from = images.findIndex((image) => String(image.id) === String(imageId));
  const to = from + Number(direction);
  if (from < 0 || to < 0 || to >= images.length) return;
  [images[from], images[to]] = [images[to], images[from]];
  trigger.disabled = true;
  try {
    const updated = await api(`/api/products/${encodeURIComponent(productIdValue)}/images/order`, {
      method: 'PATCH',
      body: { image_ids: images.map((image) => image.id) },
    });
    state.ownedProducts.set(String(productIdValue), updated);
    await Promise.all([loadOwnedProducts(), fetchProducts()]);
  } catch (error) { handleProtectedError(error, trigger); }
}

async function deleteProductImage(productIdValue, imageId, trigger) {
  trigger.disabled = true;
  try {
    const updated = await api(`/api/products/${encodeURIComponent(productIdValue)}/images/${encodeURIComponent(imageId)}`, { method: 'DELETE' });
    state.ownedProducts.set(String(productIdValue), updated);
    await Promise.all([loadOwnedProducts(), fetchProducts()]);
  } catch (error) { handleProtectedError(error, trigger); }
}

async function updateProductStatus(id, status, trigger) {
  trigger.disabled = true;
  try {
    const product = await api(`/api/products/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: { status } });
    reconcileCartProduct(product, id);
    const messages = { published: product.submitted_for_review_at ? 'Listing diajukan untuk review.' : 'Listing berhasil diterbitkan.', paused: 'Listing berhasil dijeda.', draft: 'Listing dikembalikan ke draft.' };
    showToast(messages[status] ?? 'Status listing diperbarui.');
    await Promise.all([loadProfileData(), fetchProducts()]);
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
}

async function previewProduct(id, trigger) {
  trigger.disabled = true;
  try {
    const product = await api(`/api/products/${encodeURIComponent(id)}/preview`);
    state.ownedProducts.set(String(id), product);
    openProduct(id, trigger);
  } catch (error) { handleProtectedError(error, trigger); } finally { trigger.disabled = false; }
}

async function deleteProduct(id, trigger) {
  const product = state.ownedProducts.get(String(id));
  if (!product || !window.confirm(`Arsipkan ${productName(product)}? Listing tidak dapat diterbitkan kembali, tetapi riwayatnya tetap tersimpan.`)) return;
  trigger.disabled = true;
  try {
    await api(`/api/products/${encodeURIComponent(id)}`, { method: 'DELETE' });
    reconcileCartProduct(null, id);
    showToast('Listing berhasil diarsipkan.');
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
$('#sort-select').addEventListener('change', (event) => { state.sort = event.target.value; fetchProducts({ scroll: true }); });
$$('[data-filter]').forEach((control) => control.addEventListener('click', () => setFilter(control.dataset.filter)));
$$('[data-filter-trigger]').forEach((control) => control.addEventListener('click', () => setFilter(control.dataset.filterTrigger)));
$('#reset-search').addEventListener('click', () => { state.query = ''; state.filter = 'Semua'; submitSearch(''); });
elements.loadMore.addEventListener('click', () => fetchProducts({ append: true }));

$('#discovery-filter').addEventListener('submit', (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const error = $('#discovery-filter-error');
  const fields = new FormData(form);
  const start = String(fields.get('start_date') ?? '');
  const end = String(fields.get('end_date') ?? '');
  const min = String(fields.get('min_price') ?? '').trim();
  const max = String(fields.get('max_price') ?? '').trim();

  // Mirrors the server rule so a shopper sees the reason before the request is made.
  if (Boolean(start) !== Boolean(end)) {
    error.textContent = 'Isi tanggal mulai dan tanggal selesai sewa bersamaan.';
    error.hidden = false;
    return;
  }
  if (start && end && end < start) {
    error.textContent = 'Tanggal selesai tidak boleh sebelum tanggal mulai.';
    error.hidden = false;
    return;
  }
  if (min && max && Number(max) < Number(min)) {
    error.textContent = 'Harga maksimum tidak boleh di bawah harga minimum.';
    error.hidden = false;
    return;
  }

  error.hidden = true;
  state.discovery = {
    type: String(fields.get('type') ?? ''),
    size: String(fields.get('size') ?? ''),
    city: String(fields.get('city') ?? '').trim(),
    condition: String(fields.get('condition') ?? ''),
    min_price: min,
    max_price: max,
    min_rating: String(fields.get('min_rating') ?? ''),
    start_date: start,
    end_date: end,
    quantity: String(fields.get('quantity') ?? '1').trim() || '1',
  };
  state.lastAppliedFilters = { ...state.discovery };
  // A saved search with no criterion would match everything forever, so "save" only appears once
  // the shopper has actually expressed an intent.
  const hasCriteria = Object.entries(state.lastAppliedFilters).some(([key, value]) => key !== 'quantity' && String(value).trim() !== '');
  $('#save-search').hidden = !hasCriteria || !state.user;
  fetchProducts({ scroll: true });
});

$('#reset-filters').addEventListener('click', () => {
  const form = $('#discovery-filter');
  form.reset();
  $('#discovery-filter-error').hidden = true;
  state.discovery = { type: '', size: '', city: '', condition: '', min_price: '', max_price: '', min_rating: '', start_date: '', end_date: '', quantity: '1' };
  state.lastAppliedFilters = null;
  $('#save-search').hidden = true;
  fetchProducts({ scroll: true });
});

$('#save-search').addEventListener('click', async (event) => {
  const trigger = event.currentTarget;
  if (!requireAuthentication(trigger)) return;
  const filters = state.lastAppliedFilters ?? state.discovery;
  const hasCriteria = Object.entries(filters).some(([key, value]) => key !== 'quantity' && String(value).trim() !== '');
  if (!hasCriteria) {
    showToast('Terapkan minimal satu filter sebelum menyimpan.');
    return;
  }

  const name = window.prompt('Beri nama untuk pencarian ini:', defaultSavedSearchName(filters));
  if (!name || !name.trim()) return;

  const payload = { name: name.trim().slice(0, 100), filters: { ...filters } };
  // min_rating is a display filter the scanner cannot evaluate server-side yet; keep it out of the
  // stored payload rather than saving an alert that would run with looser rules than the screen.
  delete payload.filters.min_rating;

  trigger.disabled = true;
  try {
    await api('/api/saved-searches', { method: 'POST', body: payload });
    showToast('Pencarian disimpan. Notifikasi akan dikirim saat ada yang cocok.');
    await loadSavedSearches();
  } catch (error) {
    handleProtectedError(error, trigger);
  } finally {
    trigger.disabled = false;
  }
});

function formatDateTime(value) {
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return String(value ?? '');
  return new Intl.DateTimeFormat('id-ID', { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function defaultSavedSearchName(filters) {
  const parts = [];
  if (filters.q) parts.push(`"${filters.q}"`);
  if (filters.category) parts.push(filters.category);
  if (filters.type) parts.push(filters.type);
  if (filters.size) parts.push(`ukuran ${filters.size}`);
  if (filters.city) parts.push(filters.city);
  if (filters.start_date) parts.push(`sewa ${filters.start_date}`);
  return parts.length ? parts.join(' · ') : 'Pencarian kustom';
}

async function loadSavedSearches() {
  const list = $('#saved-searches-list');
  if (!list) return;
  if (!state.user) {
    list.replaceChildren(node('p', { className: 'list-message', text: 'Masuk untuk menyimpan dan memantau pencarian.' }));
    $('#saved-searches-count').textContent = '';
    return;
  }
  try {
    const payload = await api('/api/saved-searches');
    state.savedSearches = Array.isArray(payload?.data) ? payload.data : [];
    $('#saved-searches-count').textContent = `${state.savedSearches.length} tersimpan`;
    if (!state.savedSearches.length) {
      list.replaceChildren(node('p', { className: 'list-message', text: 'Belum ada pencarian yang dipantau.' }));
      return;
    }
    list.replaceChildren(...state.savedSearches.map((entry) => savedSearchCard(entry)));
  } catch (error) {
    handleProtectedError(error);
  }
}

function savedSearchCard(entry) {
  const filters = entry.filters ?? {};
  const summary = defaultSavedSearchName(filters) === 'Pencarian kustom' && !filters.start_date && !filters.type && !filters.size && !filters.city && !filters.category
    ? Object.entries(filters).map(([key, value]) => `${key}: ${value}`).join(', ')
    : defaultSavedSearchName(filters);
  const isWindow = Boolean(filters.start_date && filters.end_date);

  const card = node('article', { className: 'profile-card saved-search-card' }, [
    node('div', { className: 'profile-card__row profile-card__heading' }, [
      node('strong', { text: entry.name ?? 'Pencarian' }),
      node('span', { className: `listing-status ${entry.alert_enabled ? '' : 'listing-status--inactive'}`, text: entry.alert_enabled ? 'Memantau' : 'Nonaktif' }),
    ]),
    node('small', { text: `${isWindow ? 'Alert tanggal tersedia' : 'Alert kostum baru'} · ${summary}` }),
    node('small', { text: entry.last_scanned_at ? `Terakhir diperiksa ${formatDateTime(entry.last_scanned_at)}` : 'Belum pernah diperiksa' }),
  ]);

  const actions = node('div', { className: 'profile-card__actions' }, [
    node('button', { className: 'button button--outline', text: isWindow ? 'Cek tanggal sekarang' : 'Cek sekarang', attrs: { 'data-run-saved-search': entry.id } }),
    node('button', { className: 'button button--outline', text: entry.alert_enabled ? 'Matikan pantauan' : 'Nyalakan pantauan', attrs: { 'data-toggle-saved-search': entry.id, 'data-next-enabled': entry.alert_enabled ? '0' : '1' } }),
    node('button', { className: 'button button--outline danger-action', text: 'Hapus', attrs: { 'data-delete-saved-search': entry.id } }),
  ]);
  card.append(actions);
  return card;
}

$('#saved-searches-list').addEventListener('click', async (event) => {
  const run = event.target.closest('[data-run-saved-search]');
  if (run) {
    run.disabled = true;
    try {
      const payload = await api(`/api/saved-searches/${encodeURIComponent(run.dataset.runSavedSearch)}`, { method: 'PATCH', body: { run: true } });
      const matched = Number(payload?.result?.matched ?? 0);
      showToast(matched > 0 ? `${matched} kostum cocok ditemukan. Notifikasi dikirim bila baru.` : 'Belum ada yang cocok sekarang.');
      await loadSavedSearches();
    } catch (error) {
      handleProtectedError(error, run);
    } finally {
      run.disabled = false;
    }
    return;
  }
  const toggle = event.target.closest('[data-toggle-saved-search]');
  if (toggle) {
    toggle.disabled = true;
    try {
      await api(`/api/saved-searches/${encodeURIComponent(toggle.dataset.toggleSavedSearch)}`, { method: 'PATCH', body: { alert_enabled: toggle.dataset.nextEnabled === '1' } });
      await loadSavedSearches();
    } catch (error) {
      handleProtectedError(error, toggle);
    } finally {
      toggle.disabled = false;
    }
    return;
  }
  const remove = event.target.closest('[data-delete-saved-search]');
  if (!remove) return;
  if (!window.confirm('Hapus pencarian yang dipantau ini?')) return;
  remove.disabled = true;
  try {
    await api(`/api/saved-searches/${encodeURIComponent(remove.dataset.deleteSavedSearch)}`, { method: 'DELETE' });
    showToast('Pencarian dihapus.');
    await loadSavedSearches();
  } catch (error) {
    handleProtectedError(error, remove);
  } finally {
    remove.disabled = false;
  }
});

$('#refresh-saved-searches').addEventListener('click', () => loadSavedSearches());

elements.productGrid.addEventListener('click', (event) => {
  const favorite = event.target.closest('[data-favorite]');
  if (favorite) { event.preventDefault(); event.stopPropagation(); toggleFavorite(favorite.dataset.favorite, favorite); return; }
  const add = event.target.closest('[data-add]');
  if (add) { event.preventDefault(); event.stopPropagation(); addToCart(add.dataset.add); return; }
  const open = event.target.closest('[data-open-product]');
  if (open) openProduct(open.dataset.openProduct, open);
});

$('#modal-content').addEventListener('click', (event) => {
  const more = event.target.closest('[data-load-reviews]');
  if (more) { loadProductReviews(more.dataset.loadReviews, { append: true }); return; }
  const store = event.target.closest('[data-open-store]');
  if (store) { openStore(store.dataset.openStore, store); return; }
  const add = event.target.closest('[data-modal-add]');
  if (!add) return;
  addToCart(add.dataset.modalAdd, $('#modal-product-variant')?.value);
  closeLayer(false);
  window.setTimeout(() => openLayer(elements.cartDrawer, add), 120);
});

$('#cart-items').addEventListener('click', (event) => {
  const remove = event.target.closest('[data-remove]');
  if (!remove) return;
  const id = String(remove.dataset.remove);
  state.cart.delete(id);
  state.rentalDates.delete(id);
  invalidateCheckoutKey();
  renderCart();
  persistCart();
});

$('#cart-items').addEventListener('change', (event) => {
  const variant = event.target.closest('[data-cart-variant]');
  if (variant) {
    const id = String(variant.dataset.cartVariant);
    const product = state.cart.get(id);
    if (!product) return;
    state.cart.set(id, { ...product, selected_variant_id: Number(variant.value) });
    state.cart.get(id).cart_updated_at = new Date().toISOString();
    invalidateCheckoutKey();
    renderCart();
    persistCart();
    if (valueOf(product, ['type'], 'Sewa') === 'Sewa') refreshRentalAvailability(id);
    return;
  }
  const input = event.target.closest('[data-rental-date]');
  if (!input) return;
  const id = String(input.dataset.rentalDate);
  const dates = state.rentalDates.get(id) ?? {};
  if (dates[input.dataset.dateKind] === input.value) return;
  dates[input.dataset.dateKind] = input.value;
  state.rentalDates.set(id, dates);
  const product = state.cart.get(id);
  if (product) product.cart_updated_at = new Date().toISOString();
  invalidateCheckoutKey();
  persistCart();
  refreshRentalAvailability(id);
});

$('#checkout-handoff-form').addEventListener('change', (event) => {
  if (event.target.id !== 'checkout-address-select') return;
  const address = state.addresses.find((item) => String(item.id) === event.target.value);
  fillCheckoutAddress(address);
  $('#delete-checkout-address').hidden = !address;
});

$('#checkout-handoff-form').addEventListener('click', async (event) => {
  if (event.target.id === 'save-checkout-address') { await saveCheckoutAddress(event.target); return; }
  if (event.target.id !== 'delete-checkout-address') return;
  const id = $('#checkout-address-select').value;
  if (!id) return;
  event.target.disabled = true;
  try {
    await api(`/api/addresses/${encodeURIComponent(id)}`, { method: 'DELETE' });
    await loadAddresses();
    showToast('Alamat dihapus.');
  } catch (error) { showToast(error.message); } finally { event.target.disabled = false; }
});

$('#orders-list').addEventListener('click', async (event) => {
  const thread = event.target.closest('[data-thread-open]');
  if (thread) { openThread(thread.dataset.threadOpen, thread); return; }
  const send = event.target.closest('[data-thread-send]');
  if (send) { sendThreadMessage(send.dataset.threadSend, send); return; }
  const more = event.target.closest('[data-thread-more]');
  if (more) { loadThread(more.dataset.threadMore, more.closest('.profile-card'), { append: true }).catch((error) => handleProtectedError(error, more)); return; }
  const review = event.target.closest('[data-review-order]');
  if (review) {
    submitProductReview(review.dataset.reviewOrder, review.dataset.reviewItem, review);
    return;
  }
  const detail = event.target.closest('[data-order-detail]');
  if (detail) { loadOrderDetail(detail.dataset.orderDetail, detail); return; }
  const cancel = event.target.closest('[data-cancel-order]');
  if (!cancel) return;
  cancel.disabled = true;
  try {
    await api('/api/orders/' + encodeURIComponent(cancel.dataset.cancelOrder) + '/items/' + encodeURIComponent(cancel.dataset.cancelItem) + '/rental', { method: 'DELETE' });
    showToast('Reservasi sewa dibatalkan.');
    await loadProfileData();
  } catch (error) {
    handleProtectedError(error, cancel);
  } finally {
    cancel.disabled = false;
  }
});

$('#incoming-orders-list').addEventListener('click', (event) => {
  const thread = event.target.closest('[data-thread-open]');
  if (thread) { openThread(thread.dataset.threadOpen, thread); return; }
  const send = event.target.closest('[data-thread-send]');
  if (send) { sendThreadMessage(send.dataset.threadSend, send); return; }
  const more = event.target.closest('[data-thread-more]');
  if (more) { loadThread(more.dataset.threadMore, more.closest('.profile-card'), { append: true }).catch((error) => handleProtectedError(error, more)); return; }
  const detail = event.target.closest('[data-fulfillment-detail]');
  if (detail) { loadFulfillmentDetail(detail.dataset.fulfillmentDetail, detail); return; }
  const action = event.target.closest('[data-fulfillment-id]');
  if (!action) return;
  updateFulfillmentStatus(action.dataset.fulfillmentId, action.dataset.nextFulfillmentStatus, action);
});

$('#seller-reviews-list').addEventListener('click', (event) => {
  const remove = event.target.closest('[data-delete-reply]');
  if (remove) { submitReviewReply(remove.dataset.deleteReply, remove, true); return; }
  const reply = event.target.closest('[data-reply-review]');
  if (reply) submitReviewReply(reply.dataset.replyReview, reply);
});

$('#seller-reviews-unanswered').addEventListener('change', (event) => {
  state.sellerReviewsUnanswered = event.target.checked;
  loadSellerReviews().catch((error) => handleProtectedError(error));
});

$('#notification-button').addEventListener('click', (event) => openNotifications(event.currentTarget));
$('#notification-list').addEventListener('click', (event) => {
  const read = event.target.closest('[data-read-notification]');
  if (read) markNotificationRead(read.dataset.readNotification, read);
});
$('#notification-unread-only').addEventListener('change', (event) => {
  state.notificationsUnreadOnly = event.target.checked;
  loadNotifications().catch((error) => handleProtectedError(error));
});
$('#mark-all-notifications').addEventListener('click', (event) => markAllNotificationsRead(event.currentTarget));
$('#load-more-notifications').addEventListener('click', () => loadNotifications({ append: true }).catch((error) => handleProtectedError(error)));

$('#load-more-seller-reviews').addEventListener('click', () => loadSellerReviews({ append: true }).catch((error) => handleProtectedError(error)));

$('#cart-button').addEventListener('click', (event) => openLayer(elements.cartDrawer, event.currentTarget));
$('#mobile-cart-button').addEventListener('click', (event) => openLayer(elements.cartDrawer, event.currentTarget));
$('#shop-now').addEventListener('click', () => { closeLayer(); $('#produk')?.scrollIntoView({ behavior: 'smooth' }); });
$('#checkout-button').addEventListener('click', (event) => checkout(event.currentTarget));
$('#checkout-handoff-form').addEventListener('input', () => { invalidateCheckoutKey(); $('#checkout-handoff-error').hidden = true; });
$$('[data-close-layer]').forEach((control) => control.addEventListener('click', () => closeLayer()));
elements.overlay.addEventListener('click', () => closeLayer());

$$('.auth-action').forEach((control) => control.addEventListener('click', (event) => { setAuthMode(control.dataset.authMode); openLayer(elements.authModal, event.currentTarget); }));
$$('.profile-action').forEach((control) => control.addEventListener('click', (event) => openProfile(event.currentTarget)));
$('.mobile-account-action').addEventListener('click', (event) => state.user ? openProfile(event.currentTarget) : (setAuthMode('login'), openLayer(elements.authModal, event.currentTarget)));
$$('[data-switch-auth]').forEach((control) => control.addEventListener('click', () => setAuthMode(control.dataset.switchAuth)));
$('#auth-form').addEventListener('submit', (event) => { event.preventDefault(); submitAuth(event.currentTarget); });
$('#forgot-password-button').addEventListener('click', () => {
  const form = $('#forgot-password-form');
  form.hidden = !form.hidden;
  if (!form.hidden) form.elements.email.value = $('#auth-email').value.trim();
});
$('#forgot-password-form').addEventListener('submit', (event) => { event.preventDefault(); submitForgotPassword(event.currentTarget); });
$('#reset-password-form').addEventListener('submit', (event) => { event.preventDefault(); submitPasswordReset(event.currentTarget); });
$('#logout-button').addEventListener('click', (event) => logout(event.currentTarget));
$('#profile-identity-form').addEventListener('submit', (event) => { event.preventDefault(); submitIdentity(event.currentTarget); });
$('#profile-password-form').addEventListener('submit', (event) => { event.preventDefault(); submitPassword(event.currentTarget); });
$('#store-onboarding-form').addEventListener('submit', (event) => { event.preventDefault(); submitStoreOnboarding(event.currentTarget); });
$('#store-summary').addEventListener('submit', async (event) => {
  if (event.target.id !== 'store-logistics-form') return;
  event.preventDefault();
  const form = event.target;
  if (!form.reportValidity()) return;
  const submit = form.querySelector('button[type="submit"]');
  submit.disabled = true;
  try {
    const fields = new FormData(form);
    const response = await api('/api/me/store/logistics', { method: 'PATCH', body: {
      origin_address_line1: fields.get('origin_address_line1'),
      origin_address_line2: fields.get('origin_address_line2') || null,
      origin_province: fields.get('origin_province'),
      origin_postal_code: fields.get('origin_postal_code'),
      pickup_enabled: fields.has('pickup_enabled'),
      pickup_instructions: fields.get('pickup_instructions') || null,
    } });
    state.store = response.store;
    renderStore();
    showToast('Pengaturan pengiriman tersimpan.');
  } catch (error) { showToast(error.message); } finally { submit.disabled = false; }
});
$('#admin-store-review-list').addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-store-review]');
  if (trigger) reviewStore(trigger.dataset.storeReview, trigger.dataset.reviewStatus, trigger);
});
$('#admin-product-review-list').addEventListener('click', (event) => {
  const review = event.target.closest('[data-product-review]');
  if (review) {
    reviewProduct(review.dataset.productReview, review.dataset.reviewStatus, review);
    return;
  }
  const preview = event.target.closest('[data-moderation-preview]');
  if (preview) {
    const product = state.pendingProducts.find((entry) => String(entry.id) === String(preview.dataset.moderationPreview));
    if (product) {
      state.ownedProducts.set(String(product.id), product);
      openProduct(product.id, preview);
    }
  }
});
$('#resend-verification').addEventListener('click', (event) => resendVerification(event.currentTarget));
$('#accept-legal-consent').addEventListener('click', (event) => acceptLegalConsent(event.currentTarget));
$('#revoke-all-sessions').addEventListener('click', (event) => revokeAllSessions(event.currentTarget));
$('#deactivate-account-form').addEventListener('submit', (event) => { event.preventDefault(); deactivateAccount(event.currentTarget); });
$('#account-sessions-list').addEventListener('click', (event) => {
  const trigger = event.target.closest('[data-revoke-session]');
  if (trigger) revokeSession(trigger.dataset.revokeSession, trigger);
});
$('#refresh-profile').addEventListener('click', loadProfileData);
$('#load-more-my-products').addEventListener('click', () => loadOwnedProducts({ append: true }).catch((error) => handleProtectedError(error)));
$('#load-more-incoming-orders').addEventListener('click', () => loadIncomingFulfillments({ append: true }).catch((error) => handleProtectedError(error)));
$('#load-more-orders').addEventListener('click', () => loadOrders({ append: true }).catch((error) => handleProtectedError(error)));
$('#rental-block-window-form').addEventListener('submit', (event) => { event.preventDefault(); submitRentalCalendarWindow(event.currentTarget); });
$('#rental-block-form').addEventListener('submit', (event) => { event.preventDefault(); submitRentalBlock(event.currentTarget); });
rentalBlockForms().forEach((form) => {
  form.addEventListener('input', (event) => {
    if (event.target.matches('input[name="start_date"], input[name="end_date"]')) syncRentalBlockDateBounds(form);
  });
  form.addEventListener('change', (event) => {
    if (!event.target.matches('input[name="start_date"], input[name="end_date"]')) return;
    syncRentalBlockDateBounds(form);
    setRentalBlockError(form.id === 'rental-block-window-form' ? $('#rental-block-window-error') : $('#rental-block-form-error'));
  });
});
$('#load-more-rental-blocks').addEventListener('click', () => loadRentalCalendar({ append: true }));
$('#rental-block-list').addEventListener('click', (event) => {
  const cancel = event.target.closest('[data-cancel-rental-block]');
  if (!cancel) return;
  if (cancel.dataset.rentalBlockProduct !== state.rentalCalendar.productId) return;
  cancelRentalBlock(cancel.dataset.cancelRentalBlock, cancel);
});
$('#add-product-button').addEventListener('click', (event) => {
  const trigger = visibleAccountTrigger() ?? event.currentTarget;
  closeLayer(false);
  window.setTimeout(() => openProductForm(null, trigger), 120);
});
$('.seller-action').addEventListener('click', (event) => state.user ? openProductForm(null, event.currentTarget) : requireAuthentication(event.currentTarget));
$('#add-product-form').addEventListener('submit', (event) => { event.preventDefault(); submitProduct(event.currentTarget); });
$('#add-product-form [name="type"]').addEventListener('change', (event) => syncProductPricingFields(event.currentTarget.form));
$('#variant-form').addEventListener('submit', (event) => { event.preventDefault(); submitVariant(event.currentTarget); });

$('#my-products-list').addEventListener('click', (event) => {
  const preview = event.target.closest('[data-preview-product]');
  if (preview) {
    previewProduct(preview.dataset.previewProduct, preview);
    return;
  }
  const addVariant = event.target.closest('[data-add-variant]');
  if (addVariant) {
    const product = state.ownedProducts.get(String(addVariant.dataset.addVariant));
    if (product) openVariantForm(product, null, addVariant);
    return;
  }
  const editVariant = event.target.closest('[data-edit-variant]');
  if (editVariant) {
    const product = state.ownedProducts.get(String(editVariant.dataset.productId));
    const variant = product?.variants?.find((entry) => String(entry.id) === editVariant.dataset.editVariant);
    if (product && variant) openVariantForm(product, variant, editVariant);
    return;
  }
  const removeVariant = event.target.closest('[data-delete-variant]');
  if (removeVariant) {
    deleteVariant(removeVariant.dataset.productId, removeVariant.dataset.deleteVariant, removeVariant);
    return;
  }
  const imageMove = event.target.closest('[data-image-move]');
  if (imageMove) {
    reorderProductImage(imageMove.dataset.productId, imageMove.dataset.imageMove, imageMove.dataset.direction, imageMove);
    return;
  }
  const imageDelete = event.target.closest('[data-image-delete]');
  if (imageDelete) {
    deleteProductImage(imageDelete.dataset.productId, imageDelete.dataset.imageDelete, imageDelete);
    return;
  }
  const calendar = event.target.closest('[data-open-rental-calendar]');
  if (calendar) {
    const product = state.ownedProducts.get(String(calendar.dataset.openRentalCalendar));
    if (!product) return;
    openRentalCalendar(product, visibleAccountTrigger() ?? calendar);
    return;
  }
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
    updateProductStatus(toggle.dataset.toggleProduct, toggle.dataset.nextStatus, toggle);
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
  fetchProducts();
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
const resetParams = new URLSearchParams(window.location.search);
if (resetParams.has('email_verified')) showToast('Email berhasil diverifikasi.');
if (resetParams.has('email_changed')) showToast('Email berhasil diperbarui.');
if (resetParams.has('notifications_unsubscribed')) showToast('Kamu tidak akan menerima email untuk kategori itu lagi.');
if (resetParams.has('reset_token') && resetParams.has('email')) {
  const resetForm = $('#reset-password-form');
  resetForm.hidden = false;
  resetForm.elements.token.value = resetParams.get('reset_token') ?? '';
  resetForm.elements.email.value = resetParams.get('email') ?? '';
  openLayer(elements.authModal);
}
refreshSession().then(() => {
  fetchProducts();
  loadSavedSearches();
});
