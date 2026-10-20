/*
 * Seitenleiste: schmal mit Icons, per Klick aufklappen (Oktober 2026).
 * Siehe web/assets/css/runalyze-sidebar.css. Gilt nur ab 801px Breite, darunter bleibt das mobile Menü.
 */
(function () {
	'use strict';

	var EXPANDED = 'sidebar-expanded';
	var OPEN = 'open';

	function isDesktop() {
		return !window.matchMedia || window.matchMedia('(min-width: 801px)').matches;
	}

	function init() {
		var bar = document.getElementById('headline');

		if (!bar || !document.body || document.body.id !== 'home') {
			return;
		}

		var toggle = document.createElement('button');
		toggle.type = 'button';
		toggle.className = 'sidebar-toggle';
		toggle.setAttribute('aria-label', 'Menü ein- oder ausklappen');
		toggle.innerHTML = '<i class="fa fa-angle-double-right"></i>';
		var logo = bar.querySelector('.logo');
		bar.insertBefore(toggle, logo ? logo.nextSibling : bar.firstChild);

		var iconTitles = {
			'fa-plus': 'Neu',
			'fa-envelope': 'Benachrichtigungen',
			'fa-book': 'Hilfe',
			'fa-question-circle': 'Hilfe'
		};

		Array.prototype.forEach.call(bar.querySelectorAll('.submenu-label, a.tab'), function (el) {
			var text = (el.textContent || '').replace(/\s+/g, ' ').trim();

			if (!text) {
				// Nur-Icon-Einträge bekommen ihren Namen aus dem Icon, für die ausgeklappte Ansicht.
				Object.keys(iconTitles).forEach(function (cls) {
					if (!text && el.querySelector('.' + cls)) {
						text = iconTitles[cls];
						el.setAttribute('data-sidebar-label', text);
					}
				});
			}

			if (text && !el.getAttribute('title')) {
				el.setAttribute('title', text);
			}
		});

		function closeMenus(except) {
			Array.prototype.forEach.call(bar.querySelectorAll('.headline-menu.' + OPEN), function (m) {
				if (m !== except) {
					m.classList.remove(OPEN);
				}
			});
		}

		function collapse() {
			bar.classList.remove(EXPANDED);
			closeMenus(null);
		}

		document.addEventListener('click', function (e) {
			if (!isDesktop()) {
				return;
			}

			var target = e.target;

			if (!target.closest) {
				return;
			}

			if (target.closest('.sidebar-toggle')) {
				e.preventDefault();

				if (bar.classList.contains(EXPANDED)) {
					collapse();
				} else {
					bar.classList.add(EXPANDED);
				}

				return;
			}

			var label = target.closest('#headline .headline-menu > .submenu-label');

			if (label) {
				// Die Beschriftung öffnet nur das Untermenü, auch wenn sie einen Link enthält.
				e.preventDefault();
				e.stopPropagation();

				var menu = label.parentNode;
				var wasOpen = menu.classList.contains(OPEN) && bar.classList.contains(EXPANDED);

				closeMenus(menu);
				bar.classList.add(EXPANDED);
				menu.classList[wasOpen ? 'remove' : 'add'](OPEN);

				return;
			}

			if (target.closest('#headline .submenu a') || target.closest('#headline a.tab')) {
				collapse();

				return;
			}

			if (!target.closest('#headline')) {
				collapse();
			}
		}, true);

		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' || e.keyCode === 27) {
				collapse();
			}
		});
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
