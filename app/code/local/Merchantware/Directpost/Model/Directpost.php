<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
class Merchantware_Directpost_Model_Directpost extends Mage_Payment_Model_Method_Cc
{
    protected $_code  = 'merchantware_directpost';
    protected $_formBlockType = 'merchantware_directpost/form';
    protected $_infoBlockType = 'merchantware_directpost/info';

    const WSDL_URL_TEST = 'https://staging.merchantware.net/Merchantware/ws/RetailTransaction/v4/Credit.asmx?WSDL';
    //const WSDL_URL_TEST = 'https://beta.merchantware.net/Merchantware/ws/RetailTransaction/v4/Credit.asmx?WSDL';
    //const WSDL_URL_TEST = 'https://transport.merchantware.net/v4/transportService.asmx';
    const WSDL_URL_LIVE = 'https://ps1.merchantware.net/Merchantware/ws/RetailTransaction/v4/Credit.asmx?WSDL';

    const APPROVAL_STATUS_APPROVED = 'APPROVED';
    const APPROVAL_STATUS_DECLINED = 'DECLINED';
    const APPROVAL_STATUS_DECLINED_DUPLICATE = 'DECLINED,DUPLICATE';
    const APPROVAL_STATUS_FAILED = 'FAILED';
    const APPROVAL_STATUS_REFERRAL = 'REFERRAL';

    const REQUEST_TYPE_AUTH_CAPTURE = 'AUTH_CAPTURE';
    const REQUEST_TYPE_AUTH_ONLY    = 'AUTH_ONLY';
    const REQUEST_TYPE_CAPTURE_ONLY = 'CAPTURE_ONLY';
    const REQUEST_TYPE_CREDIT       = 'CREDIT';
    const REQUEST_TYPE_VOID         = 'VOID';
    const REQUEST_TYPE_PRIOR_AUTH_CAPTURE = 'PRIOR_AUTH_CAPTURE';

    /**
     * Availability options
     */
    protected $_isGateway               = true;
    protected $_canAuthorize            = true;
    protected $_canCapture              = true;
    protected $_canCapturePartial       = false;
    protected $_canRefund               = true;
    protected $_canRefundInvoicePartial = true;
    protected $_canVoid                 = true;
    protected $_canUseInternal          = true;
    protected $_canUseCheckout          = true;
    protected $_canUseForMultishipping  = true;
    protected $_canSaveCc = false;
    //protected $_isInitializeNeeded      = true;

    protected $_request;

