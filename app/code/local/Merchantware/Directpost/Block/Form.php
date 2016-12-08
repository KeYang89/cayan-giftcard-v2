<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
class Merchantware_Directpost_Block_Form extends Mage_Payment_Block_Form_Cc
{
    protected function _construct() {
        parent::_construct();
        $this->setTemplate('merchantware/directpost/form.phtml');
    }
}
