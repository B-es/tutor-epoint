<?php

namespace TEPay;
use TEPay\EpointOrderProcess;

final class Init
{
    private const EPOINT_GATEWAY_CONFIG = [
        "epoint" => [
            "gateway_class" => EpointGateway::class,
            "config_class" => EpointConfig::class,
        ],
    ];

    public function __construct()
    {
        add_filter(
            "tutor_gateways_with_class",
            [self::class, "payment_gateways_with_ref"],
            10,
            2,
        );
        add_filter("tutor_payment_gateways_with_class", [
            self::class,
            "add_payment_gateways",
        ]);
        add_filter(
            "tutor_payment_gateways",
            [$this, "add_tutor_epoint_payment_method"],
            100,
        );
        add_filter("init", [$this, "process_epoint_form_submission"]);
    }

    public static function payment_gateways_with_ref(
        array $value,
        string $gateway,
    ): array {
        if (isset(self::EPOINT_GATEWAY_CONFIG[$gateway])) {
            $value[$gateway] = self::EPOINT_GATEWAY_CONFIG[$gateway];
        }

        return $value;
    }

    public static function add_payment_gateways(array $gateways): array
    {
        return $gateways + self::EPOINT_GATEWAY_CONFIG;
    }

    public function add_tutor_epoint_payment_method(array $methods): array
    {
        $epoint_payment_method = [
            "name" => "epoint",
            "label" => __("Epoint", "tepay"),
            "is_installed" => true,
            "is_active" => true,
            "icon" => TEPAY_URL . "assets/epoint-logo.png",
            "support_subscription" => false,
            "fields" => [
                [
                    "name" => "public_key",
                    "type" => "text",
                    "label" => __("Public Key", "tepay"),
                    "value" => "",
                    "desc" => __(
                        "Your Epoint Public Key (identifier).",
                        "tepay",
                    ),
                ],
                [
                    "name" => "private_key",
                    "type" => "secret_key",
                    "label" => __("Private Key", "tepay"),
                    "value" => "",
                    "desc" => __(
                        "Your Epoint Private Key (secret key for signature).",
                        "tepay",
                    ),
                ],
                [
                    "name" => "webhook_url",
                    "type" => "webhook_url",
                    "label" => __("Result URL", "tepay"),
                    "value" => "",
                    "desc" => __(
                        "Copy this URL and add it to your Epoint merchant panel as Result URL for callbacks.",
                        "tepay",
                    ),
                ],
            ],
        ];

        $methods[] = $epoint_payment_method;
        return $methods;
    }

    public function process_epoint_form_submission(): void
    {
        // Получаем настройки Epoint
        $options = get_option("tutor_option");
        $payment_settings = json_decode($options["payment_settings"], true);

        $public_key = "";
        $private_key = "";

        if (isset($payment_settings["payment_methods"])) {
            foreach ($payment_settings["payment_methods"] as $method) {
                if ($method["name"] === "epoint") {
                    foreach ($method["fields"] as $field) {
                        if ($field["name"] === "public_key") {
                            $public_key = $field["value"];
                        }
                        if ($field["name"] === "private_key") {
                            $private_key = $field["value"];
                        }
                    }
                    break;
                }
            }
        }

        if (empty($public_key) || empty($private_key)) {
            return;
        }

        // Создаем процессор с параметрами
        $epoint = new EpointOrderProcess($public_key, $private_key);
        $epoint->process_epoint_form_submission();
    }
}
