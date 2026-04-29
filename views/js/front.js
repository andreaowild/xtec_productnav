(function () {
  'use strict';

  var config = window.xtecProductNavConfig || {};
  var ACTIVE_CONTEXT_KEY = 'xtecProductNavContextKey';
  var PENDING_CONTEXT_KEY = 'xtecProductNavPendingContext';
  var PRODUCT_CARD_SELECTOR = 'article.product-miniature, .js-product-miniature';
  var PRODUCT_LIST_SELECTOR = '#js-product-list, #products, .products, .js-product-list';
  var PRODUCT_LINK_SELECTOR = [
    'a.product-thumbnail',
    'a.thumbnail',
    '.product-title a',
    '.js-product-title a',
    '.thumbnail-container a',
    'h2 a',
    'h3 a'
  ].join(',');

  function closeAllPanels(root) {
    if (!root) {
      return;
    }

    root.querySelectorAll('.xpn-fixed.is-open').forEach(function (el) {
      el.classList.remove('is-open');
    });
  }

  function bindMobilePanels(root) {
    if (!root) {
      return;
    }

    root.querySelectorAll('.xpn-fixed').forEach(function (item) {
      item.addEventListener('click', function (event) {
        var desktopHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches && window.innerWidth > 767;

        if (desktopHover) {
          return;
        }

        if (!item.classList.contains('is-open')) {
          event.preventDefault();
          closeAllPanels(root);
          item.classList.add('is-open');
        }
      });
    });

    document.addEventListener('click', function (event) {
      if (!event.target.closest('.xpn-fixed')) {
        closeAllPanels(root);
      }
    });
  }

  function safeSessionStorage() {
    try {
      return window.sessionStorage;
    } catch (error) {
      return null;
    }
  }

  function storeContextKey() {
    var storage = safeSessionStorage();

    if (!storage || !config.isListingPage || !config.contextKey) {
      return;
    }

    storage.setItem(ACTIVE_CONTEXT_KEY, config.contextKey);
  }

  function bytesToHex(bytes) {
    return Array.prototype.map.call(bytes, function (byte) {
      return byte.toString(16).padStart(2, '0');
    }).join('');
  }

  function getCurrentListingSort() {
    var params = new URLSearchParams(window.location.search || '');
    return params.get('order') || ((config.contextSeed && config.contextSeed.defaultSort) || '');
  }

  function buildCurrentListingPayload() {
    var seed = config.contextSeed || {};
    var params = new URLSearchParams(window.location.search || '');

    if (!seed.queryType) {
      return null;
    }

    return {
      shop: parseInt(seed.shop || 0, 10),
      lang: parseInt(seed.lang || 0, 10),
      currency: parseInt(seed.currency || 0, 10),
      query_type: String(seed.queryType || ''),
      id_category: parseInt(seed.idCategory || 0, 10),
      id_manufacturer: parseInt(seed.idManufacturer || 0, 10),
      search_string: String(seed.searchString || '').trim(),
      search_tag: String(seed.searchTag || '').trim(),
      encoded_facets: String(params.get('q') || ''),
      sort: String(getCurrentListingSort() || '')
    };
  }

  function syncListingContextKey() {
    var storage = safeSessionStorage();
    var payload;

    if (!storage || !config.isListingPage || !window.crypto || !window.crypto.subtle || typeof TextEncoder === 'undefined') {
      return Promise.resolve('');
    }

    payload = buildCurrentListingPayload();
    if (!payload) {
      return Promise.resolve('');
    }

    return window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(JSON.stringify(payload)))
      .then(function (buffer) {
        var key = bytesToHex(new Uint8Array(buffer));
        storage.setItem(ACTIVE_CONTEXT_KEY, key);
        config.contextKey = key;
        return key;
      })
      .catch(function () {
        return '';
      });
  }

  function setActiveProductContext(contextKey, productId) {
    var storage = safeSessionStorage();

    if (!storage || !contextKey || !productId) {
      return;
    }

    storage.setItem(ACTIVE_CONTEXT_KEY, JSON.stringify({
      contextKey: contextKey,
      productId: productId
    }));
  }

  function setPendingContext(contextKey) {
    var storage = safeSessionStorage();

    if (!storage) {
      return;
    }

    storage.setItem(PENDING_CONTEXT_KEY, JSON.stringify({
      contextKey: contextKey,
      createdAt: Date.now()
    }));
  }

  function consumePendingContext() {
    var storage = safeSessionStorage();
    var raw;
    var payload;

    if (!storage) {
      return '';
    }

    raw = storage.getItem(PENDING_CONTEXT_KEY) || '';
    if (!raw) {
      return '';
    }

    storage.removeItem(PENDING_CONTEXT_KEY);

    try {
      payload = JSON.parse(raw);
    } catch (error) {
      return '';
    }

    if (!payload || !payload.contextKey) {
      return '';
    }

    if (payload.createdAt && Math.abs(Date.now() - payload.createdAt) > 300000) {
      return '';
    }

    storage.setItem(ACTIVE_CONTEXT_KEY, payload.contextKey);

    return payload.contextKey;
  }

  function readActiveContextKey(productId) {
    var storage = safeSessionStorage();
    var raw;
    var payload;

    if (!storage) {
      return '';
    }

    raw = storage.getItem(ACTIVE_CONTEXT_KEY) || '';
    if (!raw) {
      return '';
    }

    try {
      payload = JSON.parse(raw);
    } catch (error) {
      return raw;
    }

    if (!payload || !payload.contextKey || parseInt(payload.productId || '0', 10) !== parseInt(productId || '0', 10)) {
      return '';
    }

    return payload.contextKey;
  }

  function prepareListingNavigation() {
    if (!config.isListingPage || !config.contextKey) {
      return;
    }

    document.querySelectorAll(PRODUCT_CARD_SELECTOR).forEach(function (card) {
      card.querySelectorAll(PRODUCT_LINK_SELECTOR).forEach(function (link) {
        link.addEventListener('click', function () {
          setPendingContext(readActiveContextKey() || config.contextKey);
        }, true);

        link.addEventListener('auxclick', function () {
          setPendingContext(readActiveContextKey() || config.contextKey);
        }, true);
      });
    });
  }

  function prepareProductNavigation(root, contextKey) {
    if (!root || !contextKey) {
      return;
    }

    root.querySelectorAll('.xpn-fixed').forEach(function (link) {
      link.addEventListener('click', function () {
        setPendingContext(contextKey);
      }, true);

      link.addEventListener('auxclick', function () {
        setPendingContext(contextKey);
      }, true);
    });
  }

  function fetchNavigation(root) {
    var productId = parseInt(config.productId || root.getAttribute('data-product-id') || '0', 10);
    var contextKey = consumePendingContext() || readActiveContextKey(productId);

    if (!root || !config.isProductPage || !config.navigationUrl || !contextKey || !productId) {
      return;
    }

    var url;

    try {
      url = new URL(config.navigationUrl, window.location.origin);
      url.searchParams.set('ajax', '1');
      url.searchParams.set('id_product', String(productId));
      url.searchParams.set('context_key', contextKey);
    } catch (error) {
      return;
    }

    window.fetch(url.toString(), {
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest'
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('Navigation request failed');
        }

        return response.json();
      })
      .then(function (payload) {
        if (!payload || !payload.html) {
          root.innerHTML = '';
          return;
        }

        root.outerHTML = payload.html;
        setActiveProductContext(contextKey, productId);
        prepareProductNavigation(document.getElementById('xtec-product-nav'), contextKey);
        bindMobilePanels(document.getElementById('xtec-product-nav'));
      })
      .catch(function () {
        root.innerHTML = '';
      });
  }

  function init() {
    storeContextKey();

    if (config.isListingPage) {
      syncListingContextKey();
      document.querySelectorAll(PRODUCT_LIST_SELECTOR).forEach(function (container) {
        if (typeof MutationObserver === 'undefined') {
          return;
        }

        new MutationObserver(function () {
          syncListingContextKey();
          prepareListingNavigation();
        }).observe(container, {
          childList: true,
          subtree: true
        });
      });

      window.addEventListener('popstate', function () {
        syncListingContextKey();
      });
    }

    prepareListingNavigation();

    var navRoot = document.getElementById('xtec-product-nav-root');
    if (navRoot) {
      fetchNavigation(navRoot);
      return;
    }

    bindMobilePanels(document.getElementById('xtec-product-nav'));
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
