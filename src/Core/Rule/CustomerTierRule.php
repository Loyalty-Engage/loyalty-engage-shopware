<?php declare(strict_types=1);

namespace LoyaltyEngage\Core\Rule;

use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

class CustomerTierRule extends Rule
{
    final public const RULE_NAME = 'loyaltyEngageCustomerTier';

    /**
     * @var string
     */
    protected string $operator;

    /**
     * @var string|null
     */
    protected ?string $tier;

    public function __construct(string $operator = Rule::OPERATOR_EQ, ?string $tier = null)
    {
        parent::__construct();
        $this->operator = $operator;
        $this->tier = $tier;
    }

    public function getName(): string
    {
        return self::RULE_NAME;
    }

    public function match(RuleScope $scope): bool
    {
        if (!$scope instanceof CheckoutRuleScope) {
            return false;
        }

        if (!$customer = $scope->getSalesChannelContext()->getCustomer()) {
            return false;
        }

        $customerTier = null;
        if ($customer->getCustomFields()) {
            $customerTier = $customer->getCustomFields()['le_current_tier'] ?? null;
        }

        if ($customerTier === null) {
            return RuleComparison::isNegativeOperator($this->operator);
        }

        return RuleComparison::string($customerTier, $this->tier ?? '', $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'operator' => RuleConstraints::stringOperators(false),
            'tier' => RuleConstraints::string(),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_STRING, false)
            ->stringField('tier');
    }
}
