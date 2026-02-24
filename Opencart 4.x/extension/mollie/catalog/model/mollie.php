<?php
namespace Opencart\Catalog\Model\Extension\Mollie;
/**
 * Copyright (c) 2012-2015, Mollie B.V.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * - Redistributions of source code must retain the above copyright notice,
 *    this list of conditions and the following disclaimer.
 * - Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR AND CONTRIBUTORS ``AS IS'' AND ANY
 * EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
 * WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
 * DISCLAIMED. IN NO EVENT SHALL THE AUTHOR OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR
 * SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER
 * CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT
 * LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY
 * OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH
 * DAMAGE.
 *
 * @package     Mollie
 * @license     Berkeley Software Distribution License (BSD-License 2) http://www.opensource.org/licenses/bsd-license.php
 * @author      Mollie B.V. <info@mollie.com>
 * @copyright   Mollie B.V.
 * @link        https://www.mollie.com
 *
 * @property Config $config
 * @property DB $db
 * @property Language $language
 * @property Loader $load
 * @property Log $log
 * @property Registry $registry
 * @property Session $session
 * @property URL $url
 */
require_once(DIR_EXTENSION . "mollie/system/library/mollie/helper.php");

class Mollie extends \Opencart\System\Engine\Model {
	public $mollieHelper;

    public function __construct($registry) {
        parent::__construct($registry);
        $this->mollieHelper = new \MollieHelper($registry);
    }

    /**
     * @return \Mollie\Api\MollieApiClient
     */
    protected function getAPIClient() {
        return $this->mollieHelper->getAPIClient($this->config);
    }

    public function numberFormat(float|string $amount): string {
        $currency = $this->getCurrency();
        $intCurrencies = ["ISK", "JPY"];
        if (!in_array($currency, $intCurrencies)) {
            $formattedAmount = number_format((float)$amount, 2, '.', '');
        } else {
            $formattedAmount = number_format((float)$amount, 0, '.', '');
        }    
        return $formattedAmount;    
    }

    public function getAllActive(array $data): array {
        $allowed_methods = [];
        $resetMethods = false;

        if (!isset($this->session->data['mollie_allowed_methods'])) {
            $resetMethods = true;
        } else {
            if (isset($this->session->data['payment_address']) && ($this->session->data['payment_address']['country_id'] != ($this->session->data['mollie_country_id'] ?? 0))) {
                $resetMethods = true;
            } elseif (($this->session->data['mollie_currency'] ?? '') != ($this->session->data["currency"] ?? '')) {
                $resetMethods = true;
            }
        }

        if (!$resetMethods) {
            $allowed_methods = $this->session->data['mollie_allowed_methods'];
        } else {
            try {
                $payment_methods = $this->getAPIClient()->methods->allActive($data);
            } catch (\Mollie\Api\Exceptions\ApiException $e) {
                $this->log->write("Error retrieving payment methods from Mollie: {$e->getMessage()}.");
                return [];
            }
            
            //Get payment methods allowed for this amount and currency
            foreach ($payment_methods as $allowed_method) {
                $allowed_methods[] = $allowed_method->id;
            }
            
            if (empty($allowed_methods)) {
                $data["amount"]["currency"] = "EUR";
                $allowed_methods = $this->getAllActive($data);
            }
    
            $this->session->data['mollie_allowed_methods'] = $allowed_methods;
            $this->session->data['mollie_currency'] = $this->session->data["currency"] ?? '';
            $this->session->data['mollie_country_id'] = $this->session->data['payment_address']['country_id'] ?? 0;
        }

        return $allowed_methods;            
    }

    public function getCurrency(): string {
        if ($this->config->get($this->mollieHelper->getModuleCode() . "_default_currency") == "DEF") {
            $currency = $this->session->data['currency'] ?? '';
        } else {
            $currency = $this->config->get($this->mollieHelper->getModuleCode() . "_default_currency");
        }
        return (string)$currency;
    }

    /**
     * On the checkout page this method gets called to get information about the payment method.
     *
     * @param array $address
     * @return array
     */
    public function getMethod(array $address): array {
        return $this->getMethods($address, true);
    }

