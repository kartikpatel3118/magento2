<?php
/**
 * Copyright © 2015 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */
namespace Magento\Quote\Model;

use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface as Logger;
use Magento\Quote\Api\ShippingAddressManagementInterface;

/** Quote shipping address write service object. */
class ShippingAddressManagement implements ShippingAddressManagementInterface
{
    /**
     * Quote repository.
     *
     * @var \Magento\Quote\Model\QuoteRepository
     */
    protected $quoteRepository;

    /**
     * Logger.
     *
     * @var Logger
     */
    protected $logger;

    /**
     * Validator.
     *
     * @var QuoteAddressValidator
     */
    protected $addressValidator;

    /**
     * @var \Magento\Customer\Api\AddressRepositoryInterface
     */
    protected $addressRepository;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    protected $scopeConfig;

    /**
     * @param QuoteRepository $quoteRepository
     * @param QuoteAddressValidator $addressValidator
     * @param Logger $logger
     * @param \Magento\Customer\Api\AddressRepositoryInterface $addressRepository
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Magento\Quote\Model\QuoteRepository $quoteRepository,
        QuoteAddressValidator $addressValidator,
        Logger $logger,
        \Magento\Customer\Api\AddressRepositoryInterface $addressRepository,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->quoteRepository = $quoteRepository;
        $this->addressValidator = $addressValidator;
        $this->logger = $logger;
        $this->addressRepository = $addressRepository;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * {@inheritDoc}
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    public function assign($cartId, \Magento\Quote\Api\Data\AddressInterface $address)
    {
        $saveInAddressBook = $address->getSaveInAddressBook() ? 1 : 0;
        $sameAsBilling = $address->getSameAsBilling() ? 1 : 0;
        $customerAddressId = $address->getCustomerAddressId();

        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);
        $this->addressValidator->validate($address);
        $quote->setShippingAddress($address);
        $address = $quote->getShippingAddress();
        if ($quote->isVirtual()) {
            throw new NoSuchEntityException(
                __('Cart contains virtual product(s) only. Shipping address is not applicable.')
            );
        }

        if ($customerAddressId) {
            $addressData = $this->addressRepository->getById($customerAddressId);
            $address = $quote->getShippingAddress()->importCustomerAddressData($addressData);
        }
        $address->setSameAsBilling($sameAsBilling);
        $address->setSaveInAddressBook($saveInAddressBook);
        $address->setCollectShippingRates(true);
        try {
            $address->collectTotals()->save();
        } catch (\Exception $e) {
            $this->logger->critical($e);
            throw new InputException(__('Unable to save address. Please, check input data.'));
        }

        if (!$quote->validateMinimumAmount($quote->getIsMultiShipping())) {
            throw new InputException($this->scopeConfig->getValue(
                'sales/minimum_order/error_message',
                \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
                $quote->getStoreId()
            ));
        }
        return $quote->getShippingAddress()->getId();
    }

    /**
     * {@inheritDoc}
     */
    public function get($cartId)
    {
        /** @var \Magento\Quote\Model\Quote $quote */
        $quote = $this->quoteRepository->getActive($cartId);
        if ($quote->isVirtual()) {
            throw new NoSuchEntityException(
                __('Cart contains virtual product(s) only. Shipping address is not applicable.')
            );
        }
        /** @var \Magento\Quote\Model\Quote\Address $address */
        return $quote->getShippingAddress();
    }
}
