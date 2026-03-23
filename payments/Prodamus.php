<?php

namespace Payments\Prodamus;

use Throwable;
use ErrorException;
use Ollyo\PaymentHub\Core\Support\Arr;
use Ollyo\PaymentHub\Core\Support\System;
use GuzzleHttp\Exception\RequestException;
use Ollyo\PaymentHub\Core\Payment\BasePayment;

class Prodamus extends BasePayment {
	private const API_PROCESS_ENDPOINT = '/gwprocess/v4/api.php';
	private const API_VALIDATION_ENDPOINT = '/validator/api/validationserverAPI.php';
	private const DEFAULT_CURRENCY = 'BDT';
	private const DEFAULT_COUNTRY = 'Bangladesh';
	private const DEFAULT_PHONE = '01700000000';
	private const DEFAULT_POSTCODE = '0000';
	private const TRANSACTION_PREFIX = 'TUTOR-';
	private const PRODUCT_CATEGORY = 'education';
	private const PRODUCT_PROFILE = 'non-physical-goods';
	private const SHIPPING_METHOD = 'NO';

	private const STATUS_MAP = [
		'VALID' => 'paid',
		'VALIDATED' => 'paid',
		'FAILED' => 'failed',
		'CANCELLED' => 'cancelled',
		'PENDING' => 'pending',
	];

	protected $client;

	public function check(): bool {
		$configKeys = Arr::make(['api_token', 'mode']);

		$isConfigOk = $configKeys->every(function ($key) {
			return $this->config->has($key) && !empty($this->config->get($key));
		});

		return $isConfigOk;
	}

	public function setup(): void {
		try {
			$this->client = [
				'api_token' => $this->config->get('api_token'),
				'api_domain' => $this->config->get('api_domain'),
			];
		} catch (Throwable $error) {
			throw $error;
		}
	}

	public function setData($data): void {
		try {
			$structuredData = $this->prepareData($data);
			parent::setData($structuredData);
		} catch (Throwable $error) {
			throw $error;
		}
	}

	private function prepareData(object $data): array {
		if (!isset($data->order_id) || empty($data->order_id)) {
			throw new \InvalidArgumentException(__('Order ID is required for payment processing', 'tppay'));
		}

		if (!isset($data->currency) || !isset($data->currency->code)) {
			throw new \InvalidArgumentException(__('Currency information is required for payment processing', 'tppay'));
		}

		if (!isset($data->customer) || !isset($data->customer->email)) {
			throw new \InvalidArgumentException(__('Customer email is required for payment processing', 'tppay'));
		}

		$tran_id = self::TRANSACTION_PREFIX . $data->order_id . '-' . time();

		$total_price = isset($data->total_price) && !empty($data->total_price) ? (float) $data->total_price : 0;

		if ($total_price <= 0) {
			throw new \InvalidArgumentException(__('Payment amount must be greater than zero', 'tppay'));
		}

		$total_amount = number_format($total_price, 2, '.', '');
		$product_amount = number_format($total_price, 2, '.', '');

		$prodamusData = [
			'total_amount' => $total_amount,
			'currency' => $data->currency->code,
			'tran_id' => $tran_id,
			'product_category' => self::PRODUCT_CATEGORY,
			'product_name' => $data->order_description ?? __('Course Purchase', 'tppay'),
			'product_profile' => self::PRODUCT_PROFILE,

			'success_url' => $this->config->get('success_url'),
			'fail_url' => $this->config->get('cancel_url'),
			'cancel_url' => $this->config->get('cancel_url'),
			'ipn_url' => $this->config->get('webhook_url'),

			'cus_name' => $data->customer->name ?? __('Customer', 'tppay'),
			'cus_email' => $data->customer->email,
			'cus_add1' => $data->billing_address->address1 ?? __('N/A', 'tppay'),
			'cus_add2' => $data->billing_address->address2 ?? '',
			'cus_city' => $data->billing_address->city ?? __('N/A', 'tppay'),
			'cus_state' => $data->billing_address->state ?? '',
			'cus_postcode' => $data->billing_address->postal_code ?? self::DEFAULT_POSTCODE,
			'cus_country' => $data->billing_address->country->name ?? ($data->currency->code === self::DEFAULT_CURRENCY ? self::DEFAULT_COUNTRY : __('N/A', 'tppay')),
			'cus_phone' => $data->customer->phone_number ?? self::DEFAULT_PHONE,

			'shipping_method' => self::SHIPPING_METHOD,
			'num_of_item' => 1,
			'ship_name' => $data->customer->name ?? __('Customer', 'tppay'),
			'ship_add1' => $data->billing_address->address1 ?? __('N/A', 'tppay'),
			'ship_add2' => $data->billing_address->address2 ?? '',
			'ship_city' => $data->billing_address->city ?? __('N/A', 'tppay'),
			'ship_state' => $data->billing_address->state ?? '',
			'ship_postcode' => $data->billing_address->postal_code ?? self::DEFAULT_POSTCODE,
			'ship_country' => $data->billing_address->country->name ?? ($data->currency->code === self::DEFAULT_CURRENCY ? self::DEFAULT_COUNTRY : __('N/A', 'tppay')),

			'value_a' => $data->order_id,
			'value_b' => $data->customer->email,
			'value_c' => $data->store_name ?? __('Tutor LMS', 'tppay'),
			'product_amount' => $product_amount,
		];

		return $prodamusData;
	}

