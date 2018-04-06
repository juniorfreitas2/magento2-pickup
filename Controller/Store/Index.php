<?php
/*
 * @package     Intelipost_Pickup
 * @copyright   Copyright (c) 2016 Gamuza Technologies (http://www.gamuza.com.br/)
 * @author      Eneias Ramos de Melo <eneias@gamuza.com.br>
 */

namespace Intelipost\Pickup\Controller\Store;

class Index extends \Magento\Framework\App\Action\Action
{

protected $_helper;
protected $_storesFactory;

public function __construct(
    \Magento\Framework\App\Action\Context $context,
    \Magento\Framework\View\Result\PageFactory $resultPageFactory,
    \Intelipost\Pickup\Helper\Data $helper,
    \Intelipost\Pickup\Model\StoresFactory $storesFactory
)
{
    $this->_helper = $helper;
    $this->_storesFactory = $storesFactory;
    $this->_resultPageFactory = $resultPageFactory;

    parent::__construct ($context);
}

public function execute()
{
    $info = $this->getRequest()->getParam('info');
    if(empty($info)) return false;

    $pieces = explode('_', $info); // carrier, method, store id
    if (!array_key_exists (2, $pieces)) return false;
    else $storeId = $pieces [2];

    $store = $this->_storesFactory->create()->load($storeId);
    if(!$store->getId()) return false; // not delivered by CDG (quoteId)

    $html = $this->_helper->getMapsHtml ($store);

    $resultPage = $this->_resultPageFactory->create();
    $this->getResponse()->setBody(
        $html
    );
}

}

