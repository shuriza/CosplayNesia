let products = [];

async function fetchProducts() {
  try {
    const response = await fetch('/api/products');
    if (!response.ok) throw new Error('Network response was not ok');
    products = await response.json();
    renderProducts();
  } catch (error) {
    console.error('Error fetching products:', error);
    showToast('Gagal memuat produk. Silakan coba lagi.');
  }
}


const state = { filter: "Semua", query: "", sort: "popular", visible: 8, cart: [], favorites: new Set() };
const productGrid = document.querySelector("#product-grid");
const resultCount = document.querySelector("#result-count");
const emptyState = document.querySelector("#empty-state");
const loadMore = document.querySelector("#load-more");
const overlay = document.querySelector("#overlay");
const cartDrawer = document.querySelector("#cart-drawer");
const productModal = document.querySelector("#product-modal");
const authModal = document.querySelector("#auth-modal");
const toast = document.querySelector("#toast");

const rupiah = value => new Intl.NumberFormat("id-ID", { style: "currency", currency: "IDR", maximumFractionDigits: 0 }).format(value);

function filteredProducts() {
  let result = products.filter(product => {
    const searchText = `${product.name} ${product.series} ${product.seller} ${product.city}`.toLowerCase();
    const matchesQuery = searchText.includes(state.query.toLowerCase());
    const matchesFilter = state.filter === "Semua" || state.filter === "Terbaru" || state.filter === "Terlaris" || product.category === state.filter;
    return matchesQuery && matchesFilter;
  });

  const sort = state.filter === "Terbaru" ? "newest" : state.filter === "Terlaris" ? "popular" : state.sort;
  return result.sort((a, b) => {
    if (sort === "low") return a.price - b.price;
    if (sort === "high") return b.price - a.price;
    if (sort === "newest") return b.newest - a.newest;
    return b.popular - a.popular;
  });
}

function productCard(product) {
  const favorite = state.favorites.has(product.id);
  return `
    <article class="product-card" data-product="${product.id}" tabindex="0" aria-label="Lihat ${product.name}">
      <div class="product-card__image">
        <img src="${product.image}" alt="${product.name}" loading="lazy" />
        <span class="badge ${product.badge === "Baru" ? "badge--new" : ""}">${product.badge}</span>
        <button class="favorite ${favorite ? "active" : ""}" data-favorite="${product.id}" aria-label="${favorite ? "Hapus dari" : "Tambah ke"} favorit">
          <svg viewBox="0 0 24 24"><path d="M12 21s-7-4.35-7-10a4 4 0 0 1 7-2.65A4 4 0 0 1 19 11c0 5.65-7 10-7 10Z" /></svg>
        </button>
      </div>
      <div class="product-card__body">
        <span class="product-card__series">${product.series}</span>
        <h3>${product.name}</h3>
        <div class="product-card__meta"><span class="meta-pill">${product.type}</span><span class="meta-pill">${product.size}</span><span>★ ${product.rating}</span></div>
        <div class="seller"><span class="seller__avatar">${product.seller[0]}</span><span>${product.seller}</span><span class="verified" title="Terverifikasi">✓</span></div>
        <div class="product-card__price">
          <span><strong>${rupiah(product.price)}</strong><small>${product.type === "Sewa" ? "/ 3 hari" : "Harga jual"}</small></span>
          <button class="mini-add" data-add="${product.id}" aria-label="Tambah ${product.name} ke keranjang"><svg viewBox="0 0 24 24"><path d="M3 3h2l2.4 11.2a2 2 0 0 0 2 1.6h7.7a2 2 0 0 0 2-1.6L21 7H6M12 9v5M9.5 11.5h5" /></svg></button>
        </div>
      </div>
    </article>`;
}

function renderProducts() {
  const filtered = filteredProducts();
  const visible = filtered.slice(0, state.visible);
  productGrid.innerHTML = visible.map(productCard).join("");
  resultCount.textContent = `${filtered.length} produk`;
  emptyState.hidden = filtered.length > 0;
  productGrid.hidden = filtered.length === 0;
  loadMore.hidden = filtered.length <= state.visible || filtered.length === 0;
}

