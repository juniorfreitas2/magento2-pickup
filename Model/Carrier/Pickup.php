<?php
/*
 * @package     Intelipost_Pickup
 * @copyright   Copyright (c) 2016 Gamuza Technologies (http://www.gamuza.com.br/)
 * @author      Eneias Ramos de Melo <eneias@gamuza.com.br>
 */

namespace Intelipost\Pickup\Model\Carrier;
 
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Shipping\Model\Rate\Result;
 
class Pickup
extends \Intelipost\Quote\Model\Carrier\Intelipost
// extends \Magento\Shipping\Model\Carrier\AbstractCarrier
// implements \Magento\Shipping\Model\Carrier\CarrierInterface
{

protected $_code = 'pickup';

protected $_rateResultFactory;
protected $_rateMethodFactory;
protected $_rateErrorFactory;

protected $_scopeConfig;
protected $_quoteHelper;
protected $_apiHelper;
protected $_pickupHelper;

protected $_itemsFactory;

public function __construct(
    \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
    \Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory $rateErrorFactory,
    \Psr\Log\LoggerInterface $logger,
    \Magento\Shipping\Model\Rate\ResultFactory $rateResultFactory,
    \Magento\Quote\Model\Quote\Address\RateResult\MethodFactory $rateMethodFactory,
    \Intelipost\Quote\Helper\Data $quoteHelper,
    \Intelipost\Quote\Helper\Api $apiHelper,
    \Intelipost\Pickup\Helper\Data $pickupHelper,
    \Intelipost\Quote\Model\QuoteFactory $quoteFactory,
    \Intelipost\Pickup\Model\ItemsFactory $itemsFactory,
    array $data = []
)
{
    $this->_rateResultFactory = $rateResultFactory;
    $this->_rateMethodFactory = $rateMethodFactory;
    $this->_rateErrorFactory  = $rateErrorFactory;

    $this->_scopeConfig = $scopeConfig;
    $this->_quoteHelper = $quoteHelper;
    $this->_apiHelper = $apiHelper;
    $this->_pickupHelper = $pickupHelper;

    $this->_itemsFactory = $itemsFactory;

    parent::__construct(
        $scopeConfig, $rateErrorFactory, $logger,
        $rateResultFactory, $rateMethodFactory,
        $quoteHelper, $apiHelper, $quoteFactory, $data
    );
}

public function getAllowedMethods()
{
    return ['pickup' => $this->getConfigData ('name')];
}

public function collectRates(RateRequest $request, $pickup = true)
{
    if (!$this->getConfigFlag ('active'))
    {
        return false;
    }
    else if (!$request->getDestPostcode ())
    {
        return false;
    }

    // $this->_quoteHelper->removeQuotes($this->_code);

    $min_pdt_date =  $this->getMinDate($request);
    if (!$min_pdt_date) return false;

    $pageName = $this->_quoteHelper->getPageName();

    $destPostcode = preg_replace ('#[^0-9]#', "", $request->getDestPostcode());

    $collection = $this->_itemsFactory->create()->getCollection ();
    $collection->getSelect()->join(
            ['stores' => $collection->getTable('intelipost_pickup_stores')],
            'main_table.store_id = stores.entity_id',
            ['store_name' => 'stores.name', 'store_adress' => 'stores.address',
            'store_number' => 'stores.number', 'stores_city' => 'stores.city',
            'store_zipcode' => 'stores.zipcode', 'store_delivered_cdg' => 'stores.delivered_cdg', 'arrival_date' => 'main_table.arrival_date']
        )
        ->where("stores.is_active = 1 AND (STR_TO_DATE(main_table.arrival_date, '%d/%m/%Y') > STR_TO_DATE('{$min_pdt_date}', '%d/%m/%Y')) AND ('{$destPostcode}' BETWEEN begin_zipcode AND end_zipcode)")
        ->order("STR_TO_DATE(main_table.arrival_date, '%d/%m/%Y') ASC");

    $showAllStores = $this->_scopeConfig->getValue('carriers/pickup/show_all_stores');
    if($showAllStores || $pageName == 'checkout')
    {
        $collection->getSelect()->order("STR_TO_DATE(main_table.arrival_date, '%d/%m/%Y') ASC"); // why inverted?
    }
    else
    {
        $collection->getSelect()->order("STR_TO_DATE(main_table.arrival_date, '%d/%m/%Y') DESC")->limit(1); // first
    }

    if (!$collection->count()) return false;

    /*
     * Result
     */
    $result = $this->_rateResultFactory->create();

    $resultQuotes = array();

    $carrierTitle = $this->_scopeConfig->getValue('carriers/pickup/title');

    /*
     * Sort By Proximity
     */
    $sortByProximity = $this->_scopeConfig->getValue('carriers/pickup/sort_by_proximity');
    $collectionData = $collection->getData();

    $storeZipcodes = null;
    $children = array();
    $stored = array();
    $duplacted = array();


    foreach ($collectionData as $key => $value) 
    {
        if (in_array($value['id_loja'], $stored)) 
        {
            $duplacted[$key] = $value['id_loja'];
        }

        $stored[$value['id_loja']] = $value['id_loja'];      
    }


    foreach ($duplacted as $key => $value) 
    {
        if (array_key_exists($key, $collectionData)) 
        {
            unset($collectionData[$key]);
        }
    }

    if ($sortByProximity)
    {
        $storeZipcodes = null;
        foreach($collectionData as $item)
        {
            $destinations [] = $item['store_zipcode']; // $item->getStoreZipcode();
        }

        $response = $this->_pickupHelper->calculateDistanceMatrix($destPostcode, $destinations);
        $json = json_decode($response, true);

        if(!empty($json) && !strcmp($json['status'], 'OK'))
        {
            $matrix = null;

            foreach($json['rows'][0]['elements'] as $element)
            {
                if (!array_key_exists ('distance', $element)) continue;

                $distance = intval($element ['distance']['value']);

                $matrix [] = $distance; // = $element ['distance']['text'];
            }

            $i = 0;

            if (is_array($matrix))
            {
                foreach ($collectionData as $item)
                {
                    if (!array_key_exists ($i, $matrix)) continue;

                    $children [$matrix [$i ++]] = $item;
                }

                ksort($children, SORT_NUMERIC);

                $collection = $children;
            }
        }
    }

    if (empty($children))
    {
        $children = $collectionData;
    }

    //$pageName = $this->_quoteHelper->getPageName();

    /*
     * Children
     */
    foreach($children as $item)
    {
        $storeId = $item ['store_id'] /* $item->getStoreId() */;

        /*
         * Delivered by CDG
         */
        if (!$item ['store_delivered_cdg'] /* ->getStoreDeliveredCdg() */ && !$showAllStores)
        {
            $request->setPickupDestPostcode ($item['store_zipcode'] /* ->getStoreZipcode() */);

            $result = parent::collectRates($request, true);

            $this->_pickupHelper->checkFreeshipping ($result);

            return $result;
        }

        /*
         * Config
         */
        $methodTitle = $this->_pickupHelper->getCustomTitle($item);

        /*
         * Method
         */
        $method = $this->_rateMethodFactory->create();

        $method->setCarrier($this->_code);
        $method->setCarrierTitle($carrierTitle);

        $method->setMethod($this->_code . '_' . $storeId);
        $method->setMethodTitle($methodTitle);
        $method->setMethodDescription($methodTitle);

        $method->setPrice(0);
        $method->setCost(0);

        $resultQuotes [] = $this->_quoteHelper->savePickupQuote($this->_code, $item['entity_id'], $item['store_id']);

        $result->append($method);

        if($sortByProximity && strcmp($pageName, 'checkout')) break;
    }

    $this->_helper->saveResultQuotes ($resultQuotes, true, \Intelipost\Quote\Helper\Data::RESULT_PICKUP);

    return $result;
}

public function getMinDate(RateRequest $request)
{
    $parentItem = null;

    // Product Factory
    $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
    $objectProduct = $objectManager->get('Magento\Catalog\Model\ProductFactory');

    $preSalesAttribute = $this->_scopeConfig->getValue ('carriers/presales/presales_attribute');
    $packageAttribute = $this->_scopeConfig->getValue ('carriers/presales/package_attribute');
    $readyToGoAttribute = $this->_scopeConfig->getValue ('carriers/presales/readytogo_attribute');

    $dateFormat = $this->_scopeConfig->getValue ('carriers/pickup/date_format');

    foreach ($request->getAllItems () as $item)
    {
        if ($item->getProductType() != 'simple')
        {
            $parentItem = $item;

            continue;
        }

        $product = $objectProduct->create()->load ($item->getProductId ());

        $preSalesValue = $product->getData($preSalesAttribute);
        $preSalesReady = $product->getData($readyToGoAttribute);
        $packageValue  = $product->getData($packageAttribute);

        if ($preSalesValue && !$preSalesReady && !$packageValue)
        {
            return false;

            if ($parentItem)
            {
                $preSalesItems [$preSalesValue][] = $parentItem;
            }

            $preSalesItems [$preSalesValue][] = $item;
        }
    }

    $preSalesResult = null;

    return date($dateFormat, $this->_quoteHelper->getShippedDate(false));
}

}

