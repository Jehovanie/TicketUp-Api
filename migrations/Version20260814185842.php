<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260814185842 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE TABLE category (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, color VARCHAR(50) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN category.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN category.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE event (id SERIAL NOT NULL, category_id INT DEFAULT NULL, organizer_id INT NOT NULL, location_id INT NOT NULL, title VARCHAR(150) NOT NULL, description TEXT NOT NULL, started_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, end_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, image_url TEXT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, status BOOLEAN NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_3BAE0AA712469DE2 ON event (category_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_3BAE0AA7876C4DDA ON event (organizer_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_3BAE0AA764D218E ON event (location_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN event.started_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN event.end_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN event.image_url IS '(DC2Type:array)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN event.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN event.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE location (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, size INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN location.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN location.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organizer (id SERIAL NOT NULL, name VARCHAR(100) NOT NULL, email VARCHAR(100) NOT NULL, phone VARCHAR(50) DEFAULT NULL, website VARCHAR(100) DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN organizer.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN organizer.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE organizer_membership (id SERIAL NOT NULL, user_id INT NOT NULL, organizer_id INT NOT NULL, role VARCHAR(20) NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E2DB4746A76ED395 ON organizer_membership (user_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_E2DB4746876C4DDA ON organizer_membership (organizer_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_membership_organizer_role ON organizer_membership (organizer_id, role)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_membership_user_organizer ON organizer_membership (user_id, organizer_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN organizer_membership.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN organizer_membership.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE refresh_tokens (id SERIAL NOT NULL, refresh_token VARCHAR(128) NOT NULL, username VARCHAR(255) NOT NULL, valid TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_9BACE7E1C74F2195 ON refresh_tokens (refresh_token)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_type (id SERIAL NOT NULL, event_id INT NOT NULL, name VARCHAR(50) NOT NULL, prix INT NOT NULL, quantite_max INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_BE05421171F7E88B ON ticket_type (event_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_type.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_type.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE "user" (id SERIAL NOT NULL, email VARCHAR(180) NOT NULL, roles JSON NOT NULL, password VARCHAR(255) NOT NULL, firstname VARCHAR(50) NOT NULL, lastname VARCHAR(50) NOT NULL, phone VARCHAR(30) DEFAULT NULL, language VARCHAR(10) DEFAULT NULL, is_active TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX UNIQ_IDENTIFIER_EMAIL ON "user" (email)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN "user".created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN "user".updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA712469DE2 FOREIGN KEY (category_id) REFERENCES category (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA7876C4DDA FOREIGN KEY (organizer_id) REFERENCES organizer (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event ADD CONSTRAINT FK_3BAE0AA764D218E FOREIGN KEY (location_id) REFERENCES location (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_membership ADD CONSTRAINT FK_E2DB4746A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_membership ADD CONSTRAINT FK_E2DB4746876C4DDA FOREIGN KEY (organizer_id) REFERENCES organizer (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_type ADD CONSTRAINT FK_BE05421171F7E88B FOREIGN KEY (event_id) REFERENCES event (id) NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql(<<<'SQL'
            CREATE SCHEMA public
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event DROP CONSTRAINT FK_3BAE0AA712469DE2
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event DROP CONSTRAINT FK_3BAE0AA7876C4DDA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE event DROP CONSTRAINT FK_3BAE0AA764D218E
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_membership DROP CONSTRAINT FK_E2DB4746A76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE organizer_membership DROP CONSTRAINT FK_E2DB4746876C4DDA
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_type DROP CONSTRAINT FK_BE05421171F7E88B
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE category
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE event
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE location
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE organizer
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE organizer_membership
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE refresh_tokens
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE ticket_type
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE "user"
        SQL);
    }
}