function setFilter(filter) {
  state.filter = filter;
  state.visible = 8;
  document.querySelectorAll("[data-filter]").forEach(button => button.classList.toggle("active", button.dataset.filter === filter));
  renderProducts();
  document.querySelector("#produk").scrollIntoView({ behavior: "smooth", block: "start" });
}

function submitSearch(value) {
  state.query = value.trim();
  state.visible = 12;
  document.querySelector("#search-input").value = state.query;
  document.querySelector("#mobile-search-input").value = state.query;
  renderProducts();
  document.querySelector("#produk").scrollIntoView({ behavior: "smooth", block: "start" });
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add("show");
  clearTimeout(showToast.timer);
  showToast.timer = setTimeout(() => toast.classList.remove("show"), 2400);
}

function openLayer(element) {
  element.removeAttribute("inert");
  element.classList.add("open");
  element.setAttribute("aria-hidden", "false");
  overlay.classList.add("open");
  document.body.classList.add("locked");
}

function closeLayers() {
  [cartDrawer, productModal, authModal].forEach(element => {
    element.classList.remove("open");
    element.setAttribute("aria-hidden", "true");
    element.setAttribute("inert", "");
  });
  overlay.classList.remove("open");
  document.body.classList.remove("locked");
}

function openProduct(id) {
  const product = products.find(item => item.id === id);
  if (!product) return;
  document.querySelector("#modal-content").innerHTML = `
    <div class="modal-product">
      <div class="modal-product__image"><img src="${product.image}" alt="${product.name}" /></div>
      <div class="modal-product__info">
        <span class="section-kicker">${product.series}</span>
        <h2 id="modal-product-title">${product.name}</h2>
        <div class="seller"><span class="seller__avatar">${product.seller[0]}</span><span>${product.seller}</span><span class="verified">✓</span><span>· ${product.city}</span></div>
        <div class="modal-product__price"><strong>${rupiah(product.price)}</strong> <span>${product.type === "Sewa" ? "/ 3 hari" : ""}</span></div>
        <p>Kostum lengkap, terawat, dan siap dipakai untuk event berikutnya. Setiap pesanan dilindungi pembayaran aman CosplayNesia.</p>
        <div class="modal-meta"><span><strong>${product.size}</strong><br>Ukuran</span><span><strong>★ ${product.rating}</strong><br>Rating</span><span><strong>${product.type}</strong><br>Tipe</span></div>
        <button class="button button--primary button--full" data-modal-add="${product.id}">Tambah ke keranjang</button>
      </div>
    </div>`;
  openLayer(productModal);
}

function addToCart(id) {
  const product = products.find(item => item.id === id);
  if (!product) return;
  state.cart.push(product);
  renderCart();
  showToast(`${product.name} ditambahkan ke keranjang`);
}

function renderCart() {
  document.querySelector("#cart-count").textContent = state.cart.length;
  document.querySelector("#mobile-cart-count").textContent = state.cart.length;
  const cartItems = document.querySelector("#cart-items");
  cartItems.innerHTML = state.cart.map((product, index) => `
    <article class="cart-item">
      <img src="${product.image}" alt="${product.name}" />
      <div><h3>${product.name}</h3><p>${rupiah(product.price)}</p><small>${product.type} · ${product.size}</small></div>
      <button data-remove="${index}" aria-label="Hapus ${product.name}">×</button>
    </article>`).join("");
  const hasItems = state.cart.length > 0;
  document.querySelector("#cart-empty").hidden = hasItems;
  document.querySelector("#cart-summary").hidden = !hasItems;
  document.querySelector("#cart-subtotal").textContent = rupiah(state.cart.reduce((total, product) => total + product.price, 0));
}

document.querySelector("#search-form").addEventListener("submit", event => { event.preventDefault(); submitSearch(document.querySelector("#search-input").value); });
document.querySelector("#mobile-search-form").addEventListener("submit", event => { event.preventDefault(); submitSearch(document.querySelector("#mobile-search-input").value); });
document.querySelector("#mobile-search-input").addEventListener("input", event => { if (!event.target.value) submitSearch(""); });
document.querySelector("#sort-select").addEventListener("change", event => { state.sort = event.target.value; renderProducts(); });
document.querySelectorAll("[data-filter]").forEach(button => button.addEventListener("click", () => setFilter(button.dataset.filter)));
document.querySelectorAll("[data-filter-trigger]").forEach(button => button.addEventListener("click", () => setFilter(button.dataset.filterTrigger)));
document.querySelector("#reset-search").addEventListener("click", () => { state.query = ""; state.filter = "Semua"; submitSearch(""); });
loadMore.addEventListener("click", () => { state.visible += 4; renderProducts(); });

