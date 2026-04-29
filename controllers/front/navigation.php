<?php

class Xtec_productnavNavigationModuleFrontController extends ModuleFrontController
{
    public $ajax = true;

    public function displayAjax()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');

        $productId = (int) Tools::getValue('id_product', 0);
        $contextKey = (string) Tools::getValue('context_key', '');

        if (
            !$this->module instanceof Xtec_productnav
            || $productId <= 0
            || !$this->module->isValidContextKey($contextKey)
        ) {
            if ($this->module instanceof Xtec_productnav) {
                $this->module->debugLog('Navigation ajax request rejected.', [
                    'product_id' => $productId,
                    'context_key' => $contextKey,
                    'valid_key' => $this->module->isValidContextKey($contextKey),
                ]);
            }
            $this->ajaxRender(json_encode(['html' => '']));

            return;
        }

        $html = $this->module->renderNavigationForContext($productId, $contextKey);
        $this->module->debugLog('Navigation ajax response built.', [
            'product_id' => $productId,
            'context_key' => $contextKey,
            'has_html' => $html !== '',
        ]);
        $this->ajaxRender(json_encode(['html' => $html]));
    }
}
