<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Command;

use Drupal\vneml_migrate_content\Importers\ImporterInterface;
use League\Csv\Exception;
use League\Csv\Reader;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Drupal\Console\Core\Command\Command;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

abstract class AbstractImportCommand extends Command
{
  /**
   * @var string
   */
  private $directory;

  /**
   * @var ImporterInterface
   */
  private $importer;

  /**
   * @var OutputInterface
   */
  private $output;

  /**
   * @var InputInterface
   */
  private $input;

  public function __construct(string $directory, ImporterInterface $importer)
  {
    parent::__construct();

    $this->directory = $directory;
    $this->importer = $importer;
  }

  /**
   * {@inheritdoc}
   */
  protected function initialize(InputInterface $input, OutputInterface $output)
  {
    parent::initialize($input, $output);
    $this->output = $output;
    $this->input = $input;
  }

  /**
   * @param InputInterface $input
   * @param OutputInterface $output
   *
   * @return int
   * @throws Exception
   */
  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $this->output->writeln('Start importing data.');

    $finder = new Finder();

    $finder->files()->in($this->directory)->name('*.csv');

    $this->output->writeln('- Calling pre import method of the importer.');

    $this->importer->preImport();

    foreach ($finder->files() as $file) {
      $this->output->writeln(sprintf('- Processing file: %s.', $file->getBasename()));

      $startTime = time();

      $count = $this->handleFile($file);

      $time = time() - $startTime;

      $this->output->writeln(
        sprintf('- %d records processed in %d minutes and %d seconds.', $count, ($time / 60) % 60, $time % 60)
      );
    }

    $this->output->writeln('- Calling post import method of the importer.');

    $this->importer->postImport();

    $this->output->writeln('Importing data finished.');

    return 0;
  }

  /**
   * @param SplFileInfo $file
   *
   * @return int
   * @throws Exception
   */
  private function handleFile(SplFileInfo $file): int
  {
    $csv = Reader::createFromPath($file->getPathname(), 'r');
    $csv->setHeaderOffset(0);

    $header = array_map(
      function(string $value) {
        return str_replace([' ', '-'], '_', str_replace(['(', ')'], '', strtolower($value)));
      },
      $csv->getHeader()
    );

    return $this->importer->import($csv->getRecords($header));
  }
}
