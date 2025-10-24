<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Remove legacy event column from gallery_image table
 * The event functionality is now handled by the gallery_event relation
 */
final class Version20241224000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Remove legacy event column from gallery_image table';
    }

    public function up(Schema $schema): void
    {
        // Remove the legacy event column
        $this->addSql('ALTER TABLE gallery_image DROP COLUMN event');
    }

    public function down(Schema $schema): void
    {
        // Re-add the event column for rollback
        $this->addSql('ALTER TABLE gallery_image ADD event VARCHAR(255) NOT NULL');
    }
}