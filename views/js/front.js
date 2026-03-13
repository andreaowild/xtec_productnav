(function () {
  'use strict';

  var STORAGE_KEY = 'xtecProductNavContext';
  var MAX_AGE_MS = 24 * 60 * 60 * 1000;

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
    if (root && root.classList && root.classList.contains('featured-products')) {
      var sectionTitle = root.querySelector('.block-title, .h2, .h3, h2, h3');
      return normalizeText(sectionTitle ? sectionTitle.textContent : '');
    }

    var el = document.querySelector('#js-product-list-header h1, .block-category h1, .page-title, h1.h1, h1');
    return normalizeText(el ? el.textContent : '');
  }

  function getContextType(root) {
    if (root && root.classList && root.classList.contains('featured-products')) {
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

  function persistContext(root) {
    var products = collectProducts(root);

    if (!products || products.length < 2) {
      return false;
    }

    var context = {
      type: getContextType(root),
      title: getContextTitle(root),
      updatedAt: Date.now(),
      url: window.location.href,
      origin: window.location.origin,
      products: products
    };
    console.log('XPN persistContext', {
      type: getContextType(root),
      title: getContextTitle(root),
      count: products.length,
      root: root
    });
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
console.log('XPN readContext', context);
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

  function initListingObservers() {
    var containers = document.querySelectorAll('#js-product-list, #products, .products, .js-product-list');

    if (!containers.length || typeof MutationObserver === 'undefined') {
      return;
    }

    var debouncedPersist = debounce(function () {
      var main = document.querySelector('#js-product-list, #products, .products, .js-product-list');
      if (main) {
        persistContext(main);
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