<?php declare(strict_types=1);

namespace LoyaltyEngage\Service;

use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Psr\Log\LoggerInterface;

class CustomerLoyaltyService
{
    /**
     * @var EntityRepository
     */
    private $customerRepository;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param EntityRepository $customerRepository
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityRepository $customerRepository,
        LoggerInterface $logger
    ) {
        $this->customerRepository = $customerRepository;
        $this->logger = $logger;
    }

    /**
     * Update customer loyalty data by email
     *
     * @param string $email
     * @param array $loyaltyData
     * @param Context $context
     * @return array
     */
    public function updateCustomerLoyaltyData(string $email, array $loyaltyData, Context $context): array
    {
        try {
            // Validate email
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email address provided'
                ];
            }

            // Find customer by email
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('email', $email));

            /** @var CustomerEntity|null $customer */
            $customer = $this->customerRepository->search($criteria, $context)->first();

            if (!$customer) {
                $this->logger->warning('LoyaltyEngage: Customer not found for loyalty update', ['email' => $email]);
                return [
                    'success' => false,
                    'message' => 'Customer not found'
                ];
            }

            // Prepare custom fields data
            $customFields = $customer->getCustomFields() ?? [];

            // Update loyalty fields if provided
            if (isset($loyaltyData['le_current_tier'])) {
                $customFields['le_current_tier'] = $loyaltyData['le_current_tier'];
            }

            if (isset($loyaltyData['le_points'])) {
                $customFields['le_points'] = (int) $loyaltyData['le_points'];
            }

            if (isset($loyaltyData['le_available_coins'])) {
                $customFields['le_available_coins'] = (int) $loyaltyData['le_available_coins'];
            }

            if (isset($loyaltyData['le_next_tier'])) {
                $customFields['le_next_tier'] = $loyaltyData['le_next_tier'];
            }

            if (isset($loyaltyData['le_points_to_next_tier'])) {
                $customFields['le_points_to_next_tier'] = (int) $loyaltyData['le_points_to_next_tier'];
            }

            if (isset($loyaltyData['le_reserved_coins'])) {
                $customFields['le_reserved_coins'] = (int) $loyaltyData['le_reserved_coins'];
            }

            // Update customer
            $this->customerRepository->update([
                [
                    'id' => $customer->getId(),
                    'customFields' => $customFields
                ]
            ], $context);

            $this->logger->info('LoyaltyEngage: Customer loyalty data updated successfully', [
                'customerId' => $customer->getId()
            ]);

            return [
                'success' => true,
                'customerId' => $customer->getId(),
                'message' => 'Customer loyalty data updated successfully'
            ];

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Error updating customer loyalty data', [
                'email' => $email,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while updating customer loyalty data'
            ];
        }
    }

    /**
     * Get customer loyalty data by email
     *
     * @param string $email
     * @param Context $context
     * @return array
     */
    public function getCustomerLoyaltyData(string $email, Context $context): array
    {
        try {
            // Validate email
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'Invalid email address provided'
                ];
            }

            // Find customer by email
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('email', $email));

            /** @var CustomerEntity|null $customer */
            $customer = $this->customerRepository->search($criteria, $context)->first();

            if (!$customer) {
                return [
                    'success' => false,
                    'message' => 'Customer not found'
                ];
            }

            $customFields = $customer->getCustomFields() ?? [];

            return [
                'success' => true,
                'customerId' => $customer->getId(),
                'email' => $customer->getEmail(),
                'loyaltyData' => [
                    'le_current_tier' => $customFields['le_current_tier'] ?? null,
                    'le_points' => $customFields['le_points'] ?? 0,
                    'le_available_coins' => $customFields['le_available_coins'] ?? 0,
                    'le_next_tier' => $customFields['le_next_tier'] ?? null,
                    'le_points_to_next_tier' => $customFields['le_points_to_next_tier'] ?? 0,
                    'le_reserved_coins' => $customFields['le_reserved_coins'] ?? 0,
                ]
            ];

        } catch (\Throwable $e) {
            $this->logger->error('LoyaltyEngage: Error getting customer loyalty data', [
                'email' => $email,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'message' => 'An error occurred while retrieving customer loyalty data'
            ];
        }
    }
}
