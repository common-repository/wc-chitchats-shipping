<?php

/*********************************************************************/
/*  PROGRAM          FlexRC                                          */
/*  PROPERTY         604-1097 View St                                 */
/*  OF               Victoria BC   V8V 0G9                          */
/*  				 Voice 604 800-7879                              */
/*                                                                   */
/*  Any usage / copying / extension or modification without          */
/*  prior authorization is prohibited                                */
/*********************************************************************/

namespace OneTeamSoftware\WooCommerce\Shipping\Adapter;

defined('ABSPATH') || exit;

if (!class_exists(__NAMESPACE__ . '\\ChitChats')):

class ChitChats extends AbstractAdapter
{
	protected $clientId;
	protected $accessToken;
	protected $testClientId;
	protected $testAccessToken;
	protected $includeDeliveryFee;
	protected $vatReference;
	protected $dutiesPaidRequested;
	protected $cheapestPostageTypeRequested;

	// we don't want these properties overwritten by settings
	protected $_services;

	public function __construct($id, array $settings = array())
	{
		$this->clientId = null;
		$this->accessToken = null;
		$this->testClientId = null;
		$this->testAccessToken = null;
		$this->includeDeliveryFee = false;
		$this->vatReference = '';
		$this->dutiesPaidRequested = false;
		$this->cheapestPostageTypeRequested = false;

		parent::__construct($id, $settings);

		$this->currencies = array('usd' => __('USD', $this->id), 'cad' => __('CAD', $this->id));
		$this->currency = strtolower(get_option('woocommerce_currency', ''));

		if (empty($this->currencies[$this->currency])) {
			$this->currency = 'usd';
		}

		$this->statuses = array(
			'canceled' => __('Cancelled', $this->id),
			'ready' => __('Shipping Label Created', $this->id),
			'in_transit' => __('In Transit', $this->id),
			'received' => __('Received by the carrier', $this->id),
			'released' => __('Released to the carrier', $this->id),
			'inducted' => __('Inducted', $this->id),
			'resolved' => __('Resolved', $this->id),
			'delivered' => __('Delivered', $this->id),
			'exception' => __('Exception', $this->id),
			'voided' => __('Voided', $this->id),
			'refund_approved' => __('Refunded', $this->id),
			'archived' => __('Archived', $this->id),
			'release_shipment_via_batch' => __('Release Shipment via Batch', $this->id),
		);

		$this->completedStatuses = array(
			'refund_approved',
			'refund_requested',
			'release_shipment_via_batch',
			'archived',
			'delivered',
			'inducted',
			'cancelled',
			'voided',
			'exception'
		);

		$this->contentTypes = array(
			'merchandise' => __('Merchandise', $this->id),
			'documents' => __('Documents', $this->id),
			'gift' => __('Gift', $this->id),
			'returned_goods' => __('Returned Goods', $this->id),
			'sample' => __('Sample', $this->id),
			'other' => __('Other', $this->id),
		);

		$this->initServices();
		$this->initPackageTypes();

		add_filter($this->id . '_shipments', array($this, 'onShipments'), 1, 1);
	}

	// We need it as a compatibility layer with the legacy plugin
	public function onShipments($shipments)
	{
		if (empty($shipments) || !is_array($shipments)) {
			return $shipments;
		}
		
		foreach ($shipments as $key => $shipment) {
			if (empty($shipment['status_name']) && !empty($shipment['status'])) {
				$shipment['status_name'] = $shipment['status'];
			}

			if (empty($shipment['service']) && !empty($shipment['postage_type'])) {
				$shipment['service'] = $shipment['postage_type'];
			}

			if (!empty($shipment['postage_label_png_url'])) {
				$shipment['postage_label_pdf_url'] = preg_replace('/\.png$/', '.pdf', $shipment['postage_label_png_url']);
			}	

			$shipments[$key] = $shipment;
		}

		return $shipments;
	}

	public function getName()
	{
		return 'ChitChats';
	}

	public function hasLinkFeature()
	{
		return true;
	}

