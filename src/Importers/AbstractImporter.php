<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Importers;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;
use Iterator;
use Symfony\Component\Console\Helper\ProgressBar;

abstract class AbstractImporter implements ImporterInterface
{
  public function preImport(): void
  {
  }

  public function import(Iterator $records, &$output, int $total): int
  {
    $i = 0;

    $progressBar = new ProgressBar($output, $total);
    $progressBar->start();

    foreach ($records as $record) {
      $this->handleRecord($record);
      $progressBar->advance();
      ++$i;
    }

    $progressBar->finish();
    $output->writeln('');

    return $i;
  }

  public function postImport(): void
  {
  }

  abstract protected function handleRecord(array $data): void;

  /**
   * @param string $vocabulary
   * @param string|null $termName
   * @param int|null $parentId
   * @return int
   *
   * @throws EntityStorageException
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  protected function findOrCreateTaxonomyTerm(string $vocabulary, string $termName = null, int $parentId = null): ?int
  {
    if (is_null($termName)) {
      return null;
    }

    $termName = trim($termName);

    if (empty($termName)) {
      return null;
    }

    if (is_null($parentId)) {
      $terms = collect(Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocabulary));
    } else {
      $terms = collect(Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadTree($vocabulary, $parentId));
    }

    if ($terms->isNotEmpty() && ($term = $terms->where('name', $termName)->first())) {
      return (int) $term->tid;
    }

    $term = Term::create(
      [
        'name' => $termName,
        'vid'  => $vocabulary,
      ]
    );

    if (!is_null($parentId)) {
      $term->set('parent', $parentId);
    }

    $term->save();

    return (int) $term->tid->value;
  }
}
