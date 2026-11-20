<?php

namespace App\Tests\Support;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Base des tests qui touchent la base de données.
 *
 * Chaque test part d'une base vide : toutes les tables sont vidées (sauf
 * l'historique des migrations) avant son exécution. On ne s'appuie pas sur les
 * fixtures de démonstration : chaque test crée exactement les données dont il
 * a besoin via {@see TestData}, ce qui le rend lisible et indépendant du jeu
 * de démo.
 *
 * Le schéma de la base de test doit être construit par les **migrations**
 * (et non par `doctrine:schema:create`) : les contraintes `CHECK` et l'index
 * partiel de la commande de billets n'existent que dans la migration.
 *
 *     php bin/console doctrine:database:create --env=test --if-not-exists
 *     php bin/console doctrine:migrations:migrate -n --env=test
 */
abstract class DatabaseTestCase extends KernelTestCase
{
    protected TestData $data;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->purgeDatabase();
        $this->data = new TestData($this->em());
    }

    protected function em(): EntityManagerInterface
    {
        return static::getContainer()->get(EntityManagerInterface::class);
    }

    protected function db(): Connection
    {
        return $this->em()->getConnection();
    }

    /**
     * Lecture directe en SQL : contourne la carte d'identité de Doctrine, donc
     * reflète l'état réellement écrit en base (et reste utilisable même si un
     * test a fermé l'EntityManager en provoquant une erreur).
     */
    protected function fetchInt(string $sql, array $params = []): int
    {
        return (int) $this->db()->fetchOne($sql, $params);
    }

    protected function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->db()->fetchOne($sql, $params);
    }

    private function purgeDatabase(): void
    {
        $connection = $this->db();

        // Garde-fou : ne jamais vider une base qui ne serait pas la base de test
        // (`dbname_suffix: '_test…'` dans config/packages/doctrine.yaml).
        $database = (string) $connection->getDatabase();
        if (!str_contains($database, '_test')) {
            throw new \LogicException(sprintf(
                'Refus de purger la base « %s » : les tests ne tournent que sur une base *_test.',
                $database
            ));
        }

        $tables = $connection->fetchFirstColumn(
            "SELECT tablename FROM pg_tables
             WHERE schemaname = 'public' AND tablename <> 'doctrine_migration_versions'"
        );

        if ($tables === []) {
            return;
        }

        $connection->executeStatement(sprintf(
            'TRUNCATE TABLE %s RESTART IDENTITY CASCADE',
            implode(', ', array_map(static fn (string $table) => '"' . $table . '"', $tables))
        ));
    }
}