	public function hasMediaMailFeature()
	{
		return true;
	}

	public function hasInsuranceFeature()
	{
		return true;
	}

	public function hasSignatureFeature()
	{
		return true;
	}

	public function hasDisplayDeliveryTimeFeature()
	{
		return true;
	}

	public function hasDisplayTrackingTypeFeature()
	{
		return true;
	}

	public function hasUpdateShipmentsFeature()
	{
		return true;
	}

	public function hasCreateShipmentFeature()
	{
		return true;
	}

	public function hasCreateManifestsFeature()
	{
		return true;
	}

	public function validate(array $settings)
	{
		$errors = array();

		$this->setSettings($settings);

		$clientIdKey = 'clientId';
		$clientIdName = __('Client ID', $this->id);
		$accessTokenKey = 'accessToken';
		$accessTokenName = __('Access Token', $this->id);

		if ($settings['sandbox'] == 'yes') {
			$clientIdKey = 'testClientId';
			$clientIdName = __('Test Client ID', $this->id);
			$accessTokenKey = 'testAccessToken';
			$accessTokenName = __('Test Access Token', $this->id);
		}

		if (empty($settings[$clientIdKey])) {
			$errors[] = sprintf('<strong>%s:</strong> %s', $clientIdName, __('is required for the integration to work', $this->id));
		}

		if (empty($settings[$accessTokenKey])) {
			$errors[] = sprintf('<strong>%s:</strong> %s', $accessTokenName, __('is required for the integration to work', $this->id));
		}

		if (!empty($settings[$clientIdKey]) && !empty($settings[$accessTokenKey]) && !$this->validateActiveApi()) {
			$errors[] = sprintf('<strong>%s / %s:</strong> %s', $clientIdName, $accessTokenName, __('are invalid', $this->id));
		}

		return $errors;
	}

	protected function validateActiveApi()
	{
		$response = $this->getRates(array('name' => 'Test Request'));
		if (empty($response)) {
			return false;
		} else if (!empty($response['error']['message']) && strpos($response['error']['message'], 'Unauthorized') !== false) {
			return false;
		} else if (!empty($response['error']['message']) && strpos($response['error']['message'], 'Not Found') !== false) {
			return false;
		}

		return true;
	}

	public function getRates(array $params)
	{
		$this->logger->debug(__FILE__, __LINE__, 'getRates');

		$cacheKey = $this->getCacheKey($params);
		$result = $this->getCacheValue($cacheKey);

		if (empty($result)) {
			$params['function'] = __FUNCTION__;
			$result = $this->sendRequest('shipments', 'POST', $params);

			if (isset($result['shipment']['id'])) {
				$this->delete($result['shipment']['id']);

				$this->setCacheValue($cacheKey, $result, $this->cacheExpirationInSecs);
			}	
		} else {
			$this->logger->debug(__FILE__, __LINE__, 'Found previously cached result');
		}
		
		return $result;
	}
	
	public function delete($shipmentId)
	{
		$this->logger->debug(__FILE__, __LINE__, 'delete');

		if (empty($shipmentId)) {
			$this->logger->debug(__FILE__, __LINE__, 'invalid shipment id');

			return false;
		}

		$params = array();
		$params['function'] = __FUNCTION__;

		return $this->sendRequest('shipments/' . $shipmentId, 'DELETE', $params);
	}
	
	public function updateFormFields($formFields)
	{
		$formFields = $this->addFormFieldsAt($formFields, $this->getIntegrationFormFields(), 'integration_title', 1);
		$formFields = $this->addFormFieldsAt($formFields, $this->getCommonShippingSettings(), 'insurance', 0);

		return $formFields;
	}

