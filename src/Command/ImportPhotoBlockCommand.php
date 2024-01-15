<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Command;

use Drupal\vneml_migrate_content\Importers\ImporterInterface;

/**
 * Drupal\Console\Annotations\DrupalCommand (
 *     extension="vneml_migrate_content",
 *     extensionType="module"
 * )
 */
class ImportPhotoBlockCommand extends AbstractImportCommand
{
  public function __construct(ImporterInterface $importer)
  {
    parent::__construct(DRUPAL_ROOT . '/../exports/csv/foto_block', $importer);
  }

  protected function configure(): void
  {
    $this->setName('vneml:import:photos')
      ->setDescription('Import photo blocks from CSV files.');
  }
}
