<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Geplante Arbeitszeit pro Auftrag (job.planned_minutes) und Einstellungen der
 * Anwendung (app_setting), z. B. ob parallel eingeplant werden darf. Ohne
 * Eintrag in app_setting bleibt das Verhalten unveraendert (parallel erlaubt).
 */
final class Version20261113090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Geplante Arbeitszeit am Auftrag, Tabelle app_setting';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job ADD planned_minutes INT DEFAULT NULL');
        $this->addSql('CREATE TABLE app_setting (name VARCHAR(64) NOT NULL, value JSON NOT NULL, PRIMARY KEY (name)) DEFAULT CHARACTER SET utf8mb4');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE app_setting');
        $this->addSql('ALTER TABLE job DROP planned_minutes');
    }
}
