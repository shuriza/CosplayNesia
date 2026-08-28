var base = String(Date.now());
var password = 'SmokePass123!';
var seller = { name: 'Smoke Seller ' + base, email: 'seller-' + base + '@example.com' };
var buyer = { name: 'Smoke Buyer ' + base, email: 'buyer-' + base + '@example.com' };
var productName = 'Smoke Timeline Product ' + base;

async function request(path, options = {}) {
  var method = options.method || 'POST';
  var body = options.body;
  var token = await page.evaluate(() => document.querySelector('meta[name="csrf-token"]')?.content || '');
  return await page.evaluate(async ({ path, method, body, token }) => {
    var headers = { Accept: 'application/json' };
    if (!['GET', 'HEAD'].includes(method)) headers['X-CSRF-TOKEN'] = token;
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    var response = await fetch(path, {
      method: method,
      headers: headers,
      body: body === undefined ? undefined : JSON.stringify(body),
      credentials: 'same-origin',
    });
    var raw = await response.text();
    var data = null;
    try {
      data = raw ? JSON.parse(raw) : raw;
    } catch {
      data = raw;
    }
    return { ok: response.ok, status: response.status, data: data };
  }, { path: path, method: method, body: body, token: token });
}

function ensure(result, message) {
  if (!result.ok) {
    throw new Error(message + ' (status ' + result.status + '): ' + JSON.stringify(result.data));
  }
  return result.data;
}

async function registerUser(user) {
  return ensure(await request('/api/auth/register', {
    body: { name: user.name, email: user.email, password: password, password_confirmation: password },
  }), 'register ' + user.email);
}

async function logoutUser() {
  var result = await request('/api/auth/logout', { body: {} });
  if (result.data && result.data.csrf_token) {
    await page.evaluate((token) => {
      var meta = document.querySelector('meta[name="csrf-token"]');
      if (meta) meta.content = token;
    }, result.data.csrf_token);
  }
  return ensure(result, 'logout');
}

async function waitForSelector(selector, timeout = 5000) {
  return await page.evaluate(async ({ selector, timeout }) => {
    var start = Date.now();
    while (Date.now() - start < timeout) {
      if (document.querySelector(selector)) return true;
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error('Selector not found: ' + selector);
  }, { selector: selector, timeout: timeout });
}

async function clickButtonByText(text) {
  return await page.evaluate(async (text) => {
    var start = Date.now();
    while (Date.now() - start < 5000) {
      var button = [...document.querySelectorAll('button')].find((el) => el.textContent.trim() === text && !el.disabled && getComputedStyle(el).display !== 'none');
      if (button) {
        button.click();
        return true;
      }
      await new Promise((resolve) => setTimeout(resolve, 50));
    }
    throw new Error('Button not found: ' + text);
  }, text);
}

async function reloadAndSettle() {
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 250)));
}

async function restoreCookies(cookies) {
  for (var cookie of cookies) {
    await page.setCookie(cookie);
  }
}

await registerUser(seller);
var product = ensure(await request('/api/products', {
  body: {
    name: productName,
    category: 'Game',
    price: 125000,
    type: 'Beli',
    stock: 4,
    series: 'Smoke Series',
    size: 'M',
    city: 'Jakarta',
    image: 'https://images.unsplash.com/photo-1534528741775-53994a69daeb?auto=format&fit=crop&w=700&q=85',
    is_active: true,
  },
}), 'create product');
var sellerCookies = await page.cookies();
await logoutUser();
await registerUser(buyer);
var buyerCookies = await page.cookies();
await reloadAndSettle();

var order = ensure(await request('/api/checkout', {
  body: {
    items: [{ id: product.id, quantity: 1 }],
    recipient: { name: buyer.name, phone: '081234567890', email: buyer.email },
    address: { line1: 'Jl. Smoke Timeline 1', line2: '', city: 'Jakarta', province: 'DKI Jakarta', postal_code: '12345' },
    handoff_note: 'Smoke timeline smoke',
    idempotency_key: 'timeline-smoke-' + base,
  },
}), 'checkout');
var orderId = order.order_id;
var fulfillmentId = order.order.fulfillments[0].id;

await reloadAndSettle();
await clickButtonByText('Profil');
await waitForSelector('[data-order-detail]');
await page.evaluate(() => { document.querySelector('[data-order-detail]')?.click(); });
await waitForSelector('.activity-timeline__item');
var buyerInitial = await page.evaluate(() => [...document.querySelectorAll('.activity-timeline__item')].map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
if (buyerInitial.length !== 2) throw new Error('buyer timeline item count mismatch: ' + JSON.stringify(buyerInitial));
if (!buyerInitial.some((text) => text.includes('Checkout dibuat'))) throw new Error('buyer timeline missing checkout.created: ' + JSON.stringify(buyerInitial));
if (!buyerInitial.some((text) => text.includes('Pesanan masuk'))) throw new Error('buyer timeline missing fulfillment.received: ' + JSON.stringify(buyerInitial));
if (!buyerInitial[0].includes('Anda')) throw new Error('buyer timeline missing actor label Anda: ' + JSON.stringify(buyerInitial));

await restoreCookies(sellerCookies);
await reloadAndSettle();
await clickButtonByText('Profil');
await waitForSelector('[data-fulfillment-detail]');
await page.evaluate(() => { document.querySelector('[data-fulfillment-detail]')?.click(); });
await waitForSelector('.activity-timeline__item');
var sellerInitial = await page.evaluate(() => [...document.querySelectorAll('.activity-timeline__item')].map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
if (sellerInitial.length !== 1) throw new Error('seller timeline item count mismatch: ' + JSON.stringify(sellerInitial));
if (!sellerInitial[0].includes('Pesanan masuk')) throw new Error('seller timeline missing fulfillment.received: ' + JSON.stringify(sellerInitial));
if (!sellerInitial[0].includes('Pembeli')) throw new Error('seller timeline missing actor label Pembeli: ' + JSON.stringify(sellerInitial));

await clickButtonByText('Terima pesanan');
await page.evaluate(() => new Promise((resolve) => setTimeout(resolve, 250)));
await waitForSelector('[data-fulfillment-detail]');
await page.evaluate(() => { document.querySelector('[data-fulfillment-detail]')?.click(); });
await waitForSelector('.activity-timeline__item');
var sellerAccepted = await page.evaluate(() => [...document.querySelectorAll('.activity-timeline__item')].map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
if (sellerAccepted.length !== 2) throw new Error('seller accepted timeline item count mismatch: ' + JSON.stringify(sellerAccepted));
if (!sellerAccepted.some((text) => text.includes('Diterima penjual'))) throw new Error('seller timeline missing accepted event: ' + JSON.stringify(sellerAccepted));

await restoreCookies(buyerCookies);
await reloadAndSettle();
await clickButtonByText('Profil');
await waitForSelector('[data-order-detail]');
await page.evaluate(() => { document.querySelector('[data-order-detail]')?.click(); });
await waitForSelector('.activity-timeline__item');
var buyerAfterAccept = await page.evaluate(() => [...document.querySelectorAll('.activity-timeline__item')].map((el) => el.textContent.replace(/\s+/g, ' ').trim()));
if (buyerAfterAccept.length !== 3) throw new Error('buyer after accept timeline item count mismatch: ' + JSON.stringify(buyerAfterAccept));
if (!buyerAfterAccept.some((text) => text.includes('Diterima penjual'))) throw new Error('buyer timeline missing accepted event: ' + JSON.stringify(buyerAfterAccept));

({ orderId, fulfillmentId, buyerInitial, sellerInitial, sellerAccepted, buyerAfterAccept });
