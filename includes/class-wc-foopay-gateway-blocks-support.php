<?php
use Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType;

final class WC_Foopay_Blocks extends AbstractPaymentMethodType
{
    protected $name = 'foopay';
    protected $gateway;

    public function initialize()
    {
        $gateways = WC()->payment_gateways()->payment_gateways();
        $this->gateway = $gateways['foopay'] ?? null;
    }

    public function needs_shipping_address()
    {
        return false;
    }

    public function is_active()
    {
        $is = $this->gateway && $this->gateway->is_available();

        return $is;
    }

    public function get_payment_method_script_handles()
    {
        wp_enqueue_script(
            'wc-foopay-blocks-integration',
            plugins_url('src/index.js', __DIR__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            null,
            true
        );

        $settings = get_option('woocommerce_foopay_settings', []);
        wp_add_inline_script(
            'wc-foopay-blocks-integration',
            'window.wc = window.wc || {}; window.wc.wcSettings = window.wc.wcSettings || {}; window.wc.wcSettings["foopay_data"] = ' . wp_json_encode([
                'title' => $settings['title'] ?? 'Foopay',
                'description' => $settings['description'] ?? 'Pay securely using Foopay.',
                'ariaLabel' => $settings['title'] ?? 'Foopay',

            ]) . ';',
            'before'
        );
        return ['wc-foopay-blocks-integration'];
    }


    public function get_payment_method_data()
    {
        $settings = get_option('woocommerce_foopay_settings', []);
        return [
            'title' => $settings['title'] ?? 'Foopay',
            'description' => $settings['description'] ?? 'Pay securely using Foopay.',
            'ariaLabel' => $settings['title'] ?? 'Foopay',
            'supports' => ['products', 'subscriptions', 'default', 'virtual'],

        ];
    }

    public function enqueue_payment_method_script()
    {
        wp_enqueue_script(
            'wc-foopay-blocks-integration',
            plugins_url('src/index.js', __DIR__),
            ['wc-blocks-registry', 'wc-settings', 'wp-element', 'wp-i18n'],
            null,
            true
        );
    }
}