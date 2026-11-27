<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Images d'événement : `event.image_url` (tableau PHP sérialisé, type `array`
 * déprécié en DBAL 3 et supprimé en DBAL 4) est remplacée par `event.images`
 * (JSON, liste de clés S3, 3 au plus — limite applicative).
 *
 * Pas de reprise de données : `image_url` n'a jamais été alimentée par l'API
 * (toujours `a:0:{}`, cf. open-questions Q-27).
 */
final class Version20261127122032 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'event.image_url (array PHP) remplacée par event.images (JSON, clés S3)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE event ADD images JSON DEFAULT '[]' NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event DROP image_url
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE event ADD image_url TEXT DEFAULT 'a:0:{}' NOT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event ALTER image_url DROP DEFAULT
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN event.image_url IS '(DC2Type:array)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event DROP images
        SQL);
    }
}