	public function createPayment(): void {
		try {
			$paymentData = $this->getData();

			$paymentData['api_token'] = $this->client['api_token'];

			$apiUrl = $this->client['api_domain'] . self::API_PROCESS_ENDPOINT;
			$response = $this->callProdamusApi($apiUrl, $paymentData);

			if ($response && isset($response['status']) && $response['status'] === 'SUCCESS') {
				if (isset($response['GatewayPageURL']) && !empty($response['GatewayPageURL'])) {
					header("Location: " . $response['GatewayPageURL']);
					exit;
				} else {
					throw new ErrorException(__('Gateway URL not found in response', 'tppay'));
				}
			} else {
				$errorMessage = $response['failedreason'] ?? __('Unknown error occurred', 'tppay');
				throw new ErrorException(__('Prodamus Payment Failed: ', 'tppay') . $errorMessage);
			}

		} catch (RequestException $error) {
			throw new ErrorException($error->getMessage());
		}
	}

	private function callProdamusApi(string $url, array $data): array {
		$isLocalhost = $this->config->get('mode') === 'sandbox';
		$ssl_verify = !$isLocalhost;

		$args = [
			'method' => 'POST',
			'timeout' => 60,
			'redirection' => 5,
			'httpversion' => '1.1',
			'blocking' => true,
			'headers' => [
				'Content-Type' => 'application/x-www-form-urlencoded',
			],
			'body' => $data,
			'sslverify' => $ssl_verify,
		];

		$response = wp_remote_post($url, $args);

		if (is_wp_error($response)) {
			return ['status' => 'FAILED', 'failedreason' => __('Failed to connect with Prodamus API: ', 'tppay') . $response->get_error_message()];
		}

		$http_code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if ($http_code == 200 && !empty($body)) {
			$decoded = json_decode($body, true);
			if (json_last_error() === JSON_ERROR_NONE) {
				return $decoded;
			} else {
				return ['status' => 'FAILED', 'failedreason' => __('Invalid JSON response from Prodamus API', 'tppay')];
			}
		} else {
			return ['status' => 'FAILED', 'failedreason' => __('Failed to connect with Prodamus API (HTTP ', 'tppay') . $http_code . ')'];
		}
	}