    public function getMethods(array $address = [], bool $old_version = false): array {
        // Skip upcoming methods
        if (in_array(static::MODULE_NAME, [])) {
            return [];
        }
        
        if (empty($address) && isset($this->session->data['shipping_address'])) {
            $address = $this->session->data['shipping_address'];
        }

        $currency = $this->getCurrency();
        $moduleCode = $this->mollieHelper->getModuleCode();

        $total = $this->cart->getTotal();

        // Check total for minimum and maximum amount
        $standardTotal = (float)$this->currency->convert($total, $this->config->get("config_currency"), 'EUR');
        $minimumAmount = 0.01;
        $maximumAmount = 0.0;

        $conf_min = $this->config->get($moduleCode . "_" . static::MODULE_NAME . "_total_minimum");
        if ($conf_min && !empty($conf_min)) {
            $minimumAmount = (float)$conf_min;
        }

        $conf_max = $this->config->get($moduleCode . "_" . static::MODULE_NAME . "_total_maximum");
        if ($conf_max && !empty($conf_max)) {
            $maximumAmount = (float)$conf_max;
        }

        if ($standardTotal < (float)$this->currency->convert($minimumAmount, $this->config->get("config_currency"), 'EUR')) {
            return [];
        }

        if (($maximumAmount > 0) && ($standardTotal > (float)$this->currency->convert($maximumAmount, $this->config->get("config_currency"), 'EUR'))) {
            return [];
        }
        
        // Check for order expiry days
        if (in_array(static::MODULE_NAME, ['klarnapaylater', 'klarnasliceit', 'klarnapaynow', 'klarna'])) {
            $expiry_days = (int)$this->config->get($moduleCode . "_order_expiry_days");
            if ($expiry_days && $expiry_days > 28) {
                return [];
            }
        }

        // Return nothing if ApplePay is not available
        if (static::MODULE_NAME == 'applepay') {
            if (isset($this->session->data['applePay']) && ($this->session->data['applePay'] == 0)) {
                return [];
            }
        }

        // Check if Vouchers are available
        if (static::MODULE_NAME == 'voucher') {
            $this->load->model('catalog/product');
            $voucherStatus = false;
            foreach ($this->cart->getProducts() as $cart_product) {
                $productDetails = $this->model_catalog_product->getProduct($cart_product['product_id']);
                if (!empty($productDetails['voucher_category'])) {
                    $voucherStatus = true;
                    break;
                }
            }

            if (!$voucherStatus) {
                return [];
            }
        }

        // Company name required for Billie method
        if (static::MODULE_NAME == 'billie') {
            if (isset($this->session->data['payment_address']) && empty($this->session->data['payment_address']['company'])) {
                return [];
            }
        }

        // Shipping address check
        if (static::MODULE_NAME == 'alma') {
            if (!isset($this->session->data['shipping_address'])) {
                return [];
            }
        }

        // Subscription check
        if ($this->cart->hasSubscription()) {
            $subscription_methods = ["applepay", "bancontact", "belfius", "creditcard", "eps", "ideal", "kbc", "paypal", "mybank"];
            if (!in_array(static::MODULE_NAME, $subscription_methods)) {
                return [];
            }
        }

        // Get billing country    
        $this->load->model('localisation/country');

        if (!empty($this->session->data['payment_address']['country_id'])) {
            $countryDetails = $this->model_localisation_country->getCountry($this->session->data['payment_address']['country_id']);
            $country = (string)($countryDetails['iso_code_2'] ?? '');
        } else {
            // Get billing country from store address
            $country_id = (int)$this->config->get('config_country_id');
            $countryDetails = $this->model_localisation_country->getCountry($country_id);
            $country = (string)($countryDetails['iso_code_2'] ?? '');
        }

        $total_converted = (float)$this->currency->convert($total, $this->config->get("config_currency"), $currency);    
        $sequence = $this->cart->hasSubscription() ? 'first' : 'oneoff';
            
        $data = [
            "amount"         => ["value" => (string)$this->numberFormat($total_converted), "currency" => $currency],
            "resource"       => "orders",
            "includeWallets" => "applepay",
            "billingCountry" => $country,
            "sequenceType"   => $sequence
        ];  

        $allowed_methods = $this->getAllActive($data);
        
        $method = static::MODULE_NAME;
        if ($method == 'in_3') {
            $method = 'in3';
        } elseif ($method == 'przelewy_24') {
            $method = 'przelewy24';
        }

        if (!in_array($method, $allowed_methods)) {
            return [];
        }

        $geo_zone_id = (int)$this->config->get($moduleCode . "_" . static::MODULE_NAME . "_geo_zone");
        $country_id = (int)($address['country_id'] ?? 0);
        $zone_id = (int)($address['zone_id'] ?? 0);

        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone_to_geo_zone` WHERE geo_zone_id = '" . $geo_zone_id . "' AND country_id = '" . $country_id . "' AND (zone_id = '" . $zone_id . "' OR zone_id = '0')");

        if ($geo_zone_id > 0 && !$query->num_rows) {
            return [];
        }

        // Translate payment method
        $this->load->language("extension/mollie/payment/mollie");

        $key = "method_" . $method;
        $description = $this->language->get($key);

        $lang_id = (int)$this->config->get('config_language_id');
        $custom_desc = $this->config->get($moduleCode . "_" . static::MODULE_NAME . "_description");

        if (isset($custom_desc[$lang_id]['title']) && !empty($custom_desc[$lang_id]['title'])) {
            $title = $custom_desc[$lang_id]['title'];
        } else {
            $title = $description;
        }

        if ($this->config->get($moduleCode . "_show_icons")) {
            $_image = $this->config->get($moduleCode . "_" . static::MODULE_NAME . "_image");
            
            if (!empty($_image)) {
                $this->load->model('tool/image');
                $image = $this->model_tool_image->resize($_image, 100, 100);
            } else {
                $image = $this->config->get('config_url') . 'image/mollie/' . static::MODULE_NAME . '.png';
            }            
            
            $icon = '<img src="' . $image . '" height="20" style="margin:0 5px 0 5px" />';

            if ($this->config->get($moduleCode . "_align_icons") == 'left') {
                $title = $icon . $title;
            } else {
                $title = $title . $icon;
            }
        }

        if ($old_version) {
            return [
                "code"       => "mollie_" . static::MODULE_NAME,
                "title"      => $title,
                "sort_order" => $this->config->get($moduleCode . "_" . static::MODULE_NAME . "_sort_order"),
                "terms"      => null
            ];
        } else {
            $option_data["mollie_" . static::MODULE_NAME] = [
                'code' => 'mollie_' . static::MODULE_NAME . '.' . 'mollie_' . static::MODULE_NAME,
                'name' => $title
            ];

            return [
                'code'       => "mollie_" . static::MODULE_NAME,
                'name'       => $title,
                'option'     => $option_data,
                'sort_order' => $this->config->get($moduleCode . "_" . static::MODULE_NAME . "_sort_order")
            ];
        }
    }

    public function setPayment(int|string $order_id, string $mollie_order_id, string $method): bool {
        $payment_attempt = 1;
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY payment_attempt DESC LIMIT 1");        
        
        if ($query->num_rows > 0) {
            $payment_attempt += (int)$query->row['payment_attempt'];
        }
        
        $bank_account = (string)($this->session->data['mollie_issuer'] ?? '');
        
        if (!empty($order_id) && !empty($mollie_order_id) && !empty($method)) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "mollie_payments` SET `order_id` = '" . (int)$order_id . "', `mollie_order_id` = '" . $this->db->escape($mollie_order_id) . "', `method` = '" . $this->db->escape($method) . "', `bank_account` = '" . $this->db->escape($bank_account) . "', `payment_attempt` = '" . (int)$payment_attempt . "', date_modified = NOW() ON DUPLICATE KEY UPDATE `order_id` = '" . (int)$order_id . "'");

            return ($this->db->countAffected() > 0);
        }

