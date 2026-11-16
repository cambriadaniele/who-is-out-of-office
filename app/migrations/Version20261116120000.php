<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20261116120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'per-year leave allowance overrides (fork: vacation days count in the year they are taken)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE user_leave_allowance (
                user_id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
                year SMALLINT NOT NULL,
                days INT NOT NULL,
                PRIMARY KEY(user_id, year),
                CONSTRAINT fk_user_leave_allowance_user FOREIGN KEY (user_id) REFERENCES `user` (id) ON DELETE CASCADE
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE user_leave_allowance');
    }
}