	public function getIntegrationFormFields()
	{
		$formFields = array(
			'clientId' => array(
				'title' => __('Production Client ID', $this->id),
				'type' => 'text',
				'description' => sprintf('%s %s',
					__('You can find it on the Settings > Account page at', $this->id), 
					'<a href="https://chitchats.com/clients/" target="_blank">https://chitchats.com/clients/</a>'
				)
			),
			'accessToken' => array(
				'title' => __('Production Access Token', $this->id),
				'type' => 'text',
				'description' => sprintf('%s %s',
					__('You can find it on the Settings > Account page at', $this->id), 
					'<a href="https://chitchats.com/clients/" target="_blank">https://chitchats.com/clients/</a>'
				)
			),
			'testClientId' => array(
				'title' => __('Sandbox Client ID', $this->id),
				'type' => 'text',
				'description' => sprintf('%s %s',
					__('You can find it on the Settings > Account page at', $this->id), 
					'<a href="https://staging.chitchats.com/clients/" target="_blank">https://staging.chitchats.com/clients/</a>'
				)
			),
			'testAccessToken' => array(
				'title' => __('Sandbox Access Token', $this->id),
				'type' => 'text',
				'description' => sprintf('%s %s',
					__('You can find it on the Settings > Account page at', $this->id), 
					'<a href="https://staging.chitchats.com/clients/" target="_blank">https://staging.chitchats.com/clients/</a>'
				)
			),
			'vatReference' => array(
				'title' => __('VAT Reference', $this->id),
				'type' => 'text',
				'description' =>__('Your VAT tax indentification number', $this->id),
			),
			'dutiesPaidRequested' => array(
				'title' => __('Duties Paid', $this->id),
				'type' => 'checkbox',
				'label' => __('Enable if your store collects duties', $this->id),
			),
			//'cheapestPostageTypeRequested' => array(
			//	'title' => __('Cheapest Available Rate', $this->id),
			//	'type' => 'checkbox',
			//	'label' => __('Display the cheapest shipping rate only', $this->id),
			//),
		);

		return $formFields;
	}

	protected function getCommonShippingSettings()
	{
		$formFields = array(
			'includeDeliveryFee' => array(
				'title' => __('Include Cross Boarder Delivery Fee', $this->id),
				'label' => __('Do you want cross boarder delivery Fee to be added to the shipping rate?', $this->id),
				'type' => 'checkbox',
				'default' => 'no'
			),
		);

		return $formFields;
	}

	protected function getRequestBody(&$headers, &$params)
	{
		$body = json_encode($params);

		$headers['Content-Type'] = 'application/json';
		$headers['Content-Length'] = strlen($body);

		return $body;
	}