    /**
     * Validate payment method information object
     *
     * @param   Mage_Payment_Model_Info $info
     * @return  Mage_Payment_Model_Abstract
     */
    public function validate()
    {
        if (!extension_loaded('soap')) {
            Mage::throwException(Mage::helper('merchantware_directpost')->__('SOAP extension is not enabled. Please contact us.'));
        }

        /**
         * to validate payment method is allowed for billing country or not
         */
        $paymentInfo = $this->getInfoInstance();
        if ($paymentInfo instanceof Mage_Sales_Model_Order_Payment) {
            $billingCountry = $paymentInfo->getOrder()->getBillingAddress()->getCountryId();
        } else {
            $billingCountry = $paymentInfo->getQuote()->getBillingAddress()->getCountryId();
        }
        if (!$this->canUseForCountry($billingCountry)) {
            Mage::throwException($this->_getHelper()->__('Selected payment type is not allowed for billing country.'));
        }

        $info = $this->getInfoInstance();
        $errorMsg = false;
        $availableTypes = explode(',',$this->getConfigData('cctypes'));

        $ccNumber = $info->getCcNumber();

        // remove credit card number delimiters such as "-" and space
        $ccNumber = preg_replace('/[\-\s]+/', '', $ccNumber);
        $info->setCcNumber($ccNumber);

        $ccType = '';

        if (!$this->_validateExpDate($info->getCcExpYear(), $info->getCcExpMonth())) {
            $errorCode = 'ccsave_expiration,ccsave_expiration_yr';
            $errorMsg = $this->_getHelper()->__('Incorrect credit card expiration date.');
        }

        if (in_array($info->getCcType(), $availableTypes)){
            if ($this->validateCcNum($ccNumber)
                // Other credit card type number validation
                || ($this->OtherCcType($info->getCcType()) && $this->validateCcNumOther($ccNumber))) {

                $ccType = 'OT';
                $ccTypeRegExpList = array(
                    'SO' => '/(^(6334)[5-9](\d{11}$|\d{13,14}$))|(^(6767)(\d{12}$|\d{14,15}$))/', // Solo only
                    'SM' => '/(^(5[0678])\d{11,18}$)|(^(6[^05])\d{11,18}$)|(^(601)[^1]\d{9,16}$)|(^(6011)\d{9,11}$)|(^(6011)\d{13,16}$)|(^(65)\d{11,13}$)|(^(65)\d{15,18}$)|(^(49030)[2-9](\d{10}$|\d{12,13}$))|(^(49033)[5-9](\d{10}$|\d{12,13}$))|(^(49110)[1-2](\d{10}$|\d{12,13}$))|(^(49117)[4-9](\d{10}$|\d{12,13}$))|(^(49118)[0-2](\d{10}$|\d{12,13}$))|(^(4936)(\d{12}$|\d{14,15}$))/',//Maestro/Switch
                    'VI' => '/^4[0-9]{12}([0-9]{3})?$/', // Visa
                    'MC' => '/^5[1-5][0-9]{14}$/',       // Master Card
                    'AE' => '/^3[47][0-9]{13}$/',        // American Express
                    'DI' => '/^6011[0-9]{12}$/',          // Discovery
                    'JCB' => '/^(3[0-9]{15}|(2131|1800)[0-9]{12})$/', // JCB
                    'LASER' => '/^(6304|6706|6771|6709)[0-9]{12}([0-9]{3})?$/' // LASER
                );

                foreach ($ccTypeRegExpList as $ccTypeMatch=>$ccTypeRegExp) {
                    if (preg_match($ccTypeRegExp, $ccNumber)) {
                        $ccType = $ccTypeMatch;
                        break;
                    }
                }

                if (!$this->OtherCcType($info->getCcType()) && $ccType!=$info->getCcType()) {
                    $errorCode = 'ccsave_cc_type,ccsave_cc_number';
                    $errorMsg = $this->_getHelper()->__('Credit card number mismatch with credit card type.');
                }
            }
            else {
                $errorCode = 'ccsave_cc_number';
                $errorMsg = $this->_getHelper()->__('Invalid Credit Card Number');
            }

        }
        else {
            $errorCode = 'ccsave_cc_type';
            $errorMsg = $this->_getHelper()->__('Credit card type is not allowed for this payment method.');
        }

        //validate credit card verification number
        if ($errorMsg === false && $this->hasVerification()) {
            $verifcationRegEx = $this->getVerificationRegEx();
            $regExp = isset($verifcationRegEx[$info->getCcType()]) ? $verifcationRegEx[$info->getCcType()] : '';
            if (!$info->getCcCid() || !$regExp || !preg_match($regExp ,$info->getCcCid())){
                $errorMsg = $this->_getHelper()->__('Please enter a valid credit card verification number.');
            }
        }

        if($errorMsg){
            Mage::throwException($errorMsg);
        }
        return $this;
    }

    /**
     * Getting Soap Api object
     *
     * @param   array $options
     * @return  Merchantware_Directpost_Model_Api_ExtendedSoapClient
     */
    protected function getSoapApi($options = array())
    {
        $wsdl = $this->getConfigData('test_mode') ? self::WSDL_URL_TEST  : self::WSDL_URL_LIVE;
        $_api = new Merchantware_Directpost_Model_Api_ExtendedSoapClient($wsdl, $options);
        $_api->setStoreId($this->getStore());

        return $_api;
    }

    /**
     * Add merchant api information to request.
     */
    protected function addMerchantInfo()
    {
        $this->_request->merchantName = $this->getConfigData('name');
        $this->_request->merchantSiteId = $this->getConfigData('site_id');
        $this->_request->merchantKey = $this->getConfigData('key');
    }

    /**
     * Initializing soap header
     */
    protected function iniRequest()
    {
        $this->_request = new stdClass();

        $this->addMerchantInfo();
    }

