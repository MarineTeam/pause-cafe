/*
 * The side cart.
 *
 * The whole point is that a parent ordering for three children should not have
 * to walk back to the menu between each one. Adding a meal posts in the
 * background, the drawer slides in with what is in the cart so far, and the
 * menu is still there underneath to add the next name from.
 *
 * This is the only script in the app, and it is strictly an enhancement. If it
 * is not loaded, is blocked, or throws, every form it touches is an ordinary
 * form that posts and redirects to the cart page -- which is exactly how the
 * site worked before this file existed. Anything unexpected at runtime is
 * therefore handed straight back to the browser rather than reported.
 */
(function () {
	'use strict';

	var drawer = document.getElementById('side-cart');
	var scrim = document.querySelector('.side-cart__scrim');

	if (!drawer || !scrim || !window.fetch || !window.FormData) {
		return;
	}

	// Tells the stylesheet the drawer can actually be opened, so nothing is
	// styled as interactive on a page where it never will be.
	document.documentElement.classList.add('has-side-cart');

	var opener = null;

	function isOpen() {
		return drawer.classList.contains('side-cart--open');
	}

	function open() {
		drawer.classList.add('side-cart--open');
		drawer.setAttribute('aria-hidden', 'false');
		scrim.hidden = false;
		document.body.classList.add('side-cart-open');

		var close = drawer.querySelector('[data-cart-close]');

		if (close) {
			close.focus();
		}
	}

	function close() {
		drawer.classList.remove('side-cart--open');
		drawer.setAttribute('aria-hidden', 'true');
		scrim.hidden = true;
		document.body.classList.remove('side-cart-open');

		// Back to whatever opened it, so keyboard users are not dropped at the
		// top of the page having lost the dish they were looking at.
		if (opener && document.contains(opener)) {
			opener.focus();
		}

		opener = null;
	}

	function render(payload) {
		drawer.innerHTML = payload.html;

		var count = document.querySelector('[data-cart-count]');

		if (count) {
			count.textContent = payload.count ? ' (' + payload.count + ')' : '';
		}

		if (!payload.error) {
			return;
		}

		// The same refusal the page would have shown as a flash message: sold
		// out, shut, a required question left blank.
		var flash = document.createElement('div');

		flash.className = 'flash flash--error';
		flash.textContent = payload.error;

		var head = drawer.querySelector('.side-cart__head');

		if (head) {
			head.parentNode.insertBefore(flash, head.nextSibling);
		} else {
			drawer.insertBefore(flash, drawer.firstChild);
		}
	}

	function send(form, submitter) {
		var data = new FormData(form);

		// A submit button's own name and value are part of the submission only
		// when that button is the one pressed, and FormData does not know which.
		if (submitter && submitter.name) {
			data.append(submitter.name, submitter.value);
		}

		var pressed = submitter && submitter.tagName === 'BUTTON' ? submitter : null;

		if (pressed) {
			pressed.disabled = true;
		}

		fetch(form.action, {
			method: 'POST',
			body: data,
			credentials: 'same-origin',
			headers: { 'X-Requested-With': 'XMLHttpRequest' }
		}).then(function (response) {
			if (!response.ok) {
				throw new Error('unexpected status');
			}

			return response.json();
		}).then(function (payload) {
			if (pressed) {
				pressed.disabled = false;
			}

			render(payload);
			open();
		}).catch(function () {
			/*
			 * An expired token, a dropped connection, a response that is not
			 * the JSON we asked for. Submitting the form the ordinary way both
			 * recovers and shows the real reason on the page it lands on --
			 * far better than a drawer inventing an explanation.
			 */
			if (pressed) {
				pressed.disabled = false;
			}

			form.submit();
		});
	}

	document.addEventListener('submit', function (event) {
		var form = event.target;

		if (!form || form.tagName !== 'FORM') {
			return;
		}

		var action = form.getAttribute('action') || '';

		/*
		 * Adding is intercepted wherever a dish card appears; removing and
		 * splitting only inside the drawer. The cart page posts every line at
		 * once through a form of its own and is deliberately left alone -- it
		 * is a page about editing the cart, not a place to add to it.
		 */
		var handled = ('/cart/add' === action && form.classList.contains('dish__form')) ||
			(('/cart/remove' === action || '/cart/split' === action) && drawer.contains(form));

		if (!handled) {
			return;
		}

		event.preventDefault();
		opener = opener || document.activeElement;
		send(form, event.submitter);
	});

	document.addEventListener('click', function (event) {
		var closer = event.target.closest ? event.target.closest('[data-cart-close]') : null;

		if (closer) {
			event.preventDefault();
			close();

			return;
		}

		var link = event.target.closest ? event.target.closest('[data-cart-link]') : null;

		// The header link still points at /cart, so a middle-click or a right
		// click still opens the full page. A plain click opens the drawer.
		if (link && !event.metaKey && !event.ctrlKey && !event.shiftKey && 0 === event.button) {
			event.preventDefault();
			opener = link;
			open();
		}
	});

	document.addEventListener('keydown', function (event) {
		if ('Escape' === event.key && isOpen()) {
			close();
		}
	});
}());
