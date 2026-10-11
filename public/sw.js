/*
 * Dr Business Flow service worker — saves data on repeat visits and shows a friendly page offline.
 * Privacy: only the app's built code (/build/…) and the offline page are kept on the device.
 * Pages, data and anything personal are never stored; they always come from the server.
 */
const ASSETS = "dbf-assets-v1";
const OFFLINE = "dbf-offline-v1";
const MAX_ASSETS = 150;

self.addEventListener("install", (event) => {
    event.waitUntil(caches.open(OFFLINE).then((cache) => cache.add("/offline.html")));
    self.skipWaiting();
});

self.addEventListener("activate", (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => ![ASSETS, OFFLINE].includes(k)).map((k) => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

async function trim(cache) {
    const keys = await cache.keys();
    for (const key of keys.slice(0, Math.max(0, keys.length - MAX_ASSETS))) await cache.delete(key);
}

self.addEventListener("fetch", (event) => {
    const request = event.request;
    if (request.method !== "GET") return;
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) return;

    // Built code and styles: file names change with every release, so a stored copy is always right.
    if (url.pathname.startsWith("/build/")) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const stored = await cache.match(request);
                if (stored) return stored;
                const response = await fetch(request);
                if (response.ok) {
                    await cache.put(request, response.clone());
                    trim(cache);
                }
                return response;
            }),
        );
        return;
    }

    // Pages: always from the server; the offline page only when there is no connection.
    if (request.mode === "navigate") {
        event.respondWith(fetch(request).catch(() => caches.match("/offline.html")));
    }
});
