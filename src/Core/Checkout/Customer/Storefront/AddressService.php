<?php declare(strict_types=1);

namespace Shopware\Core\Checkout\Customer\Storefront;

use Shopware\Core\Checkout\Cart\Exception\CustomerNotLoggedInException;
use Shopware\Core\Checkout\CheckoutContext;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEvents;
use Shopware\Core\Checkout\Customer\Validation\AddressValidationService;
use Shopware\Core\Checkout\Exception\AddressNotFoundException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Event\DataMappingEvent;
use Shopware\Core\Framework\Exception\InvalidUuidException;
use Shopware\Core\Framework\Struct\Uuid;
use Shopware\Core\Framework\Validation\BuildValidationEvent;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataValidationDefinition;
use Shopware\Core\Framework\Validation\DataValidator;
use Shopware\Core\System\Country\CountryCollection;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class AddressService
{
    /**
     * @var EntityRepositoryInterface
     */
    private $countryRepository;

    /**
     * @var EntityRepositoryInterface
     */
    private $customerAddressRepository;

    /**
     * @var AddressValidationService
     */
    private $addressValidationService;

    /**
     * @var DataValidator
     */
    private $validator;

    /**
     * @var EventDispatcherInterface
     */
    private $eventDispatcher;

    public function __construct(
        EntityRepositoryInterface $countryRepository,
        EntityRepositoryInterface $customerAddressRepository,
        AddressValidationService $addressValidationService,
        DataValidator $validator,
        EventDispatcherInterface $eventDispatcher
    ) {
        $this->countryRepository = $countryRepository;
        $this->customerAddressRepository = $customerAddressRepository;
        $this->addressValidationService = $addressValidationService;
        $this->validator = $validator;
        $this->eventDispatcher = $eventDispatcher;
    }

    /**
     * @throws AddressNotFoundException
     * @throws InvalidUuidException
     */
    public function getById(string $addressId, CheckoutContext $context): CustomerAddressEntity
    {
        return $this->validateAddressId($addressId, $context);
    }

    public function getCountryList(CheckoutContext $context): CountryCollection
    {
        $criteria = new Criteria([]);
        $criteria->addFilter(new EqualsFilter('country.active', true));

        /** @var CountryCollection $countries */
        $countries = $this->countryRepository->search($criteria, $context->getContext())
            ->getEntities();

        $countries->sortCountryAndStates();

        return $countries;
    }

    /**
     * @throws CustomerNotLoggedInException
     */
    public function getAddressByContext(CheckoutContext $context): array
    {
        $this->validateCustomerIsLoggedIn($context);
        $customer = $context->getCustomer();
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customer_address.customerId', $context->getCustomer()->getId()));

        /** @var CustomerAddressCollection $addresses */
        $addresses = $this->customerAddressRepository->search($criteria, $context->getContext())->getEntities();

        return $addresses->sortByDefaultAddress($customer)->getElements();
    }

    /**
     * @throws AddressNotFoundException
     * @throws CustomerNotLoggedInException
     * @throws InvalidUuidException
     */
    public function create(DataBag $data, CheckoutContext $context): string
    {
        $this->validateCustomerIsLoggedIn($context);

        $definition = $this->getCreateValidationDefinition($context->getContext());
        $this->validator->validate($data->all(), $definition);

        if ($id = $data->get('id')) {
            $this->validateAddressId((string) $id, $context)->getId();
        } else {
            $id = Uuid::uuid4()->getHex();
        }

        $addressData = [
            'salutationId' => $data->get('salutationId'),
            'firstName' => $data->get('firstName'),
            'lastName' => $data->get('lastName'),
            'street' => $data->get('street'),
            'city' => $data->get('city'),
            'zipcode' => $data->get('zipcode'),
            'countryId' => $data->get('countryId'),
            'countryStateId' => $data->get('countryStateId'),
            'company' => $data->get('company'),
            'department' => $data->get('department'),
            'title' => $data->get('title'),
            'vatId' => $data->get('vatId'),
            'additionalAddressLine1' => $data->get('additionalAddressLine1'),
            'additionalAddressLine2' => $data->get('additionalAddressLine2'),
        ];

        $mappingEvent = new DataMappingEvent(CustomerEvents::MAPPING_ADDRESS_CREATE, $data, $addressData, $context->getContext());
        $this->eventDispatcher->dispatch($mappingEvent->getName(), $mappingEvent);

        $addressData = $mappingEvent->getOutput();
        $addressData['id'] = $id;
        $addressData['customerId'] = $context->getCustomer()->getId();

        $this->customerAddressRepository->upsert([$addressData], $context->getContext());

        return $id;
    }

    /**
     * @throws CustomerNotLoggedInException
     * @throws InvalidUuidException
     * @throws AddressNotFoundException
     */
    public function delete(string $addressId, CheckoutContext $context): void
    {
        $this->validateCustomerIsLoggedIn($context);
        $this->validateAddressId($addressId, $context);
        $this->customerAddressRepository->delete([['id' => $addressId]], $context->getContext());
    }

    /**
     * @throws CustomerNotLoggedInException
     */
    private function validateCustomerIsLoggedIn(CheckoutContext $context): void
    {
        if (!$context->getCustomer()) {
            throw new CustomerNotLoggedInException();
        }
    }

    /**
     * @throws AddressNotFoundException
     * @throws InvalidUuidException
     */
    private function validateAddressId(string $addressId, CheckoutContext $context): CustomerAddressEntity
    {
        if (!Uuid::isValid($addressId)) {
            throw new InvalidUuidException($addressId);
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('address.id', $addressId));
        $criteria->addFilter(new EqualsFilter('address.customer_id', $context->getCustomer()->getId()));

        /** @var CustomerAddressEntity|null $address */
        $address = $this->customerAddressRepository->search(new Criteria([$addressId]), $context->getContext())->get($addressId);

        if (!$address) {
            throw new AddressNotFoundException($addressId);
        }

        return $address;
    }

    private function getCreateValidationDefinition(Context $context): DataValidationDefinition
    {
        $validation = $this->addressValidationService->buildCreateValidation($context);

        $validationEvent = new BuildValidationEvent($validation, $context);
        $this->eventDispatcher->dispatch($validationEvent->getName(), $validationEvent);

        return $validation;
    }
}