    /**
     * Random generator for merchant referenc code
     *
     * @return random number
     */
    protected function _generateReferenceCode()
    {
        return Mage::helper('core')->uniqHash();
    }

    /**
     * Getting customer IP address
     *
     * @return IP address string
     */
    protected function getIpAddress()
    {
        return Mage::helper('core/http')->getRemoteAddr();
    }

    /**
     * Assigning the street address associated with the payment card for use in address verification system (AVS) checks.
     *
     * @param Varien_Object $address
     */
    protected function addAvsAddress($address)
    {
        $this->_request->avsStreetAddress = $address->getStreet(1);
        $this->_request->avsStreetZipCode = $address->getPostcode();
    }

    /**
     * Assigning billing address to soap
     *
     * @param Varien_Object $billing
     * @param String $email
     */
    protected function addBillingAddress($billing, $email)
    {
        if (!$email) {
            $email = Mage::getSingleton('checkout/session')->getQuote()->getBillingAddress()->getEmail();
        }
        $billTo = new stdClass();
        $billTo->firstName = $billing->getFirstname();
        $billTo->lastName = $billing->getLastname();
        $billTo->company = $billing->getCompany();
        $billTo->street1 = $billing->getStreet(1);
        $billTo->street2 = $billing->getStreet(2);
        $billTo->city = $billing->getCity();
        $billTo->state = $billing->getRegion();
        $billTo->postalCode = $billing->getPostcode();
        $billTo->country = $billing->getCountry();
        $billTo->phoneNumber = $billing->getTelephone();
        $billTo->email = ($email ? $email : Mage::getStoreConfig('trans_email/ident_general/email'));
        $billTo->ipAddress = $this->getIpAddress();
        $this->_request->billTo = $billTo;
    }

    /**
     * Assigning shipping address to soap object
     *
     * @param Varien_Object $shipping
     */
    protected function addShippingAddress($shipping)
    {
        //checking if we have shipping address, in case of virtual order we will not have it
        if ($shipping) {
            $shipTo = new stdClass();
            $shipTo->firstName = $shipping->getFirstname();
            $shipTo->lastName = $shipping->getLastname();
            $shipTo->company = $shipping->getCompany();
            $shipTo->street1 = $shipping->getStreet(1);
            $shipTo->street2 = $shipping->getStreet(2);
            $shipTo->city = $shipping->getCity();
            $shipTo->state = $shipping->getRegion();
            $shipTo->postalCode = $shipping->getPostcode();
            $shipTo->country = $shipping->getCountry();
            $shipTo->phoneNumber = $shipping->getTelephone();
            $this->_request->shipTo = $shipTo;
        }
    }

    /**
     * Assigning credit card information
     *
     * @param Mage_Sales_Model_Order_Payment $payment
     */
    protected function addCcInfo($payment)
    {
        $this->_request->cardNumber = $payment->getCcNumber();
        $this->_request->expirationDate = $this->getCcExpDate($payment);
        $this->_request->cardholder = $payment->getCcOwner();
        if ($payment->hasCcCid()) {
            $this->_request->cardSecurityCode = $payment->getCcCid();
        }
    }

    /**
     * Retrieve CC expiration date
     *
     * @return Zend_Date
     */
    public function getCcExpDate($payment)
    {
        $date = Mage::app()->getLocale()->date(0);
        $date->setYear($payment->getCcExpYear());
        $date->setMonth($payment->getCcExpMonth());
        return $date->toString('MMyy');
    }

