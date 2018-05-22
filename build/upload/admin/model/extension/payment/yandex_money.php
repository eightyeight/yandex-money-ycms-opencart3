<?php

class ModelExtensionPaymentYandexMoney extends Model
{
    private $kassaModel;
    private $walletModel;
    private $billingModel;

    public function install()
    {
        $this->log('info', 'install yandex_money module');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'ya_money_payment` (
                `order_id`          INTEGER  NOT NULL,
                `payment_id`        CHAR(36) NOT NULL,
                `status`            ENUM(\'pending\', \'waiting_for_capture\', \'succeeded\', \'canceled\') NOT NULL,
                `amount`            DECIMAL(11, 2) NOT NULL,
                `currency`          CHAR(3) NOT NULL,
                `payment_method_id` CHAR(36) NOT NULL,
                `paid`              ENUM(\'Y\', \'N\') NOT NULL,
                `created_at`        DATETIME NOT NULL,
                `captured_at`       DATETIME NOT NULL DEFAULT \'0000-00-00 00:00:00\',
                `receipt`           TEXT DEFAULT NULL,

                CONSTRAINT `' . DB_PREFIX . 'ya_money_payment_pk` PRIMARY KEY (`order_id`),
                CONSTRAINT `' . DB_PREFIX . 'ya_money_payment_unq_payment_id` UNIQUE (`payment_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=UTF8 COLLATE=utf8_general_ci;
        ');
        $this->db->query('
            CREATE TABLE IF NOT EXISTS `' . DB_PREFIX . 'ya_money_refunds` (
                `refund_id`         CHAR(36) NOT NULL,
                `order_id`          INTEGER  NOT NULL,
                `payment_id`        CHAR(36) NOT NULL,
                `status`            ENUM(\'pending\', \'succeeded\', \'canceled\') NOT NULL,
                `amount`            DECIMAL(11, 2) NOT NULL,
                `currency`          CHAR(3) NOT NULL,
                `created_at`        DATETIME NOT NULL,
                `authorized_at`     DATETIME NOT NULL DEFAULT \'0000-00-00 00:00:00\',

                CONSTRAINT `' . DB_PREFIX . 'ya_money_refunds_pk` PRIMARY KEY (`refund_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=UTF8 COLLATE=utf8_general_ci;
        ');
        $this->db->query('CREATE INDEX `' . DB_PREFIX . 'ya_money_refunds_idx_order_id` ON `' . DB_PREFIX . 'ya_money_refunds`(`order_id`)');
        $this->db->query('CREATE INDEX `' . DB_PREFIX . 'ya_money_refunds_idx_payment_id` ON `' . DB_PREFIX . 'ya_money_refunds`(`payment_id`)');
    }

    public function uninstall()
    {
        $this->log('info', 'uninstall yandex_money module');
        $this->db->query("DROP TABLE IF EXISTS `' . DB_PREFIX . 'ya_money_payment`;");
        $this->db->query("DROP TABLE IF EXISTS `' . DB_PREFIX . 'ya_money_refunds`;");
    }

    public function log($level, $message, $context = null)
    {
        if ($this->getKassaModel()->getDebugLog()) {
            $log = new Log('yandex-money.log');
            $search = array();
            $replace = array();
            if (!empty($context)) {
                foreach ($context as $key => $value) {
                    $search[] = '{' . $key . '}';
                    $replace[] = $value;
                }
            }
            $sessionId = $this->session->getId();
            $userId = 0;
            if (isset($this->session->data['user_id'])) {
                $userId = $this->session->data['user_id'];
            }
            if (empty($search)) {
                $log->write('[' . $level . '] [' . $userId . '] [' . $sessionId . '] - ' . $message);
            } else {
                $log->write(
                    '[' . $level . '] [' . $userId . '] [' . $sessionId . '] - '
                    . str_replace($search, $replace, $message)
                );
            }
        }
    }

    /**
     * @param int $orderId
     * @return string|null
     */
    public function findPaymentIdByOrderId($orderId)
    {
        $sql = 'SELECT `payment_id` FROM `' . DB_PREFIX . 'ya_money_payment` WHERE `order_id` = ' . (int)$orderId;
        $resultSet = $this->db->query($sql);
        if (empty($resultSet) || empty($resultSet->num_rows)) {
            return null;
        }
        return $resultSet->row['payment_id'];
    }

    /**
     * @param int $offset
     * @param int $limit
     * @return array
     */
    public function findPayments($offset = 0, $limit = 0)
    {
        $res = $this->db->query('SELECT * FROM `' . DB_PREFIX . 'ya_money_payment` ORDER BY `order_id` DESC LIMIT ' . (int)$offset . ', ' . (int)$limit);
        if ($res->num_rows) {
            return $res->rows;
        }
        return array();
    }

    /**
     * @return int
     */
    public function countPayments()
    {
        $res = $this->db->query('SELECT COUNT(*) AS `count` FROM `' . DB_PREFIX . 'ya_money_payment`');
        if ($res->num_rows) {
            return $res->row['count'];
        }
        return 0;
    }

    /**
     * @param $payments
     * @return \YandexCheckout\Model\PaymentInterface[]
     */
    public function updatePaymentsStatuses($payments)
    {
        $result = array();

        $client = $this->getClient();
        $statuses = array(
            \YandexCheckout\Model\PaymentStatus::PENDING,
            \YandexCheckout\Model\PaymentStatus::WAITING_FOR_CAPTURE,
        );
        foreach ($payments as $payment) {
            if (in_array($payment['status'], $statuses)) {
                try {
                    $paymentObject = $client->getPaymentInfo($payment['payment_id']);
                    if ($paymentObject === null) {
                        $this->updatePaymentStatus($payment['payment_id'], \YandexCheckout\Model\PaymentStatus::CANCELED);
                    } else {
                        $result[] = $paymentObject;
                        if ($paymentObject->getStatus() !== $payment['status']) {
                            $this->updatePaymentStatus($payment['payment_id'], $paymentObject->getStatus(), $paymentObject->getCapturedAt());
                        }
                    }
                } catch (\Exception $e) {
                    // nothing to do
                }
            }
        }
        return $result;
    }

    /**
     * @param \YandexCheckout\Model\PaymentInterface $payment
     * @param bool $fetchPayment
     * @return bool
     */
    public function capturePayment($payment, $fetchPayment = true)
    {
        if ($fetchPayment) {
            $client = $this->getClient();
            try {
                $payment = $client->getPaymentInfo($payment->getId());
            } catch (Exception $e) {
                $this->log('error', 'Payment ' . $payment->getId() . ' not fetched from API in capture method');
                return false;
            }
        }

        if ($payment->getStatus() !== \YandexCheckout\Model\PaymentStatus::WAITING_FOR_CAPTURE) {
            return $payment->getStatus() === \YandexCheckout\Model\PaymentStatus::SUCCEEDED;
        }

        $client = $this->getClient();
        try {
            $builder = \YandexCheckout\Request\Payments\Payment\CreateCaptureRequest::builder();
            $builder->setAmount($payment->getAmount());
            $key = uniqid('', true);
            $tries = 0;
            $request = $builder->build();
            do {
                $result = $client->capturePayment($request, $payment->getId(), $key);
                if ($result === null) {
                    $tries++;
                    if ($tries > 3) {
                        break;
                    }
                    sleep(2);
                }
            } while ($result === null);
            if ($result === null) {
                throw new RuntimeException('Failed to capture payment after 3 retries');
            }
            if ($result->getStatus() !== $payment->getStatus()) {
                $this->updatePaymentStatus($payment->getId(), $result->getStatus(), $result->getCapturedAt());
            }
        } catch (Exception $e) {
            $this->log('error', 'Failed to capture payment: ' . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * @param int $orderId
     * @param array $orderInfo
     * @param \YandexCheckout\Model\PaymentInterface $payment
     * @param int $statusId
     */
    public function confirmOrderPayment($orderId, $orderInfo, $payment, $statusId)
    {
        $sql = 'UPDATE `' . DB_PREFIX . 'order_history` SET `comment` = \'Платёж подтверждён\' WHERE `order_id` = '
            . (int)$orderId . ' AND `order_status_id` <= 1';
        $comment = 'Номер транзакции: ' . $payment->getId() . '. Сумма: ' . $payment->getAmount()->getValue()
            . ' ' . $payment->getAmount()->getCurrency();
        $this->db->query($sql);

        $orderInfo['order_status_id'] = $statusId;
        $this->updateOrderStatus($orderId, $orderInfo, $comment);
    }

    public function updateOrderStatus($order_id, $order_info, $comment = '')
    {
        if ($order_info && $order_info['order_status_id']) {
            $sql = "UPDATE `" . DB_PREFIX . "order` SET order_status_id = '" . (int)$order_info['order_status_id']
                . "', date_modified = NOW() WHERE order_id = '" . (int)$order_id . "'";
            $this->db->query($sql);

            $sql = "INSERT INTO " . DB_PREFIX . "order_history SET order_id = '" . (int)$order_id
                . "', order_status_id = '" . (int)$order_info['order_status_id'] . "', notify = 0, comment = '"
                . $this->db->escape($comment) . "', date_added = NOW()";
            $this->db->query($sql);
        }
    }

    private function updatePaymentStatus($paymentId, $status, $capturedAt = null)
    {
        $sql = 'UPDATE `' . DB_PREFIX . 'ya_money_payment` SET `status` = \'' . $status . '\'';
        if ($capturedAt !== null) {
            $sql .= ', `captured_at`=\'' . $capturedAt->format('Y-m-d H:i:s') . '\'';
        }
        if ($status !== \YandexCheckout\Model\PaymentStatus::CANCELED && $status !== \YandexCheckout\Model\PaymentStatus::PENDING) {
            $sql .= ', `paid`=\'Y\'';
        }
        $sql .= ' WHERE `payment_id`=\'' . $paymentId . '\'';
        $this->db->query($sql);
    }

    /**
     * @param int $orderId
     * @return array
     */
    public function getOrderRefunds($orderId)
    {
        $sql = 'SELECT * FROM `' . DB_PREFIX . 'ya_money_refunds` WHERE `order_id` = ' . (int)$orderId;
        $recordSet = $this->db->query($sql);
        $result = array();
        foreach ($recordSet->rows as $record) {
            if ($record['status'] === \YandexCheckout\Model\RefundStatus::PENDING) {
                $record['status'] = $this->checkRefundStatus($record['refund_id']);
            }
            $result[] = $record;
        }
        return $result;
    }

    private function checkRefundStatus($refundId)
    {
        // @todo вернуть выгрузку рефанда после того, как в API появится метод получения информации о нём
        return;
        try {
            $refund = $this->getClient()->getRefundInfo($refundId);
        } catch (\Exception $e) {
            return;
        }
        $sql = 'UPDATE `' . DB_PREFIX . 'ya_money_payment` SET `status` = \''
            . $this->db->escape($refund->getStatus()) . '\'';
        if ($refund->getAuthorizedAt() !== null) {
            $sql .= ', `authorized_at` = \'' . $refund->getAuthorizedAt()->format('Y-m-d H:i:s') . '\'';
        }
        $sql .= ' WHERE `refund_id` = \'' . $this->db->escape($refund->getId()) . '\'';
        $this->db->escape($sql);
    }

    /**
     * @param string $paymentId
     * @return \YandexCheckout\Model\PaymentInterface|null
     */
    public function fetchPaymentInfo($paymentId)
    {
        try {
            $payment = $this->getClient()->getPaymentInfo($paymentId);
        } catch (Exception $e) {
            $this->log('error', 'Failed to fetch payment info: ' . $e->getMessage());
            $payment = null;
        }
        return $payment;
    }

    public function getKassaModel()
    {
        if ($this->kassaModel === null) {
            require_once __DIR__ . '/yandex_money/YandexMoneyKassaModel.php';
            $this->kassaModel = new YandexMoneyKassaModel($this->config);
        }
        return $this->kassaModel;
    }

    public function getWalletModel()
    {
        if ($this->walletModel === null) {
            require_once __DIR__ . '/yandex_money/YandexMoneyWalletModel.php';
            $this->walletModel = new YandexMoneyWalletModel($this->config);
        }
        return $this->walletModel;
    }

    public function getBillingModel()
    {
        if ($this->billingModel === null) {
            require_once __DIR__ . '/yandex_money/YandexMoneyBillingModel.php';
            $this->billingModel = new YandexMoneyBillingModel($this->config);
        }
        return $this->billingModel;
    }

    public function carrierList()
    {
        $prefix = version_compare(VERSION, '2.3.0') >= 0 ? 'extension/' : '';
        $types = array(
            'POST' => "Доставка почтой",
            'PICKUP' => "Самовывоз",
            'DELIVERY' => "Доставка курьером"
        );
        $this->load->model('setting/extension');
        $extensions = $this->model_setting_extension->getInstalled('shipping');
        foreach ($extensions as $key => $value) {
            if (!file_exists(DIR_APPLICATION . 'controller/shipping/' . $value . '.php')) {
                unset($extensions[$key]);
            }
        }
        $data['extensions'] = array();
        $files = glob(DIR_APPLICATION . 'controller/shipping/*.php');
        if ($files) {
            foreach ($files as $file) {
                $extension = basename($file, '.php');
                if (in_array($extension, $extensions)) {
                    $this->load->language($prefix . 'shipping/' . $extension);
                    $data['extensions'][] = array(
                        'name'       => $this->language->get('heading_title'),
                        'sort_order' => $this->config->get($extension . '_sort_order'),
                        'installed'  => in_array($extension, $extensions),
                        'ext'        => $extension
                    );
                }
            }
        }
        $html = '';
        $save_data = $this->config->get('yandex_money_pokupki_carrier');
        foreach ($data['extensions'] as $row) {
            $html .= '<div class="form-group">
                <label class="col-sm-4 control-label" for="yandex_money_pokupki_carrier">'.$row['name'].'</label>
                <div class="col-sm-8">
                    <select name="yandex_money_pokupki_carrier['.$row['ext'].']" id="yandex_money_pokupki_carrier" class="form-control">';
            foreach ($types as $t => $t_name) {
                $html .= '<option value="' . $t . '" ' . ((isset($save_data[$row['ext']]) && $save_data[$row['ext']] == $t) ? 'selected="selected"' : '') . '>' . $t_name . '</option>';
            }
            $html .= '</select>
                </div>
            </div>';
        }

        return $html;
    }

    public function refundPayment($payment, $order, $amount, $comment)
    {
        try {
            $builder = \YandexCheckout\Request\Refunds\CreateRefundRequest::builder();
            $builder->setAmount($amount)
                ->setCurrency(\YandexCheckout\Model\CurrencyCode::RUB)
                ->setPaymentId($payment->getId())
                ->setComment($comment);
            $request = $builder->build();
        } catch (Exception $e) {
            $this->log('error', 'Failed to create refund: ' . $e->getMessage());
            return null;
        }

        try {
            $key = uniqid('', true);
            $tries = 0;
            do {
                $response = $this->getClient()->createRefund($request, $key);
                if ($response === null) {
                    $tries++;
                    if ($tries >= 3) {
                        break;
                    }
                    sleep(2);
                }
            } while ($response === null);
        } catch (Exception $e) {
            $this->log('error', 'Failed to create refund: ' . $e->getMessage());
            return null;
        }

        if ($response !== null) {
            $this->insertRefund($response, $order['order_id']);
        }

        return $response;
    }

    /**
     * @param \YandexCheckout\Model\RefundInterface $refund
     * @param int $orderId
     */
    private function insertRefund($refund, $orderId)
    {
        $sql = 'INSERT INTO `' . DB_PREFIX . 'ya_money_refunds`('
            . '`refund_id`, `order_id`, `payment_id`, `status`, `amount`, `currency`, `created_at`'
            . ($refund->getAuthorizedAt() !== null ? ',`authorized_at`' : '')
            . ') VALUES ('
            . "'" . $this->db->escape($refund->getId()) . "',"
            . (int)$orderId . ","
            . "'" . $this->db->escape($refund->getPaymentId()) . "',"
            . "'" . $this->db->escape($refund->getStatus()) . "',"
            . "'" . $this->db->escape($refund->getAmount()->getValue()) . "',"
            . "'" . $this->db->escape($refund->getAmount()->getCurrency()) . "',"
            . "'" . $this->db->escape($refund->getCreatedAt()->format('Y-m-d H:i:s')) . "'"
            . ($refund->getAuthorizedAt() !== null ? (", '" . $this->db->escape($refund->getCreatedAt()->format('Y-m-d H:i:s')) . "'") : '')
            . ')';
        $this->db->query($sql);
    }

    private $client;

    protected function getClient()
    {
        if ($this->client === null) {
            $this->client = new \YandexCheckout\Client();
            $this->client->setAuth(
                $this->getKassaModel()->getShopId(),
                $this->getKassaModel()->getPassword()
            );
            $this->client->setLogger($this);
        }
        return $this->client;
    }
}
