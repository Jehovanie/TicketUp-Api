<?php

namespace App\Command;

use App\Service\Order\OrderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Rend les places des commandes non payées dans le délai imparti.
 *
 * À planifier chaque minute (cron, Symfony Scheduler…) :
 *
 *     * * * * * php /chemin/vers/api/bin/console app:order:expire --no-interaction
 *
 * Sans cette tâche, les paniers abandonnés finiraient par épuiser le stock.
 * Plusieurs exécutions simultanées sont sans danger (`FOR UPDATE SKIP LOCKED`).
 */
#[AsCommand(
    name: 'app:order:expire',
    description: 'Expire les commandes en attente dont le délai de paiement est dépassé et rend leurs places.',
)]
final class ExpireOrdersCommand extends Command
{
    public function __construct(private readonly OrderService $orderService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $count = $this->orderService->expirePending();

        (new SymfonyStyle($input, $output))->success(sprintf(
            '%d commande%s expirée%s.',
            $count,
            $count > 1 ? 's' : '',
            $count > 1 ? 's' : ''
        ));

        return Command::SUCCESS;
    }
}
