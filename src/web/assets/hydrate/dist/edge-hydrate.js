/**
 * Edge hydration runtime.
 *
 * Cached pages are cookie-free and identical for every anonymous visitor. This script
 * makes them personal again from uncached endpoints:
 *  1. fetch edge/csrf (starts the session, sets CraftSessionId + CRAFT_CSRF_TOKEN) and
 *     inject the token into every Craft CSRF field and meta tag;
 *  2. fetch each [data-edge-island] fragment from edge/island and swap it in, then fill
 *     the CSRF fields inside the markup that just arrived.
 *
 * Island markup lands after the initial token fill, so any form inside an island has to
 * be filled again once it exists. The token request is shared between all of them.
 */
(function() {
    'use strict';

    var config = window.EdgeConfig || {};
    var csrfPromise = null;
    var lastCsrf = null;

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
     * Resolves with the token payload, fetching it at most once per page load.
     */
    function ensureCsrf() {
        if (csrfPromise) {
            return csrfPromise;
        }

        if (!config.csrfUrl) {
            csrfPromise = Promise.resolve(null);

            return csrfPromise;
        }

        csrfPromise = fetch(config.csrfUrl, {
            credentials: 'same-origin',
            headers: {'Accept': 'application/json'}
        })
            .then(function(response) {
                if (!response.ok) {
                    throw new Error('edge/csrf returned ' + response.status);
                }
                return response.json();
            })
            .then(function(data) {
                if (!data || !data.token || !data.param) {
                    return null; // CSRF protection disabled, forms submit without a token.
                }
                lastCsrf = data;
                return data;
            })
            .catch(function(error) {
                console.warn('[edge] CSRF hydration failed:', error);
                return null;
            });

        return csrfPromise;
    }

    function hydrateCsrf() {
        return ensureCsrf().then(function(data) {
            if (!data) {
                return null;
            }
            applyCsrf(document, data);
            document.dispatchEvent(new CustomEvent('edge:csrf', {detail: data}));

            return data;
        });
    }

    function hydrateIslands() {
        var islands = document.querySelectorAll('[data-edge-island]');
        if (!islands.length || !config.islandUrl) {
            return;
        }

        islands.forEach(function(el) {
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
                    el.innerHTML = html;
                    el.setAttribute('data-edge-hydrated', '1');

                    // The fragment may contain forms that did not exist during the
                    // document pass, so fill them before anything reacts to the swap.
                    return ensureCsrf().then(function(data) {
                        applyCsrf(el, data);
                        document.dispatchEvent(new CustomEvent('edge:island', {detail: {name: name, element: el}}));
                    });
                })
                .catch(function(error) {
                    // Leave the placeholder in place, an island failure never breaks the page.
                    console.warn('[edge] island "' + name + '" hydration failed:', error);
                });
        });
    }

    // For markup a site injects itself (modals, cart drawers, anything fetched later).
    window.EdgeCsrf = {
        apply: applyCsrf,
        ensure: function(root) {
            return ensureCsrf().then(function(data) {
                applyCsrf(root, data);

                return data;
            });
        }
    };

    function run() {
        hydrateCsrf();
        hydrateIslands();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', run);
    } else {
        run();
    }
})();
