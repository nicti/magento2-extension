<?php

/*
 * @author     M2E Pro Developers Team
 * @copyright  M2E LTD
 * @license    Commercial use is forbidden
 */

namespace Ess\M2ePro\Model\Amazon\Order;

/**
 * Class \Ess\M2ePro\Model\Amazon\Order\ProxyObject
 */
class ProxyObject extends \Ess\M2ePro\Model\Order\ProxyObject
{
    /** @var \Ess\M2ePro\Model\Amazon\Order\Item\ProxyObject[] */
    protected $removedProxyItems = [];

    protected $payment;
    protected $customerFactory;
    protected $customerRepository;

    //########################################

    public function __construct(
        \Ess\M2ePro\Model\Magento\Payment $payment,
        \Magento\Customer\Model\CustomerFactory $customerFactory,
        \Magento\Customer\Api\CustomerRepositoryInterface $customerRepository,
        \Ess\M2ePro\Model\Currency $currency,
        \Ess\M2ePro\Model\ActiveRecord\Component\Child\AbstractModel $order,
        \Ess\M2ePro\Helper\Factory $helperFactory,
        \Ess\M2ePro\Model\Factory $modelFactory
    ) {
        $this->payment = $payment;
        $this->customerFactory = $customerFactory;
        $this->customerRepository = $customerRepository;
        parent::__construct($currency, $order, $helperFactory, $modelFactory);
    }

    //########################################

    /**
     * @return string
     */
    public function getCheckoutMethod()
    {
        if ($this->order->getAmazonAccount()->isMagentoOrdersCustomerPredefined() ||
            $this->order->getAmazonAccount()->isMagentoOrdersCustomerNew()) {
            return self::CHECKOUT_REGISTER;
        }

        return self::CHECKOUT_GUEST;
    }

    //########################################

    /**
     * @return bool
     */
    public function isOrderNumberPrefixSourceChannel()
    {
        return $this->order->getAmazonAccount()->isMagentoOrdersNumberSourceChannel();
    }

    /**
     * @return bool
     */
    public function isOrderNumberPrefixSourceMagento()
    {
        return $this->order->getAmazonAccount()->isMagentoOrdersNumberSourceMagento();
    }

    /**
     * @return mixed
     */
    public function getChannelOrderNumber()
    {
        return $this->order->getAmazonOrderId();
    }

    /**
     * @return string
     */
    public function getOrderNumberPrefix()
    {
        $amazonAccount = $this->order->getAmazonAccount();

        $prefix = $amazonAccount->getMagentoOrdersNumberRegularPrefix();

        if ($amazonAccount->getMagentoOrdersNumberAfnPrefix() && $this->order->isFulfilledByAmazon()) {
            $prefix .= $amazonAccount->getMagentoOrdersNumberAfnPrefix();
        }

        if ($amazonAccount->getMagentoOrdersNumberPrimePrefix() && $this->order->isPrime()) {
            $prefix .= $amazonAccount->getMagentoOrdersNumberPrimePrefix();
        }

        if ($amazonAccount->getMagentoOrdersNumberB2bPrefix() && $this->order->isBusiness()) {
            $prefix .= $amazonAccount->getMagentoOrdersNumberB2bPrefix();
        }

        return $prefix;
    }

    //########################################

