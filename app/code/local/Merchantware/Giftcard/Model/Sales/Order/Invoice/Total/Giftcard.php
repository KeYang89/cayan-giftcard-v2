<?php

class Merchantware_Giftcard_Model_Sales_Order_Invoice_Total_Giftcard extends Mage_Sales_Model_Order_Invoice_Total_Abstract
{

	/*
		Subtract the giftcard discount from the invoice.
		*/
	public function collect(Mage_Sales_Model_Order_Invoice $invoice)
	{
			$order = $invoice->getOrder();

			if($order->getGiftcardDiscount() > 0) {
				$amount = -$order->getGiftcardDiscount();
				$invoice->setGrandTotal($invoice->getGrandTotal() + $amount);
				$invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $amount);
			}

			return $this;
	}

}
