<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
/**
 * Index de `refresh_tokens` : recherche des jetons d'un utilisateur
 * (déconnexion globale, suppression de compte) et purge des jetons expirés
 * (`gesdinet:jwt:clear`). Voir open-questions.md Q-14.
 */
final class Version20261120155745 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Index refresh_tokens (username, valid)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_refresh_token_username ON refresh_tokens (username)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_refresh_token_valid ON refresh_tokens (valid)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            DROP INDEX idx_refresh_token_username
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX idx_refresh_token_valid
        SQL);
    }
}
