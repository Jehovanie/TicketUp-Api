<?php

namespace App\Tests\Integration\Command;

use App\Tests\Support\OrderTestCase;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `app:order:expire` : planifiée chaque minute, elle rend les places des
 * commandes non payées à temps (design-orders.md § 3.5).
 */
final class ExpireOrdersCommandTest extends OrderTestCase
{
    public function testLaCommandeExpireLesCommandesEchuesEtRendLesPlaces(): void
    {
        $event = $this->data->event();
        $ticketType = $this->data->ticketType($event);
        $echue = $this->placeOrder($this->data->user(), $event, $ticketType, 2);
        $recente = $this->placeOrder($this->data->user(), $event, $ticketType, 1);

        // Simule l'écoulement du délai pour la première commande seulement.
        $this->db()->executeStatement(
            'UPDATE ticket_order SET expires_at = ? WHERE id = ?',
            [(new \DateTimeImmutable('-1 minute'))->format('Y-m-d H:i:s'), $echue->getId()]
        );

        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/\b1\b/', $tester->getDisplay(), 'La sortie indique le nombre de commandes expirées.');
        self::assertSame('expired', $this->orderStatus($echue));
        self::assertSame('pending', $this->orderStatus($recente));
        self::assertSame(1, $this->reserved($ticketType));
    }

    public function testSansCommandeEchueLaCommandeNeFaitRien(): void
    {
        $tester = $this->runCommand();

        $tester->assertCommandIsSuccessful();
        self::assertMatchesRegularExpression('/\b0\b/', $tester->getDisplay());
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:order:expire'));
        $tester->execute([]);

        return $tester;
    }
}
