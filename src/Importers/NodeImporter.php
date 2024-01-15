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
use Drupal\pathauto\PathautoState;
use Drupal\taxonomy\Entity\Term;
use Drupal\vneml_migrate_content\DataFormatter;
use Exception;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Log\LoggerInterface;
use Symfony\Component\DomCrawler\Crawler;

class NodeImporter extends AbstractImporter
{
  private const IMAGE_DIRECTORIES = [
    DRUPAL_ROOT . '/../exports/files',
    DRUPAL_ROOT . '/../exports/files/ndtrc_externals',
    DRUPAL_ROOT . '/../exports/files/pdf_flipbook',
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
  private $userIds = [];

  /**
   * @var array
   */
  private $nodeIds = [];

  /**
   * @var array
   */
  private $nodeRelations = [];

  /**
   * @var array
   */
  private $missingUsers = [];

  /**
   * @var array
   */
  private $missingImages = [];

  /**
   * NodeImporter constructor.
   *
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

    $users = Drupal::database()->select('users_field_data', 'ufd')
      ->fields('ufd', ['uid', 'mail'])
      ->execute()
      ->fetchAll();

    $nodes = Drupal::database()->select('node', 'n')
      ->fields('n', ['nid', 'uuid'])
      ->execute()
      ->fetchAll();

    foreach ($users as $user) {
      $this->userIds[strtolower((string) $user->mail)] = (int) $user->uid;
    }

    foreach ($nodes as $node) {
      $this->nodeIds[$node->uuid] = (int) $node->nid;
    }
  }

  /**
   * @throws EntityStorageException
   */
  public function postImport(): void
  {
    $this->processNodeRelations();

    if (!empty($this->missingUsers)) {
      $this->logger->warning('Some users could not be found.', ['users' => implode(',', array_values($this->missingUsers))]);
    }

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
    $nodeId = $this->nodeIds[$data['trcid']] ?? null;

    if(!empty($data['tot_aan']) && strtotime('now') > strtotime($data['tot_aan'])) {
      return;
    }

      if(empty($data['titel'])) {
          return;
      }

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
      return;
    }

    
    if(empty($data['easygis_route_id'])){
      return;
    }

    $this->fillEasyGisOnly($node, $data);

    try {
      if(!empty($data['titel'])){
        $node->save();
      }
      
      $this->rememberNodeRelations($node, $data);

      $this->nodeIds[$data['trcid']] = $node->id();

      $this->logger->info('Node successfully imported.', ['nid' => $data['nid']]);
    } catch (EntityStorageException $exception) {
      $this->logger->error('Error saving entity.', ['nid' => $data['nid'], 'error' => $exception->getMessage()]);
    }
  }

  private function fillTitleOnly(Node $node, array $data): void
  {
    $node->set('title', htmlspecialchars_decode($data['titel'], ENT_QUOTES));
  }

  private function fillEasyGisOnly(Node $node, array $data): void
  {
    $node->set('field_easygis', $data['easygis_route_id']); //EasyGIS route-id
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
    $node->set('uuid', $data['trcid']);
    $node->set('field_imported', 1);
    if(!empty($data['created'])){
      $node->setCreatedTime($this->formatter->convertDateTimeToTimestamp($data['created']));
    }
    if(!empty($data['updates'])) {
      $node->setChangedTime($this->formatter->convertDateTimeToTimestamp($data['updates']));
    }
    if ($userId = $this->findUserId($data['created_by'])) {
      $node->set('uid', $userId);
    }

    $node->set('title', htmlspecialchars_decode($data['titel'], ENT_QUOTES));
    $node->set(
      'body',
      ['format' => 'full_html', 'value' => html_entity_decode($data['volledige_omschrijving'])]
    );
    $node->set(
      'field_short_description',
      ['format' => 'full_html', 'value' => html_entity_decode($data['korte_omschrijving'])]
    );
    $node->set(
      'field_calendar_description',
      ['format' => 'full_html', 'value' => html_entity_decode(nl2br($data['kalender_samenvatting']))]
    );

    $node->set('field_organizer', $data['organisator']);
    $node->set('field_company', $data['bedrijfsnaam'] ?? '');
    $node->set('field_address_1', $data['straat_huisnummer']);
    $node->set('field_postal_code', $data['postcode']);
    $node->set('field_place', $data['plaatsnaam']);
    $node->set('field_land', strtolower($data['landcode'] ?? 'nl'));
    $node->set('field_location_lat_long', $this->formatter->formatCoordinates($data['coordinaten']));

    $node->set('field_available_from', $this->formatter->formatDate($data['vanaf']));
    $node->set('field_available_to', $this->formatter->formatDate($data['tot_aan']));

    $node->set('field_contact_e_mail', $data['contact_e_mail']);
    $node->set('field_contact_phonenumber', $data['contact_telefoon']);
    $node->set('field_contact_website', $data['contact_website']);

    $node->set('field_search_and_book_link', $data['zoek_en_boek_link']);
    $node->set('field_book_directly_link', $data['reserveer_direct_link']);
    $node->set('field_spoken_languages', $data['taalinformatie']);

    if (!empty($data['ndtrc_type'])) {
      $cats = explode(',', $data['ndtrc_type']);
      if (count($cats)) {
        foreach ($cats as $cat) {
          $node->set('field_categorie', $this->findOrCreateTaxonomyTerm('categorie', trim($cat)));
        }
      }
    }

    if (!empty($data['tag'])) {
      $tags = explode(',', $data['tag']);
      if (count($tags)) {
        foreach ($tags as $tag) {
          $node->set('field_tags', $this->findOrCreateTaxonomyTerm('tags', trim($tag)));
        }
      }
    }

    if (!empty($data['plaatsnaam'])) {
      $places = explode(',', $data['plaatsnaam']);
      if (count($places)) {
        foreach ($places as $placeName) {
          $node->set('field_plaatsnaam_filter', $this->findOrCreateTaxonomyTerm('plaatsnamen', trim($placeName)));
        }
      }
    }

    if (!empty($data['facebook'])) {
      $node->set('field_facebook_name', $data['facebook']);
    }
    if (!empty($data['tripadvisor'])) {
      $node->set('field_tripadvisor', $data['tripadvisor']);
    }

    $properties = $this->exploreProperties($data['eigenschappen_weergave']);
    $terms = $this->createTermsRecursive('properties', $properties);

    if(!is_null($terms) && count($terms)) {
      $node->set('field_propertie_terms', $terms);
    }

    $node->set('field_properties_html', [
      'format' => 'full_html',
      'value' => html_entity_decode($data['eigenschappen_weergave']),
    ]);
  }

