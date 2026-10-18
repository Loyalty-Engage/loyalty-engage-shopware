<?php declare(strict_types=1);

namespace LoyaltyEngage\Command;

use LoyaltyEngage\Service\LoyaltyFeedReader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'loyalty:shop:refresh-feed', description: 'Refresh the configured Loyalty Engage assortment feed for one sales channel.')]
class LoyaltyFeedRefreshCommand extends Command
{
    public function __construct(private readonly LoyaltyFeedReader $feed) { parent::__construct(); }
    protected function configure(): void { $this->addArgument('sales-channel-id', InputArgument::REQUIRED); }
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        try {
            $products = $this->feed->products((string) $input->getArgument('sales-channel-id'), true);
            $io->success(sprintf('Feed refreshed: %d rewards.', count($products)));
            return self::SUCCESS;
        } catch (\RuntimeException $exception) {
            $io->error($exception->getMessage());
            return self::FAILURE;
        }
    }
}
