<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

use PrestaShop\PrestaShop\Adapter\Category\CategoryProductSearchProvider;
use PrestaShop\PrestaShop\Adapter\Image\ImageRetriever;
use PrestaShop\PrestaShop\Adapter\Manufacturer\ManufacturerProductSearchProvider;
use PrestaShop\PrestaShop\Adapter\Presenter\Product\ProductListingPresenter;
use PrestaShop\PrestaShop\Adapter\Product\PriceFormatter;
use PrestaShop\PrestaShop\Adapter\Product\ProductColorsRetriever;
use PrestaShop\PrestaShop\Adapter\Search\SearchProductSearchProvider;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchContext;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchProviderInterface;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchQuery;
use PrestaShop\PrestaShop\Core\Product\Search\ProductSearchResult;
use PrestaShop\PrestaShop\Core\Product\Search\SortOrder;

class Xtec_productnav extends Module
{
    public const CONFIG_SHOW_PRICE = 'XTECPRODUCTNAV_SHOW_PRICE';
    private const SESSION_KEY = 'xtec_productnav_context';
    private const SESSION_TTL = 86400;
    private const QUERY_HOOK = 'actionProductSearchProviderRunQueryAfter';
    private const MAX_CONTEXT_PRODUCTS = 500;
    private const MAX_STORED_CONTEXTS = 10;
    protected $currentListingContextKey = '';

    public function debugLog($message, array $context = [])
    {
        if (!defined('_PS_MODE_DEV_') || !_PS_MODE_DEV_) {
            return;
        }

        $suffix = $context ? ' ' . json_encode($context) : '';
        PrestaShopLogger::addLog('[' . $this->name . '] ' . $message . $suffix, 1);
    }

    public function __construct()
    {
        $this->name = 'xtec_productnav';
        $this->tab = 'front_office_features';
        $this->version = '1.1.4';
        $this->author = 'AndRed.it';
        $this->need_instance = 0;
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->trans('XTec Product Navigation', [], 'Modules.Xtec_productnav.Admin');
        $this->description = $this->trans(
            'Adds previous and next product navigation on the product page, based on the last category or search listing visited by the customer.',
            [],
            'Modules.Xtec_productnav.Admin'
        );

        $this->ps_versions_compliancy = [
            'min' => '8.0.0',
            'max' => _PS_VERSION_,
        ];
    }

    public function install()
    {
        return parent::install()
            && Configuration::updateValue(self::CONFIG_SHOW_PRICE, 1)
            && $this->registerHook([
                'displayHeader',
                'displayFooterProduct',
                self::QUERY_HOOK,
            ]);
    }

    public function uninstall()
    {
        return Configuration::deleteByName(self::CONFIG_SHOW_PRICE)
            && parent::uninstall();
    }

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitXtecProductNav')) {
            $submittedToken = (string) Tools::getValue('xtec_productnav_token');
            $expectedToken = Tools::getAdminTokenLite('AdminModules');

            if (!hash_equals($expectedToken, $submittedToken)) {
                return $this->displayError(
                    $this->trans('Invalid security token.', [], 'Admin.Notifications.Error')
                ) . $this->renderForm();
            }

            Configuration::updateValue(
                self::CONFIG_SHOW_PRICE,
                (int) Tools::getValue(self::CONFIG_SHOW_PRICE, 1)
            );

