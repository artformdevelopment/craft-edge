/**
 * Edge hydration runtime.
 *
 * Cached pages are cookie-free and identical for every anonymous visitor. This script
 * makes them personal again from a single uncached request: it fetches the visitor's CSRF
 * token together with every island fragment the page needs, fills the CSRF fields, and
 * swaps the islands in.
 *
 * One round trip regardless of island count. A cache hit costs the origin one request,
 * not one per island plus one for the token.
 *
 * The token is cached for the page's lifetime, but the server can rotate it underneath us
 * (signing in or out regenerates the session). Anything that submits must therefore be
 * able to recover, which is what refresh() and post() are for.
 */
(function() {
    'use strict';

    var config = window.EdgeConfig || {};
    var hydratePromise = null;
    var lastCsrf = null;

    function islandElements() {
        return Array.prototype.slice.call(document.querySelectorAll('[data-edge-island]'));
    }

    /**
     * Fills every CSRF field under `root`. Handles both a plain hidden input and Craft's
     * async `<craft-csrf-input>` placeholder, which Formie also emits.
     */
    function applyCsrf(root, data) {
        data = data || lastCsrf;
        root = root || document;

        if (!data || !data.token || !data.param || !root.querySelectorAll) {
            return;
        }

        root.querySelectorAll('input[name="' + data.param + '"]').forEach(function(input) {
            input.value = data.token;
        });

        root.querySelectorAll('craft-csrf-input').forEach(function(el) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = data.param;
            input.value = data.token;
            el.replaceWith(input);
        });

        // Meta tags are document-level, so only refresh them on a document-wide pass.
        if (root === document) {
            var meta = document.querySelector('meta[name="csrf-token"]');
            if (meta) {
                meta.setAttribute('content', data.token);
            }

            var param = document.querySelector('meta[name="csrf-param"]');
            if (param) {
                param.setAttribute('content', data.param);
            }
        }
    }

    /**
     * The single hydration request. Resolves with {token, param, islands}; never rejects,
     * because a hydration failure must leave the page usable rather than break it.
     */
    function hydrateOnce(names) {
        if (hydratePromise) {
            return hydratePromise;
        }

        var url = config.hydrateUrl || config.csrfUrl;

        if (!url) {
            hydratePromise = Promise.resolve(null);

            return hydratePromise;
        }

        if (config.hydrateUrl && names && names.length) {
            url += (url.indexOf('?') === -1 ? '?' : '&') + 'islands=' + encodeURIComponent(names.join(','));
        }

        hydratePromise = fetch(url, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('edge hydration returned ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (data && data.token && data.param) {
                    lastCsrf = {token: data.token, param: data.param};
                }
                return data || null;
            })
            .catch(function(error) {
                console.warn('[edge] hydration failed:', error);
                return null;
            });

        return hydratePromise;
    }

    /**
     * Discards the cached token and fetches a new one. Needed whenever the server may
     * have rotated it: after signing in or out, or when a page comes back from bfcache
     * having sat in a background tab.
     */
    function refresh(root) {
        hydratePromise = null;

        return hydrateOnce([]).then(function() {
            applyCsrf(root || document, lastCsrf);

            return lastCsrf;
        });
    }

    function swapIsland(el, html) {
        el.innerHTML = html;
        el.setAttribute('data-edge-hydrated', '1');

        // The fragment may contain forms that did not exist during the document pass.
        applyCsrf(el, lastCsrf);
        document.dispatchEvent(new CustomEvent('edge:island', {
            detail: {name: el.getAttribute('data-edge-island'), element: el}
        }));
    }

    /**
     * Falls back to the per-island endpoint, for a page served from a cache that predates
     * the batched endpoint (the shell carries whatever EdgeConfig was baked into it).
     */
    function hydrateIslandsIndividually(els) {
        if (!config.islandUrl) {
            return;
        }

        els.forEach(function(el) {
            var name = el.getAttribute('data-edge-island');
            var url = config.islandUrl + (config.islandUrl.indexOf('?') === -1 ? '?' : '&') + 'name=' + encodeURIComponent(name);

            fetch(url, {credentials: 'same-origin'})
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('edge/island returned ' + response.status);
                    }
                    return response.text();
                })
                .then(function(html) {
                    swapIsland(el, html);
                })
                .catch(function(error) {
                    // Leave the placeholder in place, an island failure never breaks the page.
                    console.warn('[edge] island "' + name + '" hydration failed:', error);
                });
        });
    }

    function looksLikeCsrfRejection(status, body) {
        return status === 400 && /Unable to verify your data|CSRF/i.test(body || '');
    }

    /**
     * POSTs with the visitor's current token and, if the server says the token is stale,
     * fetches a fresh one and replays the request exactly once.
     *
     * A page can be open long enough for the session to rotate underneath it, so any
     * mutation that isn't followed by a full page load should go through here.
     */
    function post(url, options) {
        options = options || {};

        return hydrateOnce([]).then(function() {
            function send() {
                var body = options.body;

                if (lastCsrf && lastCsrf.token) {
                    if (body instanceof FormData) {
                        body.set(lastCsrf.param, lastCsrf.token);
                    } else if (body instanceof URLSearchParams) {
                        body.set(lastCsrf.param, lastCsrf.token);
                    }
                }

                return fetch(url, {
                    method: options.method || 'POST',
                    body: body,
                    credentials: 'same-origin',
                    headers: options.headers || {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });
            }

            return send().then(function(response) {
                if (response.status !== 400) {
                    return response;
                }

                // Read a clone so the caller still gets an unconsumed body.
                return response.clone().text().then(function(text) {
                    if (!looksLikeCsrfRejection(response.status, text)) {
                        return response;
                    }

                    return refresh(document).then(send);
                });
            });
        });
    }

    function run() {
        var els = islandElements();
        var names = els.map(function(el) {
            return el.getAttribute('data-edge-island');
        });

        hydrateOnce(names).then(function(data) {
            if (!data) {
                return;
            }

            applyCsrf(document, lastCsrf);

            if (lastCsrf) {
                document.dispatchEvent(new CustomEvent('edge:csrf', {detail: lastCsrf}));
            }

            if (!data.islands) {
                hydrateIslandsIndividually(els);

                return;
            }

            els.forEach(function(el) {
                var html = data.islands[el.getAttribute('data-edge-island')];
                if (typeof html === 'string') {
                    swapIsland(el, html);
                }
            });
        });
    }

    // For markup a site injects itself (modals, cart drawers, anything fetched later).
    window.EdgeCsrf = {
        apply: applyCsrf,
        refresh: refresh,
        post: post,
        ensure: function(root) {
            return hydrateOnce([]).then(function() {
                applyCsrf(root, lastCsrf);

                return lastCsrf;
            });
        }
    };

    // A page restored from bfcache kept its DOM, and its token may have been rotated in
    // another tab meanwhile.
    window.addEventListener('pageshow', function(event) {
        if (event.persisted) {
            refresh(document);
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
