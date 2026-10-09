<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261009063137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Grundschema: Benutzer, Arbeiten, Zeiterfassung, Arbeitsanfragen';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE job (id INT AUTO_INCREMENT NOT NULL, title VARCHAR(150) NOT NULL, customer VARCHAR(150) DEFAULT NULL, priority VARCHAR(20) NOT NULL, status VARCHAR(20) NOT NULL, planned_minutes INT NOT NULL, original_planned_minutes INT NOT NULL, starts_at DATETIME NOT NULL, ends_at DATETIME NOT NULL, completed_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, assignee_id INT DEFAULT NULL, INDEX idx_job_schedule (starts_at, ends_at), INDEX idx_job_assignee_status (assignee_id, status), INDEX IDX_FBD8E0F859EC7D60 (assignee_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE time_entry (id INT AUTO_INCREMENT NOT NULL, started_at DATETIME NOT NULL, ended_at DATETIME DEFAULT NULL, user_id INT NOT NULL, job_id INT NOT NULL, INDEX idx_entry_user_end (user_id, ended_at), INDEX IDX_6E537C0CA76ED395 (user_id), INDEX IDX_6E537C0CBE04EA9 (job_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE `user` (id INT AUTO_INCREMENT NOT NULL, username VARCHAR(50) NOT NULL, password VARCHAR(255) NOT NULL, first_name VARCHAR(100) NOT NULL, last_name VARCHAR(100) NOT NULL, role VARCHAR(20) NOT NULL, weekly_hours DOUBLE PRECISION NOT NULL, active TINYINT NOT NULL, created_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_8D93D649F85E0677 (username), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE work_request (id INT AUTO_INCREMENT NOT NULL, needed_at DATETIME NOT NULL, status VARCHAR(20) NOT NULL, created_at DATETIME NOT NULL, user_id INT NOT NULL, INDEX IDX_EE5C37BDA76ED395 (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE job ADD CONSTRAINT FK_FBD8E0F859EC7D60 FOREIGN KEY (assignee_id) REFERENCES `user` (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0CA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE time_entry ADD CONSTRAINT FK_6E537C0CBE04EA9 FOREIGN KEY (job_id) REFERENCES job (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE work_request ADD CONSTRAINT FK_EE5C37BDA76ED395 FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE job DROP FOREIGN KEY FK_FBD8E0F859EC7D60');
        $this->addSql('ALTER TABLE time_entry DROP FOREIGN KEY FK_6E537C0CA76ED395');
        $this->addSql('ALTER TABLE time_entry DROP FOREIGN KEY FK_6E537C0CBE04EA9');
        $this->addSql('ALTER TABLE work_request DROP FOREIGN KEY FK_EE5C37BDA76ED395');
        $this->addSql('DROP TABLE job');
        $this->addSql('DROP TABLE time_entry');
        $this->addSql('DROP TABLE `user`');
        $this->addSql('DROP TABLE work_request');
    }
}
