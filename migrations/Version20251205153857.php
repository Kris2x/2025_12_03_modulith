<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251205153857 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_product DROP CONSTRAINT fk_dcf8f98112469de2');
        $this->addSql('ALTER TABLE catalog_product ADD CONSTRAINT FK_DCF8F98112469DE2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) ON DELETE SET NULL NOT DEFERRABLE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE catalog_product DROP CONSTRAINT FK_DCF8F98112469DE2');
        $this->addSql('ALTER TABLE catalog_product ADD CONSTRAINT fk_dcf8f98112469de2 FOREIGN KEY (category_id) REFERENCES catalog_category (id) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
