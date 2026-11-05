<?php declare(strict_types=1);

namespace LoyaltyEngage\Command;

use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loyalty:shop:demo', description: 'Create local loyalty demo products and a CMS page (never run on production).')]
class LoyaltyShopDemoCommand extends Command
{
    public function __construct(
        private readonly EntityRepository $products,
        private readonly EntityRepository $channels,
        private readonly EntityRepository $taxes,
        private readonly EntityRepository $pages,
        private readonly EntityRepository $landingPages,
        private readonly SystemConfigService $config
    ) { parent::__construct(); }

    protected function configure(): void
    {
        $this->addArgument('sales-channel-id', InputArgument::REQUIRED);
        $this->addOption('confirm-local', null, InputOption::VALUE_NONE, 'Explicitly confirm this is a disposable local test environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        if (!$input->getOption('confirm-local')) { $io->error('Pass --confirm-local only in a local test environment.'); return self::FAILURE; }
        $channelId = (string) $input->getArgument('sales-channel-id');
        if (!Uuid::isValid($channelId)) { $io->error('Invalid sales channel ID.'); return self::FAILURE; }
        $context = Context::createDefaultContext();
        $criteria = (new Criteria([$channelId]))->addAssociation('domains');
        $channel = $this->channels->search($criteria, $context)->first();
        $tax = $this->taxes->search((new Criteria())->setLimit(1), $context)->first();
        if (!$channel || !$tax) { $io->error('Sales channel or tax not found.'); return self::FAILURE; }
        $rows = [];
        foreach ([['TOY', 'Demo speeltje', 75, 20], ['SNACK', 'Demo snack', 120, 20], ['EMPTY', 'Demo uitverkochte beloning', 150, 0]] as [$suffix, $name, $coins, $stock]) {
            $sku = 'LE-DEMO-' . $suffix;
            $productId = md5('le-demo-' . $channelId . $sku);
            $existing = $this->products->search((new Criteria([$productId]))->addAssociation('visibilities'), $context)->first();
            $visibilityId = md5($productId . '-visibility-' . $channelId);
            foreach ($existing?->getVisibilities() ?? [] as $visibility) {
                if ($visibility->getSalesChannelId() === $channelId) { $visibilityId = $visibility->getId(); }
            }
            $rows[] = [
                'id' => $productId, 'productNumber' => $sku . '-' . substr($channelId, 0, 6),
                'name' => $name, 'active' => true, 'stock' => $stock, 'isCloseout' => true,
                'taxId' => $tax->getId(), 'price' => [['currencyId' => Defaults::CURRENCY, 'gross' => 5, 'net' => 5 / (1 + $tax->getTaxRate() / 100), 'linked' => true]],
                'visibilities' => [['id' => $visibilityId, 'salesChannelId' => $channelId, 'visibility' => 30]],
                'customFields' => ['le_shop_enabled' => true, 'le_shop_coin_price' => $coins, 'le_shop_type' => 'physical', 'le_shop_regular_purchase' => false],
            ];
        }
        $this->products->upsert($rows, $context);
        $pageId = md5('le-demo-cms-' . $channelId);
        $this->pages->upsert([[
            'id' => $pageId, 'name' => 'Loyalty shop - local demo', 'type' => 'landingpage',
            'sections' => [[
                'id' => md5($pageId . '-section'), 'type' => 'default', 'position' => 0,
                'blocks' => [[
                    'id' => md5($pageId . '-block'), 'type' => 'loyalty-shop', 'position' => 0,
                    'slots' => [['id' => md5($pageId . '-slot'), 'type' => 'loyalty-shop', 'slot' => 'content', 'config' => ['category' => ['source' => 'static', 'value' => null]]]],
                ]],
            ]],
        ]], $context);
        $landingId = md5('le-demo-landing-' . $channelId);
        $this->landingPages->upsert([[
            'id' => $landingId, 'name' => 'Loyalty shop local test', 'active' => true, 'url' => 'loyalty-shop-demo',
            'cmsPageId' => $pageId, 'salesChannels' => [['id' => $channelId]],
        ]], $context);
        foreach (['shopEnabled' => true, 'shopPointsName' => 'Petpunten', 'shopMinimumOrder' => 0, 'shopMaximumRewards' => 2, 'shopTiersEnabled' => false] as $name => $value) {
            $this->config->set('LoyaltyEngage.config.' . $name, $value, $channelId);
        }
        $token = $this->config->getString('LoyaltyEngage.config.shopFeedToken', $channelId);
        if (strlen($token) < 32) {
            $token = bin2hex(random_bytes(24));
            $this->config->set('LoyaltyEngage.config.shopFeedToken', $token, $channelId);
        }
        $io->success('Three demo rewards and a reusable CMS layout created. No external API calls were made.');
        $io->writeln('CMS layout ID: ' . $pageId);
        $io->writeln('Test page: ' . rtrim($channel->getDomains()?->first()?->getUrl() ?? '', '/') . '/loyaltyshop');
        $io->writeln('CMS test page: ' . rtrim($channel->getDomains()?->first()?->getUrl() ?? '', '/') . '/landingPage/' . $landingId);
        $io->writeln('Feed token is stored in plugin configuration. Configure a test Loyalty Engage source before testing real redemption.');
        return self::SUCCESS;
    }
}
