<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
class Merchantware_Directpost_Block_Info extends Mage_Payment_Block_Info_Cc
{
    protected function _construct() {
        parent::_construct();
        $this->setTemplate('merchantware/directpost/info.phtml');
    }

    protected function _getConfig()
    {
        return Mage::getSingleton('merchantware_directpost/config');
    }


     /**
     * Retrieve credit card type name
     *
     * @return string
     */
    public function getCcTypeName()
    {
        $types = $this->_getConfig()->getCcTypes();
        if (isset($types[$this->getInfo()->getCcType()])) {
            return $types[$this->getInfo()->getCcType()];
        }
        return $this->getInfo()->getCcType();
    }

    /**
     * Retrieve CC start month for switch/solo card
     *
     * @return string
     */
    public function getCcStartMonth()
    {
        $month = $this->getInfo()->getCcSsStartMonth();
        if ($month<10) {
            $month = '0'.$month;
        }
        return $month;
    }

    public function toPdf()
    {
        $this->setTemplate('merchantware/directpost/pdf/info.phtml');
        return $this->toHtml();
    }
}
