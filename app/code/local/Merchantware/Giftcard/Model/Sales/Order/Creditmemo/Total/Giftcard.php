<?php

class Merchantware_Giftcard_Model_Sales_Order_Creditmemo_Total_Giftcard extends Mage_Sales_Model_Order_Creditmemo_Total_Abstract
{

	/*
		Subtract the giftcard discount from the creditmemo.
		*/
	public function collect(Mage_Sales_Model_Order_Creditmemo $creditmemo)
	{
			$order = $creditmemo->getOrder();

			if($order->getGiftcardDiscount() > 0) {
				$amount = -$order->getGiftcardDiscount();
				$creditmemo->setGrandTotal($creditmemo->getGrandTotal() + $amount);
				$creditmemo->setBaseGrandTotal($creditmemo->getBaseGrandTotal() + $amount);
			}

			return $this;
	}

}
