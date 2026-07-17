/**
 * Edge hydration runtime.
 *
 * Cached pages are cookie-free and identical for every anonymous visitor. This script
 * makes them personal again from uncached endpoints:
 *  1. fetch edge/csrf (starts the session, sets CraftSessionId + CRAFT_CSRF_TOKEN) and
 *     inject the token into every Craft CSRF hidden input and meta tag;
 *  2. fetch each [data-edge-island] fragment from edge/island and swap it in.
 */
(function() {
    'use strict';

    var config = window.EdgeConfig || {};

    function hydrateCsrf() {
        if (!config.csrfUrl) {
            return;
        }

        fetch(config.csrfUrl, {
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
                    return; // CSRF protection disabled, forms submit without a token.
                }

                document.querySelectorAll('input[name="' + data.param + '"]').forEach(function(input) {
                    input.value = data.token;
                });

                var meta = document.querySelector('meta[name="csrf-token"]');
                if (meta) {
                    meta.setAttribute('content', data.token);
                }

                var param = document.querySelector('meta[name="csrf-param"]');
                if (param) {
                    param.setAttribute('content', data.param);
                }

                document.dispatchEvent(new CustomEvent('edge:csrf', {detail: data}));
            })
            .catch(function(error) {
                console.warn('[edge] CSRF hydration failed:', error);
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
                    document.dispatchEvent(new CustomEvent('edge:island', {detail: {name: name, element: el}}));
                })
                .catch(function(error) {
                    // Leave the placeholder in place, an island failure never breaks the page.
                    console.warn('[edge] island "' + name + '" hydration failed:', error);
                });
        });
    }

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
