<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Command;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Console\Core\Command\Command;
use Drupal\Core\Entity\EntityStorageException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drupal\Console\Annotations\DrupalCommand (
 *     extension="vneml_migrate_content",
 *     extensionType="module"
 * )
 */
class DeleteLocationsCommand extends Command
{
  protected function configure(): void
  {
    $this->setName('vneml:delete:locations')
      ->setDescription('Delete all locations added by the import.');
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   *
   * @return int
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   * @throws EntityStorageException
   */
  protected function execute(InputInterface $input, OutputInterface $output): int
  {
    $result = Drupal::entityQuery('node')
      ->condition('type', 'locatie')
      ->condition('field_imported', 1)
      ->execute();

    $storageHandler = Drupal::entityTypeManager()->getStorage('node');

    $offset = 0;
    $length = 100;

    while ($nodeIds = array_slice($result, $offset, $length)) {
      $storageHandler->delete($storageHandler->loadMultiple($nodeIds));

      $offset += $length;
    }

    return 0;
  }
}
