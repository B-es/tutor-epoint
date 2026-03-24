<?php

namespace Payments\Prodamus;

use Throwable;
use ErrorException;
use Ollyo\PaymentHub\Core\Support\Arr;
use Ollyo\PaymentHub\Core\Support\System;
use GuzzleHttp\Exception\RequestException;
use Ollyo\PaymentHub\Core\Payment\BasePayment;

require_once __DIR__ . '/../Hmac.php';

class Prodamus extends BasePayment
{
	private const API_PROCESS_ENDPOINT = '/';
	private const DEFAULT_CURRENCY = 'usd';
	private const DEFAULT_COUNTRY = 'Azerbaijan';

	private const STATUS_MAP = [
		'VALID' => 'paid',
		'VALIDATED' => 'paid',
		'FAILED' => 'failed',
		'CANCELLED' => 'cancelled',
		'PENDING' => 'pending',
	];

	protected $client;

	public function check(): bool
	{
		$configKeys = Arr::make(['api_token', 'mode']);

		$isConfigOk = $configKeys->every(function ($key) {
			return $this->config->has($key) && !empty($this->config->get($key));
		});

		return $isConfigOk;
	}

	public function setup(): void
	{
		try {
			$this->client = [
				'api_token' => $this->config->get('api_token'),
				'api_domain' => $this->config->get('api_domain'),
			];
		} catch (Throwable $error) {
			throw $error;
		}
	}

	public function setData($data): void
	{
		try {
			$structuredData = $this->prepareData($data);
			parent::setData($structuredData);
		} catch (Throwable $error) {
			throw $error;
		}
	}

	private function prepareData(object $data): array
	{
		if (!isset($data->order_id) || empty($data->order_id)) {
			throw new \InvalidArgumentException(__('Order ID is required for payment processing', 'tppay'));
		}

		if (!isset($data->currency) || !isset($data->currency->code)) {
			throw new \InvalidArgumentException(__('Currency information is required for payment processing', 'tppay'));
		}

		if (!isset($data->customer) || !isset($data->customer->email)) {
			throw new \InvalidArgumentException(__('Customer email is required for payment processing', 'tppay'));
		}

		$total_price = isset($data->total_price) && !empty($data->total_price) ? (float) $data->total_price : 0;

		if ($total_price <= 0) {
			throw new \InvalidArgumentException(__('Payment amount must be greater than zero', 'tppay'));
		}

		// Формируем данные для платежной ссылки согласно документации
		$prodamusData = [
			'do' => 'pay', // Действие "Оплата"
			'order_id' => $data->order_id,
			'customer_email' => $data->customer->email,
			'customer_phone' => $data->customer->phone_number ?? '',
			'customer_extra' => $data->order_description ?? __('Course Purchase', 'tppay'),
			'urlReturn' => $this->config->get('cancel_url'),
			'urlSuccess' => $this->config->get('success_url'),
			'urlNotification' => $this->config->get('webhook_url'),
			'currency' => self::DEFAULT_CURRENCY, // Фиксируем валюту USD (доллар)
			'products' => [
				[
					'name' => $data->items->{'0'}['item_name'] ?? __('Course Purchase', 'tppay'),
					'price' => number_format($total_price, 2, '.', ''),
					'quantity' => 1,
					'sku' => (string) $data->order_id,
					'type' => 'course', // course, service, goods
				]
			]
		];
	// 	echo '<pre>';
	// echo var_dump($prodamusData);
	// 	echo '</pre>';
	// 	die();
		// Добавляем информацию о клиенте с учетом Азербайджана
		if (!empty($data->customer->name)) {
			$prodamusData['customer_name'] = $data->customer->name;
		}

		// Добавляем адрес, если есть
		if (isset($data->billing_address)) {
			$prodamusData['customer_address'] = $data->billing_address->address1 ?? '';
			$prodamusData['customer_city'] = $data->billing_address->city ?? '';
			$prodamusData['customer_country'] = self::DEFAULT_COUNTRY; // Азербайджан
			$prodamusData['customer_postcode'] = $data->billing_address->postal_code ?? 'AZ1000';
		} else {
			$prodamusData['customer_country'] = self::DEFAULT_COUNTRY;
		}

		// Добавляем телефон, если указан
		if (empty($prodamusData['customer_phone'])) {
			unset($prodamusData['customer_phone']);
		}

		return $prodamusData;
	}

