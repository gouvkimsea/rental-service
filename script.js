let initialCart = [];
try {
  const saved = localStorage.getItem('joul_cart');
  if (saved) initialCart = JSON.parse(saved);
} catch (e) {}
const cart = new Map(initialCart); // key: productId, value: { id, name, price, quantity }
const currency = new Intl.NumberFormat('en-US', {
  style: 'currency',
  currency: 'USD',
  maximumFractionDigits: 0,
});

const cartPanel = document.querySelector('[data-cart-panel]');
const cartOverlay = document.querySelector('[data-cart-overlay]');
const cartItems = document.querySelector('[data-cart-items]');
const cartCount = document.querySelector('[data-cart-count]');
const cartTotal = document.querySelector('[data-cart-total]');
const emptyMessage = document.querySelector('[data-cart-empty]');

// Date inputs (if present on the page)
const startDateInput = document.getElementById('cart-start-date');
const endDateInput = document.getElementById('cart-end-date');
const daysMsg = document.getElementById('cart-days-msg');

if (startDateInput && endDateInput) {
    const tzOffset = (new Date()).getTimezoneOffset() * 60000;
    const localISOTime = (new Date(Date.now() - tzOffset)).toISOString().slice(0, 10);
    startDateInput.min = localISOTime;
    endDateInput.min = localISOTime;
}

if (startDateInput) startDateInput.addEventListener('change', () => {
    if (endDateInput) {
        if (!endDateInput.value || startDateInput.value > endDateInput.value) {
            endDateInput.value = startDateInput.value;
        }
    }
    renderCart();
});
if (endDateInput) endDateInput.addEventListener('change', () => {
    if (startDateInput) {
        if (!startDateInput.value || endDateInput.value < startDateInput.value) {
            startDateInput.value = endDateInput.value;
        }
    }
    renderCart();
});

function openCart() {
  cartPanel.classList.add('is-open');
  cartOverlay.classList.add('is-open');
  cartPanel.setAttribute('aria-hidden', 'false');
}

function closeCart() {
  cartPanel.classList.remove('is-open');
  cartOverlay.classList.remove('is-open');
  cartPanel.setAttribute('aria-hidden', 'true');
}

function addToCart(id, name, price) {
  const currentItem = cart.get(id) || { id, name, price: Number(price), quantity: 0 };
  currentItem.quantity += 1;
  cart.set(id, currentItem);
  renderCart();
  openCart();
}

function changeQuantity(id, amount) {
  const item = cart.get(id);
  if (!item) return;
  item.quantity += amount;
  if (item.quantity <= 0) {
    cart.delete(id);
  } else {
    cart.set(id, item);
  }
  renderCart();
}

function createCartItem(item) {
  const row = document.createElement('article');
  row.className = 'cart-item';

  const details = document.createElement('div');
  const name = document.createElement('p');
  const meta = document.createElement('p');
  name.className = 'cart-item__name';
  name.textContent = item.name;
  meta.className = 'cart-item__meta';
  meta.textContent = currency.format(item.price) + ' x ' + item.quantity;
  details.append(name, meta);

  const actions = document.createElement('div');
  const decrease = document.createElement('button');
  const quantity = document.createElement('span');
  const increase = document.createElement('button');
  actions.className = 'cart-item__actions';
  decrease.type = 'button';
  decrease.textContent = '-';
  decrease.dataset.decrease = item.id;
  decrease.setAttribute('aria-label', 'Remove one ' + item.name);
  quantity.textContent = item.quantity;
  increase.type = 'button';
  increase.textContent = '+';
  increase.dataset.increase = item.id;
  increase.setAttribute('aria-label', 'Add one ' + item.name);
  actions.append(decrease, quantity, increase);
  row.append(details, actions);
  return row;
}

function renderCart() {
  let days = 1;
  if (startDateInput && endDateInput && startDateInput.value && endDateInput.value) {
      const d1 = new Date(startDateInput.value);
      const d2 = new Date(endDateInput.value);
      const diffTime = d2 - d1;
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1; // +1 to include start day
      if (diffDays > 0) days = diffDays;
  }
  if (daysMsg) {
      daysMsg.textContent = (startDateInput && startDateInput.value && endDateInput && endDateInput.value && days > 0) 
          ? `${days} day(s) selected` 
          : 'Please select rental dates';
      daysMsg.style.color = (startDateInput && startDateInput.value && endDateInput && endDateInput.value) ? '#666' : '#a72d25';
  }

  cartItems.innerHTML = '';
  let totalItems = 0;
  let totalPrice = 0;
  cart.forEach((item) => {
    totalItems += item.quantity;
    totalPrice += (item.price * item.quantity) * days;
    cartItems.append(createCartItem(item));
  });
  cartCount.textContent = totalItems;
  if (cartTotal) cartTotal.textContent = currency.format(totalPrice);
  if (emptyMessage) emptyMessage.hidden = cart.size > 0;
  
  // Persist cart to localStorage
  localStorage.setItem('joul_cart', JSON.stringify(Array.from(cart.entries())));
}

document.querySelectorAll('[data-add-to-cart]').forEach((button) => {
  button.addEventListener('click', () => {
    addToCart(
      button.dataset.productId,
      button.dataset.name,
      button.dataset.price
    );
  });
});

document.querySelector('[data-open-cart]').addEventListener('click', openCart);
document.querySelector('[data-close-cart]').addEventListener('click', closeCart);
cartOverlay.addEventListener('click', closeCart);

cartItems.addEventListener('click', (event) => {
  const decrease = event.target.closest('[data-decrease]');
  const increase = event.target.closest('[data-increase]');
  if (decrease) changeQuantity(decrease.dataset.decrease, -1);
  if (increase) changeQuantity(increase.dataset.increase, 1);
});

document.querySelector('[data-checkout]').addEventListener('click', async () => {
  if (cart.size === 0) {
    alert('Your cart is empty.');
    return;
  }

  const startDate = startDateInput ? startDateInput.value : null;
  const endDate = endDateInput ? endDateInput.value : null;

  if (startDateInput && endDateInput && (!startDate || !endDate)) {
      alert('Rental dates are required.');
      return;
  }

  // Build cart object { productId: quantity }
  const cartData = {};
  cart.forEach((item) => {
    cartData[item.id] = item.quantity;
  });

  try {
    const response = await fetch('checkout.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ cart: cartData, start_date: startDate, end_date: endDate })
    });
    const result = await response.json();
    if (result.success) {
      alert(`Order #${result.order_id} placed successfully!`);
      cart.clear();
      renderCart();
      closeCart();
    } else {
      if (response.status === 401 || result.message === 'Please log in first.') {
        alert('You need to log in to complete your booking.');
        window.location.href = 'login.php';
      } else {
        alert(result.message || 'Checkout failed.');
      }
    }
  } catch (err) {
    alert('Network error. Please try again.');
  }
});

document.addEventListener('keydown', (event) => {
  if (event.key === 'Escape') closeCart();
});

renderCart();