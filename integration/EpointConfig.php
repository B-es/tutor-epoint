<?php

namespace TEPay;

use Tutor\Ecommerce\Settings;
use Ollyo\PaymentHub\Core\Payment\BaseConfig;
use Tutor\PaymentGateways\Configs\PaymentUrlsTrait;
use Ollyo\PaymentHub\Contracts\Payment\ConfigContract;

class EpointConfig extends BaseConfig implements ConfigContract
{
    private const CONFIG_KEYS = [
        "public_key" => "text",
        "private_key" => "secret_key",
    ];

    use PaymentUrlsTrait;

    private $public_key;
    private $private_key;

    protected $name = "epoint";

    public function __construct()
    {
        parent::__construct();

        $settings = Settings::get_payment_gateway_settings("epoint");

        if (!is_array($settings)) {
            throw new \RuntimeException(
                __("Unable to load Epoint gateway settings", "tepay"),
            );
        }

        $config_keys = array_keys(self::CONFIG_KEYS);

        foreach ($config_keys as $key) {
            if ("webhook_url" !== $key) {
                $this->$key = $this->get_field_value($settings, $key);
            }
        }
    }

    public function getPublicKey(): string
    {
        return $this->public_key;
    }

    public function getPrivateKey(): string
    {
        return $this->private_key;
    }

    public function getApiDomain(): string
    {
        return "https://epoint.az";
    }

    public function is_configured(): bool
    {
        return !empty($this->public_key) && !empty($this->private_key);
    }

    public function createConfig(): void
    {
        parent::createConfig();

        $config = [
            "public_key" => $this->getPublicKey(),
            "private_key" => $this->getPrivateKey(),
            "api_domain" => $this->getApiDomain(),
        ];

        $this->updateConfig($config);
    }
}
