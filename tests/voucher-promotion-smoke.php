<?php declare(strict_types=1);

// php tests/voucher-promotion-smoke.php /path/to/shopware/vendor/autoload.php
$loader = require $argv[1];
$loader->addPsr4('LoyaltyEngage\\', dirname(__DIR__) . '/src/');

use LoyaltyEngage\Service\VoucherPromotionConfig;
use LoyaltyEngage\Service\LoyaltyShopService;
use Psr\Log\NullLogger;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Price\Struct\CartPrice;
use Shopware\Core\Checkout\Cart\Rule\CartAmountRule;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenContainerEvent;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\Struct\ArrayEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

function check(bool $ok, string $message): void
{
    if (!$ok) { throw new RuntimeException($message); }
}

$id = str_repeat('a', 32);
$language = str_repeat('b', 32);
$legacy = 'LoyaltyEngage: VOUCHER10';
$response = ['product' => ['title' => 'Tien euro voordeel'], 'discountMinimumOrderValue' => 50.25];
$build = static fn (array $response, array $rules = []) => VoucherPromotionConfig::payload(
    $id, $legacy, 'VOUCHER10', $response, $language, $rules
);
$data = $build($response);
check($data['name'] === 'Tien euro voordeel', 'API title becomes the promotion name');
check(count($data['translations']) === 2, 'Default and current language translations');
check($data['translations'][Defaults::LANGUAGE_SYSTEM]['languageId'] === Defaults::LANGUAGE_SYSTEM, 'Identity also available in fallback language');
check($data['translations'][Defaults::LANGUAGE_SYSTEM]['customFields']['le_voucher_identity'] === VoucherPromotionConfig::identity($legacy), 'Stable SKU identity');
check(VoucherPromotionConfig::identity($legacy) !== VoucherPromotionConfig::identity($legacy . ' [channel]'), 'Managed channel identity stays isolated');
check($build(['product' => ['title' => null]])['name'] === 'VOUCHER10', 'Null title falls back to SKU');
check($build(['product' => ['title' => '  ']])['name'] === 'VOUCHER10', 'Empty title falls back to SKU');
check(mb_strlen($build(['product' => ['title' => str_repeat('x', 300)]])['name']) === 255, 'Title fits Shopware column');
check(!isset($build([])['orderRules']), 'Legacy/bridge response preserves existing rules');
$updated = $build(array_replace_recursive($response, ['product' => ['title' => 'Nieuwe naam'], 'discountMinimumOrderValue' => 75.50]), [VoucherPromotionConfig::ruleId($id)]);
check($updated['orderRules'][0]['id'] === $data['orderRules'][0]['id'], 'Updating minimum reuses the same rule');
check($updated['orderRules'][0]['conditions'][0]['id'] === $data['orderRules'][0]['conditions'][0]['id'], 'Condition updates instead of adding stale conditions');
check($updated['orderRules'][0]['conditions'][0]['value']['amount'] === 75.50, 'Updated minimum');
check($build(['discountMinimumOrderValue' => 0])['orderRules'][0]['conditions'][0]['value']['amount'] === 0.0, 'Zero removes the effective threshold');
foreach ([-1, '50', null, INF, NAN, 100000001] as $invalid) {
    try {
        $build(['discountMinimumOrderValue' => $invalid]);
        throw new RuntimeException('Invalid minimum accepted');
    } catch (UnexpectedValueException $expected) {}
}
try {
    $build($response, [str_repeat('c', 32)]);
    throw new RuntimeException('Manual rule could be bypassed');
} catch (RuntimeException $expected) {
    check(str_contains($expected->getMessage(), 'manual order rules'), 'Unsafe OR combination fails closed');
}

