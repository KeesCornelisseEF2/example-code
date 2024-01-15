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
class ImportEventsCommand extends AbstractImportCommand
{
  public function __construct(ImporterInterface $importer)
  {
    parent::__construct(DRUPAL_ROOT . '/../exports/csv/ndtrc_evenementen', $importer);
  }

  protected function configure(): void
  {
    $this->setName('vneml:import:events')
      ->setDescription('Import events from CSV files.');
  }
}
