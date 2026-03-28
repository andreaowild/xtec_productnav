(function () {
  'use strict';

  var STORAGE_KEY = 'xtecProductNavContext';
  var FULL_CONTEXT_PREFIX = 'xtecProductNavFullContext:';
  var MAX_AGE_MS = 24 * 60 * 60 * 1000;
  var MAX_REMOTE_PAGES = 100;
  var fullContextRequests = Object.create(null);

  var PRODUCT_CARD_SELECTOR = 'article.product-miniature, .js-product-miniature';
  var PRODUCT_LINK_SELECTOR = [
    'a.product-thumbnail',
    'a.thumbnail',
    '.product-title a',
    '.js-product-title a',
    '.thumbnail-container a',
    'h2 a',
    'h3 a'
  ].join(',');

  var LISTING_CONTAINER_SELECTOR = [
    '#js-product-list',
    '#products',
    '.products',
    '.js-product-list',
    '.featured-products'
  ].join(',');

  function normalizeText(value) {
    return (value || '').replace(/\s+/g, ' ').trim();
  }

  function bodyId() {
    return document.body ? (document.body.id || '') : '';
  }

  function isProductPage() {
    return bodyId() === 'product';
  }
  
  function countProductCards(root) {
    if (!root) {
      return 0;
    }

    return root.querySelectorAll(PRODUCT_CARD_SELECTOR).length;
  }

  function isFeaturedProductsRoot(root) {
    return !!(root && root.classList && root.classList.contains('featured-products'));
  }

  function shouldUseRemoteListing(root) {
    return !isFeaturedProductsRoot(root) && !isProductPage();
  }

  function safeUrl(value) {
    try {
      var url = new URL(value, window.location.origin);
      if (url.protocol !== 'http:' && url.protocol !== 'https:') {
        return '';
      }
      return url.href;
    } catch (error) {
      return '';
    }
  }

  function getProductLink(card) {
    var link = card.querySelector(PRODUCT_LINK_SELECTOR);
    return link ? safeUrl(link.getAttribute('href') || '') : '';
  }

  function getProductName(card) {
    var el = card.querySelector('.product-title a, .product-title, .js-product-title a, h2, h3');
    return normalizeText(el ? el.textContent : '');
  }

  function getProductImage(card) {
    var img = card.querySelector('img');
    if (!img) {
      return '';
    }

    return safeUrl(
      img.getAttribute('data-full-size-image-url') ||
      img.getAttribute('data-src') ||
      img.getAttribute('src') ||
      ''
    );
  }

  function getProductPrice(card) {
    var el = card.querySelector('.price, .product-price-and-shipping .price');
    return normalizeText(el ? el.textContent : '');
  }

  function getProductId(card) {
    return String(
      card.getAttribute('data-id-product') ||
      (card.dataset ? card.dataset.idProduct : '') ||
      ''
    ).trim();
  }

  function getContextTitle(root) {
    if (isFeaturedProductsRoot(root)) {
      var sectionTitle = root.querySelector('.block-title, .h2, .h3, h2, h3');
      return normalizeText(sectionTitle ? sectionTitle.textContent : '');
    }

    var el = document.querySelector('#js-product-list-header h1, .block-category h1, .page-title, h1.h1, h1');
    return normalizeText(el ? el.textContent : '');
  }

  function getContextType(root) {
    if (isFeaturedProductsRoot(root)) {
      return 'featured-products';
    }

    var id = bodyId();
    if (id === 'search') {
      return 'search';
    }
    if (id === 'manufacturer' || id === 'brand') {
      return 'brand';
    }

    if (document.body) {
      var cls = document.body.className || '';
      if (cls.indexOf('manufacturer') !== -1 || cls.indexOf('brand') !== -1) {
        return 'brand';
      }
    }

    return 'category';
  }

  function collectProducts(root) {
    var scope = root || document;
    var nodes = Array.prototype.slice.call(scope.querySelectorAll(PRODUCT_CARD_SELECTOR));
    var seen = Object.create(null);
    var products = [];

    nodes.forEach(function (node) {
      var card = node.matches(PRODUCT_CARD_SELECTOR) ? node : node.querySelector(PRODUCT_CARD_SELECTOR);

      if (!card) {
        return;
      }

      if (
        card.closest('.slick-cloned') ||
        card.closest('.swiper-slide-duplicate') ||
        card.closest('.owl-item.cloned')
      ) {
        return;
      }

      var id = getProductId(card);
      var url = getProductLink(card);
      var key = id || url;

      if (!key || seen[key]) {
        return;
      }

      seen[key] = true;

      products.push({
        id: id,
        url: url,
        name: getProductName(card),
        image: getProductImage(card),
        price: getProductPrice(card)
      });
    });

    return products.filter(function (item) {
      return !!item.url && !!item.id;
    });
  }

  function normalizeListingUrl(value) {
    var href = safeUrl(value || window.location.href);

    if (!href) {
      return '';
    }

    try {
      var url = new URL(href);
      url.hash = '';
      url.searchParams.delete('page');
      url.searchParams.delete('ajax');
      url.searchParams.delete('from-xhr');
      url.searchParams.delete('resultsPerPage');

      return url.href;
    } catch (error) {
      return '';
    }
  }

  function buildFullContextStorageKey(listingUrl) {
    return FULL_CONTEXT_PREFIX + listingUrl;
  }

  function getPaginationPageCount() {
    var pageCount = 1;
    var selectors = [
      '.pagination [href*="page="]',
      '.pagination [data-page]',
      '.js-search-link[href*="page="]'
    ];

    selectors.forEach(function (selector) {
      Array.prototype.forEach.call(document.querySelectorAll(selector), function (node) {
        var value = '';

        if (node.getAttribute) {
          value = node.getAttribute('data-page') || node.getAttribute('href') || '';
        }

        if (!value) {
          return;
        }

        try {
          var page = 0;

          if (/^\d+$/.test(value)) {
            page = parseInt(value, 10);
          } else {
            page = parseInt(new URL(value, window.location.origin).searchParams.get('page') || '0', 10);
          }

          if (page > pageCount) {
            pageCount = page;
          }
        } catch (error) {
        }
      });
    });

    return Math.min(Math.max(pageCount, 1), MAX_REMOTE_PAGES);
  }

  function mapAjaxProduct(product) {
    if (!product || !product.id_product) {
      return null;
    }

    var image = '';

    if (product.cover) {
      if (typeof product.cover.bySize === 'object' && product.cover.bySize) {
        if (product.cover.bySize.home_default && product.cover.bySize.home_default.url) {
          image = product.cover.bySize.home_default.url;
        } else {
          Object.keys(product.cover.bySize).some(function (sizeKey) {
            var size = product.cover.bySize[sizeKey];

            if (size && size.url) {
              image = size.url;
              return true;
            }

            return false;
          });
        }
      }

      if (!image && product.cover.large && product.cover.large.url) {
        image = product.cover.large.url;
      }

      if (!image && product.cover.medium && product.cover.medium.url) {
        image = product.cover.medium.url;
      }

      if (!image && product.cover.small && product.cover.small.url) {
        image = product.cover.small.url;
      }

      if (!image && product.cover.url) {
        image = product.cover.url;
      }
    }

    var url = safeUrl(product.url || product.canonical_url || '');
    var id = String(product.id_product || '').trim();

    if (!id || !url) {
      return null;
    }

    return {
      id: id,
      url: url,
      name: normalizeText(product.name || ''),
      image: safeUrl(image),
      price: normalizeText(product.price || '')
    };
  }

  function buildContext(root, products, listingUrl) {
    return {
      type: getContextType(root),
      title: getContextTitle(root),
      updatedAt: Date.now(),
      url: listingUrl || window.location.href,
      origin: window.location.origin,
      products: products
    };
  }

  function isFreshContext(context) {
    return !!(
      context &&
      Array.isArray(context.products) &&
      context.updatedAt &&
      (Date.now() - context.updatedAt) <= MAX_AGE_MS
    );
  }

  function saveFullListingContext(listingUrl, context) {
    if (!listingUrl || !isFreshContext(context)) {
      return false;
    }

    try {
      sessionStorage.setItem(buildFullContextStorageKey(listingUrl), JSON.stringify(context));
      return true;
    } catch (error) {
      return false;
    }
  }

  function readFullListingContext(listingUrl) {
    if (!listingUrl) {
      return null;
    }

    try {
      var raw = sessionStorage.getItem(buildFullContextStorageKey(listingUrl));

      if (!raw) {
        return null;
      }

      var context = JSON.parse(raw);

      if (!isFreshContext(context)) {
        return null;
      }

      if (context.origin && context.origin !== window.location.origin) {
        return null;
      }

      return context;
    } catch (error) {
      return null;
    }
  }

  function fetchFullListingContext(root) {
    var listingUrl = normalizeListingUrl(window.location.href);
    var cachedContext = readFullListingContext(listingUrl);

    if (cachedContext) {
      return Promise.resolve(cachedContext);
    }

    if (!listingUrl) {
      return Promise.resolve(null);
    }

    if (fullContextRequests[listingUrl]) {
      return fullContextRequests[listingUrl];
    }

    try {
      var totalPages = getPaginationPageCount();
      var pageNumbers = [];
      var seen = Object.create(null);

      for (var i = 1; i <= totalPages; i += 1) {
        pageNumbers.push(i);
      }

      function fetchListingPage(page) {
        var requestUrl = new URL(listingUrl);
        requestUrl.searchParams.set('page', String(page));
        requestUrl.searchParams.set('ajax', '1');
        requestUrl.searchParams.set('from-xhr', '1');

        return fetch(requestUrl.href, {
          credentials: 'same-origin',
          headers: {
            'X-Requested-With': 'XMLHttpRequest'
          }
        }).then(function (response) {
          if (!response.ok) {
            return [];
          }

          return response.json();
        }).then(function (payload) {
          if (!payload || !Array.isArray(payload.products)) {
            return [];
          }

          return payload.products.map(mapAjaxProduct).filter(function (item) {
            if (!item || seen[item.id]) {
              return false;
            }

            seen[item.id] = true;
            return true;
          });
        }).catch(function () {
          return [];
        });
      }

      fullContextRequests[listingUrl] = Promise.all(pageNumbers.map(fetchListingPage)).then(function (pages) {
        var products = [];

        pages.forEach(function (pageItems) {
          products = products.concat(pageItems);
        });

        if (products.length < 2) {
          return null;
        }

        var context = buildContext(root, products, listingUrl);
        saveFullListingContext(listingUrl, context);

        return context;
      }).then(function (context) {
        delete fullContextRequests[listingUrl];
        return context;
      });

      return fullContextRequests[listingUrl];
    } catch (error) {
      return Promise.resolve(null);
    }
  }

  function persistContext(root) {
    var listingUrl = shouldUseRemoteListing(root)
      ? normalizeListingUrl(window.location.href)
      : window.location.href;
    var fullContext = shouldUseRemoteListing(root)
      ? readFullListingContext(listingUrl)
      : null;
    var products = fullContext && Array.isArray(fullContext.products) && fullContext.products.length >= 2
      ? fullContext.products
      : collectProducts(root);

    if (!products || products.length < 2) {
      return false;
    }

    var context = buildContext(root, products, listingUrl);

    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify(context));
      return true;
    } catch (error) {
      return false;
    }
  }

  function readContext() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (!raw) {
        return null;
      }

      var context = JSON.parse(raw);
      if (!context || !Array.isArray(context.products)) {
        return null;
      }

      if (context.origin && context.origin !== window.location.origin) {
        return null;
      }

      if (!context.updatedAt || (Date.now() - context.updatedAt) > MAX_AGE_MS) {
        return null;
      }

      return context;
    } catch (error) {
      return null;
    }
  }

  function createNode(tag, className, text) {
    var el = document.createElement(tag);
    if (className) {
      el.className = className;
    }
    if (typeof text === 'string') {
      el.textContent = text;
    }
    return el;
  }

  function closeAllPanels(root) {
    if (!root) {
      return;
    }

    root.querySelectorAll('.xpn-fixed.is-open').forEach(function (el) {
      el.classList.remove('is-open');
    });
  }

  function buildThumb(product) {
    var thumb = createNode('span', 'xpn-fixed__thumb');

    if (product.image) {
      var img = document.createElement('img');
      img.src = product.image;
      img.alt = product.name || '';
      img.loading = 'lazy';
      img.decoding = 'async';
      thumb.appendChild(img);
    } else {
      thumb.appendChild(createNode('span', 'xpn-fixed__thumb-fallback', 'XTec'));
    }

    return thumb;
  }

  function buildPanel(product, label, showPrice) {
    var panel = createNode('span', 'xpn-fixed__panel');
    var inner = createNode('span', 'xpn-fixed__panel-inner');

    inner.appendChild(createNode('span', 'xpn-fixed__title', product.name || ''));

    if (showPrice && product.price) {
      inner.appendChild(createNode('span', 'xpn-fixed__price', product.price));
    }

    panel.appendChild(inner);
    return panel;
  }

  function buildFixedItem(product, direction, label, showPrice) {
    if (!product || !product.url) {
      return null;
    }

    var link = createNode('a', 'xpn-fixed xpn-fixed--' + direction);
    link.href = product.url;
    link.setAttribute('aria-label', label + ': ' + (product.name || ''));

    link.appendChild(buildThumb(product));
    link.appendChild(buildPanel(product, label, showPrice));

    return link;
  }

  function bindMobilePanels(root) {
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

  function renderProductNavigation() {
    var root = document.getElementById('xtec-product-nav');
    if (!root) {
      return;
    }

    var currentId = String(root.getAttribute('data-current-product-id') || '').trim();
    var showPrice = String(root.getAttribute('data-show-price') || '1') === '1';
    var context = readContext();
    if (!currentId || !context) {
      root.classList.add('xpn-block--hidden');
      return;
    }

    var index = context.products.findIndex(function (item) {
      return String(item.id) === currentId;
    });

    if (index === -1) {
      root.classList.add('xpn-block--hidden');
      return;
    }

    var total = context.products.length;
    var prev = null;
    var next = null;

    if (total > 1) {
      prev = index > 0
        ? context.products[index - 1]
        : context.products[total - 1];

      next = index < total - 1
        ? context.products[index + 1]
        : context.products[0];
    }

    if (!prev && !next) {
      root.classList.add('xpn-block--hidden');
      return;
    }

    root.innerHTML = '';

    if (prev) {
      root.appendChild(
        buildFixedItem(
          prev,
          'prev',
          root.getAttribute('data-prev-label') || 'Previous',
          showPrice
        )
      );
    }

    if (next) {
      root.appendChild(
        buildFixedItem(
          next,
          'next',
          root.getAttribute('data-next-label') || 'Next',
          showPrice
        )
      );
    }

    root.classList.remove('xpn-block--hidden');
    bindMobilePanels(root);
  }

  function debounce(fn, delay) {
    var timeout = null;
    return function () {
      clearTimeout(timeout);
      timeout = setTimeout(fn, delay);
    };
  }

  function findContextRootFromLink(link) {
    if (!link) {
      return null;
    }

    var card = link.closest(PRODUCT_CARD_SELECTOR);
    if (!card) {
      return null;
    }

    /* se siamo nel blocco correlati/prodotti della stessa categoria,
      diamo priorità a quel contenitore */
    var featured = card.closest('.featured-products');
    if (featured && countProductCards(featured) >= 2) {
      return featured;
    }

    /* altrimenti risaliamo gli antenati e prendiamo il primo contenitore
      che contiene almeno 2 card prodotto */
    var node = card.parentElement;

    while (node && node !== document.body) {
      if (countProductCards(node) >= 2) {
        return node;
      }

      node = node.parentElement;
    }

    return null;
  }

  function bindContextCapture() {
    function saveFromEvent(event) {
      var link = event.target.closest(PRODUCT_LINK_SELECTOR);
      if (!link) {
        return;
      }

      var root = findContextRootFromLink(link);
      if (!root) {
        return;
      }

      persistContext(root);
    }

    document.addEventListener('pointerdown', saveFromEvent, true);
    document.addEventListener('mousedown', saveFromEvent, true);
    document.addEventListener('click', saveFromEvent, true);
  }

  function hydrateFullListingContext(root) {
    if (!shouldUseRemoteListing(root)) {
      return;
    }

    fetchFullListingContext(root).then(function (context) {
      if (!context || !Array.isArray(context.products) || context.products.length < 2) {
        return;
      }

      persistContext(root);
    });
  }

  function initListingObservers() {
    var containers = document.querySelectorAll('#js-product-list, #products, .products, .js-product-list');

    if (!containers.length || typeof MutationObserver === 'undefined') {
      return;
    }

    var debouncedPersist = debounce(function () {
      var main = document.querySelector('#js-product-list, #products, .products, .js-product-list');
      if (main) {
        persistContext(main);
        hydrateFullListingContext(main);
      }
    }, 150);

    containers.forEach(function (container) {
      var observer = new MutationObserver(function () {
        debouncedPersist();
      });

      observer.observe(container, {
        childList: true,
        subtree: true
      });
    });
  }

  function init() {
    bindContextCapture();
    initListingObservers();

    if (!isProductPage()) {
      var cards = document.querySelectorAll(PRODUCT_CARD_SELECTOR);

      if (cards.length >= 2) {
        var firstCardLink = cards[0].querySelector(PRODUCT_LINK_SELECTOR);
        if (firstCardLink) {
          var initialRoot = findContextRootFromLink(firstCardLink);
          if (initialRoot) {
            persistContext(initialRoot);
            hydrateFullListingContext(initialRoot);
          }
        }
      }
    }

    if (isProductPage()) {
      renderProductNavigation();
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