	protected function getRatesParams(array $inParams)
	{
		$this->logger->debug(__FILE__, __LINE__, 'getRatesParams');

		if (empty($inParams)) {
			return array();
		}

		$params = array();
		$params['vat_reference'] = $this->vatReference;
		$params['duties_paid_requested'] = $this->dutiesPaidRequested;
		//$params['cheapest_postage_type_requested'] = $this->cheapestPostageTypeRequested;
		$params['ship_date'] = 'today';
		$params['order_id'] = '';
		$params['name'] = 'N/A';
		$params['description'] = 'Merchandise';
		$params['value'] = 1;
		$params['package_contents'] = 'merchandise';
		$params['package_type'] = 'parcel';

		$mediaMail = $this->mediaMail;
		if (isset($inParams['mediaMail'])) {
			$mediaMail = $inParams['mediaMail'];
		}

		if (!empty($mediaMail) && $mediaMail != 'exclude') {
			$params['postage_type'] = 'usps_media_mail';
		} else {
			$params['postage_type'] = 'unknown';
		}

		$params['weight'] = 0;
		$params['weight_unit'] = $this->weightUnit;
		$params['size_x'] = 0;
		$params['size_y'] = 0;
		$params['size_z'] = 0;
		$params['size_unit'] = $this->dimensionUnit;
		$params['country_code'] = '';
		$params['province_code'] = '';
		$params['city'] = 'n/a';
		$params['postal_code'] = '';
		$params['address_1'] = 'n/a';
		//$params['package_supplier'] = 'n/a';

		// set parameters that have the same name
		foreach ($params as $key => $val) {
			if (isset($inParams[$key])) {
				$params[$key] = $inParams[$key];
			}
		}

		if (!empty($inParams['order_number'])) {
			$params['order_id'] = $inParams['order_number'];
		}

		if (empty($params['value']) || $params['value'] <= 0) {
			$params['value'] = 0.01;
		}

		$params['value_currency'] = strtolower($this->getRequestedCurrency($inParams));
		$params['insurance_requested'] = $this->isInsuranceRequested($inParams);
		$params['signature_requested'] = $this->isSignatureRequested($inParams);

		if (isset($inParams['contents'])) {
			$params['package_contents'] = $inParams['contents'];
		}
		if (isset($inParams['type'])) {
			if ($inParams['type'] == 'envelope' && !empty($inParams['destination']['country']) && $inParams['destination']['country'] == 'CA') {
				$inParams['type'] = 'thick_envelope';
			}

			$params['package_type'] = $inParams['type'];
		}

		if (isset($inParams['destination'])) {
			if (!empty($inParams['destination']['name'])) {
				$params['name'] = $inParams['destination']['name'];
			} else {
				$params['name'] = 'Resident';
			}

			if (!empty($inParams['destination']['country'])) {
				$params['country_code'] = strtoupper($inParams['destination']['country']);

				if (in_array($params['country_code'], array('CA', 'US'))) {
					$params['duties_paid_requested'] = false;
				}
			}

			if (!empty($inParams['destination']['state'])) {
				$params['province_code'] = $inParams['destination']['state'];
			}

			if (!empty($inParams['destination']['postcode'])) {
				$params['postal_code'] = $inParams['destination']['postcode'];
			}

			if (!empty($inParams['destination']['city'])) {
				$params['city'] = $inParams['destination']['city'];
			}

			if (!empty($inParams['destination']['address'])) {
				$params['address_1'] = $inParams['destination']['address']; 
			}

			if (!empty($inParams['destination']['address_2'])) {
				$params['address_2'] = $inParams['destination']['address_2']; 
			}

			if (!empty($inParams['destination']['phone'])) {
				$params['phone'] = $inParams['destination']['phone']; 
			}
		}

		if (isset($inParams['length'])) {
			$params['size_x'] = $inParams['length'];
		}

		if (isset($inParams['width'])) {
			$params['size_y'] = $inParams['width'];
		}

		if (isset($inParams['height'])) {
			$params['size_z'] = $inParams['height'];
		}

		$dimensionUnit = $this->dimensionUnit;
		if (isset($inParams['dimension_unit']) && in_array($inParams['dimension_unit'], array('m', 'cm', 'in'))) {
			$dimensionUnit = $inParams['dimension_unit'];
		}

		// convert dimension, if required
		if (!in_array($dimensionUnit, array('m', 'cm', 'in'))) {
			$this->logger->debug(__FILE__, __LINE__, 'Our dimension unit is ' . $dimensionUnit . ', so convert it to cm');

			$dimensionUnit = 'cm';
			$params['size_x'] = wc_get_dimension($params['size_x'], $dimensionUnit);
			$params['size_y'] = wc_get_dimension($params['size_y'], $dimensionUnit);
			$params['size_z'] = wc_get_dimension($params['size_z'], $dimensionUnit);
		}

		$params['size_unit'] = $dimensionUnit;

		$weightUnit = $this->weightUnit;
		if (isset($inParams['weight_unit']) && in_array($inParams['weight_unit'], array('g', 'kg', 'lbs', 'oz'))) {
			$weightUnit = $inParams['weight_unit'];
		}

		// convert weight, if required
		if (!in_array($weightUnit, array('g', 'kg', 'lbs', 'oz'))) {
			$this->logger->debug(__FILE__, __LINE__, 'Our weight unit is ' . $weightUnit . ', so convert it to g');
			
			$weightUnit = 'g';
			$params['weight'] = wc_get_weight($params['weight'], $weightUnit);
		}

		if ($weightUnit == 'lbs') {
			$weightUnit = 'lb';
		}

		$params['weight_unit'] = $weightUnit;
		
		return $params;
	}

