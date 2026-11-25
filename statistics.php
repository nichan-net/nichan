<?php
// Site statistics: the board speed table (same as on the front page) and posts per day for the last
// 31 days as stacked columns, one colour per public board. Drawn by the page itself, no external scripts.
$stats = null;
if (isset($_GET['json'])) {
	require_once __DIR__ . '/inc/nichan-stats.php';
	$stats = nichan_stats_cached('speed', NICHAN_STATS_MAX_AGE, null);
}
if ($stats === null) {
	require_once __DIR__ . '/inc/functions.php';
	$stats = nichan_stats();
}

// statistics.php?json=1: fresh numbers for the static pages (front page table, board footers),
// filled in by js/nichan.js after the page loads, so they don't wait for the next rebuild.
if (isset($_GET['json'])) {
	$boards = array();
	foreach ($stats['boards'] as $uri => $b)
		$boards[$uri] = array('per_hour' => $b['per_hour'], 'month_posters' => $b['month_posters']);
	// (the numbers themselves are cached for 5 minutes, see nichan_stats())
	header('Content-Type: application/json; charset=utf-8');
	header('Cache-Control: private, max-age=60');
	echo json_encode(array('generated' => $stats['generated'], 'boards' => $boards, 'table' => nichan_stats_speed_table($stats)));
	exit;
}

header('Cache-Control: no-cache');
$selected =isset($_GET['board']) && is_string($_GET['board']) && isset($stats['boards'][$_GET['board']]) ? $_GET['board'] : false;

// Colour follows the board (fixed order of the public boards), never its rank or visibility.
// Validated categorical palette (dark surface); boards past the 8th share a neutral grey.
$palette = array('#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767');
$series = array();
$i = 0;
foreach ($stats['boards'] as $uri => $b) {
	$series[] = array(
		'uri' => $uri,
		'title' => $b['title'],
		'color' => isset($palette[$i]) ? $palette[$i] : '#8a8a8a',
		'daily' => array_values($b['daily']),
		'total' => array_sum($b['daily']),
	);
	$i++;
}
$days = array_map(function ($d) { return date('d.m', strtotime($d)); }, $stats['days']);
$first_day = date('d.m.Y', strtotime(reset($stats['days'])));
$last_day = date('d.m.Y', strtotime(end($stats['days'])));
$totals = nichan_site_totals(nichan_front_excluded());

ob_start();
?>
<style>
	.nichan-stats { max-width: 1100px; margin: 0 auto; padding: 0 8px; }
	.nichan-stats h2 { margin: 1.2em 0 .4em; }
	table.nichan-speed, table.nichan-daily { border-collapse: collapse; margin: 0 auto; }
	table.nichan-speed td, table.nichan-speed th, table.nichan-daily td, table.nichan-daily th { border: 1px solid; border-color: inherit; padding: 3px 8px; }
	table.nichan-speed td:not(:first-child), table.nichan-daily td { text-align: center; }
	table.nichan-speed tfoot td { font-weight: bold; border-top-width: 2px; }
	.nichan-table-scroll { overflow-x: auto; }
	#nichan-chart { position: relative; }
	#nichan-chart svg { width: 100%; height: auto; display: block; }
	#nichan-chart .grid { stroke: currentColor; stroke-opacity: .12; }
	#nichan-chart .baseline { stroke: currentColor; stroke-opacity: .4; }
	#nichan-chart .axis { fill: currentColor; fill-opacity: .7; font: 11px sans-serif; }
	#nichan-chart .col-hit { fill: transparent; }
	#nichan-chart .col.active .col-hit { fill: currentColor; fill-opacity: .06; }
	#nichan-tip { position: absolute; pointer-events: none; display: none; min-width: 150px; padding: 6px 9px; font-size: 12px;
		background: rgba(20, 20, 20, .95); color: #eee; border: 1px solid rgba(128, 128, 128, .6); border-radius: 4px; z-index: 5; }
	#nichan-tip .row { display: flex; justify-content: space-between; gap: 14px; }
	#nichan-tip i { display: inline-block; width: 9px; height: 9px; border-radius: 2px; margin-right: 5px; }
	.nichan-legend { display: flex; flex-wrap: wrap; gap: 6px 8px; justify-content: center; margin: 10px 0 4px; padding: 0; list-style: none; }
	.nichan-legend button { font: inherit; cursor: pointer; background: transparent; color: inherit; border: 1px solid rgba(128, 128, 128, .5);
		border-radius: 14px; padding: 4px 10px; display: inline-flex; align-items: center; gap: 6px; }
	.nichan-legend button i { width: 11px; height: 11px; border-radius: 3px; display: inline-block; }
	.nichan-legend button[aria-pressed="false"] { opacity: .45; text-decoration: line-through; }
	.nichan-legend button[aria-pressed="false"] i { background: transparent !important; outline: 1px solid currentColor; }
	.nichan-legend-actions { text-align: center; font-size: 90%; }
	.nichan-legend-actions a { cursor: pointer; }
	@media (max-width: 600px) {
		table.nichan-speed { font-size: 90%; }
		table.nichan-speed td, table.nichan-speed th { padding: 2px 4px; }
	}
