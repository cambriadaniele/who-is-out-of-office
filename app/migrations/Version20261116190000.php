<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20261116190000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'per-year vacation day corrections for future leave years (fork)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_leave_year_adjustment (
                id CHAR(36) NOT NULL,
                user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
                year SMALLINT NOT NULL,
                delta INT NOT NULL,
                reason VARCHAR(255) NOT NULL,
                actor_user_id CHAR(36) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                INDEX idx_user_leave_year_adjustment_user_year (user_id, year),
                PRIMARY KEY(id),
                CONSTRAINT fk_user_leave_year_adjustment_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_leave_year_adjustment');
    }
}
