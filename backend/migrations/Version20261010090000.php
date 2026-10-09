<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Die geplante Arbeitszeit entfaellt als fachliche Funktion.
 *
 * Entfernt werden nur job.planned_minutes und job.original_planned_minutes –
 * beide wurden ausschliesslich fuer Soll-Zeit, Verlaengerung (F5) und
 * Ueberschreitungswarnung (F6) verwendet. Termine (starts_at/ends_at), alle
 * Auftragsdaten und die Ist-Zeit (time_entry) bleiben unveraendert.
 */
final class Version20261010090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Geplante Arbeitszeit entfernen (job.planned_minutes, job.original_planned_minutes)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP planned_minutes, DROP original_planned_minutes');
    }

    public function down(Schema $schema): void
    {
        // Die geloeschten Planwerte lassen sich nicht wiederherstellen; die Spalten kommen leer zurueck.
        $this->addSql('ALTER TABLE job ADD planned_minutes INT DEFAULT NULL, ADD original_planned_minutes INT DEFAULT NULL');
    }
}
