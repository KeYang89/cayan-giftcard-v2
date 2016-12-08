<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */

/**
 *
 * Merchantware Directpost Payment Action Dropdown source
 *
 */
class Merchantware_Directpost_Model_Directpost_Source_PaymentAction
{
    public function toOptionArray()
    {
        return array(
            array(
                'value' => Merchantware_Directpost_Model_Directpost::ACTION_AUTHORIZE,
                'label' => Mage::helper('merchantware_directpost')->__('Authorize Only')
            ),
            array(
                'value' => Merchantware_Directpost_Model_Directpost::ACTION_AUTHORIZE_CAPTURE,
                'label' => Mage::helper('merchantware_directpost')->__('Authorize and Capture')
            ),
        );
    }
}
