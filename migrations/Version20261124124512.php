<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Comptes mobile money d'encaissement des organisations (MVola, Orange Money,
 * Airtel Money) : un par opérateur et par organisation.
 *
 * Les organisations existantes n'ont aucun compte : à renseigner avant d'ouvrir
 * la vente de billets (voir docs/database/open-questions.md Q-35).
 */
final class Version20261124124512 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Table organizer_payment_method (mobile money des organisations)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE organizer_payment_method (id SERIAL NOT NULL, organizer_id INT NOT NULL, provider VARCHAR(20) NOT NULL, phone_number VARCHAR(20) NOT NULL, account_name VARCHAR(100) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_5F76DEE2876C4DDA ON organizer_payment_method (organizer_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_payment_method_organizer_provider ON organizer_payment_method (organizer_id, provider)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN organizer_payment_method.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN organizer_payment_method.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_payment_method ADD CONSTRAINT chk_payment_method_provider CHECK (provider IN ('mvola', 'orange_money', 'airtel_money'))
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_payment_method ADD CONSTRAINT chk_payment_method_phone CHECK (phone_number ~ '^\+261[0-9]{9}$')
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_payment_method ADD CONSTRAINT FK_5F76DEE2876C4DDA FOREIGN KEY (organizer_id) REFERENCES organizer (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_payment_method DROP CONSTRAINT FK_5F76DEE2876C4DDA
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE organizer_payment_method
        SQL);
    }
}
