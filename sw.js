/*
 * Service worker of the Nichan web app (manifest.webmanifest).
 *  - pages always come from the network (boards change every minute); without a connection
 *    the visitor gets /offline.html instead of the browser's error page
 *  - the site's scripts, styles, fonts and icons are served from a local copy and refreshed in
 *    the background, so the app opens fast on a slow connection
 *  - posting, the admin panel, captcha and everything that isn't a plain GET is never touched
 *  - push notifications about replies are shown here (even when the site is closed)
 */
var STATIC = 'nichan-static-v5';
var OFFLINE = '/offline.html';

self.addEventListener('install', function (e) {
	e.waitUntil(caches.open(STATIC).then(function (c) {
		return c.addAll([OFFLINE, '/static/app/icon-192.png', '/stylesheets/footer.css']);
	}).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
	e.waitUntil(caches.keys().then(function (keys) {
		return Promise.all(keys.filter(function (k) { return k.indexOf('nichan-static-') === 0 && k !== STATIC; }).map(function (k) { return caches.delete(k); }));
	}).then(function () { return self.clients.claim(); }));
});

function isStatic(url) {
	return /^\/(js|stylesheets|static|images)\//.test(url.pathname) && !/\.(php|html)$/.test(url.pathname);
}

self.addEventListener('fetch', function (e) {
	var req = e.request;
	if (req.method !== 'GET') return;
	var url = new URL(req.url);
	if (url.origin !== self.location.origin) return;
	if (/^\/(imp|post|captcha)\.php/.test(url.pathname) || url.search.indexOf('?/') === 0) return;

	if (req.mode === 'navigate') {
		e.respondWith(fetch(req).catch(function () { return caches.match(OFFLINE); }));
		return;
	}
	if (!isStatic(url)) return;

	// The version is in the response URL. Keep one slot per versioned asset and
	// check the URL on every read, including when two tabs use different versions.
	var versioned = /\.(js|css)$/.test(url.pathname) && /^\?v=(?:\d+|[a-f0-9]{64})$/.test(url.search);
	var key = versioned ? new Request(url.origin + url.pathname, req) : req;
	var cache = caches.open(STATIC);
	var hit = cache.then(function (c) { return c.match(key); }).catch(function () {});
	var network = hit.then(function (saved) {
		if (versioned && saved && saved.url === req.url) return;
		return fetch(req);
	});
	e.waitUntil(network.then(function (res) {
		if (res && res.ok && res.type === 'basic' && !res.redirected && !/no-store/i.test(res.headers.get('Cache-Control') || '')) {
			// A hosting challenge can return HTML with a 200 status for a script URL.
			if (versioned) {
				var mime = (res.headers.get('Content-Type') || '').split(';')[0].trim().toLowerCase();
				if (/\.css$/.test(url.pathname) ? mime !== 'text/css' : !/^(application|text)\/(x-)?javascript$/.test(mime)) return;
			}
			var copy = res.clone();
			return cache.then(function (c) { return c.put(key, copy); });
		}
	}).catch(function () {}));
	e.respondWith(hit.then(function (saved) {
		return saved && (!versioned || saved.url === req.url) ? saved : network;
	}));
});

// Push notifications about replies to your posts (inc/nichan-push.php): the message says what to show.
self.addEventListener('push', function (e) {
	var data = {};
	try { data = e.data ? e.data.json() : {}; } catch (err) {}
	e.waitUntil(self.registration.showNotification(data.title || 'Нічан', {
		body: data.body || '',
		icon: '/static/app/icon-192.png',
		badge: '/static/app/icon-192.png',
		tag: data.tag || 'nichan',
		renotify: true,
		data: { url: data.url || '/' }
	}));
});

// a click opens the reply (in an open tab of the site if there is one)
self.addEventListener('notificationclick', function (e) {
	e.notification.close();
	var url = (e.notification.data && e.notification.data.url) || '/';
	e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
		for (var i = 0; i < list.length; i++) {
			if (new URL(list[i].url).origin === self.location.origin && 'navigate' in list[i])
				return list[i].navigate(url).then(function (c) { return c && c.focus(); }).catch(function () { return self.clients.openWindow(url); });
		}
		return self.clients.openWindow(url);
	}));
});
