<?php
namespace Opencart\Catalog\Model\Extension\Mollie\Payment;

require_once(DIR_EXTENSION . "mollie/system/library/mollie/helper.php");

class MolliePaymentLink extends \Opencart\System\Engine\Model {

    public function getPaymentLink($payment_link_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payment_link` WHERE payment_link_id = '" . $this->db->escape($payment_link_id) . "'");
        return $query->row;
    }

    public function getPaymentLinkByOrderID($order_id) {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payment_link` WHERE order_id = '" . (int)$order_id . "'");
        return $query->row;
    }

    public function setPaymentForPaymentLinkAPI($order_id, $mollie_payment_link_id, $data = []) {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "mollie_payment_link` SET `order_id` = '" . (int)$order_id . "', `payment_link_id` = '" . $this->db->escape($mollie_payment_link_id) . "', `amount` = '" . (float)$data['amount'] . "', `currency_code` = '" . $this->db->escape($data['currency_code']) . "', date_created = NOW()");
    }

    public function updatePaymentLink($payment_link_id, $date) {
        $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payment_link` SET date_payment = '" . $this->db->escape($date) . "' WHERE payment_link_id = '" . $this->db->escape($payment_link_id) . "'");
    }

    public function numberFormat($amount, $currency) {
        $intCurrencies = ["ISK", "JPY"];
        if (!in_array($currency, $intCurrencies)) {
            $formattedAmount = number_format((float)$amount, 2, '.', '');
        } else {
            $formattedAmount = number_format((float)$amount, 0);
        }
        return $formattedAmount;
    }

    /**
     * Generates and optionally sends a Mollie Payment Link.
     * @param array $order_info
     * @param array $postData
     * @return array
     */
    public function sendPaymentLink(array $order_info, array $postData = []): array {
        // Do not send payment link if the payment is already made and the new order total is unchanged
        $payment_link_details = $this->getPaymentLinkByOrderID((int)$order_info['order_id']);
        
        if (!empty($payment_link_details) && !empty($payment_link_details['date_payment'])) {
            $new_order_total = $this->numberFormat($order_info['total'], $order_info['currency_code']);

            if (!isset($postData['mollie_payment_link_amount']) && ($new_order_total == $payment_link_details['amount'])) {
                $log = new \Opencart\System\Library\Log('Mollie.log');
                $log->write("Mollie payment link not sent for order_id " . $order_info['order_id'] . ". Same order total.");
                return [];
            }
        }

        $this->load->language("extension/mollie/payment/mollie");
        $this->load->model('setting/setting');

        $data = [];
        $mollieHelper = new \MollieHelper($this->registry);
        $moduleCode = $mollieHelper->getModuleCode(); 

        // Prepare Description
        $desc = $this->config->get($moduleCode . "_description");
        $lang_id = (int)$this->config->get('config_language_id');
        
        if (is_array($desc) && isset($desc[$lang_id]['title'])) {
            $description = str_replace("%", (string)$order_info['order_id'], html_entity_decode($desc[$lang_id]['title'], ENT_QUOTES, "UTF-8"));
        } else {
            $description = 'Order ' . $order_info['order_id'];
        }

        // Determine Amount
        if (!empty($postData['mollie_payment_link_amount'])) {
            $order_total = (float)$postData['mollie_payment_link_amount'];
        } else {
            $order_total = (float)$order_info['total']; // Keep raw value for calculations first
        }

        // Determine Payment Code safely
        $payment_code = (string)($order_info['payment_method']['code'] ?? $order_info['payment_code'] ?? '');

        // Adjust amount based on existing payments (Full vs Open amount)
        if (!empty($payment_link_details) && !empty($payment_link_details['date_payment'])) {
            if (str_contains($payment_code, 'mollie_payment_link_full')) {
                $order_total = (float)$order_info['total'];
            } elseif (str_contains($payment_code, 'mollie_payment_link_open')) {
                if ($order_info['total'] > $payment_link_details['amount']) {
                    $order_total = (float)$order_info['total'] - (float)$payment_link_details['amount'];
                }
            }
        }

        $formattedAmount = $this->numberFormat($order_total, $order_info['currency_code']);
        
        // Prepare API Payload
        $linkData = [
            "description" => $description,
            "amount"      => [
                "currency" => $order_info['currency_code'], 
                "value"    => (string)$formattedAmount
            ],
            "redirectUrl" => str_replace('admin/', '', $order_info['store_url']) . 'index.php?route=extension/mollie/payment/mollie_payment_link.payLinkCallback&language=' . $this->config->get('config_language') . '&order_id=' . $order_info['order_id'],
            "webhookUrl"  => $order_info['store_url'] . 'index.php?route=extension/mollie/payment/mollie_payment_link.webhook'
        ];

        try {
            $config = $this->config;
            
            // Set API key dynamically
            $config->set($moduleCode . "_api_key", $mollieHelper->getApiKey($order_info['store_id']));
            $mollieApi = $mollieHelper->getAPIClient($config);

            $paymentLink = $mollieApi->paymentLinks->create($linkData);
            $payment_link_url = $paymentLink->getCheckoutUrl();

            // Save generated link to database
            $this->setPaymentForPaymentLinkAPI($order_info['order_id'], $paymentLink->id, ["amount" => $formattedAmount, "currency_code" => $order_info['currency_code']]);

            // --- Email Preparation ---
            $payment_link_setting = $this->config->get($moduleCode . '_payment_link_email');
            $payment_link_subject = (is_array($payment_link_setting) && !empty($payment_link_setting[$lang_id]['subject'])) 
                ? $payment_link_setting[$lang_id]['subject'] 
                : $this->language->get('text_payment_link_email_subject');

            $payment_link_text = (is_array($payment_link_setting) && !empty($payment_link_setting[$lang_id]['body'])) 
                ? $payment_link_setting[$lang_id]['body'] 
                : $this->language->get('text_payment_link_email_text');

            $payment_link_text = html_entity_decode($payment_link_text, ENT_QUOTES, 'UTF-8');

            $find = ['{firstname}', '{lastname}', '{amount}', '{order_id}', '{store_name}', '{payment_link}'];
            $replace = [
                'firstname'    => $order_info['payment_firstname'],
                'lastname'     => $order_info['payment_lastname'],
                'amount'       => html_entity_decode($this->currency->format($formattedAmount, $order_info['currency_code'], $order_info['currency_value']), ENT_NOQUOTES, 'UTF-8'),
                'order_id'     => $order_info['order_id'],
                'store_name'   => $order_info['store_name'],
                'payment_link' => $payment_link_url
            ];

            $payment_link_subject = str_replace($find, $replace, $payment_link_subject);

            // Prepare view data
            $data = $order_info;
            $data['text_link'] = $this->language->get('text_link');
            $data['title'] = $this->language->get('text_payment_link_title');
            $data['text_footer'] = $this->language->get('text_footer');

            // Logo logic
            $store_info = $this->model_setting_store->getStore($order_info['store_id']);
            if ($store_info) {
                $this->load->model('setting/setting');
                $store_logo = $this->model_setting_setting->getValue('config_logo', $store_info['store_id']);
                $store_name = $store_info['name'];
            } else {
                $store_logo = $this->config->get('config_logo');
                $store_name = $this->config->get('config_name');
            }

            // Safe HTML decoding for strings
            $store_name = html_entity_decode((string)$store_name, ENT_QUOTES, 'UTF-8');
            $store_logo = html_entity_decode((string)$store_logo, ENT_QUOTES, 'UTF-8');

            if (!empty($store_logo) && is_file(DIR_IMAGE . $store_logo)) {
                $data['logo'] = $order_info['store_url'] . 'image/' . $store_logo;
            } else {
                $data['logo'] = '';
            }

            $data['store'] = $store_name;
            $data['store_url'] = $order_info['store_url'];
            
            if (!empty($order_info['customer_id'])) {
                $data['link'] = $order_info['store_url'] . 'index.php?route=account/order.info&order_id=' . $order_info['order_id'];
            } else {
                $data['link'] = '';
            }

            // Cleanup text for HTML
            $processed_text = str_replace($find, $replace, $payment_link_text);
            $processed_text = trim(preg_replace(["/\s\s+/", "/\r\r+/", "/\n\n+/"], '<br />', $processed_text));
            $data['payment_link'] = str_replace(["\r\n", "\r", "\n"], '<br />', $processed_text);
            
            // --- Seperate mail ---
            // if '_payment_link' is 0
            if (!(bool)$this->config->get($moduleCode . '_payment_link')) {
                
                $from = $this->model_setting_setting->getValue('config_email', $order_info['store_id']);
                if (!$from) {
                    $from = $this->config->get('config_email');
                }

                if ($this->config->get('config_mail_engine')) {
                    $mail_option = [
                        'parameter'     => $this->config->get('config_mail_parameter'),
                        'smtp_hostname' => $this->config->get('config_mail_smtp_hostname'),
                        'smtp_username' => $this->config->get('config_mail_smtp_username'),
                        'smtp_password' => html_entity_decode($this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
                        'smtp_port'     => $this->config->get('config_mail_smtp_port'),
                        'smtp_timeout'  => $this->config->get('config_mail_smtp_timeout')
                    ];
            
                    $mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'), $mail_option);
                    $mail->setTo($order_info['email']);
                    $mail->setFrom($from);
                    $mail->setSender($store_name);
                    $mail->setSubject($payment_link_subject);
                    $mail->setHtml($this->load->view('extension/mollie/payment/mollie_payment_link', $data));
                    $mail->send();
                }
            }

            $log = new \Opencart\System\Library\Log('Mollie.log');
            $log->write("Mollie payment link generated for order_id " . $order_info['order_id']);

            return $data;

        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            $log = new \Opencart\System\Library\Log('Mollie.log');
            $log->write('Mollie API Error: ' . htmlspecialchars($e->getMessage()));
        }

        return [];
    }
}