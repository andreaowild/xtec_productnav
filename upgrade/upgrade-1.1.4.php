<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

function upgrade_module_1_1_4($module)
{
    $hooks = [
        'displayHeader',
        'displayFooterProduct',
        'actionProductSearchProviderRunQueryAfter',
    ];

    foreach ($hooks as $hook) {
        if (!$module->isRegisteredInHook($hook) && !$module->registerHook($hook)) {
            return false;
        }
    }

    if (Configuration::get(Xtec_productnav::CONFIG_SHOW_PRICE) === false) {
        Configuration::updateValue(Xtec_productnav::CONFIG_SHOW_PRICE, 1);
    }

    return true;
}