productGrid.addEventListener("click", event => {
  const favoriteButton = event.target.closest("[data-favorite]");
  const addButton = event.target.closest("[data-add]");
  if (favoriteButton) {
    event.stopPropagation();
    const id = Number(favoriteButton.dataset.favorite);
    state.favorites.has(id) ? state.favorites.delete(id) : state.favorites.add(id);
    renderProducts();
    showToast(state.favorites.has(id) ? "Disimpan ke favorit" : "Dihapus dari favorit");
    return;
  }
  if (addButton) {
    event.stopPropagation();
    addToCart(Number(addButton.dataset.add));
    return;
  }
  const card = event.target.closest("[data-product]");
  if (card) openProduct(Number(card.dataset.product));
});

productGrid.addEventListener("keydown", event => {
  if ((event.key === "Enter" || event.key === " ") && event.target.matches("[data-product]")) {
    event.preventDefault();
    openProduct(Number(event.target.dataset.product));
  }
});

document.querySelector("#modal-content").addEventListener("click", event => {
  const button = event.target.closest("[data-modal-add]");
  if (button) { addToCart(Number(button.dataset.modalAdd)); closeLayers(); setTimeout(() => openLayer(cartDrawer), 120); }
});

document.querySelector("#cart-items").addEventListener("click", event => {
  const button = event.target.closest("[data-remove]");
  if (!button) return;
  state.cart.splice(Number(button.dataset.remove), 1);
  renderCart();
});

function openCart() { closeLayers(); setTimeout(() => openLayer(cartDrawer), 10); }
document.querySelector("#cart-button").addEventListener("click", openCart);
document.querySelector("#mobile-cart-button").addEventListener("click", openCart);
document.querySelector("#close-cart").addEventListener("click", closeLayers);
document.querySelector("#close-modal").addEventListener("click", closeLayers);
document.querySelector("#close-auth").addEventListener("click", closeLayers);
document.querySelector("#shop-now").addEventListener("click", closeLayers);
overlay.addEventListener("click", closeLayers);
document.addEventListener("keydown", event => { if (event.key === "Escape") closeLayers(); });

document.querySelectorAll("[data-auth]").forEach(button => button.addEventListener("click", () => {
  document.querySelector("#auth-title").textContent = button.dataset.auth === "Masuk" ? "Masuk ke CosplayNesia" : "Buat akun CosplayNesia";
  openLayer(authModal);
}));

document.querySelector("#auth-form").addEventListener("submit", async event => { 
  event.preventDefault(); 
  const email = event.target.querySelector('input[type="email"]').value;
  const password = event.target.querySelector('input[type="password"]').value;
  const isLogin = document.querySelector("#auth-title").textContent.includes("Masuk");
  
  try {
    const res = await fetch('/api/auth', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: isLogin ? 'login' : 'register', email, password })
    });
    const data = await res.json();
    if (res.ok) {
      localStorage.setItem('token', data.token);
      showToast('Berhasil ' + (isLogin ? 'masuk' : 'mendaftar'));
      closeLayers();
    } else {
      showToast(data.error || 'Terjadi kesalahan');
    }
  } catch (err) {
    showToast('Koneksi gagal');
  }
});

document.querySelector("#checkout-button").addEventListener("click", async () => {
  const token = localStorage.getItem('token');
  if (!token) {
    showToast('Silakan masuk terlebih dahulu');
    document.querySelector("#auth-title").textContent = "Masuk ke CosplayNesia";
    openLayer(authModal);
    return;
  }

  try {
    const res = await fetch('/api/checkout', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ items: state.cart })
    });
    const data = await res.json();
    if (res.ok) {
      state.cart = [];
      renderCart();
      showToast('Checkout berhasil! Pesanan #' + data.orderId + ' dibuat.');
      closeLayers();
      fetchProducts(); // Refresh stock
    } else {
      showToast(data.error || 'Checkout gagal');
    }
  } catch (err) {
    showToast('Koneksi gagal');
  }
});

fetchProducts();
renderCart();