            $output .= $this->displayConfirmation(
                $this->trans('Settings updated.', [], 'Admin.Notifications.Success')
            );
        }

        return $output . $this->renderForm();
    }

    public function hookDisplayHeader()
    {
        if (!$this->context || !$this->context->controller) {
            return;
        }

        $phpSelf = (string) ($this->context->controller->php_self ?? '');
        $relevantControllers = ['product', 'category', 'search', 'manufacturer', 'brand'];

        if (!in_array($phpSelf, $relevantControllers, true)) {
            return;
        }

        $this->context->controller->registerJavascript(
            'module-' . $this->name . '-front',
            'modules/' . $this->name . '/views/js/front.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ]
        );

        if ($phpSelf === 'product') {
            $this->context->controller->registerStylesheet(
                'module-' . $this->name . '-front',
                'modules/' . $this->name . '/views/css/front.css',
                [
                    'media' => 'all',
                    'priority' => 150,
                ]
            );
        }

        Media::addJsDef([
            'xtecProductNavConfig' => [
                'contextKey' => $this->getFrontendContextKey($phpSelf),
                'isListingPage' => in_array($phpSelf, ['category', 'search', 'manufacturer', 'brand'], true),
                'isProductPage' => $phpSelf === 'product',
                'productId' => $phpSelf === 'product' ? (int) Tools::getValue('id_product', 0) : 0,
                'navigationUrl' => $phpSelf === 'product'
                    ? $this->getRelativeModuleLink('navigation')
                    : '',
                'contextSeed' => $this->getFrontendContextSeed($phpSelf),
            ],
        ]);
    }

    public function hookActionProductSearchProviderRunQueryAfter(array $params)
    {
        if (
            !$this->context
            || !$this->context->controller
            || !($params['query'] ?? null) instanceof ProductSearchQuery
            || !($params['result'] ?? null) instanceof ProductSearchResult
        ) {
            return;
        }

        if (!is_a($this->context->controller, 'ProductListingFrontControllerCore')) {
            return;
        }

        $query = $params['query'];
        $result = $params['result'];

        if (!$this->isSupportedQueryType((string) $query->getQueryType())) {
            return;
        }

        $contextKey = $this->buildContextKey($query);
        $this->currentListingContextKey = $contextKey;

        $productIds = $this->extractProductIds($result->getProducts());
        $currentPage = max(1, (int) $query->getPage());
        $resultsPerPage = max(1, (int) $query->getResultsPerPage());
        $totalProducts = (int) $result->getTotalProductsCount();

        if ($totalProducts > self::MAX_CONTEXT_PRODUCTS) {
            $this->debugLog('Listing context skipped because product total exceeds limit.', [
                'context_key' => $contextKey,
                'total' => $totalProducts,
                'query_type' => (string) $query->getQueryType(),
            ]);
            $this->deleteStoredListingContext($contextKey);

            return;
        }

        if ($totalProducts < 2 || count($productIds) < 1) {
            $this->debugLog('Listing context skipped because fewer than 2 products were resolved.', [
                'context_key' => $contextKey,
                'count' => count($productIds),
                'total' => $totalProducts,
                'query_type' => (string) $query->getQueryType(),
            ]);
            $this->deleteStoredListingContext($contextKey);

            return;
        }

        $storedContext = $this->getStoredListingContext($contextKey);
        $pages = [];

        if (!empty($storedContext['pages']) && is_array($storedContext['pages'])) {
            $pages = $storedContext['pages'];
        }

        $pageKey = (string) $currentPage;
        $normalizedPageIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));
        $existingPageIds = [];

        if (isset($pages[$pageKey])) {
            $existingPageIds = is_array($pages[$pageKey])
                ? array_values(array_unique(array_filter(array_map('intval', $pages[$pageKey]))))
                : $this->decodeStoredIds((string) $pages[$pageKey]);
        }

        $sortOrder = $query->getSortOrder();
        $serializedSort = $sortOrder ? (string) $sortOrder->toString() : '';

        if (
            $storedContext
            && $existingPageIds === $normalizedPageIds
            && (int) ($storedContext['total_products'] ?? 0) === $totalProducts
            && (int) ($storedContext['results_per_page'] ?? 0) === $resultsPerPage
            && (string) ($storedContext['sort_order'] ?? '') === $serializedSort
        ) {
            $this->debugLog('Listing context unchanged.', [
                'context_key' => $contextKey,
                'count' => count($productIds),
            ]);
            return;
        }

        $pages[$pageKey] = $normalizedPageIds;
        $newContext = [
            'context_key' => $contextKey,
            'query_type' => (string) $query->getQueryType(),
            'id_category' => (int) $query->getIdCategory(),
            'id_manufacturer' => (int) $query->getIdManufacturer(),
            'search_string' => (string) $query->getSearchString(),
            'search_tag' => (string) $query->getSearchTag(),
            'encoded_facets' => (string) $query->getEncodedFacets(),
            'sort_order' => $serializedSort,
            'total_products' => $totalProducts,
            'results_per_page' => $resultsPerPage,
            'pages' => $pages,
            'updated_at' => time(),
        ];

        $this->debugLog('Listing context stored.', [
            'context_key' => $contextKey,
            'count' => count($productIds),
            'page' => $currentPage,
            'total' => $totalProducts,
            'query_type' => (string) $query->getQueryType(),
        ]);
        $this->writeStoredListingContext($contextKey, $newContext);
    }

    public function hookDisplayFooterProduct(array $params)
    {
        $productId = $this->extractProductId($params);

        if ($productId <= 0) {
            return '';
        }

        return '<div id="xtec-product-nav-root" data-product-id="' . (int) $productId . '"></div>';
    }

    protected function renderForm()
    {
        $formAction = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
        $showPrice = (int) Configuration::get(self::CONFIG_SHOW_PRICE, 1);
        $formToken = htmlspecialchars(Tools::getAdminTokenLite('AdminModules'), ENT_QUOTES, 'UTF-8');

        $html = [];
        $html[] = '<div class="panel">';
        $html[] = '<h3><i class="icon icon-arrows-h"></i> ' . $this->trans('XTec Product Navigation', [], 'Modules.Xtec_productnav.Admin') . '</h3>';
        $html[] = '<p>' . $this->trans(
            'The module stores the last product listing visited by the customer (category or search), then renders previous / next cards on the product page.',
            [],
            'Modules.Xtec_productnav.Admin'
        ) . '</p>';
        $html[] = '<form method="post" action="' . $formAction . '">';
        $html[] = '  <input type="hidden" name="xtec_productnav_token" value="' . $formToken . '">';
        $html[] = '  <div class="form-group">';
        $html[] = '    <label class="control-label">' . $this->trans('Show price in navigation cards', [], 'Modules.Xtec_productnav.Admin') . '</label>';
        $html[] = '    <select class="form-control fixed-width-xl" name="' . self::CONFIG_SHOW_PRICE . '">';
        $html[] = '      <option value="1"' . ($showPrice === 1 ? ' selected' : '') . '>' . $this->trans('Yes', [], 'Admin.Global') . '</option>';
        $html[] = '      <option value="0"' . ($showPrice === 0 ? ' selected' : '') . '>' . $this->trans('No', [], 'Admin.Global') . '</option>';
        $html[] = '    </select>';
        $html[] = '  </div>';
        $html[] = '  <div class="panel-footer">';
        $html[] = '    <button type="submit" class="btn btn-default pull-right" name="submitXtecProductNav">';
        $html[] = '      <i class="process-icon-save"></i> ' . $this->trans('Save', [], 'Admin.Actions') . '';
        $html[] = '    </button>';
        $html[] = '  </div>';
        $html[] = '</form>';
        $html[] = '</div>';

        return implode('', $html);
    }

    protected function extractProductId(array $params)
    {
        if (isset($params['product'])) {
            $product = $params['product'];

            if (is_array($product)) {
                if (isset($product['id_product'])) {
                    return (int) $product['id_product'];
                }
                if (isset($product['id'])) {
                    return (int) $product['id'];
                }
            }

            if (is_object($product)) {
                if (isset($product->id_product)) {
                    return (int) $product->id_product;
                }
                if (isset($product->id)) {
                    return (int) $product->id;
                }
                if ($product instanceof ArrayAccess) {
                    if (isset($product['id_product'])) {
                        return (int) $product['id_product'];
                    }
                    if (isset($product['id'])) {
                        return (int) $product['id'];
                    }
                }
            }
        }

        return (int) Tools::getValue('id_product', 0);
    }

    protected function writeStoredListingContext($contextKey, array $context)
    {
        if (!$this->isValidContextKey($contextKey)) {
            return;
        }

        $store = $this->getServerSessionStore();
        $pages = [];

        if (!empty($context['pages']) && is_array($context['pages'])) {
            foreach ($context['pages'] as $page => $ids) {
                $pages[(string) $page] = is_array($ids) ? implode(',', array_map('intval', $ids)) : (string) $ids;
            }
        }

        $store[$contextKey] = [
            'context_key' => $contextKey,
            'query_type' => (string) $context['query_type'],
            'id_category' => (int) $context['id_category'],
            'id_manufacturer' => (int) $context['id_manufacturer'],
            'search_string' => (string) $context['search_string'],
            'search_tag' => (string) $context['search_tag'],
            'encoded_facets' => (string) $context['encoded_facets'],
            'sort_order' => (string) $context['sort_order'],
            'total_products' => (int) $context['total_products'],
            'results_per_page' => (int) $context['results_per_page'],
            'pages' => $pages,
            'updated_at' => (int) $context['updated_at'],
        ];

        $this->saveServerSessionStore($this->pruneStoredContexts($store));
    }

    protected function getStoredListingContext($contextKey = null)
    {
        if ($contextKey === null) {
            $this->debugLog('Requested stored context without key.');
            return null;
        }

        $store = $this->getServerSessionStore();
        $prunedStore = $this->pruneStoredContexts($store);

        if ($prunedStore !== $store) {
            $this->saveServerSessionStore($prunedStore);
        } else {
            $this->closePhpSession();
        }

        if (empty($prunedStore[$contextKey]) || !is_array($prunedStore[$contextKey])) {
            $this->debugLog('Stored context not found.', [
                'context_key' => $contextKey,
            ]);
            return null;
        }

        $context = $prunedStore[$contextKey];

        if (!is_array($context)) {
            $this->deleteStoredListingContext($contextKey);

            return null;
        }

        if (empty($context['updated_at']) || (time() - (int) $context['updated_at']) > self::SESSION_TTL) {
            $this->deleteStoredListingContext($contextKey);

            return null;
        }

        if ((int) ($context['total_products'] ?? 0) < 2 || (int) ($context['results_per_page'] ?? 0) < 1) {
            $this->deleteStoredListingContext($contextKey);

            return null;
        }

        $pages = [];
        if (!empty($context['pages']) && is_array($context['pages'])) {
            foreach ($context['pages'] as $page => $ids) {
                $pages[(string) $page] = is_array($ids) ? array_values(array_map('intval', $ids)) : $this->decodeStoredIds((string) $ids);
            }
        }
        $context['pages'] = $pages;

        return $context;
    }

    protected function pruneStoredContexts(array $store)
    {
        foreach ($store as $key => $context) {
            if (
                !is_array($context)
                || empty($context['updated_at'])
                || (time() - (int) $context['updated_at']) > self::SESSION_TTL
            ) {
                unset($store[$key]);
            }
        }

        uasort($store, function ($left, $right) {
            return ((int) ($right['updated_at'] ?? 0)) <=> ((int) ($left['updated_at'] ?? 0));
        });

        if (count($store) > self::MAX_STORED_CONTEXTS) {
            $store = array_slice($store, 0, self::MAX_STORED_CONTEXTS, true);
        }

        return $store;
    }

    protected function isSupportedQueryType($queryType)
    {
        return in_array($queryType, ['category', 'manufacturer', 'search'], true);
    }

    protected function getRelativeModuleLink($controller)
    {
        $url = (string) $this->context->link->getModuleLink($this->name, $controller, [], null, null, null, true);
        $path = (string) parse_url($url, PHP_URL_PATH);
        $query = (string) parse_url($url, PHP_URL_QUERY);

        if ($path === '') {
            return $url;
        }

        return $query !== '' ? $path . '?' . $query : $path;
    }

    protected function getFrontendContextKey($phpSelf)
    {
        if (!in_array($phpSelf, ['category', 'search', 'manufacturer', 'brand'], true)) {
            return '';
        }

        if ($this->isValidContextKey($this->currentListingContextKey)) {
            return $this->currentListingContextKey;
        }

        return $this->getCurrentRequestContextKey();
    }

    protected function getFrontendContextSeed($phpSelf)
    {
        if (!$this->context || !$this->context->controller) {
            return [];
        }

        $seed = [
            'shop' => (int) $this->context->shop->id,
            'lang' => (int) $this->context->language->id,
            'currency' => (int) $this->context->currency->id,
            'queryType' => '',
            'idCategory' => 0,
            'idManufacturer' => 0,
            'searchString' => '',
            'searchTag' => '',
            'defaultSort' => '',
        ];

        if ($phpSelf === 'category') {
            $seed['queryType'] = 'category';
            $seed['idCategory'] = (int) $this->getCurrentCategory((int) Tools::getValue('id_category', 0))->id;
            $seed['defaultSort'] = 'product.' . Tools::getProductsOrder('by') . '.' . Tools::getProductsOrder('way');
        } elseif ($phpSelf === 'manufacturer' || $phpSelf === 'brand') {
            $seed['queryType'] = 'manufacturer';
            $seed['idManufacturer'] = (int) $this->getCurrentManufacturer((int) Tools::getValue('id_manufacturer', 0))->id;
            $seed['defaultSort'] = 'product.' . Tools::getProductsOrder('by') . '.' . Tools::getProductsOrder('way');
        } elseif ($phpSelf === 'search') {
            $seed['queryType'] = 'search';
            $seed['searchString'] = (string) Tools::getValue('s', Tools::getValue('search_query', ''));
            $seed['searchTag'] = (string) Tools::getValue('tag', '');
            $seed['defaultSort'] = 'product.position.desc';
        }

        return $seed;
    }

    protected function getCurrentRequestContextKey()
    {
        if (!$this->context || !$this->context->controller) {
            return '';
        }

        $controller = $this->context->controller;
        $phpSelf = (string) ($controller->php_self ?? '');
        $query = new ProductSearchQuery();

        if ($phpSelf === 'category') {
            $categoryId = 0;

            if (method_exists($controller, 'getCategory')) {
                $category = $controller->getCategory();

                if (Validate::isLoadedObject($category)) {
                    $categoryId = (int) $category->id;
                }
            } else {
                $categoryId = (int) Tools::getValue('id_category', 0);
            }

            $query
                ->setQueryType('category')
                ->setIdCategory($categoryId)
                ->setSortOrder($this->getCurrentSortOrder('category'));
        } elseif ($phpSelf === 'manufacturer' || $phpSelf === 'brand') {
            $manufacturerId = 0;

            if (method_exists($controller, 'getManufacturer')) {
                $manufacturer = $controller->getManufacturer();

                if (Validate::isLoadedObject($manufacturer)) {
                    $manufacturerId = (int) $manufacturer->id;
                }
            } else {
                $manufacturerId = (int) Tools::getValue('id_manufacturer', 0);
            }

            $query
                ->setQueryType('manufacturer')
                ->setIdManufacturer($manufacturerId)
                ->setSortOrder($this->getCurrentSortOrder('manufacturer'));
        } elseif ($phpSelf === 'search') {
            $query
                ->setQueryType('search')
                ->setSearchString((string) Tools::getValue('s', Tools::getValue('search_query', '')))
                ->setSearchTag((string) Tools::getValue('tag', ''))
                ->setSortOrder($this->getCurrentSortOrder('search'));
        } else {
            return '';
        }

        $query->setEncodedFacets((string) Tools::getValue('q', ''));

        return $this->buildContextKey($query);
    }

    protected function getCurrentSortOrder($queryType)
    {
        $explicitOrder = (string) Tools::getValue('order', '');

        if ($explicitOrder !== '') {
            return SortOrder::newFromString($explicitOrder);
        }

        if ($queryType === 'search') {
            return new SortOrder('product', 'position', 'desc');
        }

        return new SortOrder('product', Tools::getProductsOrder('by'), Tools::getProductsOrder('way'));
    }

    protected function buildContextKey(ProductSearchQuery $query)
    {
        $sortOrder = $query->getSortOrder();
        $payload = [
            'shop' => (int) $this->context->shop->id,
            'lang' => (int) $this->context->language->id,
            'currency' => (int) $this->context->currency->id,
            'query_type' => (string) $query->getQueryType(),
            'id_category' => (int) $query->getIdCategory(),
            'id_manufacturer' => (int) $query->getIdManufacturer(),
            'search_string' => trim((string) $query->getSearchString()),
            'search_tag' => trim((string) $query->getSearchTag()),
            'encoded_facets' => (string) $query->getEncodedFacets(),
            'sort' => $sortOrder ? (string) $sortOrder->toString() : '',
        ];

        return hash('sha256', json_encode($payload));
    }

    protected function extractProductIds(array $products)
    {
        $ids = [];

        foreach ($products as $product) {
            $idProduct = (int) ($product['id_product'] ?? 0);

            if ($idProduct > 0) {
                $ids[] = $idProduct;
            }
        }

        return $ids;
    }

    protected function buildSearchContext()
    {
        return (new ProductSearchContext())
            ->setIdShop($this->context->shop->id)
            ->setIdLang($this->context->language->id)
            ->setIdCurrency($this->context->currency->id)
            ->setIdCustomer($this->context->customer ? $this->context->customer->id : null);
    }

    protected function resolveSearchProvider(ProductSearchQuery $query)
    {
        $providers = Hook::exec(
            'productSearchProvider',
            ['query' => $query],
            null,
            true
        );

        if (is_array($providers)) {
            foreach ($providers as $provider) {
                if ($provider instanceof ProductSearchProviderInterface) {
                    return $provider;
                }
            }
        }

        if ($query->getQueryType() === 'category') {
            $category = $this->getCurrentCategory((int) $query->getIdCategory());

            if (Validate::isLoadedObject($category)) {
                return new CategoryProductSearchProvider($this->getTranslator(), $category);
            }
        }

        if ($query->getQueryType() === 'manufacturer') {
            $manufacturer = $this->getCurrentManufacturer((int) $query->getIdManufacturer());

            if (Validate::isLoadedObject($manufacturer)) {
                return new ManufacturerProductSearchProvider($this->getTranslator(), $manufacturer);
            }
        }

        if ($query->getQueryType() === 'search') {
            return new SearchProductSearchProvider($this->getTranslator());
        }

        return null;
    }

    protected function getCurrentCategory($fallbackId)
    {
        $controller = $this->context->controller;

        if ($controller && method_exists($controller, 'getCategory')) {
            $category = $controller->getCategory();

            if (Validate::isLoadedObject($category)) {
                return $category;
            }
        }

        return new Category($fallbackId, $this->context->language->id);
    }

    protected function getCurrentManufacturer($fallbackId)
    {
        $controller = $this->context->controller;

        if ($controller && method_exists($controller, 'getManufacturer')) {
            $manufacturer = $controller->getManufacturer();

            if (Validate::isLoadedObject($manufacturer)) {
                return $manufacturer;
            }
        }

        return new Manufacturer($fallbackId, $this->context->language->id);
    }

    protected function decodeStoredIds($ids)
    {
        if ($ids === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('intval', explode(',', $ids)))));
    }

    public function isValidContextKey($contextKey)
    {
        return is_string($contextKey) && preg_match('/^[a-f0-9]{64}$/', $contextKey);
    }

    public function getNavigationProducts($currentProductId, $contextKey = null)
    {
        $context = $this->getStoredListingContext($contextKey);

        if (!$context) {
            $this->debugLog('Navigation context unavailable.', [
                'context_key' => $contextKey,
                'product_id' => (int) $currentProductId,
            ]);
            return ['prev' => null, 'next' => null];
        }

        $location = $this->findProductInStoredContext((int) $currentProductId, $context);

        if ($location === null) {
            $this->debugLog('Current product not found in navigation context.', [
                'context_key' => $contextKey,
                'product_id' => (int) $currentProductId,
                'pages' => array_keys($context['pages']),
            ]);
            return ['prev' => null, 'next' => null];
        }

        $prevId = $this->resolveAdjacentProductId($contextKey, $context, $location['page'], $location['index'], 'prev');
        $nextId = $this->resolveAdjacentProductId($contextKey, $context, $location['page'], $location['index'], 'next');
        $products = $this->presentProductsByIds([$prevId, $nextId]);

        return [
            'prev' => isset($products[$prevId]) ? $products[$prevId] : null,
            'next' => isset($products[$nextId]) ? $products[$nextId] : null,
        ];
    }

    public function renderNavigationForContext($productId, $contextKey)
    {
        if ((int) $productId <= 0 || !$this->isValidContextKey($contextKey)) {
            return '';
        }

        $navigation = $this->getNavigationProducts((int) $productId, $contextKey);

        if (empty($navigation['prev']) && empty($navigation['next'])) {
            return '';
        }

        $this->context->smarty->assign([
            'xpn_product_id' => (int) $productId,
            'xpn_show_price' => (int) Configuration::get(self::CONFIG_SHOW_PRICE, 1),
            'xpn_title' => $this->trans('Browse products', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_prev_label' => $this->trans('Previous', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_next_label' => $this->trans('Next', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_prev' => $navigation['prev'],
            'xpn_next' => $navigation['next'],
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/nav.tpl');
    }

    protected function presentProductsByIds(array $productIds)
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds))));

        if (!$productIds) {
            return [];
        }

        $rawProducts = [];
        foreach ($productIds as $productId) {
            $rawProducts[] = ['id_product' => $productId];
        }

        $products = (new ProductAssembler($this->context))->assembleProducts($rawProducts);
        $presenter = new ProductListingPresenter(
            new ImageRetriever($this->context->link),
            $this->context->link,
            new PriceFormatter(),
            new ProductColorsRetriever(),
            $this->getTranslator()
        );
        $settings = (new ProductPresenterFactory($this->context, new TaxConfiguration()))->getPresentationSettings();
        $output = [];

        foreach ($products as $product) {
            $presented = $presenter->present($settings, $product, $this->context->language);
            $mapped = $this->mapPresentedProduct($presented);

            if ($mapped !== null) {
                $output[(int) $mapped['id']] = $mapped;
            }
        }

        return $output;
    }

    protected function ensurePhpSessionStarted()
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    protected function closePhpSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
    }

    protected function getServerSessionStore()
    {
        $this->ensurePhpSessionStarted();

        $store = [];

        if (!empty($_SESSION[self::SESSION_KEY]) && is_array($_SESSION[self::SESSION_KEY])) {
            $store = $_SESSION[self::SESSION_KEY];
        }

        return is_array($store) ? $store : [];
    }

    protected function saveServerSessionStore(array $store)
    {
        $this->ensurePhpSessionStarted();
        $_SESSION[self::SESSION_KEY] = $store;
        $this->closePhpSession();
    }

    protected function deleteStoredListingContext($contextKey)
    {
        if (!$this->isValidContextKey($contextKey)) {
            return;
        }

        $store = $this->getServerSessionStore();

        if (array_key_exists($contextKey, $store)) {
            unset($store[$contextKey]);
            $this->saveServerSessionStore($store);
            return;
        }

        $this->closePhpSession();
    }

    protected function findProductInStoredContext($productId, array $context)
    {
        foreach ($context['pages'] as $page => $ids) {
            $index = array_search((int) $productId, array_values(array_map('intval', $ids)), true);

            if ($index !== false) {
                return [
                    'page' => (int) $page,
                    'index' => (int) $index,
                ];
            }
        }

        return null;
    }

    protected function resolveAdjacentProductId($contextKey, array &$context, $page, $index, $direction)
    {
        $pageIds = isset($context['pages'][(string) $page]) ? array_values(array_map('intval', $context['pages'][(string) $page])) : [];
        $isPrev = $direction === 'prev';
        $boundaryIndex = $isPrev ? $index - 1 : $index + 1;

        if (isset($pageIds[$boundaryIndex])) {
            return (int) $pageIds[$boundaryIndex];
        }

        $totalPages = max(1, (int) ceil((int) $context['total_products'] / max(1, (int) $context['results_per_page'])));
        $targetPage = $isPrev ? $page - 1 : $page + 1;

        if ($targetPage < 1) {
            $targetPage = $totalPages;
        } elseif ($targetPage > $totalPages) {
            $targetPage = 1;
        }

        $targetIds = $this->getOrLoadContextPage($contextKey, $context, $targetPage);

        if (!$targetIds) {
            return 0;
        }

        return (int) ($isPrev ? end($targetIds) : reset($targetIds));
    }

    protected function getOrLoadContextPage($contextKey, array &$context, $page)
    {
        $pageKey = (string) (int) $page;

        if (isset($context['pages'][$pageKey]) && is_array($context['pages'][$pageKey]) && $context['pages'][$pageKey]) {
            return array_values(array_map('intval', $context['pages'][$pageKey]));
        }

        $query = $this->buildQueryFromStoredContext($context, (int) $page);
        $provider = $this->resolveSearchProvider($query);

        if (!$provider instanceof ProductSearchProviderInterface) {
            return [];
        }

        $result = $provider->runQuery($this->buildSearchContext(), $query);
        $ids = $this->extractProductIds($result->getProducts());

        if (!$ids) {
            return [];
        }

        $context['pages'][$pageKey] = implode(',', $ids);
        $context['updated_at'] = time();
        $this->writeStoredListingContext($contextKey, $context);
        $context['pages'][$pageKey] = array_values(array_unique(array_filter(array_map('intval', $ids))));

        return $context['pages'][$pageKey];
    }

    protected function buildQueryFromStoredContext(array $context, $page)
    {
        $query = new ProductSearchQuery();
        $query
            ->setQueryType((string) $context['query_type'])
            ->setIdCategory((int) $context['id_category'])
            ->setIdManufacturer((int) $context['id_manufacturer'])
            ->setSearchString((string) $context['search_string'])
            ->setSearchTag((string) $context['search_tag'])
            ->setEncodedFacets((string) $context['encoded_facets'])
            ->setPage(max(1, (int) $page))
            ->setResultsPerPage(max(1, (int) $context['results_per_page']));

        $sortOrder = (string) ($context['sort_order'] ?? '');
        if ($sortOrder !== '') {
            $query->setSortOrder(SortOrder::newFromString($sortOrder));
        }

        return $query;
    }

    protected function mapPresentedProduct($product)
    {
        $id = (int) $this->getProductValue($product, 'id_product', 0);
        $url = (string) $this->getProductValue($product, 'url', $this->getProductValue($product, 'canonical_url', ''));

        if ($id <= 0 || $url === '') {
            return null;
        }

        return [
            'id' => $id,
            'url' => $url,
            'name' => trim((string) $this->getProductValue($product, 'name', '')),
            'image' => $this->extractImageUrl($product),
            'price' => trim(strip_tags((string) $this->getProductValue($product, 'price', ''))),
        ];
    }

    protected function extractImageUrl($product)
    {
        $cover = $this->getProductValue($product, 'cover', []);

        if (!is_array($cover) || empty($cover)) {
            return '';
        }

        if (!empty($cover['bySize']['home_default']['url'])) {
            return (string) $cover['bySize']['home_default']['url'];
        }

        if (!empty($cover['bySize']) && is_array($cover['bySize'])) {
            foreach ($cover['bySize'] as $size) {
                if (!empty($size['url'])) {
                    return (string) $size['url'];
                }
            }
        }

        foreach (['large', 'medium', 'small'] as $sizeKey) {
            if (!empty($cover[$sizeKey]['url'])) {
                return (string) $cover[$sizeKey]['url'];
            }
        }

        return (string) ($cover['url'] ?? '');
    }

    protected function getProductValue($product, $key, $default = null)
    {
        if (is_array($product) && array_key_exists($key, $product)) {
            return $product[$key];
        }

        if ($product instanceof ArrayAccess && isset($product[$key])) {
            return $product[$key];
        }

        if (is_object($product) && isset($product->{$key})) {
            return $product->{$key};
        }

        return $default;
    }
}
