/*
 * local-time.js
 * https://github.com/savetheinternet/Tinyboard/blob/master/js/local-time.js
 *
 * Released under the MIT license
 * Copyright (c) 2012 Michael Save <savetheinternet@tinyboard.org>
 * Copyright (c) 2013-2014 Marcin Łabanowski <marcin@6irc.net>
 *
 * Usage:
 *   // $config['additional_javascript'][] = 'js/jquery.min.js';
 *   $config['additional_javascript'][] = 'js/local-time.js';
 *
 */

onready(function(){
	var iso8601 = function(s) {
		s = s.replace(/\.\d\d\d+/,""); // remove milliseconds
		s = s.replace(/-/,"/").replace(/-/,"/");
		s = s.replace(/T/," ").replace(/Z/," UTC");
		s = s.replace(/([\+\-]\d\d)\:?(\d\d)/," $1$2"); // -04:00 -> -0400
		return new Date(s);
	};
	var zeropad = function(num, count) {
		return [Math.pow(10, count - num.toString().length), num].join('').substr(1);
	};

	// nichan: "(5 хвилин тому)" after the date, in whole units only; kept up to date every half a minute
	var en = window.nichan_lang === 'en';
	var units = en ? [
		['second', 'seconds'], ['minute', 'minutes'], ['hour', 'hours'], ['day', 'days'], ['week', 'weeks'], ['month', 'months'], ['year', 'years']
	] : [
		['секунду', 'секунди', 'секунд'], ['хвилину', 'хвилини', 'хвилин'], ['годину', 'години', 'годин'], ['день', 'дні', 'днів'],
		['тиждень', 'тижні', 'тижнів'], ['місяць', 'місяці', 'місяців'], ['рік', 'роки', 'років']
	];
	var plural = function(n, forms) {
		if (en)
			return forms[n === 1 ? 0 : 1];
		var d = n % 10, h = n % 100;
		return forms[d === 1 && h !== 11 ? 0 : d >= 2 && d <= 4 && (h < 10 || h >= 20) ? 1 : 2];
	};
	var ago = function(t) {
		var s = Math.max(0, Math.floor((Date.now() - t.getTime()) / 1000)), n, u;
		if (s < 60) { n = s; u = 0; }
		else if (s < 3600) { n = Math.floor(s / 60); u = 1; }
		else if (s < 86400) { n = Math.floor(s / 3600); u = 2; }
		else if (s < 7 * 86400) { n = Math.floor(s / 86400); u = 3; }
		else if (s < 30 * 86400) { n = Math.floor(s / (7 * 86400)); u = 4; }
		else if (s < 365 * 86400) { n = Math.floor(s / (30 * 86400)); u = 5; }
		else { n = Math.floor(s / (365 * 86400)); u = 6; }
		return n + ' ' + plural(n, units[u]) + (en ? ' ago' : ' тому');
	};
	var update_ago = function() {
		var spans = document.querySelectorAll('time[data-local] .nichan-ago');
		for (var i = 0; i < spans.length; i++)
			spans[i].textContent = '(' + ago(iso8601(spans[i].parentNode.getAttribute('datetime'))) + ')';
	};
	setInterval(update_ago, 30000);

	var do_localtime = function(elem) {	
		var times = elem.getElementsByTagName('time');
	
		for(var i = 0; i < times.length; i++) {
			if(typeof times[i].getAttribute('data-local') == 'undefined')
				continue;
		
			var t = iso8601(times[i].getAttribute('datetime'));
		
		
			times[i].setAttribute('data-local', 'true');
			times[i].innerHTML =
				// date
				zeropad(t.getDate(), 2) + "/" + zeropad(t.getMonth() + 1, 2) + "/" + t.getFullYear().toString().substring(2) +
				" (" + [_("Нд"), _("Пн"), _("Вт"), _("Ср"), _("Чт"), _("Пт"), _("Сб"), _("Нд")][t.getDay()]  + ") " +
				// time
				zeropad(t.getHours(), 2) + ":" + zeropad(t.getMinutes(), 2) + ":" + zeropad(t.getSeconds(), 2) +
				' <span class="nichan-ago">(' + ago(t) + ')</span>';
		};
	};

	do_localtime(document);
	
	if (window.jQuery) {
		// allow to work with auto-reload.js, etc.
		$(document).bind('new_post', function(e, post) {
			do_localtime(post);
		});
	}
});