</style>
<div class="nichan-stats">
	<p>Усього повідомлень: <strong><?= number_format($totals['total_posts']) ?></strong> · Унікальних дописувачів: <?= number_format($totals['unique_posters']) ?> · Активного контенту: <?= format_bytes($totals['active_content']) ?></p>
	<p class="nichan-online" style="display:none">Зараз на сайті: <strong data-online="site"></strong>. За останні 24 години: <strong data-online="site24"></strong></p>

	<h2>Графік кількості нових дописів</h2>
	<div id="nichan-chart" role="img" aria-label="Повідомлень за день за останні 31 день по дошках">
		<noscript><p>Графік потребує JavaScript; ті самі числа є в таблиці нижче.</p></noscript>
		<div id="nichan-tip"></div>
	</div>
	<ul class="nichan-legend" id="nichan-legend"></ul>
	<p class="nichan-legend-actions"><a id="nichan-all">показати всі</a> · <a id="nichan-none">сховати всі</a></p>
	<p class="unimportant">Дані з <?= $first_day ?> по <?= $last_day ?>. Останнє оновлення о <?= date('H:i', $stats['generated']) ?>.</p>

	<h2>Швидкість зараз</h2>
	<div class="nichan-table-scroll"><?= nichan_stats_speed_table($stats) ?></div>
	<details>
		<summary>Кількість повідомлень по днях</summary>
		<div class="nichan-table-scroll">
		<table class="nichan-daily">
			<tr><th>День</th><?php foreach ($series as $s): ?><th>/<?= htmlspecialchars($s['uri']) ?>/</th><?php endforeach; ?><th>Усього</th></tr>
			<?php foreach (array_reverse(array_keys($days)) as $d): ?>
			<tr><td><?= $days[$d] ?></td><?php $sum = 0; foreach ($series as $s): $sum += $s['daily'][$d]; ?><td><?= $s['daily'][$d] ?></td><?php endforeach; ?><td><b><?= $sum ?></b></td></tr>
			<?php endforeach; ?>
		</table>
		</div>
	</details>
