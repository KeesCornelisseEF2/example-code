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

class PageImporter extends AbstractImporter
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
  private $missingImages = [];

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

      if ('page' === $this->nodeType) {
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
    $node->set('status', $data['status']);
    $node->set('field_import_id', $data['nid']);
    $node->set('title', $data['titel']);
    $node->set('field_visible_title', [
      'format' => 'full_html',
      'value' => '<h1>' . $data['titel'] . '</h1>',
    ]);
    $node->set('body', [
      'format' => 'full_html',
      'value' => html_entity_decode($data['tekst_links'] . $data['tekst_rechts']),
    ]);
    if($node->hasField('field_title_above_content')) {
      $node->set('field_title_above_content', '');
    }
    $node->set('path', ['alias' => '/' . $data['url'], 'pathauto' => PathautoState::SKIP]);
    $node->set('field_meta_tags', serialize(['description' => $data['metatag_description']]));

    $fieldContent = $node->get('field_content')->getValue();

    if (!empty($fieldContent[0])) {
      $paragraph = Paragraph::load($fieldContent[0]['target_id']);
    } else {
      $paragraph = Paragraph::create(['type' => '2_koloms_tekst', 'langcode' => $data['taal']]);
    }

    if('page' == $this->nodeType){
      $paragraph->set(
        'field_two_column_text',
        [
          'format' => 'full_html',
          'value' => '',
        ]
      );
    }else{
      $paragraph->set(
        'field_two_column_text',
        [
          'format' => 'full_html',
          'value' => html_entity_decode($data['tekst_links'] . $data['tekst_rechts']),
        ]
      );
    }


    $paragraph->save();

    $fieldContent[0] = [
      'target_id' => $paragraph->id(),
      'target_revision_id' => $paragraph->getRevisionId(),
    ];

    if (!empty($data['video'])) {
      if (!empty($fieldContent[1])) {
        $video = Paragraph::load($fieldContent[1]['target_id']);
      } else {
        $video = Paragraph::create(['type' => 'video', 'langcode' => $data['taal']]);
      }

      $video->set('field_video_url', $data['video']);
      $video->save();

      $fieldContent[1] = [
        'target_id' => $video->id(),
        'target_revision_id' => $video->getRevisionId(),
      ];
    }

    $node->set('field_content', $fieldContent);

    if (!empty($data['filter_plaatsnaam'])) {
      $places = explode(',', $data['filter_plaatsnaam']);
      if (count($places)) {
        foreach ($places as $placeName) {
          $node->set('field_plaatsnaam_filter', $this->findOrCreateTaxonomyTerm('plaatsnamen', trim($placeName)));
        }
      }
    }

    if (!empty($data['filter_type'])) {
      $categories = explode(',', $data['filter_type']);
      if (count($categories)) {
        foreach ($categories as $category) {
          $node->set('field_categorie', $this->findOrCreateTaxonomyTerm('categorie', trim($category)));
        }
      }
    }

    if (!empty($data['filter_tag'])) {
      $tags = explode(',', $data['filter_tag']);
      if (count($tags)) {
        foreach ($tags as $tag) {
          $node->set('field_tags', $this->findOrCreateTaxonomyTerm('tags', trim($tag)));
        }
      }
    }
  }

  private function queueNodeImage(Node $node, array $data): void
  {
    if (empty($data['afbeelding'])) {
      return;
    }

    $image = str_replace('public://', '', trim($data['afbeelding']));

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
}
