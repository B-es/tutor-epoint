<?php

namespace TPPay;
use TPPay\ProdamusOrderProcess;

final class Init {
    private const PRODAMUS_GATEWAY_CONFIG = [
        'prodamus' => [
            'gateway_class' => ProdamusGateway::class,
            'config_class' => ProdamusConfig::class,
        ],
    ];

    public function __construct() {
        add_filter('tutor_gateways_with_class', [self::class, 'payment_gateways_with_ref'], 10, 2);
        add_filter('tutor_payment_gateways_with_class', [self::class, 'add_payment_gateways']);
        add_filter('tutor_payment_gateways', [$this, 'add_tutor_prodamus_payment_method'], 100);
        add_filter('init', [$this, 'process_prodamus_form_submission']);
    }

    public static function payment_gateways_with_ref(array $value, string $gateway): array {
        if (isset(self::PRODAMUS_GATEWAY_CONFIG[$gateway])) {
            $value[$gateway] = self::PRODAMUS_GATEWAY_CONFIG[$gateway];
        }

        return $value;
    }

    public static function add_payment_gateways(array $gateways): array {
        return $gateways + self::PRODAMUS_GATEWAY_CONFIG;
    }

    public function add_tutor_prodamus_payment_method(array $methods): array {
        $prodamus_payment_method = [
            'name' => 'prodamus',
            'label' => __('Prodamus', 'tppay'),
            'is_installed' => true,
            'is_active' => true,
            'icon' => TPPAY_URL . 'assets/prodamus-logo.png',
            'support_subscription' => false,
            'fields' => [
                [
                    'name' => 'environment',
                    'type' => 'select',
                    'label' => __('Environment', 'tppay'),
                    'options' => [
                        'sandbox' => __('Sandbox', 'tppay'),
                        'live' => __('Live', 'tppay'),
                    ],
                    'value' => 'sandbox',
                ],
                [
                    'name' => 'api_token',
                    'type' => 'secret_key',
                    'label' => __('API Token', 'tppay'),
                    'value' => '',
                    'desc' => __('Your Prodamus API Token.', 'tppay'),
                ],
                [
                    'name' => 'webhook_url',
                    'type' => 'webhook_url',
                    'label' => __('IPN URL', 'tppay'),
                    'value' => '',
                    'desc' => __('Copy this URL and add it to your Prodamus merchant panel as IPN URL', 'tppay'),
                ],
            ],
        ];

        $methods[] = $prodamus_payment_method;
        return $methods;
    }

    public function process_prodamus_form_submission(): void {
        $prodamus = new ProdamusOrderProcess();
        $prodamus->process_prodamus_form_submission();
    }

}