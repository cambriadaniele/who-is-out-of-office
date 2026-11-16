<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20261116200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'school holidays shown in the calendar (fork)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE school_holiday (
                id CHAR(36) NOT NULL COMMENT '(DC2Type:uuid)',
                group_name VARCHAR(100) NOT NULL,
                name VARCHAR(150) NOT NULL,
                start_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                end_date DATE NOT NULL COMMENT '(DC2Type:date_immutable)',
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_school_holiday_dates (start_date, end_date),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE school_holiday');
    }
}
