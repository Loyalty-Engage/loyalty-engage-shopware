<?php declare(strict_types=1);

namespace LoyaltyEngage\Core\Rule;

use Shopware\Core\Checkout\CheckoutRuleScope;
use Shopware\Core\Framework\Rule\Rule;
use Shopware\Core\Framework\Rule\RuleComparison;
use Shopware\Core\Framework\Rule\RuleConfig;
use Shopware\Core\Framework\Rule\RuleConstraints;
use Shopware\Core\Framework\Rule\RuleScope;

class CustomerPointsRule extends Rule
{
    final public const RULE_NAME = 'loyaltyEngageCustomerPoints';

    /**
     * @var string
     */
    protected string $operator;

    /**
     * @var int|null
     */
    protected ?int $points;

    public function __construct(string $operator = Rule::OPERATOR_EQ, ?int $points = null)
    {
        parent::__construct();
        $this->operator = $operator;
        $this->points = $points;
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

        $customerPoints = null;
        if ($customer->getCustomFields()) {
            $customerPoints = $customer->getCustomFields()['le_points'] ?? null;
        }

        if ($customerPoints === null) {
            return RuleComparison::isNegativeOperator($this->operator);
        }

        return RuleComparison::numeric((float) $customerPoints, (float) ($this->points ?? 0), $this->operator);
    }

    public function getConstraints(): array
    {
        return [
            'operator' => RuleConstraints::numericOperators(false),
            'points' => RuleConstraints::int(),
        ];
    }

    public function getConfig(): RuleConfig
    {
        return (new RuleConfig())
            ->operatorSet(RuleConfig::OPERATOR_SET_NUMBER, false)
            ->intField('points');
    }
}