    /**
     * Authorizing payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function authorize(Varien_Object $payment, $amount)
    {
        $this->_preAuthorizationKeyed($payment, $amount);
        return $this;
    }

    /**
     * Capturing payment
     *
     * @param Varien_Object $payment
     * @param float $amount
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function capture(Varien_Object $payment, $amount)
    {
        if($this->_isPreAuthorized($payment))
        {
            //Capture Amount (previously authorized)
            $this->_postAuthorization($payment, $amount);
        }
        else
        {
            //Direct Sale - Authorize and Capture
            $this->_saleKeyed($payment, $amount);
        }
        return $this;
    }

    /**
     * To assign transaction id and token after capturing payment
     *
     * @param Mage_Sale_Model_Order_Invoice $invoice
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function processInvoice($invoice, $payment)
    {
        parent::processInvoice($invoice, $payment);
        $invoice->setTransactionId($payment->getLastTransId());

        return $this;
    }


    /**
     * Void the payment transaction
     *
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function void(Varien_Object $payment)
    {
        $this->_void($payment);
        return $this;
    }

    /**
     * To assign correct transaction id and token before refund
     *
     * @param Mage_Sale_Model_Order_Invoice $invoice
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function processBeforeRefund($invoice, $payment)
    {
        parent::processBeforeRefund($invoice, $payment);
        $payment->setRefundTransactionId($invoice->getTransactionId());

        return $this;
    }

    /**
     * Refund the payment transaction
     *
     * @param Mage_Sale_Model_Order_Payment $payment
     * @param float $amount
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function refund(Varien_Object $payment, $amount)
    {
        $this->_refund($payment, $amount);

        return $this;
    }

    /**
     * To assign correct transaction id and token after refund
     *
     * @param Mage_Sale_Model_Order_Creditmemo $creditmemo
     * @param Mage_Sale_Model_Order_Payment $payment
     * @return Merchantware_Directpost_Model_Directpost
     */
    public function processCreditmemo($creditmemo, $payment)
    {
        parent::processCreditmemo($creditmemo, $payment);
        $creditmemo->setTransactionId($payment->getLastTransId());
        return $this;
    }

    /**
     * Send preAuthorizationKeyed request to MerchantWare gateway
     *
     * @param Mage_Payment_Model_Info $payment
     * @param decimal $amount
     * @return $result
     * @throws Mage_Core_Exception
     */
    private function _preAuthorizationKeyed($payment, $amount)
    {
        $error = false;
        $soapClient = $this->getSoapApi();
        $this->iniRequest();

        //Add CC info
        $this->addCcInfo($payment);

        //Add order info
        $order = $payment->getOrder();
        $grandTotal = $order->getBaseGrandTotal();
        $this->_request->merchantTransactionId = $order->getIncrementId();
        $this->_request->amount = $grandTotal;

        //Add AVS address
        $this->addAvsAddress($payment->getOrder()->getBillingAddress());

        $debugData['request'] = $this->_request;

        $additionalInfo = array();
        try {
            $result = $soapClient->PreAuthorizationKeyed($this->_request);
            $result = $result->PreAuthorizationKeyedResult;
            $debugData['result'] = $result;

            if(!empty($result->ErrorMessage))
            {
                $additionalInfo['ErrorMessage'] = $result->ErrorMessage;
            }

            //Authorization is approved.
            $additionalInfo['ApprovalStatus'] = $result->ApprovalStatus;
            //$additionalInfo['Amount'] = $result->Amount;
            $additionalInfo['Amount'] = $grandTotal; //added by Jairo
            $additionalInfo['AuthorizationCode'] = $result->AuthorizationCode;
            if(!empty($result->Cardholder))
            {
                $additionalInfo['Cardholder'] = $result->Cardholder;
            }
            if(!empty($result->AvsResponse))
            {
                $additionalInfo['AvsResponse'] = $result->AvsResponse;
            }
            if(!empty($result->CvResponse))
            {
                $additionalInfo['CvResponse'] = $result->AvsResponse;
            }
            $additionalInfo['CardType'] = $result->CardType;
            if(!empty($result->InvoiceNumber))
            {
                $additionalInfo['InvoiceNumber'] = $result->InvoiceNumber;
            }
            $additionalInfo['Token'] = $result->Token;
            $additionalInfo['TransactionDate'] = $result->TransactionDate;
            $additionalInfo['TransactionType'] = $result->TransactionType;
            if(!empty($result->ExtraData))
            {
                $additionalInfo['ExtraData'] = $result->ExtraData;
            }

            switch($result->ApprovalStatus)
            {
                case self::APPROVAL_STATUS_APPROVED :
                    $payment->setTransactionId($result->Token)
                        ->setIsTransactionClosed(0)
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$additionalInfo);
                    break;
                case self::APPROVAL_STATUS_DECLINED :
                case self::APPROVAL_STATUS_DECLINED_DUPLICATE :
                case self::APPROVAL_STATUS_FAILED :
                case self::APPROVAL_STATUS_REFERRAL :
                default :
                    $error = Mage::helper('merchantware_directpost')->__('Your payment transaction has been declined. Gateway approval status: %s | Gateway error response: %s', $result->ApprovalStatus, $result->ErrorMessage);
                    Mage::throwException($error);
                break;
            }
            return $result;
        } catch (Exception $e) {
            Mage::throwException(
                Mage::helper('merchantware_directpost')->__('Gateway request error: %s', $e->getMessage())
            );
        }
        if ($error !== false) {
            Mage::throwException($error);
        }

