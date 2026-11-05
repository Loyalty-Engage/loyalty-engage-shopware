<?php declare(strict_types=1);

// php tests/personalizations-smoke.php /path/to/shopware/vendor/autoload.php
$loader = require $argv[1];
$loader->addPsr4('LoyaltyEngage\\', dirname(__DIR__) . '/src/');

use LoyaltyEngage\Service\PersonalizationConfig;
use LoyaltyEngage\Service\PersonalizationContext;
use LoyaltyEngage\Service\LoyaltyShopConfig;
use LoyaltyEngage\Subscriber\PersonalizationConfigSubscriber;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Core\System\SystemConfig\Event\BeforeSystemConfigChangedEvent;

function checkPersonalization(bool $condition, string $label): void
{
    if (!$condition) { throw new RuntimeException($label); }
}
function rejectPersonalization(callable $test): void
{
    try { $test(); } catch (InvalidArgumentException) { return; }
    throw new RuntimeException('Invalid configuration accepted');
}
$uuid = '804bd81b-a77e-42ee-a70c-53e62eadf4ae';
checkPersonalization(PersonalizationConfig::tenant(strtoupper($uuid)) === $uuid, 'UUID normalized');
$snippet = '<script type="module" async src="https://le-cloud.app/f/t/' . $uuid . '.js"></script>';
checkPersonalization(PersonalizationConfig::tenant($snippet) === $uuid, 'Official snippet normalized');
$bootstrap = file_get_contents(__DIR__ . '/fixtures/personalization-loader.html');
checkPersonalization(PersonalizationConfig::tenant($bootstrap) === '4e0e4abb-50e1-4953-b528-8dc6d571272e', 'Official inline bootstrap normalized');
rejectPersonalization(static fn () => PersonalizationConfig::tenant(str_replace('le-cloud.app', 'evil.example', $bootstrap)));
rejectPersonalization(static fn () => PersonalizationConfig::tenant(str_replace('f.async=!0;', 'f.async=!0;alert(1);', $bootstrap)));
rejectPersonalization(static fn () => PersonalizationConfig::tenant(str_replace('<script type=', '<script onload="alert(1)" type=', $bootstrap)));
foreach ([
    '<script>alert(1)</script>',
    str_replace('le-cloud.app', 'evil.example', $snippet),
    str_replace('https://', 'http://', $snippet),
    str_replace(' async', ' onload="alert(1)"', $snippet),
    $snippet . '<img src=x onerror=alert(1)>',
    $snippet . $snippet,
] as $invalid) { rejectPersonalization(static fn () => PersonalizationConfig::tenant($invalid)); }
$rule = ['key' => 'pdp-punten', 'public_id' => 'bp-12', 'enabled' => true, 'placement' => 'manual',
    'page_scope' => 'product', 'pages' => [], 'personalized' => false, 'use_cart_value' => false];
checkPersonalization(PersonalizationConfig::normalizeRules([$rule]) == [$rule], 'Rule normalized');
rejectPersonalization(static fn () => PersonalizationConfig::normalizeRules([$rule, $rule]));
rejectPersonalization(static fn () => PersonalizationConfig::normalizeRules([array_replace($rule, ['key' => '<script>'])]));
rejectPersonalization(static fn () => PersonalizationConfig::normalizeRules([array_replace($rule, ['enabled' => 'false'])]));
rejectPersonalization(static fn () => PersonalizationConfig::normalizeRules([array_replace($rule, ['pages' => ['not-a-page']])]));
$subscriber = new PersonalizationConfigSubscriber();
$event = new BeforeSystemConfigChangedEvent('LoyaltyEngage.config.personalizationTenant', $snippet, null);
$subscriber->validate($event);
checkPersonalization($event->getValue() === $uuid, 'Only UUID persisted');
$inlineEvent = new BeforeSystemConfigChangedEvent('LoyaltyEngage.config.personalizationTenant', $bootstrap, null);
$subscriber->validate($inlineEvent);
checkPersonalization($inlineEvent->getValue() === '4e0e4abb-50e1-4953-b528-8dc6d571272e', 'Bootstrap persisted as UUID only');
$inherit = new BeforeSystemConfigChangedEvent('LoyaltyEngage.config.personalizationRules', null, 'channel');
$subscriber->validate($inherit);
checkPersonalization($inherit->getValue() === null, 'Inheritance preserved');
$source = new class extends SystemConfigService {
    public array $values = [];
    public function __construct() {}
    public function get(string $key, ?string $salesChannelId = null) { return $this->values[$salesChannelId ?? 'global'][$key] ?? null; }
};
$source->values['global'] = ['LoyaltyEngage.config.personalizationTenant' => $uuid, 'LoyaltyEngage.config.personalizationRules' => [$rule]];
$config = new PersonalizationConfig(new LoyaltyShopConfig($source));
checkPersonalization(count($config->storefront('channel')['rules']) === 1, 'Global fallback');
$source->values['disabled']['LoyaltyEngage.config.personalizationRules'] = [];
checkPersonalization($config->storefront('disabled')['rules'] === [], 'Channel can disable inherited rules');
$source->values['invalid']['LoyaltyEngage.config.personalizationTenant'] = 'invalid';
checkPersonalization($config->storefront('invalid')['rules'] === [], 'Bad legacy configuration fails closed');
checkPersonalization(PersonalizationContext::identifier(' Client@Example.com ', true) === hash('sha256', 'client@example.com'), 'Normalized SHA-256 identifier');
checkPersonalization(PersonalizationContext::identifier('client@example.com', false) === null, 'Guests have no identifier');
checkPersonalization(PersonalizationContext::convert(120, 1.2, 1) === 100.0, 'Organization currency conversion');
checkPersonalization(PersonalizationContext::convert(120, 0, 1) === null, 'Invalid currency factor omitted');
checkPersonalization(PersonalizationContext::convert(INF, 1, 1) === null, 'Invalid amount omitted');
checkPersonalization(PersonalizationContext::convert(1.0e308, 0.0001, 1) === null, 'Currency overflow omitted');
try {
    $subscriber->validate(new BeforeSystemConfigChangedEvent('LoyaltyEngage.config.personalizationTenant', '<script>alert(1)</script>', null));
    throw new RuntimeException('Invalid tenant accepted by settings');
} catch (Symfony\Component\HttpKernel\Exception\BadRequestHttpException $exception) {
    checkPersonalization($exception->getStatusCode() === 400, 'Invalid settings return a client error, not HTTP 500');
}
echo "Personalization configuration and context smoke checks passed\n";
