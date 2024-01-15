<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Importers;

use Iterator;

interface ImporterInterface
{
  public function preImport(): void;

  public function import(Iterator $records, &$output, int $total): int;

  public function postImport(): void;
}
