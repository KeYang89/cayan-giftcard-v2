<?php
/**
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
/**
 * Merchantware Payment CC Types Source Model
 *
 * @category    Merchantware
 * @package     Merchantware_Directpost
 */
class Merchantware_Directpost_Model_Directpost_Source_Cctype extends Mage_Payment_Model_Source_Cctype
{
    public function getAllowedTypes()
    {
        return array('VI', 'MC', 'AE', 'DI', 'OT');
    }
}