        $this->_debug($debugData);

        return false;
    }

    /**
     * Send postAuthorization request to MerchantWare gateway
     *
     * @param Mage_Payment_Model_Info $payment
     * @param decimal $amount
     * @return $result
     * @throws Mage_Core_Exception
     */
    private function _postAuthorization($payment, $amount)
    {
        $error = false;
        $soapClient = $this->getSoapApi();
        $this->iniRequest();

        //Add token
        if ($payment->getParentTransactionId()) {
            $this->_request->token = $payment->getParentTransactionId();
        }
        if(!isset($this->_request->token) || empty($this->_request->token))
        {
            $error = Mage::helper('merchantware_directpost')->__('No previous authorized transactions could be found.  Cannot capture this transaction.');
            Mage::throwException($error);
        }

        //Add amount
        $this->_request->amount = $grandTotal;

        //Add AVS address
        $this->addAvsAddress($payment->getOrder()->getBillingAddress());

        $debugData['request'] = $this->_request;

        $additionalInfo = array();
        try {
            $result = $soapClient->PostAuthorization($this->_request);
            $result = $result->PostAuthorizationResult;
            $debugData['result'] = $result;

            if(!empty($result->ErrorMessage))
            {
                $additionalInfo['ErrorMessage'] = $result->ErrorMessage;
            }

            //Authorization is approved.
            $additionalInfo['ApprovalStatus'] = $result->ApprovalStatus;
            //$additionalInfo['Amount'] = $result->Amount;
            $additionalInfo['Amount'] = $grandTotal;  //added by Jairo
            $additionalInfo['AuthorizationCode'] = $result->AuthorizationCode;
            if(!empty($result->Cardholder))
            {
                $additionalInfo['Cardholder'] = $result->Cardholder;
            }
            if(!empty($result->AvsResponse))
            {
                $additionalInfo['AvsResponse'] = $result->AvsResponse;
            }
            if(!empty($result->CvResponse))
            {
                $additionalInfo['CvResponse'] = $result->AvsResponse;
            }
            $additionalInfo['CardType'] = $result->CardType;
            if(!empty($result->InvoiceNumber))
            {
                $additionalInfo['InvoiceNumber'] = $result->InvoiceNumber;
            }
            $additionalInfo['Token'] = $result->Token;
            $additionalInfo['TransactionDate'] = $result->TransactionDate;
            $additionalInfo['TransactionType'] = $result->TransactionType;
            if(!empty($result->ExtraData))
            {
                $additionalInfo['ExtraData'] = $result->ExtraData;
            }

            switch($this->_parseApprovalStatus($result->ApprovalStatus))
            {
                case self::APPROVAL_STATUS_APPROVED :
                    $payment->setTransactionId($result->Token)
                        ->setIsTransactionClosed(0)
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$additionalInfo);
                    break;
                case self::APPROVAL_STATUS_DECLINED :
                case self::APPROVAL_STATUS_DECLINED_DUPLICATE :
                case self::APPROVAL_STATUS_FAILED :
                case self::APPROVAL_STATUS_REFERRAL :
                default :
                    $error = Mage::helper('merchantware_directpost')->__('Your payment transaction has been declined. Gateway approval status: %s | Gateway error response: %s', $result->ApprovalStatus, $result->ErrorMessage);
                    Mage::throwException($error);
                    break;
            }
            return $result;
        } catch (Exception $e) {
            Mage::throwException(
                Mage::helper('merchantware_directpost')->__('Gateway request error: %s', $e->getMessage())
            );
        }
        if ($error !== false) {
            Mage::throwException($error);
        }

        $this->_debug($debugData);

        return false;
    }

    /**
     * Send saleKeyed request to MerchantWare gateway
     *
     * @param Mage_Payment_Model_Info $payment
     * @param decimal $amount
     * @return $result
     * @throws Mage_Core_Exception
     */
    private function _saleKeyed($payment, $amount)
    {
        $error = false;
        $soapClient = $this->getSoapApi();
        $this->iniRequest();

        //Add CC info
        $this->addCcInfo($payment);

        //Add order info
        $order = $payment->getOrder();
		$grandTotal = $order->getBaseGrandTotal();
        $this->_request->merchantTransactionId = $order->getIncrementId();
        $this->_request->amount = $grandTotal;

        //Add AVS address
        $this->addAvsAddress($payment->getOrder()->getBillingAddress());

        $debugData['request'] = $this->_request;

        try {
            $result = $soapClient->SaleKeyed($this->_request);
            $result = $result->SaleKeyedResult;
            $debugData['result'] = $result;
            if(!empty($result->ErrorMessage))
            {
                $additionalInfo['ErrorMessage'] = $result->ErrorMessage;
            }

            $additionalInfo['ApprovalStatus'] = $result->ApprovalStatus;
            //$additionalInfo['Amount'] = $result->Amount;
            $additionalInfo['Amount'] = $grandTotal;//added by Jairo
            $additionalInfo['AuthorizationCode'] = $result->AuthorizationCode;
            if(!empty($result->Cardholder))
            {
                $additionalInfo['Cardholder'] = $result->Cardholder;
            }
            if(!empty($result->AvsResponse))
            {
                $additionalInfo['AvsResponse'] = $result->AvsResponse;
            }
            if(!empty($result->CvResponse))
            {
                $additionalInfo['CvResponse'] = $result->AvsResponse;
            }
            $additionalInfo['CardType'] = $result->CardType;
            if(!empty($result->InvoiceNumber))
            {
                $additionalInfo['InvoiceNumber'] = $result->InvoiceNumber;
            }
            $additionalInfo['Token'] = $result->Token;
            $additionalInfo['TransactionDate'] = $result->TransactionDate;
            $additionalInfo['TransactionType'] = $result->TransactionType;
            if(!empty($result->ExtraData))
            {
                $additionalInfo['ExtraData'] = $result->ExtraData;
            }

            switch($this->_parseApprovalStatus($result->ApprovalStatus))
            {
                case self::APPROVAL_STATUS_APPROVED :
                    //SaleKey Approved.
                    $message = 'Type: Sale | ApprovalStatus: '.$result->ApprovalStatus. ' |  Amount: '.$result->Amount;
                    $payment->setSkipTransactionCreation(false);
                    $payment->setTransactionId($result->Token)
                        ->addTransaction( Mage_Sales_Model_Order_Payment_Transaction::TYPE_PAYMENT, null, false, $message )
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$additionalInfo)
                        ->setIsTransactionClosed(1)
                        ->save();
                    break;
                case self::APPROVAL_STATUS_DECLINED :
                case self::APPROVAL_STATUS_DECLINED_DUPLICATE :
                case self::APPROVAL_STATUS_FAILED :
                case self::APPROVAL_STATUS_REFERRAL :
                default :
                    $error = Mage::helper('merchantware_directpost')->__('Your payment transaction has been declined. Gateway approval status: %s | Gateway error response: %s', $result->ApprovalStatus, $result->ErrorMessage);
                    Mage::throwException($error);
                    break;
            }
            return $result;

        } catch (Exception $e) {
            Mage::throwException(
                Mage::helper('merchantware_directpost')->__('Gateway request error: %s', $e->getMessage())
            );
        }

        if ($error !== false) {
            Mage::throwException($error);
        }
        $this->_debug($debugData);

        return false;
    }

    /**
     * Send refund request to MerchantWare gateway
     *
     * @param Mage_Payment_Model_Info $payment
     * @param decimal $amount
     * @return $result
     * @throws Mage_Core_Exception
     */
    private function _refund($payment, $amount)
    {
        $error = false;
        $soapClient = $this->getSoapApi();
        $this->iniRequest();

        //Add token
        if ($payment->getRefundTransactionId()) {
            $this->_request->token = $payment->getRefundTransactionId();
        }
        else
        {
            //No transaction Id.
            $error = Mage::helper('merchantware_directpost')->__('No gateway transaction could be found.  Gateway transaction could not be refunded.');
            Mage::throwException($error);
        }

        //Add merchantTransactionId
        $order = $payment->getOrder();
		$grandTotal = $order->getBaseGrandTotal();
        $this->_request->merchantTransactionId = $order->getIncrementId();

        //Add overrideAmount
        $this->_request->overrideAmount = $amount;

        $debugData['request'] = $this->_request;

        $additionalInfo = array();
        try {
            $result = $soapClient->Refund($this->_request);
            $result = $result->RefundResult;
            $debugData['result'] = $result;

            if(!empty($result->ErrorMessage))
            {
                $additionalInfo['ErrorMessage'] = $result->ErrorMessage;
            }

            //Authorization is approved.
            $additionalInfo['ApprovalStatus'] = $result->ApprovalStatus;
            //$additionalInfo['Amount'] = $result->Amount;
            $additionalInfo['Amount'] = $grandTotal;//added by Jairo
            $additionalInfo['AuthorizationCode'] = $result->AuthorizationCode;
            if(!empty($result->Cardholder))
            {
                $additionalInfo['Cardholder'] = $result->Cardholder;
            }
            if(!empty($result->AvsResponse))
            {
                $additionalInfo['AvsResponse'] = $result->AvsResponse;
            }
            if(!empty($result->CvResponse))
            {
                $additionalInfo['CvResponse'] = $result->AvsResponse;
            }
            $additionalInfo['CardType'] = $result->CardType;
            if(!empty($result->InvoiceNumber))
            {
                $additionalInfo['InvoiceNumber'] = $result->InvoiceNumber;
            }
            $additionalInfo['Token'] = $result->Token;
            $additionalInfo['TransactionDate'] = $result->TransactionDate;
            $additionalInfo['TransactionType'] = $result->TransactionType;
            if(!empty($result->ExtraData))
            {
                $additionalInfo['ExtraData'] = $result->ExtraData;
            }

            switch($this->_parseApprovalStatus($result->ApprovalStatus))
            {
                case self::APPROVAL_STATUS_APPROVED :
                    //SaleKey Approved.
                    $message = 'Type: Refund | ApprovalStatus: '.$result->ApprovalStatus. ' |  Amount: '.$result->Amount;
                    $payment->setSkipTransactionCreation(false);
                    $payment->setTransactionId($result->Token)
                        ->addTransaction( Mage_Sales_Model_Order_Payment_Transaction::TYPE_REFUND, null, false, $message )
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$additionalInfo)
                        ->setIsTransactionClosed(1)
                        ->save();
                    break;
                case self::APPROVAL_STATUS_DECLINED :
                case self::APPROVAL_STATUS_DECLINED_DUPLICATE :
                case self::APPROVAL_STATUS_FAILED :
                case self::APPROVAL_STATUS_REFERRAL :
                default :
                    $error = Mage::helper('merchantware_directpost')->__('Your refund request has been declined. Gateway approval status: %s | Gateway error response: %s', $result->ApprovalStatus, $result->ErrorMessage);
                    Mage::throwException($error);
                    break;
            }
            return $result;
        } catch (Exception $e) {
            Mage::throwException(
                Mage::helper('merchantware_directpost')->__('Gateway request error: %s', $e->getMessage())
            );
        }
        if ($error !== false) {
            Mage::throwException($error);
        }

        $this->_debug($debugData);

        return false;
    }

    /**
     * Send void request to MerchantWare gateway
     *
     * @param Mage_Payment_Model_Info $payment
     * @return $result
     * @throws Mage_Core_Exception
     */
    private function _void($payment)
    {
        $error = false;
        $soapClient = $this->getSoapApi();
        $this->iniRequest();

        //Add token
        if ($payment->getParentTransactionId() !== FALSE) {
            $this->_request->token = $payment->getParentTransactionId();
        }
        else
        {
            //Cannot void
            $error = Mage::helper('merchantware_directpost')->__('No previous transactions could be found.  Cannot void this transaction.');
            Mage::throwException($error);
        }

        //Add order info
        $order = $payment->getOrder();
		$grandTotal = $order->getBaseGrandTotal();
        $this->_request->merchantTransactionId = $order->getIncrementId();

        $additionalInfo = array();
        try {
            $result = $soapClient->Void($this->_request);
            $result = $result->VoidResult;

            if(!empty($result->ErrorMessage))
            {
                $additionalInfo['ErrorMessage'] = $result->ErrorMessage;
            }

            //Authorization is approved.
            $additionalInfo['ApprovalStatus'] = $result->ApprovalStatus;
            //$additionalInfo['Amount'] = $result->Amount;
            $additionalInfo['Amount'] = $grandTotal;//added by Jairo
            $additionalInfo['AuthorizationCode'] = $result->AuthorizationCode;
            if(!empty($result->Cardholder))
            {
                $additionalInfo['Cardholder'] = $result->Cardholder;
            }
            if(!empty($result->AvsResponse))
            {
                $additionalInfo['AvsResponse'] = $result->AvsResponse;
            }
            if(!empty($result->CvResponse))
            {
                $additionalInfo['CvResponse'] = $result->AvsResponse;
            }
            $additionalInfo['CardType'] = $result->CardType;
            if(!empty($result->InvoiceNumber))
            {
                $additionalInfo['InvoiceNumber'] = $result->InvoiceNumber;
            }
            $additionalInfo['Token'] = $result->Token;
            $additionalInfo['TransactionDate'] = $result->TransactionDate;
            $additionalInfo['TransactionType'] = $result->TransactionType;
            if(!empty($result->ExtraData))
            {
                $additionalInfo['ExtraData'] = $result->ExtraData;
            }

            switch($this->_parseApprovalStatus($result->ApprovalStatus))
            {
                case self::APPROVAL_STATUS_APPROVED :
                    //Void Approved.
                    $message = 'Type: Void | ApprovalStatus: '.$result->ApprovalStatus. ' |  Amount: '.$result->Amount;
                    $payment->setSkipTransactionCreation(false);
                    $payment->setTransactionId($result->Token)
                        ->addTransaction( Mage_Sales_Model_Order_Payment_Transaction::TYPE_VOID, null, false, $message )
                        ->setTransactionAdditionalInfo(Mage_Sales_Model_Order_Payment_Transaction::RAW_DETAILS,$additionalInfo)
                        ->setIsTransactionClosed(1)
                        ->save();
                    break;
                case self::APPROVAL_STATUS_DECLINED :
                case self::APPROVAL_STATUS_DECLINED_DUPLICATE :
                case self::APPROVAL_STATUS_FAILED :
                    $error = Mage::helper('merchantware_directpost')->__('Void request failed.  Gateway error response: %s', $result->ApprovalStatus, $result->ErrorMessage);
                    Mage::throwException($error);
                    break;
                case self::APPROVAL_STATUS_REFERRAL :
                default :
                    $error = Mage::helper('merchantware_directpost')->__('Unable to void this transaction. Gateway approval status: %s | Gateway error response: %s', $result->ApprovalStatus, $result->ErrorMessage);
                    Mage::throwException($error);
                    break;
            }
            return $result;
        } catch (Exception $e) {
            Mage::throwException(
                Mage::helper('merchantware_directpost')->__('Gateway request error: %s', $e->getMessage())
            );
        }
        if ($error !== false) {
            Mage::throwException($error);
        }

        return false;
    }

    /**
     * Parse MerchantWare Approval Status response string.
     */
    private function _parseApprovalStatus($approvalStatus)
    {
        $approvalStatusParts = explode(';', $approvalStatus);

        $status = $approvalStatusParts[0];
        $code = (isset($approvalStatusParts[1]) ? $approvalStatusParts[1] : null);
        $message = (isset($approvalStatusParts[2]) ? $approvalStatusParts[2] : null);

        return $status;
    }

    /**
     * Return true if the payment has already been preAuthorized.
     *
     * @param Varien_Object $payment
     * @return bool
     */
    protected function _isPreAuthorized($payment)
    {
        if ($payment->getParentTransactionId()) {
            return true;
        }
        return false;
    }
}