	protected function getRequestParams(array $inParams)
	{
		$this->logger->debug(__FILE__, __LINE__, 'getRequestParams: ' . print_r($inParams, true));

		$params = array();

		if (!empty($inParams['function']) && $inParams['function'] == 'getRates') {
			$params = $this->getRatesParams($inParams);
		}

		return $params;
	}

	protected function getRatesResponse($response, array $params)
	{
		$this->logger->debug(__FILE__, __LINE__, 'getRatesResponse');

		if (empty($response['shipment'])) {
			return array();
		}

		$newResponse = array();
		$shipment = &$response['shipment'];

		if (!empty($shipment['rates'])) {
			$rates = $shipment['rates'];
			$newRates = array();

			foreach ($rates as $rate) {
				$serviceId = $rate['postage_type'];
				$serviceName = $this->getServiceName($serviceId);

				$rate['service'] = $serviceId;
				$rate['postage_description'] = apply_filters($this->id . '_service_name', $serviceName, $serviceId);
				$rate['cost'] = $rate['postage_fee'];

				if (!empty($rate['insurance_fee'])) {
					$rate['cost'] += $rate['insurance_fee'];
				}

				if ($this->includeDeliveryFee && !empty($rate['delivery_fee'])) {
					$rate['cost'] += $rate['delivery_fee'];
				}

				$newRates[$serviceId] = $rate;
			}
			
			$newResponse['shipment']['rates'] = $this->sortRates($newRates);
		}

		if (!empty($shipment['id'])) {
			$newResponse['shipment']['id'] = $shipment['id'];
		}

		return $newResponse;
	}

	protected function getResponse($response, array $params)
	{
		$this->logger->debug(__FILE__, __LINE__, 'getResponse');

		$newResponse = array('response' => $response, 'params' => $params);

		if (!empty($response['error'])) {
			$newResponse['error'] = $response['error'];
		}

		if (!empty($params['function']) && $params['function'] == 'getRates') {
			$newResponse = array_replace_recursive($newResponse, $this->getRatesResponse($response, $params));
		}

		return $newResponse;
	}

	protected function getRouteUrl($route)
	{
		if ($this->sandbox) {
			if (empty($this->testClientId) || empty($this->testAccessToken)) {
				$this->logger->debug(__FILE__, __LINE__, 'testClientId and testAccessToken are required');
	
				return false;
			}
		} else {
			if (empty($this->clientId) || empty($this->accessToken)) {
				$this->logger->debug(__FILE__, __LINE__, 'clientId and accessToken are required');
	
				return false;
			}
		}

		$routeUrl = sprintf('https://%s/api/v1/clients/%s/%s',
							$this->sandbox ? 'staging.chitchats.com' : 'chitchats.com',
							$this->sandbox ? $this->testClientId : $this->clientId,
							$route);
		
		return $routeUrl;
	}

	protected function addHeadersAndParams(&$headers, &$params)
	{
		$headers['Authorization'] = ($this->sandbox ? $this->testAccessToken : $this->accessToken);
	}

	public function getServices()
	{
		return $this->_services;
	}

	protected function getServiceName($serviceId)
	{
		if (!empty($this->_services[$serviceId])) {
			return $this->_services[$serviceId];
		}

		return ucwords(str_replace("_", " ", $serviceId));
	}

