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
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;

class PhotoBlockImporter extends AbstractImporter
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
    $nodeId = $this->nodeIds[$data['nid']] ?? null;

    if (null !== $nodeId) {
      /** @var Node $node */
      $node = Node::load($nodeId);

      if ($node->hasTranslation($data['taal'])) {
        $node = $node->getTranslation($data['taal']);
      } else {
        $node = $node->addTranslation($data['taal']);
      }
    } else {
      /** @var Node $node */
      $node = Node::create(['type' => $this->nodeType, 'langcode' => $data['taal']]);
    }

    $this->fillNode($node, $data);

    try {
      $node->save();

      if ($node->get('field_image')->isEmpty()) {
        $this->queueNodeImage($node, $data);
      }

      $this->nodeIds[$data['nid']] = $node->id();

      $this->logger->info('Page successfully imported.', ['nid' => $data['nid']]);
    } catch (EntityStorageException $exception) {
      $this->logger->error('Error saving entity.', ['nid' => $data['nid'], 'error' => $exception->getMessage()]);
    }
  }

  /**
   * @param Node $node
   * @param array $data
   *
   * @throws Exception
   */
  private function fillNode(Node $node, array $data): void
  {
    $photoLink = $this->getCTAlink($data);
    $node->set('status', $data['status']);

    $node->set('field_import_id', $data['nid']);
    $node->set('title', $data['titel']);
    $node->set('field_advertentie', $data['advertentie?']);
    $node->set('field_cta', [
      'uri' => $photoLink,
      'title' => $data['titel'],
    ]);

    $this->linkBasePage($node, $data);

    $node->set('path', [ 'pathauto' => false ]);
  }

  /**
   * @param Node $node
   * @param array $data
   */
  private function linkBasePage(Node $node, array $data): void
  {
    $basePageIds = [];
    if(!empty($data['menuitem'])) {
      $pages = explode(',', $data['menuitem']);
      foreach($pages as $page) {
        if('<front>' == trim(html_entity_decode($page))) {
          $basePageIds[] = $this->frontId;
        }elseif(isset($this->basePageIds[$this->stripNodeId($page)])){
          $basePageIds[] = $this->basePageIds[$this->stripNodeId($page)];
        }
      }
    }
    $pages = collect($basePageIds);

    foreach($pages as $index => $page) {
      if($index == 0) {
        $node->set('field_base_page', $page);
      } else {
        $node->get('field_base_page')->appendItem([
          'target_id' => $page,
        ]);
      }
    }
  }

  private function queueNodeImage(Node $node, array $data): void
  {
    if (empty($data['foto'])) {
      return;
    }

    $image = str_replace('public://', '', trim($data['foto']));

    if (empty($image)) {
      return;
    }

    if ($imagePath = $this->locateImage($image)) {
      $this->imageUploadQueue->createItem((object) ['nodeId' => $node->id(), 'image' => $imagePath]);
    } else {
      $this->missingImages[$image] = $image;
    }
  }

  private function locateImage(string $image): ?string
  {
    foreach (static::IMAGE_DIRECTORIES as $directory) {
      $path = sprintf('%s/%s', $directory, $image);

      if (file_exists($path)) {
        return $path;
      }
    }

    return null;
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