    /**
     * @return \Magento\Customer\Api\Data\CustomerInterface
     * @throws \Ess\M2ePro\Model\Exception
     */
    public function getCustomer()
    {
        if ($this->order->getAmazonAccount()->isMagentoOrdersCustomerPredefined()) {
            $customerDataObject = $this->customerRepository->getById(
                $this->order->getAmazonAccount()->getMagentoOrdersCustomerId()
            );

            if ($customerDataObject->getId() === null) {
                throw new \Ess\M2ePro\Model\Exception('Customer with ID specified in Amazon Account
                    Settings does not exist.');
            }

            return $customerDataObject;
        }

        /** @var $customerBuilder \Ess\M2ePro\Model\Magento\Customer */
        $customerBuilder = $this->modelFactory->getObject('Magento\Customer');

        if ($this->order->getAmazonAccount()->isMagentoOrdersCustomerNew()) {
            $customerInfo = $this->getAddressData();

            $customerObject = $this->customerFactory->create();
            $customerObject->setWebsiteId($this->order->getAmazonAccount()->getMagentoOrdersCustomerNewWebsiteId());
            $customerObject->loadByEmail($customerInfo['email']);

            if ($customerObject->getId() !== null) {
                $customerBuilder->setData($customerInfo);
                $customerBuilder->updateAddress($customerObject);

                return $customerObject->getDataModel();
            }

            $customerInfo['website_id'] = $this->order->getAmazonAccount()->getMagentoOrdersCustomerNewWebsiteId();
            $customerInfo['group_id'] = $this->order->getAmazonAccount()->getMagentoOrdersCustomerNewGroupId();

            $customerBuilder->setData($customerInfo);
            $customerBuilder->buildCustomer();
            $customerBuilder->getCustomer()->save();

            return $customerBuilder->getCustomer()->getDataModel();
        }

        return null;
    }

    //########################################

    /**
     * @return array
     */
    public function getBillingAddressData()
    {
        if ($this->order->getAmazonAccount()->isMagentoOrdersBillingAddressSameAsShipping()) {
            return parent::getBillingAddressData();
        }

        if ($this->order->getShippingAddress()->hasSameBuyerAndRecipient()) {
            return parent::getBillingAddressData();
        }

        $customerNameParts = $this->getNameParts($this->order->getBuyerName());

        return [
            'firstname' => $customerNameParts['firstname'],
            'middlename' => $customerNameParts['middlename'],
            'lastname' => $customerNameParts['lastname'],
            'country_id' => '',
            'region' => '',
            'region_id' => '',
            'city' => 'Amazon does not supply the complete billing Buyer information.',
            'postcode' => '',
            'street' => '',
            'company' => ''
        ];
    }

    /**
     * @return bool
     */
    public function shouldIgnoreBillingAddressValidation()
    {
        if ($this->order->getAmazonAccount()->isMagentoOrdersBillingAddressSameAsShipping()) {
            return false;
        }

        if ($this->order->getShippingAddress()->hasSameBuyerAndRecipient()) {
            return false;
        }

        return true;
    }

    //########################################

    public function getCurrency()
    {
        return $this->order->getCurrency();
    }

    //########################################

    /**
     * @return array
     */
    public function getPaymentData()
    {
        $paymentData = [
            'method' => $this->payment->getCode(),
            'component_mode' => \Ess\M2ePro\Helper\Component\Amazon::NICK,
            'payment_method' => '',
            'channel_order_id' => $this->order->getAmazonOrderId(),
            'channel_final_fee' => 0,
            'cash_on_delivery_cost' => 0,
            'transactions' => []
        ];

        return $paymentData;
    }

    //########################################

    /**
     * @return array
     */
    public function getShippingData()
    {
        $shippingData = [
            'shipping_method' => $this->order->getShippingService(),
            'shipping_price' => $this->getBaseShippingPrice(),
            'carrier_title' => $this->getHelper('Module\Translation')->__('Amazon Shipping')
        ];

        if ($this->order->isPrime()) {
            $shippingData['shipping_method'] .= ' | Is Prime';
        }

        if ($this->order->isBusiness()) {
            $shippingData['shipping_method'] .= ' | Is Business';
        }

        if ($this->order->isMerchantFulfillmentApplied()) {
            $merchantFulfillmentInfo = $this->order->getMerchantFulfillmentData();

            $shippingData['shipping_method'] .= ' | Amazon\'s Shipping Services';

            if (!empty($merchantFulfillmentInfo['shipping_service']['carrier_name'])) {
                $carrier = $merchantFulfillmentInfo['shipping_service']['carrier_name'];
                $shippingData['shipping_method'] .= ' | Carrier: ' . $carrier;
            }

            if (!empty($merchantFulfillmentInfo['shipping_service']['name'])) {
                $service = $merchantFulfillmentInfo['shipping_service']['name'];
                $shippingData['shipping_method'] .= ' | Service: ' . $service;
            }

            if (!empty($merchantFulfillmentInfo['shipping_service']['date']['estimated_delivery']['latest'])) {
                $deliveryDate = $merchantFulfillmentInfo['shipping_service']['date']['estimated_delivery']['latest'];
                $shippingData['shipping_method'] .= ' | Delivery Date: ' . $deliveryDate;
            }
        }

        return $shippingData;
    }

    /**
     * @return float
     */
    protected function getShippingPrice()
    {
        $price = $this->order->getShippingPrice() - $this->order->getShippingDiscountAmount();

        if ($this->isTaxModeNone() && $this->getShippingPriceTaxRate() > 0) {
            $price += $this->order->getShippingPriceTaxAmount();
        }

        return $price;
    }

    //########################################

    /**
     * @return string[]
     * @throws \Ess\M2ePro\Model\Exception\Logic
     */
    public function getChannelComments()
    {
        return array_merge(
            $this->getDiscountComments(),
            $this->getGiftWrappedComments(),
            $this->getRemovedOrderItemsComments(),
            $this->getAFNWarehouseComments()
        );
    }

    /**
     * @return string[]
     * @throws \Ess\M2ePro\Model\Exception\Logic
     */
    public function getDiscountComments()
    {
        $translation = $this->getHelper('Module_Translation');
        $comments = [];

        if ($this->order->getPromotionDiscountAmount() > 0) {
            $discount = $this->currency->formatPrice(
                $this->getCurrency(),
                $this->order->getPromotionDiscountAmount()
            );

            $comments[] =  $translation->__(
                '%value% promotion discount amount was subtracted from the total amount.',
                $discount
            );
        }

        if ($this->order->getShippingDiscountAmount() > 0) {
            $discount = $this->currency->formatPrice(
                $this->getCurrency(),
                $this->order->getShippingDiscountAmount()
            );

            $comments[] = $translation->__(
                '%value% discount amount was subtracted from the shipping Price.',
                $discount
            );
        }

        return $comments;
    }

    /**
     * @return string[]
     * @throws \Ess\M2ePro\Model\Exception\Logic
     */
    public function getGiftWrappedComments()
    {
        $itemsGiftPrices = [];
        foreach ($this->order->getParentObject()->getItemsCollection() as $item) {
            /** @var \Ess\M2ePro\Model\Order\Item $item */

            $giftPrice = $item->getChildObject()->getGiftPrice();
            if (empty($giftPrice)) {
                continue;
            }

            if ($item->getMagentoProduct()) {
                $itemsGiftPrices[] = [
                    'name'  => $item->getMagentoProduct()->getName(),
                    'type'  => $item->getChildObject()->getGiftType(),
                    'price' => $giftPrice
                ];
            }
        }

        if (empty($itemsGiftPrices)) {
            return [];
        }

        $comment = '<u>'.
            $this->getHelper('Module_Translation')->__('The following Items are purchased with gift wraps') .
            ':</u><br/>';

        foreach ($itemsGiftPrices as $productInfo) {
            $formattedCurrency = $this->currency->formatPrice(
                $this->getCurrency(),
                $productInfo['price']
            );

            $comment .= "<b>{$productInfo['name']}</b> > {$productInfo['type']} ({$formattedCurrency})<br/>";
        }

        return [$comment];
    }

    /**
     * @return string[]
     * @throws \Ess\M2ePro\Model\Exception\Logic
     */
    public function getRemovedOrderItemsComments()
    {
        if (empty($this->removedProxyItems)) {
            return [];
        }

        $comment = '<u>'.
            $this->getHelper('Module_Translation')->__(
                'The following SKUs have zero price and can not be included in Magento order line items'
            ).
            ':</u><br/>';

        foreach ($this->removedProxyItems as $item) {
            if ($item->getMagentoProduct()) {
                $comment .= "<b>{$item->getMagentoProduct()->getSku()}</b>: {$item->getQty()} QTY<br/>";
            }
        }

        return [$comment];
    }

    /**
     * @return string[]
     * @throws \Ess\M2ePro\Model\Exception\Logic
     */
    public function getAFNWarehouseComments()
    {
        if (!$this->order->isFulfilledByAmazon()) {
            return [];
        }

        $comment = '';
        $helper = $this->getHelper('Data');
        $translation = $this->getHelper('Module_Translation');

        foreach ($this->order->getParentObject()->getItemsCollection() as $item) {
            /** @var \Ess\M2ePro\Model\Order\Item $item */

            $centerId = $item->getChildObject()->getFulfillmentCenterId();
            if (empty($centerId)) {
                return [];
            }

            if ($item->getMagentoProduct()) {
                $sku = $item->getMagentoProduct()->getSku();
                $comment .= "<b>{$translation->__('SKU')}:</b> {$helper->escapeHtml($sku)}&nbsp;&nbsp;&nbsp;";
            }

            if ($generalId = $item->getChildObject()->getGeneralId()) {
                $general = $item->getChildObject()->getIsIsbnGeneralId() ? 'ISBN' : 'ASIN';
                $comment .= "<b>{$translation->__($general)}:</b> {$helper->escapeHtml($generalId)}&nbsp;&nbsp;&nbsp;";
            }

            $comment .= "<b>{$translation->__('AFN Warehouse')}:</b> {$helper->escapeHtml($centerId)}<br/><br/>";
        }

        return empty($comment) ? [] : [$comment];
    }

    //########################################

    /**
     * @return bool
     */
    public function hasTax()
    {
        return $this->order->getProductPriceTaxRate() > 0;
    }

    /**
     * @return bool
     */
    public function isSalesTax()
    {
        return $this->hasTax();
    }

    /**
     * @return bool
     */
    public function isVatTax()
    {
        return false;
    }

    // ---------------------------------------

    /**
     * @return float|int
     */
    public function getProductPriceTaxRate()
    {
        return $this->order->getProductPriceTaxRate();
    }

    /**
     * @return float|int
     */
    public function getShippingPriceTaxRate()
    {
        return $this->order->getShippingPriceTaxRate();
    }

    // ---------------------------------------

    /**
     * @return bool|null
     */
    public function isProductPriceIncludeTax()
    {
        $configValue = $this->getHelper('Module')
            ->getConfig()
            ->getGroupValue('/amazon/order/tax/product_price/', 'is_include_tax');

        if ($configValue !== null) {
            return (bool)$configValue;
        }

        if ($this->isTaxModeChannel() || ($this->isTaxModeMixed() && $this->hasTax())) {
            return false;
        }

        return null;
    }

    /**
     * @return bool|null
     */
    public function isShippingPriceIncludeTax()
    {
        $configValue = $this->getHelper('Module')
            ->getConfig()
            ->getGroupValue('/amazon/order/tax/shipping_price/', 'is_include_tax');

        if ($configValue !== null) {
            return (bool)$configValue;
        }

        if ($this->isTaxModeChannel() || ($this->isTaxModeMixed() && $this->hasTax())) {
            return false;
        }

        return null;
    }

    // ---------------------------------------

    /**
     * @return bool
     */
    public function isTaxModeNone()
    {
        return $this->order->getAmazonAccount()->isMagentoOrdersTaxModeNone();
    }

    /**
     * @return bool
     */
    public function isTaxModeMagento()
    {
        return $this->order->getAmazonAccount()->isMagentoOrdersTaxModeMagento();
    }

    /**
     * @return bool
     */
    public function isTaxModeChannel()
    {
        return $this->order->getAmazonAccount()->isMagentoOrdersTaxModeChannel();
    }

    //########################################
}
