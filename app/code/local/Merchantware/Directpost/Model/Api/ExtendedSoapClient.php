<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
class Merchantware_Directpost_Model_Api_ExtendedSoapClient extends SoapClient
{
    /**
     * Store Id for retrieving config data
     *
     * @var int
     */
    protected $_storeId;

    /**
     * Store Id setter
     *
     * @param int $storeId
     * @return Merchantware_Directpost_Model_Api_ExtendedSoapClient
     */
    public function setStoreId($storeId)
    {
        $this->_storeId = $storeId;
        return $this;
    }

    /**
     * Store Id getter
     *
     * @return int
     */
    public function getStoreId()
    {
        return $this->_storeId;
    }

    /**
     * XPaths that should be replaced in debug with '***'
     *
     * @var array
     */
    protected $_debugReplacePrivateDataXPaths = array(
        '//*[contains(name(),\'CardNumber\')]/*/text()',
    );

    public function __construct($wsdl, $options = array())
    {
        parent::__construct($wsdl, $options);
    }

    protected function getBaseApi()
    {
        return Mage::getSingleton('merchantware_directpost/directpost');
    }

    public function __doRequest($request, $location, $action, $version, $oneWay = 0)
    {
        $api = $this->getBaseApi();
        $requestDOM = new DOMDocument('1.0');
        $requestDOM->loadXML($request);
        $request = $requestDOM->saveXML();
        $requestDOMXPath = new DOMXPath($requestDOM);
        foreach ($this->_debugReplacePrivateDataXPaths as $xPath) {
            foreach ($requestDOMXPath->query($xPath) as $element) {
                $element->data = '***';
            }
        }

        $debugData = array(
            'request_location' => $location,
            'request' => $requestDOM->saveXML()
        );

        try {
            $response = parent::__doRequest($request, $location, $action, $version, $oneWay);
        }
        catch (Exception $e) {
            $debugData['result'] = array('error' => $e->getMessage(), 'code' => $e->getCode());
            $api->debugData($debugData);
            throw $e;
        }

        $debugData['result'] = $response;
        $api->debugData($debugData);

        return $response;
    }
}