// Evaluate the actual Shopware rule repeatedly, including a cart reduced after applying.
$context = (new ReflectionClass(SalesChannelContext::class))->newInstanceWithoutConstructor();
$cart = new Cart('voucher-test');
$scope = new CartRuleScope($cart, $context);
$condition = $data['orderRules'][0]['conditions'][0];
$rule = new CartAmountRule($condition['value']['operator'], $condition['value']['amount']);
foreach ([[50.24, false], [50.25, true], [80.0, true], [20.0, false]] as [$total, $expected]) {
    $cart->setPrice(new CartPrice($total, $total, $total, new CalculatedTaxCollection(), new TaxRuleCollection(), CartPrice::TAX_STATE_GROSS));
    check($rule->match($scope) === $expected, 'Shopware minimum evaluates cart total ' . $total);
}

class VoucherTestRepository extends EntityRepository
{
    public array $created = [];
    public array $updated = [];
    public array $queries = [];
    public function __construct(public ?Shopware\Core\Checkout\Promotion\PromotionEntity $existing = null) {}
    public function search(Criteria $criteria, Context $context): EntitySearchResult
    {
        $this->queries[] = $criteria;
        return new EntitySearchResult('promotion', $this->existing ? 1 : 0,
            new Shopware\Core\Checkout\Promotion\PromotionCollection($this->existing ? [$this->existing] : []), null, $criteria, $context);
    }
    public function create(array $data, Context $context): EntityWrittenContainerEvent
    {
        $this->created = array_merge($this->created, $data);
        return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
    }
    public function update(array $data, Context $context): EntityWrittenContainerEvent
    {
        $this->updated = array_merge($this->updated, $data);
        return EntityWrittenContainerEvent::createWithWrittenEvents([], $context, []);
    }
}
class VoucherTestContext extends SalesChannelContext
{
    public function __construct() {}
    public function getContext(): Context { return Context::createDefaultContext(); }
    public function getSalesChannelId(): string { return str_repeat('d', 32); }
}
$service = (new ReflectionClass(LoyaltyShopService::class))->newInstanceWithoutConstructor();
(new ReflectionProperty($service, 'logger'))->setValue($service, new NullLogger());
$method = new ReflectionMethod($service, 'findOrCreatePromotion');
$repository = new VoucherTestRepository();
(new ReflectionProperty($service, 'promotionRepository'))->setValue($service, $repository);
$channel = new VoucherTestContext();
$createdId = $method->invoke($service, 'VOUCHER10', 10.0, 'absolute', $channel, false, $response);
check(is_string($createdId) && count($repository->created) === 1, 'New voucher creates one promotion');
check($repository->created[0]['name'] === 'Tien euro voordeel', 'Service forwards API title');
check($repository->created[0]['orderRules'][0]['conditions'][0]['value']['amount'] === 50.25, 'Service persists minimum before code registration');
check($repository->created[0]['discounts'][0]['type'] === 'absolute', 'Absolute discount retained');
$repository->existing = new Shopware\Core\Checkout\Promotion\PromotionEntity();
$repository->existing->setId($id);
$repository->existing->setOrderRules(new Shopware\Core\Content\Rule\RuleCollection());
$repository->existing->setTranslations(new Shopware\Core\Checkout\Promotion\Aggregate\PromotionTranslation\PromotionTranslationCollection());
$existingId = $method->invoke($service, 'VOUCHER10', 10.0, 'absolute', $channel, false, $response);
check($existingId === $id && count($repository->created) === 1, 'Existing promotion reused without duplicate');
check($repository->updated[0]['id'] === $id && $repository->updated[0]['name'] === 'Tien euro voordeel', 'Existing name synchronized');
check(isset($repository->updated[0]['orderRules']), 'Existing promotion receives minimum rule');
$filters = $repository->queries[1]->getFilters()[0]->getQueries();
check($filters[0]->getField() === 'customFields.le_voucher_identity', 'Lookup uses stable identity after rename');
check($filters[1]->getField() === 'name' && $filters[1]->getValue() === $legacy, 'Legacy promotions still discoverable');
echo "Voucher promotion smoke tests passed.\n";
