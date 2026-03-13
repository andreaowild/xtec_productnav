<?php
if (!defined('_PS_VERSION_')) {
    exit;
}

class Xtec_productnav extends Module
{
    public const CONFIG_SHOW_PRICE = 'XTECPRODUCTNAV_SHOW_PRICE';

    public function __construct()
    {
        $this->name = 'xtec_productnav';
        $this->tab = 'front_office_features';
        $this->version = '1.0.1';
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

    protected function renderForm()
    {
        $formAction = htmlspecialchars($_SERVER['REQUEST_URI'] ?? '', ENT_QUOTES, 'UTF-8');
        $showPrice = (int) Configuration::get(self::CONFIG_SHOW_PRICE, 1);

        $html = [];
        $html[] = '<div class="panel">';
        $html[] = '<h3><i class="icon icon-arrows-h"></i> ' . $this->trans('XTec Product Navigation', [], 'Modules.Xtec_productnav.Admin') . '</h3>';
        $html[] = '<p>' . $this->trans(
            'The module stores the last product listing visited by the customer (category or search), then renders previous / next cards on the product page.',
            [],
            'Modules.Xtec_productnav.Admin'
        ) . '</p>';
        $html[] = '<form method="post" action="' . $formAction . '">';
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

    public function hookDisplayHeader()
    {
        if (!$this->context || !$this->context->controller) {
            return;
        }

        $phpSelf = (string) ($this->context->controller->php_self ?? '');
        $relevantControllers = ['product', 'category', 'search', 'manufacturer'];

        if (!in_array($phpSelf, $relevantControllers, true)) {
            return;
        }

        $this->context->controller->registerStylesheet(
            'module-' . $this->name . '-front',
            'modules/' . $this->name . '/views/css/front.css',
            [
                'media' => 'all',
                'priority' => 150,
            ]
        );

        $this->context->controller->registerJavascript(
            'module-' . $this->name . '-front',
            'modules/' . $this->name . '/views/js/front.js',
            [
                'position' => 'bottom',
                'priority' => 150,
            ]
        );
    }

    public function hookDisplayFooterProduct(array $params)
    {
        $productId = $this->extractProductId($params);

        if ($productId <= 0) {
            return '';
        }

        $this->context->smarty->assign([
            'xpn_product_id' => $productId,
            'xpn_show_price' => (int) Configuration::get(self::CONFIG_SHOW_PRICE, 1),
            'xpn_title' => $this->trans('Browse products', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_prev_label' => $this->trans('Previous', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_next_label' => $this->trans('Next', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_context_category' => $this->trans('Category', [], 'Modules.Xtec_productnav.Shop'),
            'xpn_context_search' => $this->trans('Search', [], 'Modules.Xtec_productnav.Shop'),
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/nav.tpl');
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
}
