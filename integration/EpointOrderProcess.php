<?php

namespace TEPay;

use Throwable;
use Exception;

class EpointOrderProcess
{
    private const STATUS_MAP = [
        "paid" => "paid",
        "success" => "paid",
        "VALID" => "paid",
        "VALIDATED" => "paid",
        "FAILED" => "failed",
        "CANCELLED" => "cancelled",
        "PENDING" => "pending",
    ];

    private $public_key;
    private $private_key;
    private $api_domain;

    /**
     * Конструктор
     */
    public function __construct(string $public_key, string $private_key)
    {
        $this->public_key = $public_key;
        $this->private_key = $private_key;
        $this->api_domain = "https://epoint.az";
    }

    /**
     * Обработка формы (для совместимости со старым методом)
     */
    public function process_epoint_form_submission(): void
    {
        // Проверяем, что это возврат с оплаты
        $order_placement = isset($_GET["tutor_order_placement"])
            ? sanitize_text_field(wp_unslash($_GET["tutor_order_placement"]))
            : "";
        if ($order_placement !== "success") {
            return;
        }

        if (empty($_POST) || !isset($_POST["tran_id"])) {
            return;
        }

        $tran_id = isset($_POST["tran_id"])
            ? sanitize_text_field(wp_unslash($_POST["tran_id"]))
            : "";
        if (empty($tran_id)) {
            return;
        }

        $value_a = isset($_POST["value_a"])
            ? sanitize_text_field(wp_unslash($_POST["value_a"]))
            : "";
        $order_id = absint($value_a);
        if (!$order_id) {
            return;
        }

        $sanitized_post = [];
        foreach ($_POST as $key => $value) {
            $sanitized_post[$key] = is_array($value)
                ? array_map(
                    "sanitize_text_field",
                    array_map("wp_unslash", $value),
                )
                : sanitize_text_field(wp_unslash($value));
        }

        // Проверяем подпись если есть
        $headers = getallheaders();
        $signature = $headers["Sign"] ?? "";

        $is_valid = true;
        if (!empty($signature)) {
            $is_valid = $this->verifySignature($sanitized_post, $signature);
        }

        if ($is_valid) {
            $status = isset($sanitized_post["status"])
                ? $sanitized_post["status"]
                : "paid";
            $payment_status = self::STATUS_MAP[$status] ?? "paid";

            self::update_order_in_database(
                $order_id,
                $payment_status,
                $sanitized_post["tran_id"] ?? "",
            );

            // Зачисляем на курс если оплата успешна
            if ($payment_status === "paid") {
                $this->enroll_student_to_course($order_id);
            }
        }
    }

    /**
     * Обработка вебхука от Epoint
     */
    public function processWebhook(array $webhookData, string $signature): array
    {
        try {
            if (empty($webhookData)) {
                return $this->errorResponse("Empty webhook data");
            }

            if (!$this->verifySignature($webhookData, $signature)) {
                return $this->errorResponse("Invalid signature");
            }

            $status = $webhookData["status"] ?? "";
            if ($status !== "success") {
                return $this->errorResponse("Payment not successful", [
                    "status" => $status,
                ]);
            }

            $orderId = $this->extractOrderId($webhookData);
            if (!$orderId) {
                return $this->errorResponse(
                    "Order ID not found in webhook data",
                );
            }

            if ($this->isOrderProcessed($orderId)) {
                return $this->successResponse("Order already processed");
            }

            self::update_order_in_database(
                $orderId,
                "paid",
                $webhookData["transaction_id"] ?? "",
            );

            $enrollmentResult = $this->enroll_student_to_course($orderId);

            $this->markAsProcessed($orderId);
            $this->saveTransaction($orderId, $webhookData);

            return $this->successResponse("Payment processed successfully", [
                "order_id" => $orderId,
                "enrolled" => $enrollmentResult,
            ]);
        } catch (Throwable $error) {
            error_log("EpointOrderProcess Error: " . $error->getMessage());
            return $this->errorResponse($error->getMessage());
        }
    }