	public function verifyAndCreateOrderData(object $payload): object {
		$returnData = System::defaultOrderData();

		try {
			$post_data = $payload->post;

			if (empty($post_data) || !is_array($post_data)) {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('No transaction data received. IPN endpoint should only receive POST requests from Prodamus.', 'tppay');
				return $returnData;
			}

			$sanitized_post = [];
			foreach ($post_data as $key => $value) {
				$sanitized_post[$key] = is_array($value) ? array_map('sanitize_text_field', array_map('wp_unslash', $value)) : sanitize_text_field(wp_unslash($value));
			}

			if (empty($sanitized_post['tran_id']) || empty($sanitized_post['status'])) {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('Invalid transaction data: Missing transaction ID or status.', 'tppay');
				return $returnData;
			}

			$tran_id = $sanitized_post['tran_id'];
			$amount = $sanitized_post['amount'] ?? 0;
			$currency = $sanitized_post['currency'] ?? 'BDT';
			$status = $sanitized_post['status'];

			$validated = $this->validateTransaction($sanitized_post);

			if ($validated) {
				$order_id = $sanitized_post['value_a'] ?? '';

				$payment_status = $this->mapPaymentStatus($status);

				$returnData->id = $order_id;
				$returnData->payment_status = $payment_status;
				$returnData->transaction_id = $sanitized_post['bank_tran_id'] ?? $tran_id;
				$returnData->payment_payload = json_encode($sanitized_post);
				$returnData->payment_error_reason = $status !== 'VALID' && $status !== 'VALIDATED' ? ($sanitized_post['error'] ?? __('Payment failed', 'tppay')) : '';

				$store_amount = floatval($sanitized_post['store_amount'] ?? $amount);
				$gateway_fee = floatval($amount) - $store_amount;

				$returnData->fees = number_format($gateway_fee, 2, '.', '');
				$returnData->earnings = number_format($store_amount, 2, '.', '');
				$returnData->tax_amount = 0;

			} else {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('Transaction validation with Prodamus API failed.', 'tppay');
			}

			return $returnData;

		} catch (Throwable $error) {
			if (defined('WP_DEBUG') && WP_DEBUG) {
				error_log('Prodamus IPN Error: ' . $error->getMessage());
			}

			$returnData->payment_status = 'failed';
			$returnData->payment_error_reason = __('Error processing payment: ', 'tppay') . $error->getMessage();
			return $returnData;
		}
	}

	private function validateTransaction(array $post_data): bool {
		if (!$this->verifyHash($post_data)) {
			return false;
		}

		$tran_id = $post_data['tran_id'];
		$amount = $post_data['amount'] ?? 0;
		$currency = $post_data['currency'] ?? 'BDT';

		$val_id = urlencode($post_data['val_id'] ?? '');
		$api_token = urlencode($this->client['api_token']);

		$validationUrl = $this->client['api_domain'] . self::API_VALIDATION_ENDPOINT . '?val_id=' . $val_id . '&api_token=' . $api_token . '&store_passwd=' . $store_passwd . '&v=1&format=json';

		$isLocalhost = $this->config->get('mode') === 'sandbox';
		$ssl_verify = !$isLocalhost;

		$args = [
			'timeout' => 30,
			'sslverify' => $ssl_verify,
		];

		$response = wp_remote_get($validationUrl, $args);

		if (is_wp_error($response)) {
			return false;
		}

		$code = wp_remote_retrieve_response_code($response);
		$body = wp_remote_retrieve_body($response);

		if ($code == 200 && !empty($body)) {
			$result = json_decode($body);

			if (json_last_error() === JSON_ERROR_NONE && isset($result->status) && ($result->status === 'VALID' || $result->status === 'VALIDATED')) {
				if ($currency === 'BDT') {
					return trim($tran_id) === trim($result->tran_id) && abs($amount - $result->amount) < 1;
				} else {
					return trim($tran_id) === trim($result->tran_id) && abs($amount - $result->currency_amount) < 1;
				}
			}
		}

		return false;
	}

	private function verifyHash(array $post_data): bool {
		if (!isset($post_data['verify_sign']) || !isset($post_data['verify_key'])) {
			return true;
		}

		$pre_define_key = explode(',', $post_data['verify_key']);
		$new_data = [];

		foreach ($pre_define_key as $value) {
			if (isset($post_data[$value])) {
				$new_data[$value] = $post_data[$value];
			}
		}

		ksort($new_data);

		$hash_string = "";
		foreach ($new_data as $key => $value) {
			$hash_string .= $key . '=' . $value . '&';
		}
		$hash_string = rtrim($hash_string, '&');

		return md5($hash_string) === $post_data['verify_sign'];
	}

	private function mapPaymentStatus(string $prodamusStatus): string {
		return self::STATUS_MAP[$prodamusStatus] ?? 'failed';
	}
}