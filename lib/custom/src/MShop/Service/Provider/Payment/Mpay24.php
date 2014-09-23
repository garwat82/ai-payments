<?php

/**
 * @copyright Copyright (c) Metaways Infosystems GmbH, 2012
 * @license LGPLv3, http://www.arcavias.com/en/license
 * @package MShop
 * @subpackage Service
 */

/**
 * Payment provider for mpay24
 *
 * @package MShop
 * @subpackage Service
 */

class MShop_Service_Provider_Payment_Mpay24
	extends MShop_Service_Provider_Payment_Abstract
	implements MShop_Service_Provider_Payment_Interface
{
	private $_apiEndpoint;
	private $_apiWsdl;
	private $_paymentTypes;
	private $_soap;

	private $_beConfig = array(
		'mpay24.HttpAuthUsername' => array(
			'code' => 'mpay24.HttpAuthUsername',
			'internalcode'=> 'mpay24.HttpAuthUsername',
			'label'=> 'HTTP Auth Username',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> true,
		),
		'mpay24.HttpAuthPassword' => array(
			'code' => 'mpay24.HttpAuthPassword',
			'internalcode'=> 'mpay24.HttpAuthPassword',
			'label'=> 'HTTP Auth Password',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> true,
		),
		'mpay24.MerchantId' => array(
			'code' => 'mpay24.MerchantId',
			'internalcode'=> 'mpay24.MerchantId',
			'label'=> 'Merchant ID',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> '',
			'required'=> true,
		),
		'mpay24.ApiEndpoint' => array(
			'code' => 'mpay24.ApiEndpoint',
			'internalcode'=> 'mpay24.ApiEndpoint',
			'label'=> 'API Endpoint',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> 'https://www.mpay24.com/app/bin/etpproxy_v15',
			'required'=> false,
		),
		'mpay24.ApiWsdl' => array(
			'code' => 'mpay24.ApiWsdl',
			'internalcode'=> 'mpay24.ApiWsdl',
			'label'=> 'API WSDL',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> 'https://www.mpay24.com/soap/etp/1.5/ETP.wsdl',
			'required'=> false,
		),
		'mpay24.PaymentTypes' => array(
			'code' => 'mpay24.PaymentTypes',
			'internalcode'=> 'mpay24.PaymentTypes',
			'label'=> 'Payment Types',
			'type'=> 'string',
			'internaltype'=> 'string',
			'default'=> 'CC',
			'required'=> false,
		)
	);

	public function __construct( MShop_Context_Item_Interface $context, MShop_Service_Item_Interface $serviceItem )
	{
		parent::__construct( $context, $serviceItem );

		$configParameters = array();
		foreach ($this->_beConfig as $k => $v) {
			if ($v['required']) {
				$configParameters[] = $k;
			}
		}

		$config = $serviceItem->getConfig();

		foreach( $configParameters as $param )
		{
			if( !isset( $config[ $param ] ) ) {
				throw new MShop_Service_Exception( sprintf( 'Parameter "%1$s" for configuration not available', $param ) );
			}
		}

		$this->_apiEndpoint = $this->_getConfigValue( array( 'mpay24.ApiEndpoint' ), $this->_beConfig['mpay24.ApiEndpoint']['default'] );
		$this->_apiWsdl = $this->_getConfigValue( array( 'mpay24.ApiWsdl' ), $this->_beConfig['mpay24.ApiWsdl']['default'] );
		$this->_paymentTypes = explode(',', $this->_getConfigValue( array( 'mpay24.PaymentTypes' ), $this->_beConfig['mpay24.PaymentTypes']['default'] ));
	}

	/**
	 * Returns the configuration attribute definitions of the provider to generate a list of available fields and
	 * rules for the value of each field in the administration interface.
	 *
	 * @return array List of attribute definitions implementing MW_Common_Critera_Attribute_Interface
	 */
	public function getConfigBE()
	{
		$list = parent::getConfigBE();

		foreach( $this->_beConfig as $key => $config ) {
			$list[$key] = new MW_Common_Criteria_Attribute_Default( $config );
		}

		return $list;
	}

	/**
	 * Checks the backend configuration attributes for validity.
	 *
	 * @param array $attributes Attributes added by the shop owner in the administraton interface
	 * @return array An array with the attribute keys as key and an error message as values for all attributes that are
	 * 	known by the provider but aren't valid
	 */
	public function checkConfigBE( array $attributes )
	{
		$errors = parent::checkConfigBE( $attributes );

		return array_merge( $errors, $this->_checkConfig( $this->_beConfig, $attributes ) );
	}

	/**
	 * Tries to get an authorization or captures the money immediately for the given order if capturing the money
	 * separately isn't supported or not configured by the shop owner.
	 *
	 * @param MShop_Order_Item_Interface $order Order invoice object
	 * @return MW_Common_Form_Interface Form object with URL, action and parameters to redirect to
	 * 	(e.g. to an external server of the payment provider or to a local success page)
	 */
	public function process( MShop_Order_Item_Interface $order )
	{
		$orderBaseManager = MShop_Factory::createManager( $this->_getContext(), 'order/base' );
		$orderBaseItem = $orderBaseManager->load($order->getBaseId());
		$countryId = $orderBaseItem->getAddress()->getCountryId();
		$now = date('YmdHis');
		$orderNo = $order->getId();
		$hash = sha1($orderNo . $this->_getConfigValue(array('mpay24.HttpAuthPassword')) . $now . $countryId);
		$tid = implode('-', array($countryId, $orderNo, $now));
		$userField = $hash;

		$result = $this->_apiSelectPayment($orderBaseItem, $tid, $userField, $order);

		$this->_saveAttributes(array('tid' => $tid), $orderBaseItem->getService('payment'));
		$order->setPaymentStatus(MShop_Order_Item_Abstract::PAY_PENDING);

		return new MShop_Common_Item_Helper_Form_Default($result->location, 'POST', array());
	}

	public function updateSync($additional) {
		if (empty($additional['TID']) || empty($additional['USER_FIELD'])) {
			return null;
		}

		$tid = $additional['TID'];
		list($countryId, $id, $date) = explode('-', $tid);
		$hash = sha1($id . $this->_getConfigValue(array('mpay24.HttpAuthPassword')) . $date . $countryId);
		if ($additional['USER_FIELD'] != $hash) {
			return null;
		}

		$orderManager = MShop_Factory::createManager($this->_getContext(), 'order');
		$orderBaseManager = MShop_Factory::createManager($this->_getContext(), 'order/base');
		$order = $orderManager->getItem($id);

		$this->_apiTransactionConfirmation($this->_fetchMpayTid($tid));
		$this->_setPaymentStatus($order, $this->_apiTransactionConfirmation($this->_fetchMpayTid($tid)));
		$orderManager->saveItem( $order );

		return $order;
	}

	protected function _soapCall($method, array $params = array()) {
		if (!$this->_soap) {
			$this->_soap = new SoapClient($this->_apiWsdl, array(
				'login' => $this->_getConfigValue(array('mpay24.HttpAuthUsername')),
				'password' => $this->_getConfigValue(array('mpay24.HttpAuthPassword'))
			));
			$this->_soap->__setLocation($this->_apiEndpoint);
		}

		$params = array(
			'merchantID' => $this->_getConfigValue(array('mpay24.MerchantId'))
		) + $params;
		return $this->_soap->$method($params);
	}

	protected function _apiSelectPayment(MShop_Order_Item_Base_Interface $orderBase, $tid, $userField, MShop_Order_Item_Interface $order) {
		$result = $this->_soapCall('SelectPayment', array('mdxi' => $this->_getMdxi($orderBase, $tid, $userField, $order)));
		return $result;
	}

	protected function _apiTransactionConfirmation($mpayTID) {
		$result = $this->_soapCall('TransactionConfirmation', array('mpayTID' => $mpayTID));
		return $result;
	}

	protected function _apiTransactionStatus($tid) {
		$result = $this->_soapCall('TransactionStatus', array('tid' => $tid));
		return $result;
	}

	protected function _fetchMpayTid($tid) {
		foreach ($this->_apiTransactionStatus($tid)->parameter as $k => $v) {
			if ($v->name == 'MPAYTID') {
				return $v->value;
			}
		}
		return false;
	}

	protected function xmlStartElement($xml, $name, array $attributes = array(), $text = null) {
		$xml->startElement($name);
		foreach ($attributes as $k => $v) {
			$xml->writeAttribute($k, $v);
		}
		if ($text !== null) {
			$xml->text($text);
		}
	}

	protected function xmlWriteElement($xml, $name, $text = null, array $attributes = array()) {
		$this->xmlStartElement($xml, $name, $attributes, $text);
		$xml->endElement();
	}

	protected function _getMdxi(MShop_Order_Item_Base_Interface $orderBase, $tid, $userField, MShop_Order_Item_Interface $order) {
		$styles = array();
		$styles['background'] = 'background-color:#FFF';
		$styles['color'] = 'color: #000';
		$styles['highlightColor'] = 'color: #0095e0';
		$styles['border'] = 'border:none;border-top: 1px solid #000;border-bottom: 1px solid #000';
		$styles['tableCell'] = "{$styles['background']};{$styles['color']};{$styles['border']};text-transform:uppercase;padding:5px;text-align:center";

		$orderAddressPayment = $orderBase->getAddress( MShop_Order_Item_Base_Address_Abstract::TYPE_PAYMENT );

		$xml = new XmlWriter();
		$xml->openMemory();
		if ($this->verbose) {
			$xml->setIndent(true);
		}
		$xml->startDocument('1.0', 'UTF-8');
		$this->xmlStartElement($xml, 'Order', array(
			'Style'              => "{$styles['color']};font-family:Helvetica,sans-serif;font-size:12px",
			'PageHeaderStyle'    => "{$styles['background']};margin-bottom:14px;margin-top:28px",
			'PageStyle'          => "{$styles['background']};border: 1px solid #000",
			'PageCaptionStyle'   => "{$styles['background']};{$styles['color']};background:transparent;padding-left:0px",
			'InputFieldsStyle'   => "{$styles['background']};border:1px solid #000;padding:2px 0px;margin-bottom:5px;width:100%;max-width:200px;",
			'DropDownListsStyle' => 'padding:2px 0px;margin-bottom:5px;',
			'ButtonsStyle'       => 'background-color: #0095e0;border: none;color: #FFFFFF;cursor: pointer;font-size:10px;font-weight:bold;padding:5px 10px;text-transform:uppercase;',
			'ErrorsStyle'        => "{$styles['background']};padding: 10px 0px;",
			'SuccessTitleStyle'  => $styles['background'],
			'ErrorTitleStyle'    => $styles['background']
		));

		$xml->writeElement('UserField', $userField);
		$xml->writeElement('Tid', $tid);
		$this->xmlWriteElement($xml, 'TemplateSet', 'WEB', array(
			'Language' => 'DE'
		));

		if ($this->_paymentTypes) {
			$xml->startElement('PaymentTypes');
			foreach ($this->_paymentTypes as $paymentType) {
				$xml->startElement('Payment');
				$xml->writeAttribute('Type', $paymentType);
				$xml->endElement();
			}
			$xml->endElement();
		}

		$this->xmlStartElement($xml, 'ShoppingCart', array(
			'Header'           => 'Bestellung',
			'HeaderStyle'      => "{$styles['background']};{$styles['color']};margin-bottom:14px;",
			'CaptionStyle'     => "{$styles['background']};{$styles['color']};background:transparent;padding-left:0px;font-size:14px;",
			'DescriptionStyle' => "width:280px;{$styles['background']};{$styles['color']};{$styles['border']};text-transform:uppercase;padding:5px;padding-right:0px;text-align:left;",
			'QuantityStyle'    => "width:80px;{$styles['tableCell']};",
			'ItemPriceStyle'   => "width:80px;{$styles['tableCell']};",
			'PriceStyle'       => "width:80px;{$styles['tableCell']};"
		));


		$xml->writeElement('Description', 'Bestellnummer ' . ($order->getId()));
		foreach ($orderBase->getProducts() as $product) {
			$xml->startElement('Item');
			$this->xmlWriteElement($xml, 'Description', $product->getName(), array(
				'Style' => "{$styles['background']};{$styles['color']};{$styles['border']};padding:5px 10px;"
			));
			$this->xmlWriteElement($xml, 'Quantity', $product->getQuantity(), array(
				'Style' => $styles['tableCell']
			));
			$this->xmlWriteElement($xml, 'ItemPrice', $product->getPrice()->getValue(), array(
				'Style' => $styles['tableCell']
			));
			$this->xmlWriteElement($xml, 'Price', $product->getSumPrice()->getValue(), array(
				'Style' => $styles['tableCell']
			));
			$xml->endElement();
		}
		$xml->endElement();


		$price = $orderBase->getPrice();
		$this->xmlWriteElement($xml, 'Price', $price->getValue(), array(
			'Style'       => "{$styles['background']};{$styles['highlightColor']};border:none;padding:4px;font-weight:bold;padding:3px 10px;font-size:14px;border-top: 1px solid #000;",
			'HeaderStyle' => "{$styles['background']};{$styles['color']};padding:3px;font-weight:normal;border-top: 1px solid #00;"
		));
		$xml->writeElement('Currency', $price->getCurrencyId());

		$xml->startElement('BillingAddr');
		$xml->writeAttribute('Mode', 'ReadWrite');
		$xml->writeElement('Name', $orderAddressPayment->getFirstName() . ' ' . $orderAddressPayment->getLastName());
		/*
		$xml->writeElement('Street', $orderAddressPayment->getAddress1() . ' ' . $orderAddressPayment->getAddress2() . ' ' . $orderAddressPayment->getAddress3());
		$xml->writeElement('Zip', $orderAddressPayment->getPostal());
		$xml->writeElement('City', $orderAddressPayment->getCity());
		$xml->startElement('Country');
		$xml->writeAttribute('Code', $orderAddressPayment->getCountryId());
		$xml->endElement();
		*/
		$xml->writeElement('Email', $orderAddressPayment->getEmail());
		$xml->endElement();

		$returnUrl = $this->_getConfigValue( array( 'payment.url-success' ) );
		$returnUrl .= ( strpos( $returnUrl, '?' ) !== false ? '&' : '?' ) . 'orderid=' . $orderBase->getId();


		$xml->startElement('URL');
		$xml->writeElement('Success', $returnUrl);
		$xml->writeElement('Error', $this->_getConfigValue(array('payment.url-failure', 'payment.url-success')));
		$xml->writeElement('Confirmation', $this->_getConfigValue(array('payment.url-update', 'payment.url-success')));
		$xml->endElement();

		$xml->endElement();

		return $xml->outputMemory();
	}

	protected function _setPaymentStatus(MShop_Order_Item_Interface $invoice, $response) {
		switch ($response->confirmation->confirmed) {
			case 'SUSPENDED':
				$invoice->setPaymentStatus( MShop_Order_Item_Abstract::PAY_PENDING);
				break;
			case 'RESERVED':
			case 'BILLED':
				$invoice->setPaymentStatus( MShop_Order_Item_Abstract::PAY_RECEIVED );
				break;
			case 'REVERSED':
			case 'CREDITED':
			case 'ERROR':
			default:
				$invoice->setPaymentStatus( MShop_Order_Item_Abstract::PAY_CANCELED );
				break;
		}
	}

	/**
	 * Saves a list of attributes for the order service.
	 *
	 * @param array $attributes Attributes which have to be saved
	 * @param MShop_Order_Item_Base_Serive_Interface $serviceItem Service Item which saves the attributes
	 */
	protected function _saveAttributes( array $attributes, MShop_Order_Item_Base_Service_Interface $serviceItem, $type = 'payment/mpay24' )
	{
		$attributeManager = MShop_Factory::createManager( $this->_getContext(), 'order/base/service/attribute' );

		$map = array();
		foreach( $serviceItem->getAttributes() as $attributeItem ) {
			$map[ $attributeItem->getCode() ] = $attributeItem;
		}

		foreach( $attributes as $code => $value )
		{
			if( array_key_exists( $code, $map ) !== true )
			{
				$attributeItem = $attributeManager->createItem();
				$attributeItem->setServiceId( $serviceItem->getId() );
				$attributeItem->setCode( $code );
				$attributeItem->setType( $type );
			}
			else
			{
				$attributeItem = $map[$code];
			}

			$attributeItem->setValue( $value );
			$attributeManager->saveItem( $attributeItem );
		}
	}
}