<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Aufgaben ohne Termin, Beschreibung, nachvollziehbare Ist-Zeit und
 * Farbschema pro Benutzer.
 *
 * Bestehende Daten bleiben vollstaendig erhalten: Spalten werden nur
 * erweitert (NULL erlaubt) bzw. mit Standardwert ergaenzt.
 */
final class Version20261009150000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Aufgaben ohne Termin/Dauer, Beschreibung, automatisch beendete Arbeitsabschnitte, Theme je Benutzer';
    }

    public function up(Schema $schema): void
    {
        // Termin und geplante Zeit werden optional (To-do ohne Zeitangaben).
        $this->addSql('ALTER TABLE job ADD description LONGTEXT DEFAULT NULL, CHANGE planned_minutes planned_minutes INT DEFAULT NULL, CHANGE original_planned_minutes original_planned_minutes INT DEFAULT NULL, CHANGE starts_at starts_at DATETIME DEFAULT NULL, CHANGE ends_at ends_at DATETIME DEFAULT NULL');

        // Vom System an der Kappungsgrenze beendete Abschnitte kennzeichnen.
        $this->addSql('ALTER TABLE time_entry ADD auto_closed TINYINT DEFAULT 0 NOT NULL');
        $this->addSql('CREATE INDEX idx_entry_job_end ON time_entry (job_id, ended_at)');

        // Hell/Dunkel/System pro Benutzer.
        $this->addSql("ALTER TABLE `user` ADD theme VARCHAR(10) DEFAULT 'system' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // Aufgaben ohne Termin bekaemen sonst ungueltige Werte – sie erhalten
        // als Termin ihren Erstellungszeitpunkt bzw. eine Stunde Plan.
        $this->addSql('UPDATE job SET planned_minutes = 60 WHERE planned_minutes IS NULL');
        $this->addSql('UPDATE job SET original_planned_minutes = planned_minutes WHERE original_planned_minutes IS NULL');
        $this->addSql('UPDATE job SET starts_at = created_at WHERE starts_at IS NULL');
        $this->addSql('UPDATE job SET ends_at = DATE_ADD(starts_at, INTERVAL planned_minutes MINUTE) WHERE ends_at IS NULL');

        $this->addSql('ALTER TABLE `user` DROP theme');
        $this->addSql('DROP INDEX idx_entry_job_end ON time_entry');
        $this->addSql('ALTER TABLE time_entry DROP auto_closed');
        $this->addSql('ALTER TABLE job DROP description, CHANGE planned_minutes planned_minutes INT NOT NULL, CHANGE original_planned_minutes original_planned_minutes INT NOT NULL, CHANGE starts_at starts_at DATETIME NOT NULL, CHANGE ends_at ends_at DATETIME NOT NULL');
    }
}
