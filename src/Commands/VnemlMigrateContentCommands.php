<?php

namespace Drupal\vneml_migrate_content\Commands;

use Consolidation\OutputFormatters\StructuredData\RowsOfFields;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\taxonomy\Entity\Term;
use Drupal\vneml_migrate_content\DataFormatter;
use Drupal\vneml_migrate_content\Importers\ImporterInterface;
use Drush\Commands\DrushCommands;
use League\Csv\Exception;
use League\Csv\Reader;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\Input;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * A Drush commandfile.
 *
 * In addition to this file, you need a drush.services.yml
 * in root of your module, and a composer.json file that provides the name
 * of the services file to use.
 *
 * See these files for an example of injecting Drupal services:
 *   - http://cgit.drupalcode.org/devel/tree/src/Commands/DevelCommands.php
 *   - http://cgit.drupalcode.org/devel/tree/drush.services.yml
 */
class VnemlMigrateContentCommands extends DrushCommands {

  /**
   * @var string
   */
  private $directory;

  /**
   * @var ImporterInterface
   */
  private $importer;

  /**
   * @var DataFormatter
   */
  private $formatter;

  /**
   * @var Finder
   */
  private $finder;



  public function __construct()
  {
    parent::__construct();

    $this->formatter = new DataFormatter();
    $this->finder = new Finder();

  }

  /**
   * @usage command import pages
   *   Usage description
   *
   * @command vneml:import:pages
   * @aliases vip
   * @throws Exception
   */
  public function pages() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/basispagina';
    $this->importer = \Drupal::service('vneml_migrate_content.page_importer');

    $this->runImporter();

