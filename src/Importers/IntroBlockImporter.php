<?php

declare(strict_types=1);

namespace Drupal\vneml_migrate_content\Importers;

use Drupal;
use Drupal\Component\Plugin\Exception\InvalidPluginDefinitionException;
use Drupal\Component\Plugin\Exception\PluginNotFoundException;
use Drupal\Core\Entity\EntityStorageException;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Queue\QueueInterface;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;
use Drupal\pathauto\PathautoState;
use Drupal\vneml_migrate_content\DataFormatter;
use Exception;
use Illuminate\Support\Collection;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class IntroBlockImporter extends AbstractImporter
{
  private const IMAGE_DIRECTORIES = [
    DRUPAL_ROOT . '/../exports/files',
  ];

  /**
   * @var string
   */
  private $nodeType;

  /**
   * @var DataFormatter
   */
  private $formatter;

  /**
   * @var QueueInterface
   */
  private $imageUploadQueue;

  /**
   * @var EntityStorageInterface
   */
  private $nodeStorage;

  /**
   * @var LoggerInterface
   */
  private $logger;

  /**
   * @var array
   */
  private $nodeIds = [];

  /**
   * @var array
   */
  private $basePageIds = [];

  /**
   * @var array
   */
  private $basePageTitleIds = [];


  /**
   * @var array
   */
  private $missingImages = [];

  /**
   * @var int
   */
  private $frontId = 0;

  /**
   * @var string
   */
  private $parType = '2_columns_text_incl_embed';

  /**
   * @param string $nodeType
   * @param DataFormatter $formatter
   *
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  public function __construct(string $nodeType, DataFormatter $formatter)
  {
    $this->nodeType = $nodeType;
    $this->formatter = $formatter;
    $this->imageUploadQueue = Drupal::queue('vneml_image_uploader');
    $this->nodeStorage = Drupal::entityTypeManager()->getStorage('node');

    $this->logger = new Logger(
      sprintf('vneml_migrate_content_%s', $this->nodeType),
      [
        new StreamHandler(
          sprintf(
            '%s/modules/custom/vneml_migrate_content/logs/vneml_migrate_content_%s.log',
            DRUPAL_ROOT,
            $this->nodeType
          )
        ),
      ]
    );
  }

  public function preImport(): void
  {
    $this->logger->info('Starting new import.');

    $query = Drupal::database()->select('node__field_import_id', 'nid');
    $query->join('node', 'n', 'n.nid = nid.entity_id');
    $query->fields('n', ['nid'])
      ->fields('nid', ['field_import_id_value'])
      ->condition('n.type', $this->nodeType);

    foreach ($query->execute()->fetchAll() as $node) {
      $this->nodeIds[$node->field_import_id_value] = (int) $node->nid;
    }

    $query = Drupal::database()->select('node__field_import_id', 'nid');
    $query->join('node', 'n', 'n.nid = nid.entity_id');
    $query->fields('n', ['nid'])
      ->fields('nid', ['field_import_id_value'])
      ->condition('n.type', ['page', 'overview_page'], 'IN');

    foreach ($query->execute()->fetchAll() as $node) {
      $this->basePageIds[$node->field_import_id_value] = (int) $node->nid;
    }

    $query = Drupal::database()->select('node_field_data', 'n');
    $query->fields('n', ['nid', 'title'])
      ->condition('n.type', ['page'], 'IN');

    foreach ($query->execute()->fetchAll() as $node) {
      $this->basePageTitleIds[$node->title] = (int) $node->nid;
    }

    $config = \Drupal::config('system.site');
    $front_uri = $config->get('page.front');

    $this->frontId = $this->stripNodeId($front_uri);
  }

  public function postImport(): void
  {
    if (!empty($this->missingImages)) {
      $this->logger->warning('Some images could not be found.', ['images' => implode(',', array_values($this->missingImages))]);
    }

    $this->logger->info('Import finished.');
  }

  /**
   * @param array $data
   *
   * @throws Exception
   */
  protected function handleRecord(array $data): void
  {

    $basePages = $this->getBasePages($data);

    if(is_null($basePages)) {
      return;
    }

    foreach($basePages AS $page) {

      if($page === 0) {
        continue;
      }

      $node = Node::load($page);

      if ($node->hasTranslation($data['taal'])) {
        $node = $node->getTranslation($data['taal']);
      }

      // Indien het veld niet bestaat voor dit type
      if(!$node->hasField('field_content')) {
        continue;
      }


      $text = $this->concatField($data);
      if(empty($text)) {
        continue;
      }

      $this->fillParagraph($node, $data, $text);

      try {
        $node->save();

        $this->logger->info('Page successfully imported.', ['nid' => $data['nid']]);
      } catch (EntityStorageException $exception) {
        $this->logger->error('Error saving entity.', ['nid' => $data['nid'], 'error' => $exception->getMessage()]);
      }

    }


  }

  /**
   * @param Node $node
   * @param array $data
   *
   * @throws Exception
   */
  private function fillParagraph(Node $node, array $data, string $text): void
  {
    $fieldContent = $node->get('field_content')->getValue();

    // Check if paragraph already exists
    if(count($fieldContent) > 0) {
      foreach($fieldContent AS $index => $item) {
        $paragraph = Paragraph::load($item['target_id']);

        if($paragraph->getType() === $this->parType) {
          break;
        }else{
          unset($paragraph);
        }
      }
    }

    // If not existing, make a new one
    if(!isset($paragraph) || !$paragraph instanceof Paragraph) {
      $paragraph = Paragraph::create(['type' => $this->parType, 'langcode' => $data['taal']]);
      $index = count($fieldContent);
    }

    $paragraph->set('field_two_column_text', [
      'format' => 'full_html',
      'value' => $text,
    ]);

    $paragraph->set('status', $data['status']);
    $paragraph->set('field_property_title', html_entity_decode( $data['titel'], ENT_QUOTES) );
    $paragraph->set('field_code', html_entity_decode( $data['embed_veld'], ENT_QUOTES) );
    $paragraph->set('field_import_id', $data['nid']);
    $paragraph->save();

    $fieldContent[$index] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];

    $node->set('field_content', $fieldContent);
  }

  /**
   * @param array $data
   * @return string
   */
  private function concatField (array $data):?string
  {
    $text = html_entity_decode( trim($data['tekst_links'] . $data['tekst_rechts']) );

    if(empty($text)) {
      return null;
    }

    return $text;
  }

  /**
   * @param array $data
   * @return Collection|null
   */
  private function getBasePages(array $data): ?Collection
  {
    $basePageIds = [];
    if(empty($data['menuitem'])) {
      return null;
    }
    $pages = explode(',', $data['menuitem']);
    foreach($pages as $page) {
      if('<front>' == trim(html_entity_decode($page))) {
        $basePageIds[] = $this->frontId;
      }elseif(isset($this->basePageIds[$this->stripNodeId($page)])){
        $basePageIds[] = $this->basePageIds[$this->stripNodeId($page)];
      }
    }
    $pages = collect($basePageIds);

    return $pages;
  }

  /**
   * @param array $data
   * @return string
   */
  private function getCTAlink(array $data):string {
    if(substr( $data['foto_link'], 0, 4 ) === 'node') {
      $nodeId = $this->stripNodeId($data['foto_link']);
      $photoLink = 'internal:/node/' . $this->basePageIds[$nodeId];
    }else if(substr( $data['foto_link'], 0, 4 ) === 'http'){
      $photoLink = $data['foto_link'];
    }else {
      $photoLink = 'internal:/' . $data['foto_link'];
    }
    return $photoLink;
  }


  private function stripNodeId (string $link): int {
    $nodeId = str_replace('node/', '', trim($link));

    if(is_numeric($nodeId)) {
      return (int) $nodeId;
    }

    return 0;
  }
}