	public function createPayment(): void
	{
		try {
			$paymentData = $this->getData();

			$apiUrl = $this->client['api_domain'] . self::API_PROCESS_ENDPOINT;

			// Генерируем подпись
			$paymentData['signature'] = \Hmac::create($paymentData, $this->client['api_token']);

			$response = $this->callProdamusApi($apiUrl, $paymentData);

			if ($response && isset($response['status']) && $response['status'] === 'SUCCESS') {
				if (isset($response['GatewayPageURL']) && !empty($response['GatewayPageURL'])) {
					// Выполняем редирект на платежную форму
					wp_redirect($response['GatewayPageURL']);
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

	private function callProdamusApi(string $url, array $data): array
	{
		$isLocalhost = $this->config->get('mode') === 'sandbox';

		// Для демо-режима добавляем 'demo' к сигнатуре (согласно документации Prodamus)
		// if ($isLocalhost) {
		// 	$data['signature'] = 'demo' . $data['signature'];
		// }

		// Формируем GET-ссылку для редиректа
		$link = sprintf('%s?%s', $url, http_build_query($data));

		// Логируем для отладки
		error_log('Prodamus payment URL: ' . $link);

		// Возвращаем ссылку для редиректа
		return [
			'status' => 'SUCCESS',
			'GatewayPageURL' => $link
		];
	}

	public function handleWebhook(): void
	{
		try {
			// Получаем POST данные от Prodamus
			$postData = $_POST;

			// Получаем подпись из заголовков
			$headers = getallheaders();
			$signature = $headers['Sign'] ?? '';

			// Проверяем подпись
			if (empty($postData) || empty($signature)) {
				http_response_code(400);
				echo 'error: Invalid request';
				exit;
			}

			// Проверяем подпись запроса
			if (!\Hmac::verify($postData, $this->client['api_token'], $signature)) {
				http_response_code(400);
				echo 'error: Invalid signature';
				exit;
			}

			// Обрабатываем данные оплаты
			$orderId = $postData['order_id'] ?? '';
			$paymentStatus = $postData['status'] ?? '';
			$transactionId = $postData['transaction_id'] ?? '';
			$amount = $postData['amount'] ?? 0;

			// Проверяем статус оплаты
			if ($paymentStatus === 'paid') {
				// Оплата успешна - обновляем статус заказа
				// Здесь ваша логика обновления заказа
				error_log("Prodamus: Payment successful for order {$orderId}, transaction {$transactionId}");

				// Отправляем успешный ответ Prodamus
				http_response_code(200);
				echo 'success';
			} else {
				// Оплата не удалась
				error_log("Prodamus: Payment failed for order {$orderId}, status: {$paymentStatus}");
				http_response_code(200);
				echo 'success';
			}

			exit;
		} catch (Throwable $error) {
			error_log('Prodamus webhook error: ' . $error->getMessage());
			http_response_code(400);
			echo 'error: ' . $error->getMessage();
			exit;
		}
	}

	public function verifyAndCreateOrderData(object $payload): object
	{
		$returnData = System::defaultOrderData();

		try {
			// Получаем данные из POST запроса
			$post_data = $payload->post;

			if (empty($post_data) || !is_array($post_data)) {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('No transaction data received.', 'tppay');
				return $returnData;
			}

			// Проверяем подпись
			$headers = getallheaders();
			$signature = $headers['Sign'] ?? '';

			if (empty($signature)) {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('Signature not found', 'tppay');
				return $returnData;
			}

			// Верифицируем подпись
			if (!\Hmac::verify($post_data, $this->client['api_token'], $signature)) {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('Invalid signature', 'tppay');
				return $returnData;
			}

			// Извлекаем данные заказа
			$order_id = $post_data['order_id'] ?? '';
			$status = $post_data['status'] ?? '';
			$transaction_id = $post_data['transaction_id'] ?? '';
			$amount = $post_data['amount'] ?? 0;
			$currency = $post_data['currency'] ?? 'USD';

			if (empty($order_id) || empty($status)) {
				$returnData->payment_status = 'failed';
				$returnData->payment_error_reason = __('Invalid transaction data: Missing order ID or status.', 'tppay');
				return $returnData;
			}

			$returnData->id = $order_id;
			$returnData->payment_status = $this->mapPaymentStatus($status);
			$returnData->transaction_id = $transaction_id;
			$returnData->payment_payload = json_encode($post_data);
			$returnData->payment_error_reason = $status !== 'paid' ? ($post_data['error'] ?? __('Payment failed', 'tppay')) : '';
			$returnData->earnings = $amount;

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
	private function mapPaymentStatus(string $prodamusStatus): string
	{
		return self::STATUS_MAP[$prodamusStatus] ?? 'failed';
	}
}