        return false;
    }

    public function setPaymentForPaymentAPI(int|string $order_id, string $mollie_payment_id, string $method): bool {
        $payment_attempt = 1;
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY payment_attempt DESC LIMIT 1");        
        
        if ($query->num_rows > 0) {
            $payment_attempt += (int)$query->row['payment_attempt'];
        }
        
        $bank_account = (string)($this->session->data['mollie_issuer'] ?? '');
        
        if (!empty($order_id) && !empty($mollie_payment_id) && !empty($method)) {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "mollie_payments` SET `order_id` = '" . (int)$order_id . "', `transaction_id` = '" . $this->db->escape($mollie_payment_id) . "', `method` = '" . $this->db->escape($method) . "', `bank_account` = '" . $this->db->escape($bank_account) . "', `payment_attempt` = '" . (int)$payment_attempt . "', date_modified = NOW() ON DUPLICATE KEY UPDATE `order_id` = '" . (int)$order_id . "'");

            return ($this->db->countAffected() > 0);
        }

        return false;
    }

    public function updatePayment(int|string $order_id, string $mollie_order_id, array $data, ?string $consumer = null): bool {
        if (!empty($order_id) && !empty($mollie_order_id)) {
            $amount = isset($data['amount']) ? (float)$data['amount'] : 0.0;
            $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `transaction_id` = '" . $this->db->escape((string)$data['payment_id']) . "', `bank_status` = '" . $this->db->escape((string)$data['status']) . "', amount = '" . $amount . "', date_modified = NOW() WHERE `order_id` = '" . (int)$order_id . "' AND `mollie_order_id` = '" . $this->db->escape($mollie_order_id) . "'");

            return ($this->db->countAffected() > 0);
        }
        return false;
    }

    public function updatePaymentForPaymentAPI(int|string $order_id, string $mollie_payment_id, array $data, ?string $consumer = null): bool {
        if (!empty($order_id) && !empty($mollie_payment_id)) {
            $amount = isset($data['amount']) ? (float)$data['amount'] : 0.0;
            $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `bank_status` = '" . $this->db->escape((string)$data['status']) . "', amount = '" . $amount . "', date_modified = NOW() WHERE `order_id` = '" . (int)$order_id . "' AND `transaction_id` = '" . $this->db->escape($mollie_payment_id) . "'");

            return ($this->db->countAffected() > 0);
        }
        return false;
    }

    public function getPaymentID(int|string $order_id): string|bool {
        if (!empty($order_id)) {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `order_id` = '" . (int)$order_id . "'");
            if ($results->num_rows == 0) return false;
            return (string)$results->row['transaction_id'];
        }
        return false;
    }

    public function getOrderID(int|string $order_id): string|bool {
        if (!empty($order_id)) {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `order_id` = '" . (int)$order_id . "' ORDER BY payment_attempt DESC LIMIT 1");
            if ($results->num_rows == 0) return false;
            return (string)$results->row['mollie_order_id'];
        }
        return false;
    }

    public function getOrderProducts(int|string $order_id): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_product` WHERE order_id = '" . (int)$order_id . "'");
        return $query->rows;
    }

    public function getCouponDetails(int|string $orderID): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$orderID . "' AND code = 'coupon'");
        return $query->num_rows ? $query->row : [];
    }

    public function getVoucherDetails(int|string $orderID): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$orderID . "' AND code = 'voucher'");
        return $query->num_rows ? $query->row : [];        
    }

    public function getRewardPointDetails(int|string $orderID): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$orderID . "' AND code = 'reward'");
        return $query->num_rows ? $query->row : [];        
    }

    public function getOtherOrderTotals(int|string $orderID): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_total` WHERE order_id = '" . (int)$orderID . "' AND code != 'shipping' AND code != 'tax' AND code != 'voucher' AND code != 'sub_total' AND code != 'coupon' AND code != 'reward' AND code != 'total'");
        return $query->rows;
    }

	public function getPayment(int|string $order_id): array|bool {
        if (!empty($order_id)) {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `order_id` = '" . (int)$order_id . "'");
            if ($results->num_rows == 0) return false;
            return $results->row;
        }
        return false;
    }

    public function getPaymentById(string $id, string $type): array {
        if ($type == 'payment') {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `transaction_id` = '" . $this->db->escape($id) . "'");
        } else {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `mollie_order_id` = '" . $this->db->escape($id) . "'");
        }
        
        return $results->num_rows ? $results->row : [];
    }

    public function cancelReturn(int|string $order_id, string $mollie_order_id, array $data): bool {
        if (!empty($order_id)) {
            if (!empty($mollie_order_id)) {
                $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `transaction_id` = '" . $this->db->escape((string)$data['payment_id']) . "', `bank_status` = '" . $this->db->escape((string)$data['status']) . "', `refund_id` = '" . $this->db->escape((string)($data['refund_id'] ?? '')) . "' WHERE `order_id` = '" . (int)$order_id . "' AND `mollie_order_id` = '" . $this->db->escape($mollie_order_id) . "'");
            } else {
                $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `bank_status` = '" . $this->db->escape((string)$data['status']) . "', `refund_id` = '" . $this->db->escape((string)($data['refund_id'] ?? '')) . "' WHERE `order_id` = '" . (int)$order_id . "' AND `transaction_id` = '" . $this->db->escape((string)$data['payment_id']) . "'");
            }

            return ($this->db->countAffected() > 0);
        }
        return false;
    }

    public function checkMollieOrderID(string $mollie_order_id): bool {
        if (!empty($mollie_order_id)) {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `mollie_order_id` = '" . $this->db->escape($mollie_order_id) . "'");
            return ($results->num_rows > 0);
        }
        return false;
    }

    public function getOrderStatuses(int|string $order_id): array {
        $results = $this->db->query("SELECT DISTINCT order_status_id FROM `" . DB_PREFIX . "order_history` WHERE `order_id` = '" . (int)$order_id . "'");
        
        $orderStatuses = [];
        if (!empty($results->rows)) {
            foreach ($results->rows as $row) {
                $orderStatuses[] = (int)$row['order_status_id'];
            }
        }

        return $orderStatuses;
    }

    public function addCustomer(array $data): void {
        $this->db->query("INSERT INTO `" . DB_PREFIX . "mollie_customers` SET `customer_id` = '" . (int)$data['customer_id'] . "', `mollie_customer_id` = '" . $this->db->escape((string)$data['mollie_customer_id']) . "', `email` = '" . $this->db->escape((string)$data['email']) . "', `date_created` = NOW()");
    }

    public function getMollieCustomer(string $email): string {
        if (!empty($email)) {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_customers` WHERE `email` = '" . $this->db->escape($email) . "'");
            if ($results->num_rows == 0) return '';
            return (string)$results->row['mollie_customer_id'];
        }
        return '';
    }

    public function getMollieCustomerById(): array {
        $query = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_customers` WHERE `customer_id` = '" . (int)$this->customer->getId() . "'");
        return $query->num_rows ? $query->row : [];
    }

    public function deleteMollieCustomer(string $email): void {
        $this->db->query("DELETE FROM `" . DB_PREFIX . "mollie_customers` WHERE email = '" . $this->db->escape(mb_strtolower($email)) . "'");
    }

    public function subscriptionPayment(array $item, string $mollie_subscription_id, string $mollie_order_id = '', string $mollie_payment_id = ''): void {
        $next_payment = new \DateTime('now');
        $subscription_end = new \DateTime('now');

        if ($item['duration'] != 0) {
            $next_payment = $this->calculateSchedule($item['frequency'], $next_payment, (int)$item['cycle']);
            $subscription_end = $this->calculateSchedule($item['frequency'], $subscription_end, (int)$item['cycle'] * (int)$item['duration']);
        } else {
            $next_payment = $this->calculateSchedule($item['frequency'], $next_payment, (int)$item['cycle']);
            $subscription_end = new \DateTime('0000-00-00');
        }

        $order_id = (int)($this->session->data['order_id'] ?? 0);
        $order_subscription_id = (int)($item['order_subscription_id'] ?? 0);

        if (!empty($mollie_order_id)) {
            $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `mollie_subscription_id` = '" . $this->db->escape($mollie_subscription_id) . "', `next_payment` = '" . $this->db->escape($next_payment->format('Y-m-d H:i:s')) . "', subscription_end = '" . $this->db->escape($subscription_end->format('Y-m-d H:i:s')) . "', order_subscription_id = '" . $order_subscription_id . "' WHERE `order_id` = '" . $order_id . "' AND `mollie_order_id` = '" . $this->db->escape($mollie_order_id) . "'");
        } else {
            $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `mollie_subscription_id` = '" . $this->db->escape($mollie_subscription_id) . "', `next_payment` = '" . $this->db->escape($next_payment->format('Y-m-d H:i:s')) . "', subscription_end = '" . $this->db->escape($subscription_end->format('Y-m-d H:i:s')) . "', order_subscription_id = '" . $order_subscription_id . "' WHERE `order_id` = '" . $order_id . "' AND `transaction_id` = '" . $this->db->escape($mollie_payment_id) . "'");
        }
    }

    private function calculateSchedule(string $frequency, \DateTime $next_payment, int $cycle): \DateTime {
        if ($frequency == 'semi_month') {
            $day = (int)$next_payment->format('d');
            $value = 15 - $day;
            $isEven = ($cycle % 2 == 0);

            $odd = ($cycle + 1) / 2;
            $plus_even = ($cycle / 2) + 1;
            $minus_even = $cycle / 2;

            if ($day == 1) {
                $odd = $odd - 1;
                $plus_even = $plus_even - 1;
                $day = 16;
            }

            if ($day <= 15 && $isEven) {
                $next_payment->modify('+' . $value . ' day');
                $next_payment->modify('+' . $minus_even . ' month');
            } elseif ($day <= 15) {
                $next_payment->modify('first day of this month');
                $next_payment->modify('+' . $odd . ' month');
            } elseif ($day > 15 && $isEven) {
                $next_payment->modify('first day of this month');
                $next_payment->modify('+' . $plus_even . ' month');
            } elseif ($day > 15) {
                $next_payment->modify('+' . $value . ' day');
                $next_payment->modify('+' . $odd . ' month');
            }
        } else {
            $next_payment->modify('+' . $cycle . ' ' . $frequency);
        }
        return $next_payment;
    }

    public function getPaymentBySubscriptionID(string $mollie_subscription_id): array {
        if (!empty($mollie_subscription_id)) {
            $results = $this->db->query("SELECT * FROM `" . DB_PREFIX . "mollie_payments` WHERE `mollie_subscription_id` = '" . $this->db->escape($mollie_subscription_id) . "'");
            if ($results->num_rows == 0) return [];
            return $results->row;
        }
        return [];
    }

    public function addSubscriptionPayment(array $data): void {
        $profile = $this->getProfile($data['order_subscription_id']);
        $subscription_order = $this->getPaymentBySubscriptionID($data['mollie_subscription_id']);

        if (empty($profile) || empty($subscription_order)) {
            return;
        }

        $this->load->model('checkout/order');
        $order_info = $this->model_checkout_order->getOrder($profile['order_id']);

        $amount = $this->currency->convert($data['amount'], $data['currency'], $this->config->get("config_currency"));
        $frequency = $profile['subscription_frequency'];
        $cycle = (int)$profile['subscription_cycle'];

        $today = new \DateTime('now');
        $unlimited = new \DateTime('0000-00-00');
        $next_payment = new \DateTime($subscription_order['next_payment']);
        $subscription_end = new \DateTime($subscription_order['subscription_end']);

        if ($data['status'] == 'paid') {
            $this->db->query("INSERT INTO `" . DB_PREFIX . "mollie_subscription_payments` SET `transaction_id` = '" . $this->db->escape((string)$data['transaction_id']) . "', `mollie_subscription_id` = '" . $this->db->escape((string)$data['mollie_subscription_id']) . "', `mollie_customer_id` = '" . $this->db->escape((string)$data['mollie_customer_id']) . "', `amount` = '" . (float)$amount . "', `method` = '" . $this->db->escape((string)$data['method']) . "', `status` = '" . $this->db->escape((string)$data['status']) . "', `order_subscription_id` = '" . (int)$data['order_subscription_id'] . "', `date_created` = NOW()");

            $next_payment = $this->calculateSchedule($frequency, $next_payment, $cycle);
            $next_payment_str = $next_payment->format('Y-m-d H:i:s');

            if ($subscription_end > $today || $subscription_end == $unlimited) {
                $this->db->query("UPDATE `" . DB_PREFIX . "mollie_payments` SET `next_payment` = '" . $this->db->escape($next_payment_str) . "' WHERE `mollie_subscription_id` = '" . $this->db->escape($data['mollie_subscription_id']) . "'");

                // Send mail to the customer
                $lang_id = (int)$this->config->get('config_language_id');
                $email_settings = $this->config->get($this->mollieHelper->getModuleCode() . "_subscription_email");
                
                $subject = $email_settings[$lang_id]['subject'] ?? 'Subscription Payment';
                $body = $email_settings[$lang_id]['body'] ?? '';

                $find = ["{firstname}", "{lastname}", "{next_payment}", "{product_name}", "{order_id}", "{store_name}"];
                $replace = [
                    $order_info['firstname'] ?? '', 
                    $order_info['lastname'] ?? '', 
                    $next_payment->format('d-m-Y'), 
                    $profile['subscription_name'] ?? '', 
                    $profile['order_id'] ?? '', 
                    $order_info['store_name'] ?? ''
                ];
                
                $body = html_entity_decode(str_replace($find, $replace, $body), ENT_QUOTES, 'UTF-8');

                if ($this->config->get('config_mail_engine')) {
                    if (version_compare(VERSION, '4.0.2.0', '<')) {
                        $mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'));
                        $mail->parameter = (string)$this->config->get('config_mail_parameter');
                        $mail->smtp_hostname = (string)$this->config->get('config_mail_smtp_hostname');
                        $mail->smtp_username = (string)$this->config->get('config_mail_smtp_username');
                        $mail->smtp_password = html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8');
                        $mail->smtp_port = (int)$this->config->get('config_mail_smtp_port');
                        $mail->smtp_timeout = (int)$this->config->get('config_mail_smtp_timeout');
            
                        $mail->setTo($order_info['email']);
                        $mail->setFrom((string)$this->config->get('config_email'));
                        $mail->setSender(html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
                        $mail->setSubject(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
                        $mail->setHtml($body);
                    } else {
                        $mail_option = [
                            'parameter'     => (string)$this->config->get('config_mail_parameter'),
                            'smtp_hostname' => (string)$this->config->get('config_mail_smtp_hostname'),
                            'smtp_username' => (string)$this->config->get('config_mail_smtp_username'),
                            'smtp_password' => html_entity_decode((string)$this->config->get('config_mail_smtp_password'), ENT_QUOTES, 'UTF-8'),
                            'smtp_port'     => (int)$this->config->get('config_mail_smtp_port'),
                            'smtp_timeout'  => (int)$this->config->get('config_mail_smtp_timeout')
                        ];
            
                        $mail = new \Opencart\System\Library\Mail($this->config->get('config_mail_engine'), $mail_option);
                        $mail->setTo($order_info['email']);
                        $mail->setFrom((string)$this->config->get('config_email'));
                        $mail->setSender(html_entity_decode((string)$this->config->get('config_name'), ENT_QUOTES, 'UTF-8'));
                        $mail->setSubject(html_entity_decode($subject, ENT_QUOTES, 'UTF-8'));
                        $mail->setHtml($body);
                    }
    
                    $mail->send();
                }
            }
        }
    }

    private function getProfile(int|string $order_subscription_id): array {
        $qry = $this->db->query("SELECT * FROM `" . DB_PREFIX . "order_subscription` WHERE order_subscription_id = '" . (int)$order_subscription_id . "'");
        return $qry->num_rows ? $qry->row : [];
    }

    public function getProductVoucherCategory(int|string $product_id): ?string {
        $query = $this->db->query("SELECT voucher_category FROM `" . DB_PREFIX . "product` WHERE product_id = '" . (int)$product_id . "'");

        if ($query->num_rows) {
            return $query->row['voucher_category'] ?? null;
        }
        return null;
    }

    public function getSubscription(int|string $order_id): mixed {
        $mollie_order = $this->getPayment($order_id);

        if (!$mollie_order || empty($mollie_order['mollie_subscription_id'])) {
            return false;
        }

        $subscription_id = $mollie_order['mollie_subscription_id'];
        $mollie_customer = $this->getMollieCustomerById();

        if (empty($mollie_customer) || empty($mollie_customer['mollie_customer_id'])) {
            return false;
        }

        $api = $this->getAPIClient();

        try {
            $customer = $api->customers->get($mollie_customer['mollie_customer_id']);
            $subscription = $customer->getSubscription($subscription_id);

            return $subscription;

        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            return false;
        }  
    }
}