<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Rollen Sekretaerin und Lehrling, Lehrling am Auftrag, Abwesenheiten und
 * endgueltiges Loeschen von Benutzern ohne Verlust der Historie.
 *
 * Bestehende Daten bleiben erhalten:
 *  - Benutzer mit der Rolle "vorarbeiter" werden zu "sekretaerin" – mit
 *    denselben Rechten (Planung, Auswertung).
 *  - time_entry und work_request bekommen den Namen des Benutzers als Kopie
 *    (user_name) und verweisen nach dem Loeschen eines Benutzers auf NULL,
 *    statt mitgeloescht zu werden (vorher ON DELETE CASCADE).
 *  - job.apprentice_id und die Tabelle absence kommen leer dazu.
 */
final class Version20261016090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Rolle Vorarbeiter -> Sekretaerin, Rolle Lehrling, Lehrling am Auftrag, Abwesenheiten, Historie beim Loeschen von Benutzern';
    }

    public function up(Schema $schema): void
    {
        // Umbenennung der Rolle – die Rechte bleiben dieselben.
        $this->addSql("UPDATE `user` SET role = 'sekretaerin' WHERE role = 'vorarbeiter'");

        // Lehrling als zusaetzliche Unterstuetzung am Auftrag.
        $this->addSql('ALTER TABLE job ADD apprentice_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE job ADD CONSTRAINT FK_FBD8E0F85BE609BD FOREIGN KEY (apprentice_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX idx_job_apprentice ON job (apprentice_id)');

        // Urlaub und persoenliche Termine.
        $this->addSql('CREATE TABLE absence (id INT AUTO_INCREMENT NOT NULL, type VARCHAR(30) NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, all_day TINYINT DEFAULT 0 NOT NULL, note LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME DEFAULT NULL, user_id INT NOT NULL, created_by_id INT DEFAULT NULL, INDEX idx_absence_user_range (user_id, starts_at, ends_at), INDEX IDX_765AE0C9A76ED395 (user_id), INDEX IDX_765AE0C9B03A8386 (created_by_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_765AE0C9A76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE absence ADD CONSTRAINT FK_765AE0C9B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        // Arbeitsabschnitte bleiben beim Loeschen eines Benutzers erhalten (Ist-Zeit-Nachweis).
        $this->addSql('ALTER TABLE time_entry DROP FOREIGN KEY `FK_6E537C0CA76ED395`');
        $this->addSql("ALTER TABLE time_entry ADD user_name VARCHAR(201) DEFAULT '' NOT NULL, CHANGE user_id user_id INT DEFAULT NULL");
        $this->addSql("UPDATE time_entry e INNER JOIN `user` u ON u.id = e.user_id SET e.user_name = TRIM(CONCAT(u.first_name, ' ', u.last_name))");
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');

        // "Brauche Arbeit"-Anfragen bleiben fuer die Statistik erhalten.
        $this->addSql('ALTER TABLE work_request DROP FOREIGN KEY `FK_EE5C37BDA76ED395`');
        $this->addSql("ALTER TABLE work_request ADD user_name VARCHAR(201) DEFAULT '' NOT NULL, CHANGE user_id user_id INT DEFAULT NULL");
        $this->addSql("UPDATE work_request r INNER JOIN `user` u ON u.id = r.user_id SET r.user_name = TRIM(CONCAT(u.first_name, ' ', u.last_name))");
        $this->addSql('ALTER TABLE work_request ADD CONSTRAINT FK_EE5C37BDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        // Ohne Benutzer gespeicherte Historie (nach endgueltigem Loeschen) passt nicht ins alte Schema.
        $orphans = (int) $this->connection->fetchOne('SELECT (SELECT COUNT(*) FROM time_entry WHERE user_id IS NULL) + (SELECT COUNT(*) FROM work_request WHERE user_id IS NULL)');
        $this->abortIf($orphans > 0, sprintf('%d Arbeitsabschnitte/Anfragen gehören zu gelöschten Benutzern – das alte Schema kann sie nicht speichern.', $orphans));

        $lehrlinge = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `user` WHERE role = 'lehrling'");
        $this->abortIf($lehrlinge > 0, sprintf('Es gibt %d Benutzer mit der Rolle „Lehrling“, die das alte Schema nicht kennt. Bitte zuerst eine andere Rolle vergeben.', $lehrlinge));

        $this->addSql('ALTER TABLE work_request DROP FOREIGN KEY FK_EE5C37BDA76ED395');
        $this->addSql('ALTER TABLE work_request DROP user_name, CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE work_request ADD CONSTRAINT `FK_EE5C37BDA76ED395` FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE time_entry DROP FOREIGN KEY FK_6E537C0CA76ED395');
        $this->addSql('ALTER TABLE time_entry DROP user_name, CHANGE user_id user_id INT NOT NULL');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT `FK_6E537C0CA76ED395` FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE absence DROP FOREIGN KEY FK_765AE0C9A76ED395');
        $this->addSql('ALTER TABLE absence DROP FOREIGN KEY FK_765AE0C9B03A8386');
        $this->addSql('DROP TABLE absence');

        $this->addSql('ALTER TABLE job DROP FOREIGN KEY FK_FBD8E0F85BE609BD');
        $this->addSql('DROP INDEX idx_job_apprentice ON job');
        $this->addSql('ALTER TABLE job DROP apprentice_id');

        $this->addSql("UPDATE `user` SET role = 'vorarbeiter' WHERE role = 'sekretaerin'");
    }
}