</div>
<script>
(function () {
	var series = <?= json_encode($series, JSON_UNESCAPED_UNICODE) ?>;
	var days = <?= json_encode($days) ?>;
	var selected = <?= json_encode($selected) ?>;
	var on = {};
	series.forEach(function (s) { on[s.uri] = !selected || s.uri === selected; });

	var box = document.getElementById('nichan-chart'), tip = document.getElementById('nichan-tip');
	var legend = document.getElementById('nichan-legend');
	var NS = 'http://www.w3.org/2000/svg';
	var W = 900, H = 300, L = 40, R = 8, T = 12, B = 28;

	function el(name, attrs, parent) {
		var e = document.createElementNS(NS, name);
		for (var k in attrs) e.setAttribute(k, attrs[k]);
		if (parent) parent.appendChild(e);
		return e;
	}
	function niceStep(max) {
		var step = Math.pow(10, Math.floor(Math.log(Math.max(1, max)) / Math.LN10));
		var m = [1, 2, 2.5, 5, 10];
		for (var i = 0; i < m.length; i++) if (step * m[i] * 4 >= max) return step * m[i];
		return step * 10;
	}

	function draw() {
		hide();
		var old = box.querySelector('svg');
		if (old) box.removeChild(old);
		var visible = series.filter(function (s) { return on[s.uri]; });
		var totals = days.map(function (_, d) { return visible.reduce(function (a, s) { return a + s.daily[d]; }, 0); });
		var max = Math.max.apply(null, totals.concat([1]));
		var step = niceStep(max), top = Math.max(step, Math.ceil(max / step) * step);
		var pw = W - L - R, ph = H - T - B, slot = pw / days.length, bw = Math.min(18, Math.max(3, slot - 3));
		var svg = el('svg', {viewBox: '0 0 ' + W + ' ' + H});
		box.insertBefore(svg, tip);

		for (var v = 0; v <= top; v += step) {
			var gy = T + ph - v / top * ph;
			el('line', {'class': 'grid', x1: L, x2: W - R, y1: gy, y2: gy}, svg);
			el('text', {'class': 'axis', x: L - 6, y: gy + 4, 'text-anchor': 'end'}, svg).textContent = v;
		}

		days.forEach(function (day, d) {
			var g = el('g', {'class': 'col'}, svg);
			var x = L + d * slot + (slot - bw) / 2, base = T + ph;
			el('rect', {'class': 'col-hit', x: L + d * slot, y: T, width: slot, height: ph}, g);
			var segs = visible.filter(function (s) { return s.daily[d] > 0; });
			segs.forEach(function (s, i) {
				var h = s.daily[d] / top * ph;
				var last = i === segs.length - 1;
				// 1px surface gap between stacked segments; only the top segment gets rounded corners.
				var gh = Math.max(1, h - (last ? 0 : 1));
				var y = base - h;
				if (last && gh > 3) {
					var r = Math.min(3, bw / 2);
					el('path', {fill: s.color, d: 'M' + x + ' ' + (y + gh) + 'V' + (y + r) + 'Q' + x + ' ' + y + ' ' + (x + r) + ' ' + y +
						'H' + (x + bw - r) + 'Q' + (x + bw) + ' ' + y + ' ' + (x + bw) + ' ' + (y + r) + 'V' + (y + gh) + 'Z'}, g);
				} else {
					el('rect', {fill: s.color, x: x, y: y, width: bw, height: gh}, g);
				}
				base -= h;
			});
			if (d % 5 === 0 || d === days.length - 1)
				el('text', {'class': 'axis', x: x + bw / 2, y: H - 9, 'text-anchor': 'middle'}, svg).textContent = day;

			g.addEventListener('mouseenter', function () { show(d, g); });
			g.addEventListener('mouseleave', hide);
			g.addEventListener('click', function () { show(d, g); });
		});
		el('line', {'class': 'baseline', x1: L, x2: W - R, y1: T + ph, y2: T + ph}, svg);
	}

	function show(d, g) {
		hide();
		g.classList.add('active');
		var rows = series.filter(function (s) { return on[s.uri] && s.daily[d] > 0; });
		var sum = rows.reduce(function (a, s) { return a + s.daily[d]; }, 0);
		tip.innerHTML = '<b>' + days[d] + '</b> — ' + sum + (rows.length ? '' : '<br><span style="opacity:.7">немає дописів</span>') +
			rows.map(function (s) {
				return '<div class="row"><span><i style="background:' + s.color + '"></i>/' + s.uri + '/</span><span>' + s.daily[d] + '</span></div>';
			}).join('');
		tip.style.display = 'block';
		// k: screen px per CSS px of the box (not 1 when «Розмір тексту» zooms the page)
		var r = g.getBoundingClientRect(), b = box.getBoundingClientRect(), k = b.width / box.offsetWidth || 1;
		var left = (r.left - b.left + r.width / 2) / k - tip.offsetWidth / 2;
		tip.style.left = Math.max(0, Math.min(box.offsetWidth - tip.offsetWidth, left)) + 'px';
		tip.style.top = '0px';
	}
	function hide() {
		tip.style.display = 'none';
		Array.prototype.forEach.call(box.querySelectorAll('.col.active'), function (c) { c.classList.remove('active'); });
	}

	series.forEach(function (s) {
		var li = document.createElement('li'), btn = document.createElement('button');
		btn.type = 'button';
		btn.innerHTML = '<i style="background:' + s.color + '"></i>/' + s.uri + '/ – ' + s.title.replace(/</g, '&lt;') +
			' <span class="unimportant">(' + s.total + ')</span>';
		btn.setAttribute('aria-pressed', on[s.uri] ? 'true' : 'false');
		btn.addEventListener('click', function () {
			on[s.uri] = !on[s.uri];
			btn.setAttribute('aria-pressed', on[s.uri] ? 'true' : 'false');
			draw();
		});
		li.appendChild(btn);
		legend.appendChild(li);
	});
	function setAll(v) {
		series.forEach(function (s) { on[s.uri] = v; });
		Array.prototype.forEach.call(legend.querySelectorAll('button'), function (b) { b.setAttribute('aria-pressed', v ? 'true' : 'false'); });
		draw();
	}
	document.getElementById('nichan-all').addEventListener('click', function () { setAll(true); });
	document.getElementById('nichan-none').addEventListener('click', function () { setAll(false); });
	draw();
})();
</script>
<?php
$body = ob_get_clean();

echo Element('portal.html', array(
	'portal_section' => 'statistics',
	'config' => $config,
	'title' => 'Статистика',
	'body' => $body,
));