  /**
   * @param string $properties_html
   * @return array|null
   */
  private function exploreProperties(string $properties_html): ?array
  {
    $crawler = new Crawler( html_entity_decode($properties_html) );

    return $crawler->filter('div.ndtrc-eigenschap')->each(function (Crawler $node, $i) {
      return [
        $node->filter('div.ndtrc-label')->text() => $node->filter('div.ndtrc-value')->text()
      ];
    });
  }

  /**
   * @param string $email
   * @return int|null
   */
  private function findUserId(string $email): ?int
  {
    $email = strtolower($email);
    $userId = $this->userIds[$email] ?? null;

    if (null === $userId) {
      $this->missingUsers[$email] = $email;
    }

    return $userId;
  }

  private function queueNodeImages(Node $node, array $data): void
  {
    if (empty($data['media_bestanden'])) {
      return;
    }

    $images = [];

    foreach (explode(',', $data['media_bestanden']) as $key => $image) {
      $image = trim($image);

      if (empty($image)) {
        continue;
      }

      if ($imagePath = $this->locateImage($image) ) {
        $images[] = $imagePath;
      } else {
        $this->missingImages[$image] = $image;
      }
    }

    if (!empty($images)) {
      $this->imageUploadQueue->createItem((object) ['nodeId' => $node->id(), 'images' => $images]);
    }
  }

  /**
   * @param string $image
   * @param array $data
   * @return string|null
   */
  private function locateNDTRCImage(string $image, array $data): ?string
  {
    $domain = 'https://media.ndtrc.nl/Images';
    $image = str_replace('ndtrc_externals/', '', $image);
    return $this->imageFileExists($domain . '/' . substr($data['trcid'], 0, 2) . '/' . $data['trcid'] . '/' . $image);
  }

  /**
   * @param string $url
   * @return string|null
   */
  private function imageFileExists(string $url)
  {
    $contents = file_get_contents($url);

    if ($contents && strlen($contents)) {
      return $url;
    }

    return null;
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

  private function rememberNodeRelations(Node $node, array $data): void
  {
    if (empty($data['gerelateerd_aan'])) {
      return;
    }

    $this->nodeRelations[$node->id()] = array_map('trim', explode(',', $data['gerelateerd_aan']));
  }

  /**
   * @throws EntityStorageException
   */
  private function processNodeRelations(): void
  {
    foreach ($this->nodeRelations as $nodeId => $nodeRelations) {
      $relatedNodeIds = [];

      foreach ($nodeRelations as $nodeRelation) {
        if (isset($this->nodeIds[$nodeRelation])) {
          $relatedNodeIds[] = $this->nodeIds[$nodeRelation];
        }
      }

      if (!empty($relatedNodeIds)) {
        /** @var Node $node */
        $node = Node::load($nodeId);

        $node->set('field_related_to', $relatedNodeIds);

        $node->save();
      }
    }
  }

  /**
   * @param $vocabulary
   * @param $terms
   * @return array
   * @throws EntityStorageException
   * @throws InvalidPluginDefinitionException
   * @throws PluginNotFoundException
   */
  private function createTermsRecursive($vocabulary, $terms): array
  {
    $term_ids = [];
    foreach($terms AS $term) {
      foreach($term AS $parent => $child) {
        if(empty($parent)) {
          continue;
        }
        $parent_id = $this->findOrCreateTaxonomyTerm($vocabulary, $parent);
        $child_id = $this->findOrCreateTaxonomyTerm($vocabulary, $child, $parent_id);

        $term_ids[] = ['target_id' => $child_id];
      }
    }
    return $term_ids;
  }
}
