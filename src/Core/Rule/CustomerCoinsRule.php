<?php declare(strict_types=1);

namespace LoyaltyEngage\Core\Rule;

use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

class CustomerCoinsRule extends Rule
{
    final public const RULE_NAME = 'loyaltyEngageCustomerCoins';

    /**
     * @var string
     */
    protected string $operator;

    /**
     * @var int|null
     */
    protected ?int $coins;

    public function __construct(string $operator = Rule::OPERATOR_EQ, ?int $coins = null)
    {
        parent::__construct();
        $this->operator = $operator;
        $this->coins = $coins;
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

        $customerCoins = null;
        if ($customer->getCustomFields()) {
            $customerCoins = $customer->getCustomFields()['le_available_coins'] ?? null;
        }

        if ($customerCoins === null) {
            return RuleComparison::isNegativeOperator($this->operator);
        }

        return RuleComparison::numeric((float) $customerCoins, (float) ($this->coins ?? 0), $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'operator' => RuleConstraints::numericOperators(false),
            'coins' => RuleConstraints::int(),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_NUMBER, false)
            ->intField('coins');
    }
}
