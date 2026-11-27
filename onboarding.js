/* Super Speedy screen (settings 1.15.3). No network of its own: it only
   - gives each hero video its source when it scrolls into view, and plays it muted;
   - keeps the trial form's "Exactly what Submit sends" preview in step with the fields.
   The form itself is an ordinary POST to wp-admin, sent only when Submit is pressed. */
(function () {
	'use strict';

	// On a phone the tab strip scrolls sideways: bring the current tab into view.
	var current = document.querySelector('.ssp-ob-nav .is-current');
	if (current && current.parentNode.scrollWidth > current.parentNode.clientWidth) {
		var navBox = current.parentNode.getBoundingClientRect();
		var tabBox = current.getBoundingClientRect();
		current.parentNode.scrollLeft += (tabBox.left - navBox.left) - (navBox.width - tabBox.width) / 2;
	}

	var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
	var videos = document.querySelectorAll('video.ssp-ob-video[data-src]');

	function start(video) {
		if (!video.getAttribute('src')) {
			video.setAttribute('src', video.getAttribute('data-src'));
		}
		if (reduce) {
			video.setAttribute('controls', '');
			return;
		}
		var p = video.play();
		if (p && p.catch) {
			p.catch(function () { video.setAttribute('controls', ''); });
		}
	}

	if (videos.length) {
		if ('IntersectionObserver' in window) {
			var io = new IntersectionObserver(function (entries) {
				entries.forEach(function (e) {
					if (e.isIntersecting) {
						start(e.target);
					} else if (!e.target.paused) {
						e.target.pause();
					}
				});
			}, { rootMargin: '200px 0px' });
			Array.prototype.forEach.call(videos, function (v) { io.observe(v); });
		} else {
			Array.prototype.forEach.call(videos, start);
		}
	}

	// Offer countdowns on Deals. Local clock only.
	var clocks = document.querySelectorAll('.ssp-ob-countdown[data-ssw-end]');
	if (clocks.length) {
		var pad = function (n) { return (n < 10 ? '0' : '') + n; };
		var tick = function () {
			var now = Math.floor(Date.now() / 1000);
			Array.prototype.forEach.call(clocks, function (c) {
				var left = Math.max(0, parseInt(c.getAttribute('data-ssw-end'), 10) - now);
				var parts = { d: Math.floor(left / 86400), h: Math.floor(left % 86400 / 3600), m: Math.floor(left % 3600 / 60), s: left % 60 };
				Array.prototype.forEach.call(c.querySelectorAll('[data-ssw-unit]'), function (cell) {
					var u = cell.getAttribute('data-ssw-unit');
					cell.textContent = u === 'd' ? String(parts.d) : pad(parts[u]);
				});
			});
		};
		tick();
		setInterval(tick, 1000);
	}

	var form = document.querySelector('form.ssp-ob-trial');
	if (!form) {
		return;
	}
	var pre = form.querySelector('.ssp-ob-preview');
	var facts = {};
	try { facts = JSON.parse(form.getAttribute('data-facts') || '{}'); } catch (err) { facts = {}; }

	function val(name) {
		var el = form.elements[name];
		return el ? String(el.value || '').trim() : '';
	}

	function payload() {
		var plugins = [];
		Array.prototype.forEach.call(form.querySelectorAll('input[name="plugins[]"]:checked'), function (el) {
			if (plugins.indexOf(el.value) === -1) {
				plugins.push(el.value);
			}
		});
		var include = form.elements.include_facts && form.elements.include_facts.checked;
		return {
			email: val('email'),
			name: val('name').slice(0, 100),
			site_url: form.getAttribute('data-site-url'),
			plugins: plugins,
			requirements: val('requirements').slice(0, 2000),
			site_facts: include ? facts : {}
		};
	}

	function render() {
		if (pre) {
			pre.textContent = JSON.stringify(payload(), null, 4);
		}
	}

	form.addEventListener('input', render);
	form.addEventListener('change', render);
	render();
}());
