<?php

namespace Payments\Epoint;

use Throwable;
use ErrorException;
use Ollyo\PaymentHub\Core\Support\Arr;
use Ollyo\PaymentHub\Core\Support\System;
use GuzzleHttp\Exception\RequestException;
use Ollyo\PaymentHub\Core\Payment\BasePayment;
use TEPay\EpointOrderProcess; // Добавляем импорт

class Epoint extends BasePayment
{
    private const API_CHECKOUT_ENDPOINT = "/api/1/checkout";
    private const API_STATUS_ENDPOINT = "/api/1/get-status";
    private const DEFAULT_CURRENCY = "AZN";
    private const DEFAULT_LANGUAGE = "ru";

    private const STATUS_MAP = [
        "success" => "paid",
        "new" => "pending",
        "returned" => "refunded",
        "error" => "failed",
    ];

    protected $client;

    public function check(): bool
    {
        $configKeys = Arr::make(["public_key", "private_key"]);

        $isConfigOk = $configKeys->every(function ($key) {
            return $this->config->has($key) && !empty($this->config->get($key));
        });

        return $isConfigOk;
    }

    public function setup(): void
    {
        try {
            $this->client = [
                "public_key" => $this->config->get("public_key"),
                "private_key" => $this->config->get("private_key"),
                "api_domain" =>
                    $this->config->get("api_domain") ?: "https://epoint.az",
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
            throw new \InvalidArgumentException(
                __("Order ID is required for payment processing", "tepay"),
            );
        }

        if (!isset($data->customer) || !isset($data->customer->email)) {
            throw new \InvalidArgumentException(
                __(
                    "Customer email is required for payment processing",
                    "tepay",
                ),
            );
        }

        $total_price =
            isset($data->total_price) && !empty($data->total_price)
                ? (float) $data->total_price
                : 0;

        if ($total_price <= 0) {
            throw new \InvalidArgumentException(
                __("Payment amount must be greater than zero", "tepay"),
            );
        }

        // Формируем данные для Epoint API согласно документации
        $epointData = [
            "public_key" => $this->client["public_key"],
            "amount" => number_format($total_price, 2, ".", ""),
            "currency" => self::DEFAULT_CURRENCY, // AZN по умолчанию
            "language" => self::DEFAULT_LANGUAGE,
            "order_id" => (string) $data->order_id,
            "description" =>
                $data->order_description ?? __("Course Purchase", "tepay"),
            "customer_email" => $data->customer->email,
            "customer_phone" => $data->customer->phone_number ?? "",
            "success_url" => $this->config->get("success_url"),
            "error_url" => $this->config->get("cancel_url"),
            "result_url" => $this->config->get("webhook_url"),
        ];

        // Удаляем пустые поля
        $epointData = array_filter($epointData, function ($value) {
            return $value !== "" && $value !== null;
        });

        return $epointData;
    }

    public function createPayment(): void
    {
        try {
            $paymentData = $this->getData();

            $apiUrl = $this->client["api_domain"] . self::API_CHECKOUT_ENDPOINT;

            // Формируем JSON и кодируем в base64
            $jsonData = json_encode($paymentData);
            $data = base64_encode($jsonData);

            // Генерируем подпись: base64_encode(sha1(private_key + data + private_key, 1))
            $privateKey = $this->client["private_key"];
            $signature = base64_encode(
                sha1($privateKey . $data . $privateKey, true),
            );

            // Отправляем POST запрос
            $response = $this->callEpointApi($apiUrl, $data, $signature);

            if (
                $response &&
                isset($response["url"]) &&
                !empty($response["url"])
            ) {
                // Выполняем редирект на платежную форму
                wp_redirect($response["url"]);
                exit();
            } else {
                $errorMessage =
                    $response["error"] ?? __("Unknown error occurred", "tepay");
                throw new ErrorException(
                    __("Epoint Payment Failed: ", "tepay") . $errorMessage,
                );
            }
        } catch (RequestException $error) {
            throw new ErrorException($error->getMessage());
        }
    }

    private function callEpointApi(
        string $url,
        string $data,
        string $signature,
    ): array {
        // Формируем POST данные
        $postData = [
            "data" => $data,
            "signature" => $signature,
        ];

        // Отправляем POST запрос
        $response = wp_remote_post($url, [
            "body" => $postData,
            "timeout" => 30,
            "headers" => [
                "Content-Type" => "application/x-www-form-urlencoded",
            ],
        ]);

        if (is_wp_error($response)) {
            throw new ErrorException(
                "API request failed: " . $response->get_error_message(),
            );
        }

        $body = wp_remote_retrieve_body($response);
        $result = json_decode($body, true);

        error_log("Epoint API Response: " . print_r($result, true));

        return $result ?: [];
    }

    public function handleWebhook(): void
    {
        try {
            $postData = $_POST;
            $signature = $postData["signature"] ?? "";
            $data = $postData["data"] ?? "";

            if (empty($postData) || empty($signature) || empty($data)) {
                http_response_code(400);
                echo "error: Invalid request";
                exit();
            }

            // Проверяем подпись: base64_encode(sha1(private_key + data + private_key, 1))
            $privateKey = $this->client["private_key"];
            $expectedSignature = base64_encode(
                sha1($privateKey . $data . $privateKey, true),
            );

            if ($signature !== $expectedSignature) {
                http_response_code(400);
                echo "error: Invalid signature";
                exit();
            }

            // Декодируем данные
            $resultData = json_decode(base64_decode($data), true);

            if (!$resultData || !isset($resultData["status"])) {
                http_response_code(400);
                echo "error: Invalid data";
                exit();
            }

            // Используем EpointOrderProcess для обработки
            $processor = new EpointOrderProcess(
                $this->client["public_key"],
                $this->client["private_key"],
            );

            $result = $processor->processWebhook($resultData, $signature);

            if ($result["success"]) {
                http_response_code(200);
                echo "success";
            } else {
                http_response_code(400);
                echo "error: " . $result["message"];
            }

            exit();
        } catch (Throwable $error) {
            error_log("Epoint webhook error: " . $error->getMessage());
            http_response_code(400);
            echo "error: " . $error->getMessage();
            exit();
        }
    }

    public function verifyAndCreateOrderData(object $payload): object
    {
        $returnData = System::defaultOrderData();

        try {
            // Получаем данные из POST запроса
            $post_data = $payload->post;

            if (empty($post_data) || !is_array($post_data)) {
                $returnData->payment_status = "failed";
                $returnData->payment_error_reason = __(
                    "No transaction data received.",
                    "tepay",
                );
                return $returnData;
            }

            $signature = $post_data["signature"] ?? "";
            $data = $post_data["data"] ?? "";

            if (empty($signature) || empty($data)) {
                $returnData->payment_status = "failed";
                $returnData->payment_error_reason = __(
                    "Signature or data not found",
                    "tepay",
                );
                return $returnData;
            }

            // Верифицируем подпись
            $privateKey = $this->client["private_key"];
            $expectedSignature = base64_encode(
                sha1($privateKey . $data . $privateKey, true),
            );

            if ($signature !== $expectedSignature) {
                $returnData->payment_status = "failed";
                $returnData->payment_error_reason = __(
                    "Invalid signature",
                    "tepay",
                );
                return $returnData;
            }

            // Декодируем данные
            $decodedData = json_decode(base64_decode($data), true);

            if (!$decodedData) {
                $returnData->payment_status = "failed";
                $returnData->payment_error_reason = __(
                    "Invalid data format",
                    "tepay",
                );
                return $returnData;
            }

            // Извлекаем данные заказа
            $order_id = $decodedData["order_id"] ?? "";
            $status = $decodedData["status"] ?? "";
            $transaction_id = $decodedData["transaction"] ?? "";
            $amount = $decodedData["amount"] ?? 0;
            $currency = $decodedData["currency"] ?? self::DEFAULT_CURRENCY;

            if (empty($order_id) || empty($status)) {
                $returnData->payment_status = "failed";
                $returnData->payment_error_reason = __(
                    "Invalid transaction data: Missing order ID or status.",
                    "tepay",
                );
                return $returnData;
            }

            $returnData->id = $order_id;
            $returnData->payment_status = $this->mapPaymentStatus($status);
            $returnData->transaction_id = $transaction_id;
            $returnData->payment_payload = json_encode($decodedData);
            $returnData->payment_error_reason =
                $status !== "success"
                    ? $decodedData["error"] ?? __("Payment failed", "tepay")
                    : "";
            $returnData->earnings = $amount;

            return $returnData;
        } catch (Throwable $error) {
            if (defined("WP_DEBUG") && WP_DEBUG) {
                error_log("Epoint IPN Error: " . $error->getMessage());
            }

            $returnData->payment_status = "failed";
            $returnData->payment_error_reason =
                __("Error processing payment: ", "tepay") .
                $error->getMessage();
            return $returnData;
        }
    }
    private function mapPaymentStatus(string $epointStatus): string
    {
        return self::STATUS_MAP[$epointStatus] ?? "failed";
    }
}
