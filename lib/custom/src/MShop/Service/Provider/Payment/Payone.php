<?php

/**
 * @license LGPLv3, http://opensource.org/licenses/LGPL-3.0
 * @copyright Aimeos (aimeos.org), 2017
 * @package MShop
 * @subpackage Service
 */


namespace Aimeos\MShop\Service\Provider\Payment;


/**
 * Payone payment provider
 *
 * @package MShop
 * @subpackage Service
 */
class Payone
	extends \Aimeos\MShop\Service\Provider\Payment\OmniPay
	implements \Aimeos\MShop\Service\Provider\Payment\Iface
{
	/**
	 * Returns the data passed to the Omnipay library
	 *
	 * @param \Aimeos\MShop\Order\Item\Base\Iface $base Basket object
	 * @param $orderid Unique order ID
	 * @param array $params Request parameter if available
	 */
	protected function getData( \Aimeos\MShop\Order\Item\Base\Iface $base, $orderid, array $params )
	{
		$data = parent::getData( $base, $orderid, $params );
		$lines = [];
		foreach( $base->getProducts() as $product )  {
			$lines[] = new \Omnipay\Payone\Extend\Item([
				'id' => (string) $product->toArray()['order.base.product.prodcode'],
				'name' => $product->getName(),
				'itemType' => 'goods', // Available types: goods, shipping etc.
				'quantity' => $product->getQuantity(),
				'price' => $product->getPrice()->getValue(),
				'vat' => (int) $product->getPrice()->getTaxRate(),
			]);
		}
		if ($base->getService('delivery')->getPrice()->getCosts() != '0.00') {
			$deliveryObject = $base->getService('delivery');
			$lines[] = new \Omnipay\Payone\Extend\Item([
				'id' => (string) $deliveryObject->getId(),
				'name'->getService('delivery')->getName()
				'itemType' => 'shipment',
				'quantity' => 1,
				'price' => $deliveryObject->getPrice()->getCosts(),
				'vat' => (int) $deliveryObject->getPrice()->getTaxRate(),
			]);
		}
		if ($base->getService('delivery')->getPrice()->getCosts() != '0.00') {
			$completePrice = (string) ( (float) $deliveryObject->getPrice()->getCosts() + (float) $base->getPrice()->getValue() );
		} else {
			$completePrice = $base->getPrice()->getValue();
		}
		$items = new \Omnipay\Common\ItemBag($lines);
		$data = array_merge($data, array(
				'amount' => $completePrice,
				'accessMethod' => 'classic',
				'items' => $items,
			) );
		return $data;
	}
	
	/**
	 * Updates the orders for which status updates were received via direct requests (like HTTP).
	 *
	 * @param array $params Associative list of request parameters
	 * @param string|null $body Information sent within the body of the request
	 * @param string|null &$output Response body for notification requests
	 * @param array &$header Response headers for notification requests
	 * @return \Aimeos\MShop\Order\Item\Iface|null Order item if update was successful, null if the given parameters are not valid for this provider
	 */
	public function updateSync( array $params = [], $body = null, &$output = null, array &$header = [] )
	{
		if( isset( $params['reference'] ) )
		{
			$result = $this->updateSyncOrder( $params['reference'], $params, $body, $output, $header );
			$output = 'TSOK'; // payment update successful

			return $result;
		}
	}


	/**
	 * Returns the order item for the given ID without checking the service code
	 *
	 * @param string $id Unique order ID
	 * @return \Aimeos\MShop\Order\Item\Iface $item Order object
	 */
	protected function getOrder( $id )
	{
		return \Aimeos\MShop\Factory::createManager( $this->getContext(), 'order' )->getItem( $id );
	}
}