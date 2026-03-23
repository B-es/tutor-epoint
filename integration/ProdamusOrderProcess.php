<?php

namespace TPPay;

class ProdamusOrderProcess {

	private const API_PROCESS_ENDPOINT = '/gwprocess/v4/api.php';
	private const API_VALIDATION_ENDPOINT = '/validator/api/validationserverAPI.php';

	private const STATUS_MAP = [
		'VALID'     => 'paid',
		'VALIDATED' => 'paid',
		'FAILED'    => 'failed',
		'CANCELLED' => 'cancelled',
		'PENDING'   => 'pending',
	];

	protected $client;

	public function process_prodamus_form_submission(): void {
        $order_placement = isset($_GET['tutor_order_placement']) ? sanitize_text_field(wp_unslash($_GET['tutor_order_placement'])) : '';
        if ($order_placement !== 'success') {
            return;
        }

        if (empty($_POST) || !isset($_POST['tran_id'])) {
            return;
        }

        $tran_id = isset($_POST['tran_id']) ? sanitize_text_field(wp_unslash($_POST['tran_id'])) : '';
        if (empty($tran_id)) {
            return;
        }

        $value_a = isset($_POST['value_a']) ? sanitize_text_field(wp_unslash($_POST['value_a'])) : '';
        $order_id = absint($value_a);
        if (!$order_id) {
            return;
        }

        $options = get_option('tutor_option');
        $payment_settings = json_decode($options['payment_settings'], true);

        $prodamus_settings = null;
        foreach ($payment_settings['payment_methods'] as $method) {
            if ($method['name'] === 'prodamus') {
                $prodamus_settings = $method;
                break;
            }
        }

        if (!$prodamus_settings) {
            return;
        }
        try {
            foreach ($prodamus_settings['fields'] as $field) {
                if (!isset($field['name']) || !isset($field['value'])) {
                    continue;
                }

                switch ($field['name']) {
                    case 'api_token':
                        $this->client['api_token'] = $field['value'];
                        break;
                    case 'environment':
                        $this->client['environment'] = $field['value'];
                        break;
                }
            }

            if (empty($this->client['api_token']) || empty($this->client['environment'])) {
                return;
            }

            $this->client['api_domain'] = $this->client['environment'] === 'sandbox'
                ? 'https://sandbox.prodamus.com'
                : 'https://securepay.prodamus.com';

            $sanitized_post = [];
            foreach ($_POST as $key => $value) {
                $sanitized_post[$key] = is_array($value) ? array_map('sanitize_text_field', array_map('wp_unslash', $value)) : sanitize_text_field(wp_unslash($value));
            }

            if ($this->validateTransaction($sanitized_post)) {
                $status = isset($sanitized_post['status']) ? $sanitized_post['status'] : 'FAILED';
                $payment_status = self::STATUS_MAP[$status] ?? 'failed';

                self::update_order_in_database($order_id, $payment_status, $sanitized_post['tran_id'] ?? '');
            }
        } catch (\Exception $e) {
        }

    }

    private function verifyHash(array $post_data): bool {
       
    }

    private function validateTransaction(array $post_data): bool {
        if (!$this->verifyHash($post_data)) {
            return false;
        }

        $tran_id = sanitize_text_field($post_data['tran_id'] ?? '');
        $amount = isset($post_data['amount']) ? floatval($post_data['amount']) : 0.0;
        $currency = sanitize_text_field($post_data['currency'] ?? 'BDT');

        $val_id = urlencode(sanitize_text_field($post_data['val_id'] ?? ''));
        $api_token = urlencode(sanitize_text_field($this->client['api_token']));

        $validationUrl = $this->client['api_domain'] . self::API_VALIDATION_ENDPOINT . '?val_id=' . $val_id . '&api_token=' . $api_token . '&store_passwd=' . $store_passwd . '&v=1&format=json';

        $isLocalhost = $this->client['environment'] === 'sandbox';
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

    private static function update_order_in_database(int $order_id, string $payment_status, string $transaction_id): void {
        global $wpdb;

        $sanitized_payment_status = sanitize_text_field($payment_status);
        $sanitized_transaction_id = sanitize_text_field($transaction_id);

        $update_data = [
            'payment_status' => $sanitized_payment_status,
            'transaction_id' => $sanitized_transaction_id,
        ];

        if ($sanitized_payment_status === 'paid') {
            $update_data['order_status'] = 'completed';
        }

        $wpdb->update(
            $wpdb->prefix . 'tutor_orders',
            $update_data,
            ['id' => $order_id],
            array_fill(0, count($update_data), '%s'),
            ['%d']
        );
    }

}