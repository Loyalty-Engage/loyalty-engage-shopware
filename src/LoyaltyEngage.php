<?php declare(strict_types=1);

namespace LoyaltyEngage;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
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
        
        // Ensure custom field labels are set correctly after install
        $this->updateCustomFieldLabels($installContext->getContext());
        
        // Also update custom field set labels
        $this->updateCustomFieldSetLabels($installContext->getContext());
    }

    /**
     * @param UpdateContext $updateContext
     */
    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        // Update custom field labels on plugin update
        $this->updateCustomFieldLabels($updateContext->getContext());
        $this->updateCustomFieldSetLabels($updateContext->getContext());
    }

    /**
     * @param ActivateContext $activateContext
     */
    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);

        // Update custom field labels on plugin activation
        $this->updateCustomFieldLabels($activateContext->getContext());
        $this->updateCustomFieldSetLabels($activateContext->getContext());
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
     * Add custom fields to order and customer entities
     */
    private function addCustomFields(InstallContext $installContext): void
    {
        $this->addOrderCustomFields($installContext);
        $this->addCustomerCustomFields($installContext);
    }

    /**
     * Add custom fields to order entity
     */
    private function addOrderCustomFields(InstallContext $installContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        // Check if custom field set already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'loyalty_engage_fields'));
        $customFieldSets = $customFieldSetRepository->search($criteria, $installContext->getContext());

        if ($customFieldSets->count() > 0) {
            return;
        }

        // Create custom field set for orders
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
     * Add custom fields to customer entity for loyalty data
     */
    private function addCustomerCustomFields(InstallContext $installContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        // Check if custom field set already exists
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'loyalty_engage_customer_fields'));
        $customFieldSets = $customFieldSetRepository->search($criteria, $installContext->getContext());

        if ($customFieldSets->count() > 0) {
            return;
        }

        // Create custom field set for customers
        $customFieldSetRepository->create([
            [
                'name' => 'loyalty_engage_customer_fields',
                'config' => [
                    'label' => [
                        'en-GB' => 'Loyalty Engage Customer Data',
                        'de-DE' => 'Loyalty Engage Kundendaten',
                    ],
                ],
                'relations' => [
                    [
                        'entityName' => 'customer',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => 'le_current_tier',
                        'type' => 'text',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Current Tier',
                                'de-DE' => 'Aktuelle Stufe',
                            ],
                            'customFieldPosition' => 1,
                        ],
                    ],
                    [
                        'name' => 'le_points',
                        'type' => 'int',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Loyalty Points',
                                'de-DE' => 'Treuepunkte',
                            ],
                            'customFieldPosition' => 2,
                        ],
                    ],
                    [
                        'name' => 'le_available_coins',
                        'type' => 'int',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Available Coins',
                                'de-DE' => 'Verfügbare Münzen',
                            ],
                            'customFieldPosition' => 3,
                        ],
                    ],
                    [
                        'name' => 'le_next_tier',
                        'type' => 'text',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Next Tier',
                                'de-DE' => 'Nächste Stufe',
                            ],
                            'customFieldPosition' => 4,
                        ],
                    ],
                    [
                        'name' => 'le_points_to_next_tier',
                        'type' => 'int',
                        'config' => [
                            'label' => [
                                'en-GB' => 'Points to Next Tier',
                                'de-DE' => 'Punkte bis zur nächsten Stufe',
                            ],
                            'customFieldPosition' => 5,
                        ],
                    ],
                ],
            ],
        ], $installContext->getContext());
    }

    /**
     * Update custom field labels for existing fields
     */
    private function updateCustomFieldLabels($context): void
    {
        $customFieldRepository = $this->container->get('custom_field.repository');

        // Define the labels for each custom field
        $fieldLabels = [
            'le_current_tier' => [
                'en-GB' => 'Current Tier',
                'de-DE' => 'Aktuelle Stufe',
            ],
            'le_points' => [
                'en-GB' => 'Loyalty Points',
                'de-DE' => 'Treuepunkte',
            ],
            'le_available_coins' => [
                'en-GB' => 'Available Coins',
                'de-DE' => 'Verfügbare Münzen',
            ],
            'le_next_tier' => [
                'en-GB' => 'Next Tier',
                'de-DE' => 'Nächste Stufe',
            ],
            'le_points_to_next_tier' => [
                'en-GB' => 'Points to Next Tier',
                'de-DE' => 'Punkte bis zur nächsten Stufe',
            ],
            'loyalty_order_place' => [
                'en-GB' => 'Loyalty Order Placed',
                'de-DE' => 'Loyalty Bestellung Platziert',
            ],
            'loyalty_order_place_retrieve' => [
                'en-GB' => 'Loyalty Order Retrieve Count',
                'de-DE' => 'Loyalty Bestellung Abrufzähler',
            ],
        ];

        foreach ($fieldLabels as $fieldName => $labels) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', $fieldName));
            $customFields = $customFieldRepository->search($criteria, $context);

            if ($customFields->count() > 0) {
                $customField = $customFields->first();
                $config = $customField->getConfig() ?? [];
                $config['label'] = $labels;

                $customFieldRepository->update([
                    [
                        'id' => $customField->getId(),
                        'config' => $config,
                    ],
                ], $context);
            }
        }
    }

    /**
     * Update custom field set labels for existing sets
     */
    private function updateCustomFieldSetLabels($context): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        // Define the labels for each custom field set
        $setLabels = [
            'loyalty_engage_fields' => [
                'en-GB' => 'Loyalty Engage Fields',
                'de-DE' => 'Loyalty Engage Felder',
            ],
            'loyalty_engage_customer_fields' => [
                'en-GB' => 'Loyalty Engage Customer Data',
                'de-DE' => 'Loyalty Engage Kundendaten',
            ],
        ];

        foreach ($setLabels as $setName => $labels) {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', $setName));
            $customFieldSets = $customFieldSetRepository->search($criteria, $context);

            if ($customFieldSets->count() > 0) {
                $customFieldSet = $customFieldSets->first();
                $config = $customFieldSet->getConfig() ?? [];
                $config['label'] = $labels;

                $customFieldSetRepository->update([
                    [
                        'id' => $customFieldSet->getId(),
                        'config' => $config,
                    ],
                ], $context);
            }
        }
    }

    /**
     * Remove custom fields from order and customer entities
     */
    private function removeCustomFields(UninstallContext $uninstallContext): void
    {
        $customFieldSetRepository = $this->container->get('custom_field_set.repository');

        // Remove order custom fields
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'loyalty_engage_fields'));
        $customFieldSets = $customFieldSetRepository->search($criteria, $uninstallContext->getContext());

        $ids = [];
        foreach ($customFieldSets->getElements() as $customFieldSet) {
            $ids[] = ['id' => $customFieldSet->getId()];
        }

        if (!empty($ids)) {
            $customFieldSetRepository->delete($ids, $uninstallContext->getContext());
        }

        // Remove customer custom fields
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', 'loyalty_engage_customer_fields'));
        $customFieldSets = $customFieldSetRepository->search($criteria, $uninstallContext->getContext());

        $ids = [];
        foreach ($customFieldSets->getElements() as $customFieldSet) {
            $ids[] = ['id' => $customFieldSet->getId()];
        }

        if (!empty($ids)) {
            $customFieldSetRepository->delete($ids, $uninstallContext->getContext());
        }
    }
}
