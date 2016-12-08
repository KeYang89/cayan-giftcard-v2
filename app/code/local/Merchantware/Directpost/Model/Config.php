<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
class Merchantware_Directpost_Model_Config extends Mage_Payment_Model_Config
{
    protected $_ccTypes = array();

    /**
     * Retrieve array of credit card types
     *
     * @return array
    */
    public function getCcTypes()
    {
        $pTypes = parent::getCcTypes();
        $this->_ccTypes = array();
        foreach ($pTypes as $code => $name) {
            $this->_ccTypes[$code] = $name;
        }
        return $this->_ccTypes;
    }
}
