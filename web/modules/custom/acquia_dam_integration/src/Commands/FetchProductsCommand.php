<?php

namespace Drupal\acquia_dam_integration\Commands;

use Drupal\acquia_dam_integration\Service\AcquiaDamApiUtility;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\node\Entity\Node;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush commands for product from the Acquia DAM/PIM.
 */
class FetchProductsCommand extends DrushCommands {

  /**
   * The Acquia DAM API Utility.
   *
   * @var \Drupal\acquia_dam_integration\Service\AcquiaDamApiUtility
   */
  protected $ApiUtility;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Entity Type Manager service.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Database connection service.
   *
   * @var \Drupal\Core\Database\Connection
   */
  protected $connection;

  /**
   * Logger Factory.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $loggerFactory;

  /**
   * The Language Manager service.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;


  protected $updatedParentlist = [];
  protected $counter = [
    'product_created' => 0,
    'product_updated' => 0,
    'parent_product_created' => 0,
    'parent_product_updated' => 0,
  ];

  /**
   * Constructs a new FetchProductsCommand instance.
   *
   * @param \Drupal\acquia_dam_integration\Service\AcquiaDamApiUtility $api_utility
   *   The Acquia DAM API Utility.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager service.
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \Drupal\Core\Language\LanguageManagerInterface $language_manager
   *   The language manager.
   */
  public function __construct(AcquiaDamApiUtility $api_utility, ClientInterface $http_client, EntityTypeManagerInterface $entity_type_manager, Connection $connection, LoggerChannelFactoryInterface $logger_factory, LanguageManagerInterface $language_manager) {
    parent::__construct();
    $this->ApiUtility = $api_utility;
    $this->httpClient = $http_client;
    $this->entityTypeManager = $entity_type_manager;
    $this->connection = $connection;
    $this->loggerFactory = $logger_factory->get('acquia_dam_pim_integration');
    $this->languageManager = $language_manager;
  }

