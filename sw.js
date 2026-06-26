/**
 * School Attendance Verification System
 * sw.js - Service Worker for PWA offline support and caching
 */

const CACHE_NAME = 'attendance-pwa-v2';

// Core assets to cache for offline access
const PRECACHE_ASSETS = [
    '/lecturer_app.php',
    '/login.php',
    '/manifest.json',
    '/icons/icon-192.png',
    '/icons/icon-512.png',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css',
    'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js'
];

// Install event: pre-cache all core assets
self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_NAME).then((cache) => {
            console.log('[SW] Pre-caching core assets...');
            return cache.addAll(PRECACHE_ASSETS);
        }).then(() => {
            return self.skipWaiting(); // Activate immediately without waiting
        })
    );
});

// Activate event: delete old cache versions
self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((cacheNames) => {
            return Promise.all(
                cacheNames
                    .filter((name) => name !== CACHE_NAME)
                    .map((name) => {
                        console.log('[SW] Removing old cache:', name);
                        return caches.delete(name);
                    })
            );
        }).then(() => {
            return self.clients.claim(); // Take control immediately
        })
    );
});

// Fetch event: Network-first strategy with cache fallback
self.addEventListener('fetch', (event) => {
    // Skip non-GET requests and cross-origin API calls
    if (event.request.method !== 'GET') return;

    // API calls (ping, submit) must always go to the network — never cache
    const url = new URL(event.request.url);
    const skipCachePatterns = ['api_submit_attendance', 'api_ping_location', 'logout'];
    if (skipCachePatterns.some((p) => url.pathname.includes(p))) {
        return; // Let browser handle it normally
    }

    event.respondWith(
        fetch(event.request)
            .then((networkResponse) => {
                // Clone and store fresh response in cache
                const responseClone = networkResponse.clone();
                caches.open(CACHE_NAME).then((cache) => {
                    cache.put(event.request, responseClone);
                });
                return networkResponse;
            })
            .catch(() => {
                // Network failed — serve from cache
                return caches.match(event.request).then((cachedResponse) => {
                    if (cachedResponse) {
                        console.log('[SW] Serving from cache:', event.request.url);
                        return cachedResponse;
                    }
                    // Fallback for uncached navigation requests
                    return caches.match('/lecturer_app.php');
                });
            })
    );
});

// Background sync (future enhancement placeholder)
self.addEventListener('sync', (event) => {
    if (event.tag === 'sync-attendance') {
        console.log('[SW] Background sync triggered for pending attendance submissions');
    }
});
