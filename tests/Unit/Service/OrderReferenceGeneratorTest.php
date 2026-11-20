<?php

namespace App\Tests\Unit\Service;

use App\Service\Order\OrderReferenceGenerator;
use PHPUnit\Framework\TestCase;

/**
 * Référence publique d'une commande (design-orders.md § 3.1) : c'est elle qui
 * apparaît dans les URL et au service client, jamais l'identifiant séquentiel.
 */
final class OrderReferenceGeneratorTest extends TestCase
{
    public function testFormatLisibleSansCaracteresAmbigus(): void
    {
        $reference = (new OrderReferenceGenerator())->generate();

        // « TU- » + 8 caractères, sans I, O, 0 ni 1 (confusions à la lecture / dictée).
        self::assertMatchesRegularExpression('/^TU-[A-HJ-NP-Z2-9]{8}$/', $reference);
        self::assertLessThanOrEqual(20, strlen($reference), 'La colonne reference fait 20 caractères.');
    }

    public function testLesReferencesNeSeSuiventPas(): void
    {
        $generator = new OrderReferenceGenerator();
        $references = [];

        for ($i = 0; $i < 1000; $i++) {
            $references[] = $generator->generate();
        }

        self::assertCount(1000, array_unique($references));
    }
}
