/* Super Speedy Plugins dashboard widget: the offer countdown. Local only, no requests. */
(function () {
	var boxes = document.querySelectorAll('#ssp_dashboard_news [data-ssw-end]');
	if (!boxes.length) { return; }
	function pad(n) { return (n < 10 ? '0' : '') + n; }
	function tick() {
		var now = Math.floor(Date.now() / 1000);
		for (var i = 0; i < boxes.length; i++) {
			var left = Math.max(0, parseInt(boxes[i].getAttribute('data-ssw-end'), 10) - now);
			var parts = { d: Math.floor(left / 86400), h: Math.floor(left % 86400 / 3600), m: Math.floor(left % 3600 / 60), s: left % 60 };
			var cells = boxes[i].querySelectorAll('[data-ssw-unit]');
			for (var j = 0; j < cells.length; j++) {
				var unit = cells[j].getAttribute('data-ssw-unit');
				cells[j].textContent = unit === 'd' ? String(parts.d) : pad(parts[unit]);
			}
		}
	}
	tick();
	setInterval(tick, 1000);
})();

/* Narrow screens only. The server puts the box, by WordPress's own placement, in the first
   dashboard column with no visible box. Core shows 1 to 4 columns depending on width (or the
   user's Screen Options choice), so on a narrower screen that column may not be a column of its
   own. Then, and only while the user has never dragged the box, move it to the top of the first
   empty visible column, or of the rightmost one. It is not saved: a drag saves as normal. */
(function () {
	function place() {
		var box = document.getElementById('ssp_dashboard_news');
		var holder = document.getElementById('dashboard-widgets');
		var inner = box && box.querySelector('.ssw');
		if (!box || !holder || !inner || inner.getAttribute('data-ssw-placed') === '1') { return; }
		var chosen = box.closest('.postbox-container');
		var at = chosen ? parseInt(chosen.id.replace('postbox-container-', ''), 10) : 0;
		var pref = holder.className.match(/columns-(\d)/);
		var mq = function (q) { return window.matchMedia && window.matchMedia(q).matches; };
		var shown = pref ? parseInt(pref[1], 10) : (mq('(min-width: 1801px)') ? 4 : mq('(min-width: 1500px)') ? 3 : mq('(min-width: 800px)') ? 2 : 1);
		if (!at || at <= shown) { return; }
		var target = null;
		for (var k = 1; k <= shown && !target; k++) {
			var sortable = document.querySelector('#postbox-container-' + k + ' .meta-box-sortables');
			if (!sortable) { continue; }
			var others = Array.prototype.filter.call(sortable.children, function (el) {
				return el !== box && el.classList.contains('postbox') && el.getClientRects().length > 0;
			});
			if (!others.length) { target = sortable; }
		}
		target = target || document.querySelector('#postbox-container-' + shown + ' .meta-box-sortables');
		if (!target) { return; }
		target.insertBefore(box, target.firstChild);
		if (window.postboxes && typeof window.postboxes._mark_area === 'function') { window.postboxes._mark_area(); }
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', place); } else { place(); }
})();