	protected function initPackageTypes()
	{
		$this->packageTypes = array(
			'parcel' => __('Parcel', $this->id),
			'envelope' => __('Flat Envelope', $this->id),
			'thick_envelope' => __('Thick Envelope', $this->id),
			'letter' => __('Letter', $this->id),
			'flat_rate_envelope' => __('USPS Letter Flat Rate Envelope', $this->id),
			'flat_rate_legal_envelope' => __('USPS Legal Flat Rate Envelope', $this->id),
			'flat_rate_padded_envelope' => __('USPS Padded Flat Rate Envelope', $this->id),
			'flat_rate_gift_card_envelope' => __('USPS Gift Card Flat Rate Envelope', $this->id),
			'flat_rate_window_envelope' => __('USPS Window Flat Rate Envelope', $this->id),
			'flat_rate_cardboard_envelope' => __('USPS Cardboard Flat Rate Envelope', $this->id),
			'small_flat_rate_envelope' => __('USPS Small Flat Rate Envelope', $this->id),
			'small_flat_rate_box' => __('USPS Small Flat Rate Box', $this->id),
			'medium_flat_rate_box_1' => __('USPS Medium Flat Rate Box - 1', $this->id),
			'medium_flat_rate_box_2' => __('USPS Medium Flat Rate Box - 2', $this->id),
			'large_flat_rate_box' => __('USPS Large Flat Rate Box', $this->id),
			'large_flat_rate_board_game_box' => __('USPS Large Flat Rate Board Game Box', $this->id),
			'regional_rate_box_a_1' => __('USPS Priority Mail Regional Rate Box - A1', $this->id),
			'regional_rate_box_a_2' => __('USPS Priority Mail Regional Rate Box - A2', $this->id),
			'regional_rate_box_b_1' => __('USPS Priority Mail Regional Rate Box - B1', $this->id),
			'regional_rate_box_b_2' => __('USPS Priority Mail Regional Rate Box - B2', $this->id),
		);
	}

	protected function initServices()
	{
		$this->_services = array(
			'unknown' => __('Unknown', $this->id), 
			'usps_express' => __('USPS Priority Mail Express', $this->id), 
			'usps_express_mail_international' => __('USPS Priority Mail International', $this->id),
			'usps_first' => __('USPS First-Class Mail', $this->id),
			'usps_first_class_mail_international' => __('USPS First-Class Mail International', $this->id),
			'usps_first_class_package_international_service' => __('USPS First-Class Package International Service', $this->id),
			'usps_library_mail' => __('USPS Library Mail', $this->id),
			'usps_media_mail' => __('USPS Media Mail', $this->id),
			'usps_parcel_select' => __('USPS Parcel Select', $this->id),
			'usps_priority' => __('USPS Priority Mail', $this->id),
			'usps_priority_mail_international' => __('USPS Priority Mail International', $this->id),
			'usps_other' => __('USPS Other Mail Class', $this->id),
			'ups_other' => __('UPS Other Mail Class', $this->id),
			'fedex_other' => __('FedEx Other Mail Class', $this->id),
			'chit_chats_select' => __('Chit Chats Select', $this->id),
			'chit_chats_collect' => __('Chit Chats Collect', $this->id),
			'chit_chats_slim' => __('Chit Chats Slim', $this->id),
			'chit_chats_canada_tracked' => __('Chit Chats Canada Tracked', $this->id),
			'chit_chats_domestic_tracked' => __('Chit Chats Domestic Tracked', $this->id),
			'chit_chats_international_not_tracked' => __('Chit Chats International Standard', $this->id),
			'chit_chats_international_tracked' => __('Chit Chats International Tracked', $this->id),
			'chit_chats_us_edge' => __('Chit Chats U.S. Edge', $this->id),
			'chit_chats_us_select' => __('Chit Chats U.S. Select', $this->id),
			'chit_chats_us_tracked' => __('Chit Chats U.S. Tracked', $this->id),
			'chit_chats_us_economy_tracked' => __('Chit Chats U.S. Economy Tracked', $this->id),
			'dhl_other' => __('DHL Other Mail Class', $this->id),
			'asendia_priority_tracked' => __('Asendia International Priority Tracked', $this->id),
			'ups_mi_expedited' => __('UPS Mail Innovations Parcel Select', $this->id)
		);
	}

	public function getCacheKey(array $params)
	{
		$cacheValue = $this->id . json_encode($params);
		if ($this->sandbox) {
			$cacheValue .= $this->testClientId;
			$cacheValue .= $this->testAccessToken;
			$cacheValue .= '_test';
		} else {
			$cacheValue .= $this->clientId;
			$cacheValue .= $this->accessToken;
			$cacheValue .= '_production';
		}
		$cacheValue .= $this->includeDeliveryFee;

		return md5($cacheValue);
	}
}

endif;
