<?php
namespace Opencart\Admin\Controller\Extension\Mollie\Total;

use \Opencart\System\Helper as Helper;

// Load the Mollie Helper
require_once(DIR_EXTENSION . 'mollie/system/library/mollie/helper.php');

class MolliePaymentFee extends \Opencart\System\Engine\Controller {
	protected array $error = [];
	protected array $data = [];
	private string $token;
	private string $moduleCode = 'total_mollie_payment_fee';
	public \MollieHelper $mollieHelper;

	public function __construct($registry) {
		parent::__construct($registry);

		$this->token = 'user_token=' . $this->session->data['user_token'];
		$this->mollieHelper = new \MollieHelper($registry);
	}

	public function index(): void {
		// Load essential models
		$this->load->model('setting/setting');
		$this->load->model('localisation/language');
		$this->load->model('localisation/geo_zone');
		$this->load->model('localisation/tax_class');
		$this->load->model('customer/customer_group');

		$this->load->language('extension/mollie/total/mollie_payment_fee');

		$this->document->setTitle(strip_tags($this->language->get('heading_title')));

		// Breadcrumbs setup
		$data['breadcrumbs'] = [];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_home'),
			'href' => $this->url->link('common/dashboard', $this->token, true)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('text_extension'),
			'href' => $this->url->link('marketplace/extension', $this->token . '&type=total', true)
		];

		$data['breadcrumbs'][] = [
			'text' => $this->language->get('heading_title'),
			'href' => $this->url->link('extension/mollie/total/mollie_payment_fee', $this->token, true)
		];
		
		// Action links
		$data['save'] = $this->url->link('extension/mollie/total/mollie_payment_fee.save', $this->token, true);
		$data['back'] = $this->url->link('marketplace/extension', $this->token . '&type=total', true);
		
		// Populate data variables
		$data['payment_methods'] = $this->mollieHelper->MODULE_NAMES;
		$data['stores']          = $this->getStores();
		$data['geo_zones']       = $this->model_localisation_geo_zone->getGeoZones();
		$data['tax_classes']     = $this->model_localisation_tax_class->getTaxClasses();
		$data['customer_groups'] = $this->model_customer_customer_group->getCustomerGroups();

		// Languages with dynamic image path resolution
		$languages = $this->model_localisation_language->getLanguages();
		$data['languages'] = [];
		foreach ($languages as $language) {
			$language['image'] = $this->getLanguageImage($language['code']);
			$data['languages'][] = $language;
		}

		// Get configurations
		$data['total_mollie_payment_fee_status'] = $this->config->get('total_mollie_payment_fee_status');
		$data['total_mollie_payment_fee_sort_order'] = $this->config->get('total_mollie_payment_fee_sort_order');
		$data['total_mollie_payment_fee_tax_class_id'] = $this->config->get('total_mollie_payment_fee_tax_class_id');

		if ($this->config->get('total_mollie_payment_fee_charge')) {
			$data['total_mollie_payment_fee_charge'] = $this->config->get('total_mollie_payment_fee_charge');
		} else {
			$data['total_mollie_payment_fee_charge'] = [];
		}

		// Load layout components
		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/mollie/total/mollie_payment_fee', $data));
	}

	public function save(): void {
		$this->load->language('extension/mollie/total/mollie_payment_fee');

		$json = [];

		// Check user modification permissions
		if (!$this->user->hasPermission('modify', 'extension/mollie/total/mollie_payment_fee')) {
			$json['error'] = $this->language->get('error_permission');
		}

		// If no errors, save the settings
		if (!$json) {
			$this->load->model('setting/setting');

			$this->model_setting_setting->editSetting($this->moduleCode, $this->request->post);

			$json['success'] = $this->language->get('text_success');
		}

		// Output JSON response
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($json));
	}

	private function getStores(): array {
		$this->load->model('setting/store');
		
		$stores = [];
		
		// Default store
		$stores[0] = [
			'store_id' => 0,
			'name'     => $this->config->get('config_name')
		];

		$results = $this->model_setting_store->getStores();

		foreach ($results as $store) {
			$stores[$store['store_id']] = [
				'store_id' => $store['store_id'],
				'name'     => $store['name']
			];
		}

		return $stores;
	}

	protected function getLanguageImage(string $code): string {
		// 1. Check standard admin location
		if (is_file(DIR_LANGUAGE . $code . '/' . $code . '.png')) {
			return 'language/' . $code . '/' . $code . '.png';
		}

		// 2. Search in extension folders (dynamic search for language extensions)
		$files = glob(DIR_EXTENSION . '*/admin/language/' . $code . '/' . $code . '.png');
		if ($files) {
			// Convert system path to relative web path
			$relative_path = str_replace(DIR_OPENCART, '', $files[0]);
			
			// Return full URL to ensure it works regardless of current directory depth
			// Also replaces backslashes with forward slashes and encodes spaces for URLs
			return HTTP_CATALOG . ltrim(str_replace(['\\', ' '], ['/', '%20'], $relative_path), '/');
		}

		// 3. Fallback to the catalog language directory
		if (is_file(DIR_OPENCART . 'catalog/language/' . $code . '/' . $code . '.png')) {
			return HTTP_CATALOG . 'catalog/language/' . $code . '/' . $code . '.png';
		}

		return '';
	}
}