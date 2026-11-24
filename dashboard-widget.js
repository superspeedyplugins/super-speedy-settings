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
