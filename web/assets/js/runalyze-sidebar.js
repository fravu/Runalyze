/*
 * Obere Leiste mit Suchfeld und linke Icon-Leiste mit Hover-Spalte (Oktober 2026).
 * Verschiebt beim Laden die linken Menüs aus #headline in eine eigene Leiste (#rz-sidebar).
 * Siehe web/assets/css/runalyze-sidebar.css. Gilt nur ab 801px Breite,
 * darunter werden die Menüs zurückgesetzt und das mobile Menü bleibt unverändert.
 */
(function () {
	'use strict';

	var DESKTOP = '(min-width: 801px)';
	var mql = window.matchMedia ? window.matchMedia(DESKTOP) : null;
	var bar = null;
	var sidebar = null;
	var search = null;
	var moved = [];

	function isDesktop() {
		return !mql || mql.matches;
	}

	function text(el) {
		return (el.textContent || '').replace(/\s+/g, ' ').trim();
	}

	function hasIcon(el, cls) {
		return !!el.querySelector('.' + cls);
	}

	/* ---------- Beschriftungen und Rollen ---------- */
	var iconTitles = {
		'fa-plus': 'Neu',
		'fa-envelope': 'Benachrichtigungen',
		'fa-book': 'Hilfe',
		'fa-question-circle': 'Hilfe',
		'fa-user': 'Profil'
	};

	function labelItems() {
		Array.prototype.forEach.call(bar.querySelectorAll('.headline-menu, a.tab'), function (item) {
			var label = item.classList.contains('headline-menu') ? item.querySelector('.submenu-label') : item;
			var title = label ? text(label) : '';

			if (!title && label) {
				Object.keys(iconTitles).forEach(function (cls) {
					if (!title && hasIcon(label, cls)) {
						title = iconTitles[cls];
					}
				});
			} else if (label && hasIcon(label, 'fa-user')) {
				title = 'Profil (' + title + ')';
			}

			if (label && title && !label.getAttribute('title')) {
				label.setAttribute('title', title);
			}

			var submenu = item.querySelector ? item.querySelector('.submenu') : null;

			if (submenu && title && !submenu.getAttribute('data-title')) {
				submenu.setAttribute('data-title', title);
			}

			if (item.classList.contains('headline-menu') && label) {
				if (hasIcon(label, 'fa-plus')) { item.classList.add('rz-plus'); }
				if (hasIcon(label, 'fa-envelope')) { item.classList.add('rz-notify'); }
				if (hasIcon(label, 'fa-user')) { item.classList.add('rz-user'); }
				if (hasIcon(label, 'fa-book')) { item.classList.add('rz-help'); }
			} else if (item.tagName === 'A' && hasIcon(item, 'fa-question-circle')) {
				item.classList.add('rz-help');
			}
		});
	}

	/* ---------- Aufbau und Rückbau ---------- */
	function build() {
		if (sidebar) {
			return;
		}

		sidebar = document.createElement('nav');
		sidebar.id = 'rz-sidebar';

		var logo = bar.querySelector('.logo');
		var home = document.createElement('a');
		home.className = 'tab rz-home';
		home.href = logo ? logo.getAttribute('href') : '/';
		home.setAttribute('title', 'Start');
		home.innerHTML = '<i class="fa fa-home"></i>';

		if (/\/dashboard\/?$/.test(window.location.pathname) || window.location.pathname === '/') {
			home.classList.add('active');
		}

		sidebar.appendChild(home);

		Array.prototype.slice.call(bar.children).forEach(function (child) {
			var left = child.classList.contains('headline-menu') && child.classList.contains('left');
			var searchTab = child.tagName === 'A' && child.classList.contains('tab') && hasIcon(child, 'fa-search');

			if (left || searchTab) {
				moved.push({ el: child, next: child.nextSibling });
				sidebar.appendChild(child);
			}
		});

		document.body.appendChild(sidebar);
		buildSearch(logo);
	}

	function teardown() {
		if (!sidebar) {
			return;
		}

		for (var i = moved.length - 1; i >= 0; i--) {
			bar.insertBefore(moved[i].el, moved[i].next && moved[i].next.parentNode === bar ? moved[i].next : null);
		}

		moved = [];
		sidebar.parentNode.removeChild(sidebar);
		sidebar = null;

		if (search) {
			search.parentNode.removeChild(search);
			search = null;
		}
	}

	/* ---------- Schnellsuche über alle Menüeinträge ---------- */
	function menuEntries() {
		var entries = [];
		var seen = {};

		Array.prototype.forEach.call(document.querySelectorAll('#rz-sidebar a[href], #headline a[href]'), function (a) {
			var href = a.getAttribute('href');
			var name = text(a);

			if (!href || href === '#' || !name || a.classList.contains('logo') || a.classList.contains('rz-home')) {
				return;
			}

			var menu = a.closest('.headline-menu');
			var submenu = a.closest('.submenu');
			var group = submenu ? (submenu.getAttribute('data-title') || '') : '';

			if (!group && menu) {
				group = menu.querySelector('.submenu-label') ? (menu.querySelector('.submenu-label').getAttribute('title') || '') : '';
			}

			var key = name + '|' + href;

			if (!seen[key]) {
				seen[key] = true;
				entries.push({ name: name, group: group, el: a });
			}
		});

		return entries;
	}

	function buildSearch(logo) {
		search = document.createElement('div');
		search.id = 'rz-search';
		search.innerHTML =
			'<i class="fa fa-search rz-search-icon"></i>' +
			'<input type="text" autocomplete="off" spellcheck="false" aria-label="Suche" placeholder="Suche nach Tools, Einstellungen oder Seiten …">' +
			'<span class="rz-search-hint">/</span>' +
			'<ul id="rz-search-results"></ul>';

		bar.insertBefore(search, logo ? logo.nextSibling : bar.firstChild);

		var input = search.querySelector('input');
		var list = search.querySelector('#rz-search-results');
		var shown = [];
		var active = 0;

		function render() {
			var q = input.value.trim().toLowerCase();

			if (!q) {
				list.classList.remove('rz-visible');
				list.innerHTML = '';
				shown = [];

				return;
			}

			shown = menuEntries().filter(function (e) {
				return e.name.toLowerCase().indexOf(q) !== -1 || e.group.toLowerCase().indexOf(q) !== -1;
			}).slice(0, 8);

			active = 0;
			list.innerHTML = '';

			if (!shown.length) {
				var none = document.createElement('li');
				none.className = 'rz-empty';
				none.textContent = 'Nichts gefunden';
				list.appendChild(none);
			}

			shown.forEach(function (e, i) {
				var li = document.createElement('li');
				var label = document.createElement('span');
				var group = document.createElement('small');

				label.textContent = e.name;
				group.textContent = e.group;
				li.appendChild(label);
				li.appendChild(group);

				if (i === 0) {
					li.className = 'rz-active';
				}

				li.addEventListener('mousedown', function (ev) {
					ev.preventDefault();
					choose(i);
				});

				list.appendChild(li);
			});

			list.classList.add('rz-visible');
		}

		function mark() {
			Array.prototype.forEach.call(list.children, function (li, i) {
				li.classList.toggle('rz-active', i === active);
			});
		}

		function choose(i) {
			var entry = shown[i];

			if (!entry) {
				return;
			}

			input.value = '';
			render();
			input.blur();
			entry.el.click();
		}

		input.addEventListener('input', render);
		input.addEventListener('focus', render);
		input.addEventListener('blur', function () {
			list.classList.remove('rz-visible');
		});

		input.addEventListener('keydown', function (e) {
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				active = Math.min(active + 1, Math.max(shown.length - 1, 0));
				mark();
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				active = Math.max(active - 1, 0);
				mark();
			} else if (e.key === 'Enter') {
				e.preventDefault();
				choose(active);
			} else if (e.key === 'Escape') {
				input.value = '';
				render();
				input.blur();
			}
		});
	}

	/* ---------- Start ---------- */
	function init() {
		bar = document.getElementById('headline');

		if (!bar || !document.body || document.body.id !== 'home') {
			return;
		}

		labelItems();

		function apply() {
			if (isDesktop()) {
				build();
			} else {
				teardown();
			}
		}

		if (mql) {
			if (mql.addEventListener) {
				mql.addEventListener('change', apply);
			} else if (mql.addListener) {
				mql.addListener(apply);
			}
		}

		apply();

		document.addEventListener('keydown', function (e) {
			if (e.key !== '/' || e.ctrlKey || e.metaKey || e.altKey || !search) {
				return;
			}

			var t = e.target;
			var tag = t && t.tagName ? t.tagName.toLowerCase() : '';

			if (tag === 'input' || tag === 'textarea' || tag === 'select' || (t && t.isContentEditable)) {
				return;
			}

			e.preventDefault();
			search.querySelector('input').focus();
		});

		// Antippen (Touch/Tastatur): Beschriftung öffnet die Spalte, auch wenn sie einen Link enthält.
		document.addEventListener('click', function (e) {
			if (!sidebar || !e.target.closest) {
				return;
			}

			var label = e.target.closest('#rz-sidebar .headline-menu > .submenu-label');
			var open = sidebar.querySelector('.headline-menu.open');

			if (label) {
				e.preventDefault();
				e.stopPropagation();

				var menu = label.parentNode;

				if (open && open !== menu) {
					open.classList.remove('open');
				}

				menu.classList.toggle('open');

				return;
			}

			if (open) {
				open.classList.remove('open');
			}
		}, true);
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();
