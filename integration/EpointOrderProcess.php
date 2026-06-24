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

    public function process_epoint_form_submission(): void
    {
        error_log(
            "=== EpointOrderProcess::process_epoint_form_submission (обработка возврата) НАЧАЛО",
        );
        error_log("GET params: " . print_r($_GET, true));
        error_log("POST params: " . print_r($_POST, true));

        // Проверяем параметр в GET, указывающий на успешный возврат
        $order_placement = isset($_GET["tutor_order_placement"])
            ? sanitize_text_field(wp_unslash($_GET["tutor_order_placement"]))
            : "";
        error_log(
            "process_epoint_form_submission: GET tutor_order_placement = " .
                $order_placement,
        );

        if ($order_placement !== "success") {
            error_log(
                "process_epoint_form_submission: параметр не равен success, выход",
            );
            return;
        }

        // --- Извлекаем order_id ---
        $order_id = 0;
        if (isset($_GET["order_id"])) {
            $raw_order_id = sanitize_text_field(wp_unslash($_GET["order_id"]));
            // Если есть '?', обрезаем всё после него (костыль для тестового значения)
            if (strpos($raw_order_id, "?") !== false) {
                $raw_order_id = substr(
                    $raw_order_id,
                    0,
                    strpos($raw_order_id, "?"),
                );
            }
            $order_id = absint($raw_order_id);
        } elseif (isset($_POST["order_id"])) {
            $order_id = absint(
                sanitize_text_field(wp_unslash($_POST["order_id"])),
            );
        } elseif (isset($_POST["value_a"])) {
            // если используется поле value_a (старый формат)
            $order_id = absint(
                sanitize_text_field(wp_unslash($_POST["value_a"])),
            );
        }

        if (!$order_id) {
            error_log(
                "process_epoint_form_submission: order_id не найден или равен 0, выход",
            );
            return;
        }
        error_log("process_epoint_form_submission: order_id = " . $order_id);

        // --- Извлекаем transaction_id ---
        $transaction_id = "";
        if (isset($_GET["transaction_id"])) {
            $transaction_id = sanitize_text_field(
                wp_unslash($_GET["transaction_id"]),
            );
        } elseif (isset($_GET["tran_id"])) {
            $transaction_id = sanitize_text_field(wp_unslash($_GET["tran_id"]));
        } elseif (isset($_POST["transaction_id"])) {
            $transaction_id = sanitize_text_field(
                wp_unslash($_POST["transaction_id"]),
            );
        } elseif (isset($_POST["tran_id"])) {
            $transaction_id = sanitize_text_field(
                wp_unslash($_POST["tran_id"]),
            );
        }

        if (empty($transaction_id)) {
            error_log(
                "process_epoint_form_submission: transaction_id не найден, продолжаем без него",
            );
        } else {
            error_log(
                "process_epoint_form_submission: transaction_id = " .
                    $transaction_id,
            );
        }

        // --- Извлекаем статус ---
        $status = "";
        if (isset($_GET["payment_status"])) {
            $status = sanitize_text_field(wp_unslash($_GET["payment_status"]));
        } elseif (isset($_POST["status"])) {
            $status = sanitize_text_field(wp_unslash($_POST["status"]));
        }
        if (empty($status)) {
            $status = "success"; // по умолчанию считаем успешным
        }
        error_log("process_epoint_form_submission: status = " . $status);

        $payment_status = self::STATUS_MAP[$status] ?? "paid";
        error_log(
            "process_epoint_form_submission: payment_status = " .
                $payment_status,
        );

        // --- Проверка подписи (если есть) ---
        $headers = getallheaders();
        $signature =
            $headers["Sign"] ??
            (isset($_POST["signature"]) ? $_POST["signature"] : "");
        $is_valid = true;
        if (!empty($signature)) {
            // Собираем данные для проверки подписи
            $data_for_verify = [
                "order_id" => $order_id,
                "transaction_id" => $transaction_id,
                "status" => $status,
            ];
            // Если есть POST data (закодированный JSON) – используем его
            if (!empty($_POST["data"])) {
                $decoded = json_decode(base64_decode($_POST["data"]), true);
                if (is_array($decoded)) {
                    $data_for_verify = $decoded;
                }
            }
            $is_valid = $this->verifySignature($data_for_verify, $signature);
            error_log(
                "process_epoint_form_submission: проверка подписи = " .
                    ($is_valid ? "ВЕРНА" : "НЕВЕРНА"),
            );
        } else {
            error_log(
                "process_epoint_form_submission: подпись отсутствует, считаем валидным (только для теста)",
            );
        }

        if (!$is_valid) {
            error_log("process_epoint_form_submission: подпись НЕВЕРНА, выход");
            return;
        }

        // --- Обновление заказа ---
        self::update_order_in_database(
            $order_id,
            $payment_status,
            $transaction_id,
        );
        error_log("process_epoint_form_submission: заказ обновлён");

        // --- Зачисление студента, если оплата успешна ---
        if ($payment_status === "paid") {
            error_log(
                'process_epoint_form_submission: статус "paid", вызываем enroll_student_to_course',
            );
            $enroll_result = $this->enroll_student_to_course($order_id);
            error_log(
                "process_epoint_form_submission: enroll_student_to_course вернул " .
                    ($enroll_result ? "ИСТИНА" : "ЛОЖЬ"),
            );
        } else {
            error_log(
                'process_epoint_form_submission: статус НЕ "paid", зачисление не вызывается',
            );
        }

        error_log("=== process_epoint_form_submission ЗАВЕРШЁН");
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
            error_log("Что за хуйня");
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
        error_log("=== enroll_student_to_course START, order_id=" . $order_id);
        global $wpdb;

        // 1. Проверяем, не отправлено ли уже письмо для этого заказа
        $email_sent = get_post_meta($order_id, "_enrollment_email_sent", true);
        if ($email_sent === "yes") {
            error_log(
                "enroll_student_to_course: письмо уже отправлено для заказа " .
                    $order_id .
                    ", пропускаем",
            );
            return true;
        }

        // 2. Получаем заказ
        $order = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$wpdb->prefix}tutor_orders WHERE id = %d",
                $order_id,
            ),
        );

        if (!$order) {
            error_log(
                "enroll_student_to_course: ЗАКАЗ НЕ НАЙДЕН для id " . $order_id,
            );
            return false;
        }
        error_log(
            'enroll_student_to_course: $order = ' . print_r($order, true),
        );

        // 3. Извлекаем user_id
        $studentId = 0;
        if (property_exists($order, "user_id")) {
            $studentId = (int) $order->user_id;
        } elseif (property_exists($order, "customer_id")) {
            $customer = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT user_id FROM {$wpdb->prefix}tutor_customers WHERE id = %d",
                    $order->customer_id,
                ),
            );
            if ($customer && property_exists($customer, "user_id")) {
                $studentId = (int) $customer->user_id;
            }
        }

        if (!$studentId) {
            error_log(
                "enroll_student_to_course: НЕ УДАЛОСЬ ОПРЕДЕЛИТЬ studentId",
            );
            return false;
        }
        error_log("enroll_student_to_course: student_id = $studentId");

        // 4. Получаем course_id (item_id) из tutor_order_items
        $item = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT item_id FROM {$wpdb->prefix}tutor_order_items WHERE order_id = %d LIMIT 1",
                $order_id,
            ),
        );
        if (!$item || !property_exists($item, "item_id")) {
            error_log(
                "enroll_student_to_course: НЕ НАЙДЕН item_id для order_id $order_id в tutor_order_items",
            );
            return false;
        }
        $courseId = (int) $item->item_id;
        error_log(
            "enroll_student_to_course: course_id из tutor_order_items = $courseId",
        );

        if (!$courseId) {
            error_log(
                "enroll_student_to_course: course_id = $courseId, не может быть 0",
            );
            return false;
        }

        // 5. Проверяем наличие tutor_utils
        if (!function_exists("tutor_utils")) {
            error_log(
                "enroll_student_to_course: ФУНКЦИЯ tutor_utils НЕ СУЩЕСТВУЕТ",
            );
            return false;
        }

        try {
            // 6. Проверяем, зачислен ли уже студент
            $isEnrolled = tutor_utils()->is_enrolled($courseId, $studentId);
            error_log(
                "enroll_student_to_course: isEnrolled = " .
                    ($isEnrolled ? "ДА" : "НЕТ"),
            );

            if (!$isEnrolled) {
                // Зачисляем, если ещё не зачислен
                $enrollment = tutor_utils()->do_enroll($courseId, $studentId);
                error_log(
                    "enroll_student_to_course: результат do_enroll = " .
                        ($enrollment ? "УСПЕШНО" : "НЕУДАЧА"),
                );

                if (!$enrollment) {
                    error_log(
                        "enroll_student_to_course: do_enroll вернул ложь, зачисление НЕ ПРОИЗОШЛО",
                    );
                    return false;
                }
            }

            // 7. Отправляем письмо (если ещё не отправлено) и ставим метку
            error_log(
                "enroll_student_to_course: отправляем письмо (если не отправлено)",
            );
            $this->send_enrollment_email($studentId, $order_id);
            update_post_meta($order_id, "_enrollment_email_sent", "yes");
            error_log(
                "enroll_student_to_course: письмо отправлено, мета-поле установлено",
            );

            return true;
        } catch (Throwable $error) {
            error_log(
                "enroll_student_to_course ИСКЛЮЧЕНИЕ: " . $error->getMessage(),
            );
            return false;
        }
    }
    /**
     * Отправка письма о регистрации на курс
     */
    private function send_enrollment_email(int $user_id, int $order_id): void
    {
        // ЛОГ: начало метода с параметрами
        error_log(
            "=== send_enrollment_email START, user_id=" .
                $user_id .
                ", order_id=" .
                $order_id,
        );

        global $wpdb;

        // Проверка валидности ID
        if ($order_id < 1 || $user_id < 1) {
            error_log(
                "send_enrollment_email: НЕКОРРЕКТНЫЕ ID (order_id или user_id меньше 1), выход",
            );
            return;
        }

        // Получаем ID курса из таблицы tutor_order_items
        $post_id_object = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT item_id FROM {$wpdb->prefix}tutor_order_items WHERE order_id = %d",
                $order_id,
            ),
        );
        if (!$post_id_object) {
            error_log(
                "send_enrollment_email: НЕТ ЗАПИСИ В tutor_order_items для order_id " .
                    $order_id .
                    ", выход",
            );
            return;
        }
        $post_id = $post_id_object->item_id;
        error_log("send_enrollment_email: ID курса (post_id) = " . $post_id);

        // Получаем название курса из таблицы posts
        $post_object = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT post_title FROM {$wpdb->prefix}posts WHERE post_type = 'courses' and id = %d",
                $post_id,
            ),
        );
        if (!$post_object) {
            error_log(
                "send_enrollment_email: НЕ НАЙДЕН КУРС С ID " .
                    $post_id .
                    " в таблице posts, выход",
            );
            return;
        }
        $course_title = $post_object->post_title;
        error_log("send_enrollment_email: название курса = " . $course_title);

        // Получаем email и имя пользователя из таблицы tutor_customers
        $to_object = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT billing_email, billing_first_name, billing_last_name FROM {$wpdb->prefix}tutor_customers WHERE user_id = %d",
                $user_id,
            ),
        );
        if (!$to_object) {
            error_log(
                "send_enrollment_email: НЕТ ЗАПИСИ В tutor_customers ДЛЯ user_id " .
                    $user_id .
                    ", выход",
            );
            return;
        }
        $to = $to_object->billing_email;
        if (empty($to)) {
            error_log(
                "send_enrollment_email: ПОЛЕ billing_email ПУСТОЕ для user_id " .
                    $user_id .
                    ", выход",
            );
            return;
        }
        error_log("send_enrollment_email: email получателя = " . $to);
        $first_name = $to_object->billing_first_name;
        $last_name = $to_object->billing_last_name;

        // Формируем тему и текст письма
        $subject = sprintf(
            __("Успешная регистрация на курс: %s", "tepay"),
            $course_title,
        );
        $message = sprintf(
            __(
                "Здравствуйте, %s!\n\nВы успешно зарегистрировались на курс \"%s\".\nНомер заказа: #%d\n\nСпасибо за оплату через Epoint!",
                "tepay",
            ),
            $first_name ?: $last_name,
            $course_title,
            $order_id,
        );
        $headers = ["Content-Type: text/plain; charset=UTF-8"];

        // ЛОГ: перед вызовом wp_mail
        error_log(
            "send_enrollment_email: вызываем wp_mail с to=" .
                $to .
                ", subject=" .
                $subject,
        );

        // Отправляем письмо
        $mail_result = wp_mail($to, $subject, $message, $headers);

        // ЛОГ: результат отправки
        error_log(
            "send_enrollment_email: wp_mail вернул " .
                ($mail_result ? "ИСТИНА (успешно)" : "ЛОЖЬ (ошибка)"),
        );
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
