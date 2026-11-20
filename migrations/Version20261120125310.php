<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Commande de billets : tables `ticket_order`, `order_item`, `ticket` et compteur
 * `ticket_type.quantite_reservee`.
 *
 * Généré par `doctrine:migrations:diff`, complété à la main par les contraintes
 * CHECK : Doctrine ne sait ni les décrire ni les relire, il ne les supprimera
 * donc pas lors des diffs suivants. Ce sont les garde-fous de dernier recours
 * si un code contourne `OrderService` (voir tests/Integration/Order/OrderSchemaTest).
 *
 * @see docs/database/design-orders.md
 */
final class Version20261120125310 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Commande de billets : ticket_order, order_item, ticket, ticket_type.quantite_reservee';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE order_item (id SERIAL NOT NULL, order_id INT NOT NULL, ticket_type_id INT NOT NULL, ticket_type_name VARCHAR(50) NOT NULL, unit_price INT NOT NULL, quantity INT NOT NULL, line_total INT NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_52EA1F098D9F6D38 ON order_item (order_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_item_ticket_type ON order_item (ticket_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_item_order_type ON order_item (order_id, ticket_type_id)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN order_item.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket (id SERIAL NOT NULL, order_item_id INT NOT NULL, code VARCHAR(32) NOT NULL, status VARCHAR(20) NOT NULL, holder_name VARCHAR(100) DEFAULT NULL, used_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_ticket_order_item ON ticket (order_item_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_ticket_code ON ticket (code)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket.used_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            CREATE TABLE ticket_order (id SERIAL NOT NULL, user_id INT DEFAULT NULL, event_id INT NOT NULL, reference VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, total_amount INT NOT NULL, currency CHAR(3) NOT NULL, buyer_email VARCHAR(180) NOT NULL, buyer_firstname VARCHAR(50) NOT NULL, buyer_lastname VARCHAR(50) NOT NULL, buyer_phone VARCHAR(30) DEFAULT NULL, idempotency_key VARCHAR(64) DEFAULT NULL, expires_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, paid_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, cancelled_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, updated_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY(id))
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_DD19F013A76ED395 ON ticket_order (user_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_DD19F01371F7E88B ON ticket_order (event_id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_order_user_created ON ticket_order (user_id, created_at)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_order_event_status ON ticket_order (event_id, status)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX idx_order_pending_expiry ON ticket_order (expires_at) WHERE ((status)::text = 'pending'::text)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_order_reference ON ticket_order (reference)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE UNIQUE INDEX uniq_order_idempotency ON ticket_order (user_id, idempotency_key)
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_order.expires_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_order.paid_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_order.cancelled_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_order.created_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            COMMENT ON COLUMN ticket_order.updated_at IS '(DC2Type:datetime_immutable)'
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F098D9F6D38 FOREIGN KEY (order_id) REFERENCES ticket_order (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item ADD CONSTRAINT FK_52EA1F09C980D5C1 FOREIGN KEY (ticket_type_id) REFERENCES ticket_type (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket ADD CONSTRAINT FK_97A0ADA3E415FB15 FOREIGN KEY (order_item_id) REFERENCES order_item (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_order ADD CONSTRAINT FK_DD19F013A76ED395 FOREIGN KEY (user_id) REFERENCES "user" (id) ON DELETE SET NULL NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_order ADD CONSTRAINT FK_DD19F01371F7E88B FOREIGN KEY (event_id) REFERENCES event (id) ON DELETE RESTRICT NOT DEFERRABLE INITIALLY IMMEDIATE
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_type ADD quantite_reservee INT DEFAULT 0 NOT NULL
        SQL);

        // ---- Contraintes CHECK (ajoutées à la main) ----
        $checks = [
            // On ne vend jamais plus que le quota, et un compteur ne passe pas sous zéro.
            'ticket_type' => [
                'chk_ticket_type_reservee' => 'quantite_reservee >= 0 AND quantite_reservee <= quantite_max',
            ],
            'ticket_order' => [
                'chk_order_status' => "status IN ('pending', 'paid', 'cancelled', 'expired', 'refunded')",
                'chk_order_total' => 'total_amount >= 0',
                // Une commande en attente bloque des places : elle doit avoir une échéance.
                'chk_order_pending' => "status <> 'pending' OR expires_at IS NOT NULL",
            ],
            'order_item' => [
                'chk_item_price' => 'unit_price >= 0',
                'chk_item_quantity' => 'quantity > 0',
                'chk_item_total' => 'line_total = unit_price * quantity',
            ],
            'ticket' => [
                'chk_ticket_status' => "status IN ('valid', 'used', 'cancelled')",
                'chk_ticket_used' => "status <> 'used' OR used_at IS NOT NULL",
            ],
        ];

        foreach ($checks as $table => $constraints) {
            foreach ($constraints as $name => $expression) {
                $this->addSql(sprintf('ALTER TABLE %s ADD CONSTRAINT %s CHECK (%s)', $table, $name, $expression));
            }
        }
    }

    public function down(Schema $schema): void
    {
        // `CREATE SCHEMA public` généré par Doctrine retiré : il fait échouer le
        // `down()` (le schéma existe toujours). Les CHECK des tables supprimées
        // partent avec elles ; seul celui de `ticket_type` est à retirer.
        $this->addSql('ALTER TABLE ticket_type DROP CONSTRAINT chk_ticket_type_reservee');
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F098D9F6D38
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item DROP CONSTRAINT FK_52EA1F09C980D5C1
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket DROP CONSTRAINT FK_97A0ADA3E415FB15
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_order DROP CONSTRAINT FK_DD19F013A76ED395
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_order DROP CONSTRAINT FK_DD19F01371F7E88B
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE order_item
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE ticket
        SQL);
        $this->addSql(<<<'SQL'
            DROP TABLE ticket_order
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE ticket_type DROP quantite_reservee
        SQL);
    }
}
