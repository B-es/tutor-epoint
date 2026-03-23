<?php

namespace TPPay;

use Tutor\Ecommerce\Settings;
use Ollyo\PaymentHub\Core\Payment\BaseConfig;
use Tutor\PaymentGateways\Configs\PaymentUrlsTrait;
use Ollyo\PaymentHub\Contracts\Payment\ConfigContract;

class ProdamusConfig extends BaseConfig implements ConfigContract {

	private const CONFIG_KEYS = [
		'environment' => 'select',
		'api_token' => 'secret_key',
	];

	use PaymentUrlsTrait;

	private $environment;
	private $api_token;

	protected $name = 'prodamus';

	public function __construct() {
		parent::__construct();

		$settings = Settings::get_payment_gateway_settings('prodamus');

		if (!is_array($settings)) {
			throw new \RuntimeException(__('Unable to load Prodamus gateway settings', 'tppay'));
		}

		$config_keys = array_keys(self::CONFIG_KEYS);

		foreach ($config_keys as $key) {
			if ('webhook_url' !== $key) {
				$this->$key = $this->get_field_value($settings, $key);
			}
		}
	}

	public function getMode(): string {
		return $this->environment;
	}

	public function getAPIToken(): string {
		return $this->api_token;
	}

	public function getApiDomain(): string {
		return $this->environment === 'sandbox'
			? 'https://demo.payform.ru'
			: 'https://payform.ru';
	}

	public function is_configured(): bool {
		return !empty($this->api_token);
	}

	public function createConfig(): void {
		parent::createConfig();

		$config = [
			'api_token' => $this->getAPIToken(),
			'api_domain' => $this->getApiDomain(),
		];

		$this->updateConfig($config);
	}
}