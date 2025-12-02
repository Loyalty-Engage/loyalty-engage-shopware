<?php declare(strict_types=1);

namespace LoyaltyEngage;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Doctrine\DBAL\Connection;

class LoyaltyEngage extends Plugin
{
    /**
     * @param InstallContext $installContext
     */
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);

        // Add custom fields to order entity for tracking loyalty order status
        $this->addCustomFields($installContext);
    }

    /**
     * @param UninstallContext $uninstallContext
     */
    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        // Remove custom fields if not keeping user data
        $this->removeCustomFields($uninstallContext);
    }

    /**
     * Add custom fields to order entity
     */
    private function addCustomFields(InstallContext $installContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        // Check if custom field set already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'loyalty_engage_fields'));
        $customFieldSets = $customFieldSetRepository->search($criteria, $installContext->getContext());

        if ($customFieldSets->count() > 0) {
            return;
        }

        // Create custom field set
        $customFieldSetRepository->create([
            [
                'name' => 'loyalty_engage_fields',
                'config' => [
                    'label' => [
                        'en-GB' => 'Loyalty Engage Fields',
                        'de-DE' => 'Loyalty Engage Felder',
                    ],
                ],
                'relations' => [
                    [
                        'entityName' => 'order',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => 'loyalty_order_place',
                        'type' => 'bool',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Loyalty Order Placed',
                                'de-DE' => 'Loyalty Bestellung Platziert',
                            ],
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'name' => 'loyalty_order_place_retrieve',
                        'type' => 'int',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Loyalty Order Retrieve Count',
                                'de-DE' => 'Loyalty Bestellung Abrufzähler',
                            ],
                            'customFieldPosition' => 2,
                        ],
                    ],
                ],
            ],
        ], $installContext->getContext());
    }

    /**
     * Remove custom fields from order entity
     */
    private function removeCustomFields(UninstallContext $uninstallContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'loyalty_engage_fields'));

        $customFieldSets = $customFieldSetRepository->search($criteria, $uninstallContext->getContext());

        if ($customFieldSets->count() === 0) {
            return;
        }

        // ✅ Get the IDs and wrap each in an array with the 'id' key
        $ids = [];
        foreach ($customFieldSets->getElements() as $customFieldSet) {
            $ids[] = ['id' => $customFieldSet->getId()];
        }

        // ✅ Double check: $ids must be a non-associative array of arrays
        if (!empty($ids)) {
            $customFieldSetRepository->delete($ids, $uninstallContext->getContext());
        }
    }
}