  /**
   * Create or update all product from the Acquia DAM/PIM.
   *
   * @param int $offset
   *   Max 9999, Min 0, Default 0.
   * @param int $limit
   *   Max 100, Min 0, Default 10.
   *
   * @usage pim:sync-products
   *   Create/update products.
   *
   * @command dam-pim:sync-products
   * @aliases dam-pim-sp
   */
  public function syncProducts($offset = 0, $limit = 100) : void {
    $url = $this->ApiUtility->apiUrl() . '/products/search';

    // This filter will get only solo and variant products.
    $filter = [
      ["type" => "exclude_parents"],
    ];

    $body = json_encode([
      "offset" => $offset,
      "limit" => $limit,
      "filters" => $filter,
      "expand" => ["attributes"],
    ]);

    try {
      $response = $this->httpClient->request('POST', $url, [
        'body' => $body,
        'http_errors' => FALSE,
        'headers' => $this->ApiUtility->headers(),
      ]);

      if ($response->getStatusCode() == 200) {
        $result = Json::decode($response->getBody());
        if ($result['total_count'] == 0) {
          $this->output()->writeln("No Records found.");
          $this->loggerFactory->info("No Records found.");
          return;
        }

        // When record items exists.
        if (isset($result['items'])) {
          $this->processProductVariations($result['items'], $result['total_count'], $offset);
        }
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Exception occurred: ' . $e->getMessage());
    }

  }

  /**
   * Process products.
   *
   * @param array $items
   *   The items array.
   * @param int $total_count
   *   Total count of result.
   * @param int $offset
   *   Maximum 9999, Mininimum 0, Default 0.
   */
  protected function processProductVariations(array $items, int $total_count, int $offset) {

    // Create/update all solo and variants consequently parents products.
    foreach ($items as $item) {
      // Check if product exists.
      $product_id = $this->checkExistingProductVariation($item['product_id']);

      // If product does not exists create one.
      if (!$product_id) {
        $product = Node::create($this->ApiUtility->fieldsArray($this->ApiUtility->getProduct($item['product_id']), 'product', 'en'));
        $product->save();
        $product_id = $product->id();
        $this->logger()->success(dt('Created product @prod_name.', [
          '@prod_name' => $item['name'],
        ]));
        $this->counter['product_created']++;
      }
      // Else, load and update the product details.
      else {
        $product = $this->entityTypeManager
          ->getStorage('node')
          ->load($this->checkExistingProductVariation($item['product_id']));

        // Set feild values.
        foreach ($this->ApiUtility->fieldsArray($this->ApiUtility->getProduct($item['product_id']), 'product', 'en') as $field => $values) {
          $product->set($field, $values);
        }
        $product->save();

        $this->logger()->success(dt('Updated product @prod_name.', [
          '@prod_name' => $item['name'],
        ]));
        $this->counter['product_updated']++;
      }

      // If product exists or created check for managin parent product.
      if (isset($item["parent_product"]["parent_product_id"])) {
        $this->manageParentProduct($item['parent_product']['parent_product_id'], $product);
      }

      $offset++;
    }

    if ($offset < $total_count) {
      $this->syncProducts($offset, 100);
    }

    $msg = 'Created @product_created products, @parent_product_created parent products and updated @product_updated products and @parent_product_updated parent products.';
    $msg_vars = [
      '@product_created' => $this->counter['product_created'],
      '@product_updated' => $this->counter['product_updated'],
      '@parent_product_created' => $this->counter['parent_product_created'],
      '@parent_product_updated' => $this->counter['parent_product_updated'],
    ];

    $this->logger()->success(dt($msg, $msg_vars));
    $this->loggerFactory->info($msg, $msg_vars);
  }


  /**
   * Manage parent products.
   */
  protected function manageParentProduct($parent_product_id, $child_product) {

    // Fetch parent products.
    $parent_products = $this->entityTypeManager->getStorage('node')->loadByProperties([
      'type' => 'product',
      'field_product_id' => $parent_product_id,
    ]);

    // When parent product exists add variant product reference.
    if ($parent_products && !empty($parent_products)) {
      $parent_product = reset($parent_products);

      // Let's also update it if not updated in this same drush process.
      if (!in_array($parent_product_id, $this->updatedParentlist)) {
        foreach ($this->ApiUtility->fieldsArray($this->ApiUtility->getProduct($parent_product_id), 'product', 'en') as $field => $values) {
          $parent_product->set($field, $values);
        }
        $this->updatedParentlist[] = $parent_product_id;

        $this->logger()->success(dt('Updated parent product @prod_name.', [
          '@prod_name' => $parent_product->label(),
        ]));
        $this->counter['parent_product_updated']++;
      }
      $parent_product->save();
    }
    // Else, create parent product and variant product reference.
    else {
      $parent_product = Node::create($this->ApiUtility->fieldsArray($this->ApiUtility->getProduct($parent_product_id), 'product', 'en'));
      $parent_product->save();

      $this->logger()->success(dt('Created parent product @prod_name.', [
        '@prod_name' => $parent_product->label(),
      ]));
      $this->counter['parent_product_created']++;
    }

    // Link parent to child product.
    $child_product->set('field_parent_product', ['target_id' => $parent_product->id()]);
    $child_product->save();
  }

  /**
   * Check product variation that already exist in Drupal.
   *
   * @param string $id
   *   Widen collective variation id.
   *
   * @return int|bool
   *   Product variation exit or not.
   */
  protected function checkExistingProductVariation(string $id) : ?int {
    // Product variation that already exist in Drupal.

    // Load the entity query service.
    $entity_query = \Drupal::entityQuery('node')
      ->condition('type', 'product')  // Add condition to check for content type.
      ->condition('field_product_id', $id)
      ->accessCheck(TRUE);

    // Execute the query.
    $nids = $entity_query->execute();

    // If $nids is not empty, the node exists.
    return !empty($nids) ? reset($nids) : FALSE;
  }

}
