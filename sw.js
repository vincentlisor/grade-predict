const CACHE = 'spcc-grades-v2';
const SHELL = [
    'index.php',
    'professor/login.php',
    'admin/login.php',
    'manifest.php',
    'assets/css/app.css',
    'assets/js/app.js',
    'assets/js/drafts.js',
    'assets/js/gradebook.js',
    'img/logo.png',
    'img/icon-192.png',
    'img/icon-512.png',
];

self.addEventListener('install', function (event) {
    event.waitUntil(
        caches.open(CACHE).then(function (cache) {
            return Promise.all(SHELL.map(function (path) {
                const url = new URL(path, self.registration.scope).href;
                return cache.add(url).catch(function () { return null; });
            }));
        }).then(function () {
            return self.skipWaiting();
        })
    );
});

self.addEventListener('activate', function (event) {
    event.waitUntil(
        caches.keys().then(function (keys) {
            return Promise.all(keys.filter(function (key) {
                return key !== CACHE;
            }).map(function (key) {
                return caches.delete(key);
            }));
        }).then(function () {
            return self.clients.claim();
        })
    );
});

function isApi(url) {
    return url.pathname.indexOf('/api/') !== -1;
}

function keepCopy(request, response) {
    if (!response || !response.ok) return false;
    const finalUrl = new URL(response.url);
    if (finalUrl.pathname.indexOf('login.php') !== -1 && request.url.indexOf('login.php') === -1) {
        return false;
    }
    return true;
}

function fromNetwork(request, timeoutMs) {
    const run = (async function () {
        const response = await fetch(request);
        if (keepCopy(request, response) && !response.redirected) {
            try {
                const headers = new Headers(response.headers);
                headers.delete('Cache-Control');
                headers.delete('Pragma');
                headers.delete('Expires');
                const stored = new Response(await response.clone().blob(), {
                    status: response.status,
                    statusText: response.statusText,
                    headers: headers,
                });
                const cache = await caches.open(CACHE);
                await cache.put(new Request(request.url, { method: 'GET' }), stored);
            } catch (error) {}
        }
        return response;
    })();
    if (!timeoutMs) return run;
    return new Promise(function (resolve, reject) {
        const timer = setTimeout(function () { reject(new Error('timeout')); }, timeoutMs);
        run.then(function (response) {
            clearTimeout(timer);
            resolve(response);
        }, function (error) {
            clearTimeout(timer);
            reject(error);
        });
    });
}

async function fromCache(request) {
    const exact = await caches.match(request);
    if (exact) return exact;
    if (request.destination === 'script' || request.destination === 'style' || request.destination === 'image') {
        return caches.match(request, { ignoreSearch: true });
    }
    const similar = await similarClass(request);
    if (similar) return similar;
    return null;
}

async function similarClass(request) {
    const url = new URL(request.url);
    if (url.pathname.indexOf('class.php') === -1) return null;
    const id = url.searchParams.get('id');
    if (!id) return null;
    const view = url.searchParams.get('view') || 'sheet';
    const period = url.searchParams.get('period') || 'prelim';
    const cache = await caches.open(CACHE);
    const keys = await cache.keys();
    for (let i = 0; i < keys.length; i += 1) {
        const cachedUrl = new URL(keys[i].url);
        if (cachedUrl.pathname.indexOf('class.php') === -1) continue;
        if (cachedUrl.searchParams.get('id') !== id) continue;
        const cachedView = cachedUrl.searchParams.get('view') || 'sheet';
        const cachedPeriod = cachedUrl.searchParams.get('period') || 'prelim';
        if (cachedView === view && cachedPeriod === period) {
            return cache.match(keys[i]);
        }
    }
    return null;
}

function offlinePage() {
    const html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title></head>'
        + '<body style="font-family:Segoe UI,sans-serif;background:#F5F7FA;color:#142B49;padding:40px;">'
        + '<h1>This page is not on this device yet</h1>'
        + '<p>Connect once and open your dashboard. Class sheets and office pages are saved on this device after that, and they open without internet.</p>'
        + '</body></html>';
    return new Response(html, { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

self.addEventListener('fetch', function (event) {
    const request = event.request;
    if (request.method !== 'GET') return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin || isApi(url)) return;

    event.respondWith((async function () {
        const cached = await fromCache(request);
        if (!self.navigator.onLine && cached) return cached;
        try {
            const timeout = cached && request.mode === 'navigate' ? 2500 : 0;
            return await fromNetwork(request, timeout);
        } catch (error) {
            if (cached) return cached;
            if (request.mode === 'navigate') return offlinePage();
            return new Response('', { status: 503 });
        }
    })());
});