    return 0;
  }

  /**
   * @usage command import pdfpages
   *   Usage description
   *
   * @command vneml:import:pdfpages
   * @aliases vipdf
   * @throws Exception
   */
  public function pdfpages() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/pdf_flipbook';
    $this->importer = \Drupal::service('vneml_migrate_content.pagepdf_importer');

    $this->runImporter();

    return 0;
  }

  /**
   * @usage command import overview-pages
   *   Usage description
   *
   * @command vneml:import:overview-pages
   * @aliases viop
   * @throws Exception
   */
  public function overviewPages() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/resultatenpagina';
    $this->importer = \Drupal::service('vneml_migrate_content.overview_page_importer');

    $this->runImporter();

    return 0;
  }


  /**
   * @usage command import users
   *   Usage description
   *
   * @command vneml:import:users
   * @aliases viu
   * @throws Exception
   */
  public function users() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/users';
    $this->importer = \Drupal::service('vneml_migrate_content.user_importer');

    $this->runImporter();

    return 0;
  }

  /**
   * @usage command import locations
   *   Usage description
   *
   * @command vneml:import:locations
   * @aliases vil
   * @throws Exception
   */
  public function locations() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/ndtrc_locaties';
    $this->importer = \Drupal::service('vneml_migrate_content.location_importer');

    $this->runImporter();

    return 0;
  }

  /**
   * @usage command import term references locations
   *   Usage description
   *
   * @command vneml:import:locations-terms
   * @aliases vilt
   * @throws Exception
   */
  public function locationsTerms() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/ndtrc_locaties';
    $this->importer = \Drupal::service('vneml_migrate_content.location_terms_importer');

    $this->runImporter();

    return 0;
  }


  /**
   * @usage command import events
   *   Usage description
   *
   * @command vneml:import:events
   * @aliases vie
   * @throws Exception
   */
  public function events() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/ndtrc_evenementen';
    $this->importer = \Drupal::service('vneml_migrate_content.event_importer');

    $this->runImporter();

    return 0;
  }

  /**
   * @usage command import Photo Blocks
   *   Usage description
   *
   * @command vneml:import:photo-blocks
   * @aliases vifb
   * @throws Exception
   */
  public function photoBlocks() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/foto_blok';
    $this->importer = \Drupal::service('vneml_migrate_content.photo_block_importer');

    $this->runImporter();

    return 0;
  }


  /**
   * @usage command import Intro Blocks
   *   Usage description
   *
   * @command vneml:import:intro-blocks
   * @aliases viib
   * @throws Exception
   */
  public function introBlocks() {
    $this->directory = DRUPAL_ROOT . '/../exports/csv/intro_block';
    $this->importer = \Drupal::service('vneml_migrate_content.intro_block_importer');

    $this->runImporter();

    return 0;
  }


  /**
   * @usage command import Photo Blocks
   *   Usage description
   *
   * @command vneml:getfront
   * @aliases getf
   * @throws Exception
   */
  public function getFront() {
    $config = \Drupal::config('system.site');
    $front_uri = $config->get('page.front');

    dump($front_uri);

    return 0;
  }

  /**
   * @usage hello world
   *   Usage description
   *
   * @command vneml:custom:length
   * @aliases vcl
   * @throws Exception
   */
  public function makeLength() {
    $this->_module_change_text_field_max_length('node', 'field_search_and_book_link', 355);
  }

  /**
   * @usage rename terms
   *
   * @command vneml:rename:terms
   * @aliases vrt
   * @throws Exception
   */
  public function renamePlaces() {
    $this->renameTerms('plaatsnamen');
  }


  /**
   * @usage command delete events
   *   Usage description
   *
   * @command vneml:delete:events
   * @aliases vde
   */
  public function deleteAllEvents() {
    $result = \Drupal::entityQuery('node')
      ->condition('type', 'evenement')
      ->condition('field_imported', 1)
      ->execute();

    try {
      $this->deleteNodes($result);
    } catch (\Exception $e) {
      $this->output->writeln('Error: ' . $e->getMessage());
    }

    return 0;
  }

  /**
   * @usage command delete locations
   *   Usage description
   *
   * @command vneml:delete:locations
   * @aliases vdl
   */
  public function deleteAllLocations() {
    $result = \Drupal::entityQuery('node')
      ->condition('type', 'locatie')
      ->condition('field_imported', 1)
      ->execute();

    try {
      $this->deleteNodes($result);
    } catch (\Exception $e) {
      $this->output->writeln('Error: ' . $e->getMessage());
    }

    return 0;
  }

  /**
   * @param $result
   * @throws \Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException
   * @throws \Drupal\Component\Plugin\Exception\PluginNotFoundException
   * @throws \Drupal\Core\Entity\EntityStorageException
   */
  private function deleteNodes($result):void {
    $storageHandler = \Drupal::entityTypeManager()->getStorage('node');

    $offset = 0;
    $length = 10;

    $progressBar = new ProgressBar($this->output, count($result));
    $progressBar->start();

    while ($nodeIds = array_slice($result, $offset, $length)) {
      $storageHandler->delete($storageHandler->loadMultiple($nodeIds));

      $progressBar->advance($length);
      $offset += $length;
    }
    $progressBar->finish();
    $this->output->writeln('');
    $this->output->writeln(count($result) . ' items verwijderd.' );
  }


  /**
   * Rename places
   */
  private function renameTerms($vocabulary):void {
    $result = \Drupal::entityQuery('taxonomy_term')
      ->condition('vid', $vocabulary)
      ->execute();

    $offset = 0;
    $length = 10;

    $progressBar = new ProgressBar($this->output, count($result));
    $progressBar->start();

    while ($termIds = array_slice($result, $offset, $length)) {
      foreach($termIds as $tid) {
        $term = Term::load($tid);
        $name = ucfirst( strtolower( $term->getName() ));
//        dump($name);
        $term->set('name', $name);
        $term->save();
      }

      $progressBar->advance($length);
      $offset += $length;
    }
    $progressBar->finish();
    $this->output->writeln('');
    $this->output->writeln(count($result) . ' items aangepast :-)' );
  }

  /**
   * @throws Exception
   */
  private function runImporter() {
    $this->finder->files()->in($this->directory)->name('*.csv');
    $this->importer->preImport();

    foreach ($this->finder->files() as $file) {
      $this->output->writeln(sprintf('Processing file: %s.', $file->getBasename()));

      $startTime = time();

      $count = $this->handleFile($file);

      $time = time() - $startTime;

      $this->output->writeln(
        sprintf('- %d records processed in %d minutes and %d seconds.', $count, ($time / 60) % 60, $time % 60)
      );
    }

    $this->importer->postImport();
    $this->output->writeln('Importing data finished.');
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


    return $this->importer->import($csv->getRecords($header), $this->output, $csv->count());
  }



  /**
   * Update the length of a text field which already contains data.
   *
   * @param string $entity_type_id
   * @param string $field_name
   * @param integer $new_length
   */
  private function _module_change_text_field_max_length ($entity_type_id, $field_name, $new_length) {
    $name = 'field.storage.' . $entity_type_id . "." . $field_name;

    // Get the current settings
    $result = \Drupal::database()->query(
      'SELECT data FROM {config} WHERE name = :name',
      [':name' => $name]
    )->fetchField();
    $data = unserialize($result);
    $data['settings']['max_length'] = $new_length;

    // Write settings back to the database.
    \Drupal::database()->update('config')
      ->fields(['data' => serialize($data)])
      ->condition('name', $name)
      ->execute();

    // Update the value column in both the _data and _revision tables for the field
    $table = $entity_type_id . "__" . $field_name;
    $table_revision = $entity_type_id . "_revision__" . $field_name;
    $new_field = ['type' => 'varchar', 'length' => $new_length];
    $col_name = $field_name . '_value';
    \Drupal::database()->schema()->changeField($table, $col_name, $col_name, $new_field);
    \Drupal::database()->schema()->changeField($table_revision, $col_name, $col_name, $new_field);

    // Flush the caches.
    drupal_flush_all_caches();
  }


}
