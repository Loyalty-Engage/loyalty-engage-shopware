<?php declare(strict_types=1);

namespace LoyaltyEngage\Command;

use Doctrine\DBAL\Connection;
use LoyaltyEngage\Service\DeliveryOutbox;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'loyalty-engage:deliveries', description: 'Show loyalty delivery states; optionally retry due, idempotent deliveries')]
class DeliveryOutboxCommand extends Command
{
    public function __construct(private readonly DeliveryOutbox $outbox, private readonly Connection $connection)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('retry-due', null, InputOption::VALUE_NONE, 'Process due deliveries (never uncertain returns)');
        $this->addOption('requeue', null, InputOption::VALUE_REQUIRED, 'Requeue a rejected/exhausted idempotent delivery after fixing its cause');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('requeue') !== null && !$this->outbox->requeue((string) $input->getOption('requeue'))) {
            $output->writeln('Delivery not eligible: only rejected/exhausted idempotent operations can be requeued.');
            return Command::FAILURE;
        }
        if ($input->getOption('retry-due')) {
            $this->outbox->retryDue();
        }
        $rows = $this->connection->fetchAllAssociative('SELECT kind, state, COUNT(*) AS total FROM le_delivery_outbox GROUP BY kind, state ORDER BY kind, state');
        foreach ($rows as $row) {
            $output->writeln(sprintf('%s %s: %d', $row['kind'], $row['state'], $row['total']));
        }
        if ($rows === []) {
            $output->writeln('No loyalty deliveries recorded.');
        }
        foreach ($this->connection->fetchAllAssociative("SELECT id, kind, state, attempts, last_status FROM le_delivery_outbox WHERE state IN ('uncertain', 'rejected', 'exhausted') ORDER BY updated_at DESC LIMIT 20") as $row) {
            $output->writeln(sprintf('%s %s %s attempts=%d status=%s', $row['id'], $row['kind'], $row['state'], $row['attempts'], $row['last_status'] ?? 'unknown'));
        }
        return Command::SUCCESS;
    }
}