    /**
     * Проверка подписи
     */
    private function verifySignature(array $data, string $signature): bool
    {
        // Формируем JSON и кодируем в base64
        $jsonData = json_encode($data);
        $dataEncoded = base64_encode($jsonData);

        // Проверяем подпись: base64_encode(sha1(private_key + data + private_key, 1))
        $expectedSignature = base64_encode(
            sha1($this->private_key . $dataEncoded . $this->private_key, true),
        );

        return $signature === $expectedSignature;
    }

    /**
     * Извлечение ID заказа
     */
    private function extractOrderId(array $webhookData): ?int
    {
        $orderId =
            $webhookData["order_id"] ??
            ($webhookData["value_a"] ??
                ($webhookData["merchant_order_id"] ?? null));

        return $orderId ? (int) $orderId : null;
    }

    /**
     * Проверка, обработан ли заказ
     */
    private function isOrderProcessed(int $orderId): bool
    {
        $processed = get_post_meta($orderId, "_epoint_processed", true);
        return $processed === "yes";
    }

    /**
     * Отметка заказа как обработанного
     */
    private function markAsProcessed(int $orderId): void
    {
        update_post_meta($orderId, "_epoint_processed", "yes");
    }

    /**
     * Сохранение транзакции
     */
    private function saveTransaction(int $orderId, array $webhookData): void
    {
        $transactionData = [
            "transaction_id" => $webhookData["transaction_id"] ?? "",
            "amount" => $webhookData["amount"] ?? 0,
            "currency" => $webhookData["currency"] ?? "USD",
            "payment_method" => $webhookData["payment_method"] ?? "",
            "payment_date" => current_time("mysql"),
            "raw_data" => $webhookData,
        ];

        update_post_meta($orderId, "_epoint_transaction", $transactionData);
    }

    /**
     * Зачисление студента на курс
     */
    private function enroll_student_to_course(int $order_id): bool
    {
        global $wpdb;

        // Получаем данные заказа
        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tutor_orders WHERE id = %d",
                $order_id,
            ),
        );

        if (!$order) {
            return false;
        }

        if (!function_exists("tutor_utils")) {
            return false;
        }

        try {
            $courseId = $order->course_id;
            $studentId = $order->user_id;

            $isEnrolled = tutor_utils()->is_enrolled($courseId, $studentId);

            if ($isEnrolled) {
                return true;
            }

            $enrollment = tutor_utils()->do_enroll($courseId, $studentId);

            if ($enrollment) {
                error_log(
                    "Student {$studentId} enrolled to course {$courseId} after Epoint payment",
                );
                return true;
            }

            return false;
        } catch (Throwable $error) {
            error_log("Enrollment error: " . $error->getMessage());
            return false;
        }
    }

    /**
     * Обновление заказа в базе данных
     */
    private static function update_order_in_database(
        int $order_id,
        string $payment_status,
        string $transaction_id,
    ): void {
        global $wpdb;

        $sanitized_payment_status = sanitize_text_field($payment_status);
        $sanitized_transaction_id = sanitize_text_field($transaction_id);

        $update_data = [
            "payment_status" => $sanitized_payment_status,
            "transaction_id" => $sanitized_transaction_id,
        ];

        if ($sanitized_payment_status === "paid") {
            $update_data["order_status"] = "completed";
        }

        $wpdb->update(
            $wpdb->prefix . "tutor_orders",
            $update_data,
            ["id" => $order_id],
            array_fill(0, count($update_data), "%s"),
            ["%d"],
        );
    }

    /**
     * Успешный ответ
     */
    private function successResponse(string $message, array $data = []): array
    {
        return [
            "success" => true,
            "message" => $message,
            "data" => $data,
        ];
    }

    /**
     * Ответ с ошибкой
     */
    private function errorResponse(string $message, array $context = []): array
    {
        return [
            "success" => false,
            "message" => $message,
            "context" => $context,
        ];
    }
}
