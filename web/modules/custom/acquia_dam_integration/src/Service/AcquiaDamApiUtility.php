<?php

namespace Drupal\acquia_dam_integration\Service;

use Drupal\taxonomy\Entity\Term;
use Drupal\Component\Serialization\Json;
use Drupal\acquia_dam\Entity\MediaSourceField;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\media\Entity\Media;
use GuzzleHttp\ClientInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

class AcquiaDamApiUtility {

  /**
   * Acquia Dam configuration.
   *
   * @var \Drupal\Core\Config\ImmutableConfig
   */
  protected $config;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * The logger factory.
   *
   * @var \Drupal\Core\Logger\LoggerChannelFactoryInterface
   */
  protected $loggerFactory;

  /**
   * The HTTP client.
   *
   * @var \GuzzleHttp\Client
   */
  protected $httpClient;

  /**
   * Constructs a DefaultController object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   Config factory.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ConfigFactoryInterface $configFactory, EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface$logger_factory, ClientInterface $http_client) {
    $this->config = $configFactory;
    $this->entityTypeManager = $entity_type_manager;
    $this->loggerFactory = $logger_factory;
    $this->httpClient = $http_client;
  }

  /**
   * Widen API URL.
   *
   * @return string|null
   *   Return API URL.
   */
  public function apiUrl() : string {
    // Use excluding trailing slash 'https://api.widencollective.com/v2';
    return $this->config->get('acquia_dam_integration.settings')->get('api_url');
  }

  /**
   * Http client headers.
   *
   * @return array
   *   Return headers array.
   */
  public function headers() : array {
    $api_key = $this->config->get('acquia_dam_integration.settings')->get('api_key');

    return [
      "Content-Type" => "application/json",
      "Authorization" => 'Bearer ' . $api_key,
    ];
  }

  /**
   * Check term that already exist in Drupal.
   *
   * @param string $id
   *   Widen collective term id.
   * @param string $vid
   *   The vocabulary id.
   * @param stfing $field_name
   *   The field name.
   *
   * @return bool
   *   Product exit or not.
   */
  public function checkExistingTerm(string $id, string $vid, $field_name): bool {

    // Term that already exist in Drupal.
    $properties = [];
    $term = "";
    if (!empty($field_name)) {
      $properties[$field_name] = $id;
    }
    if (!empty($vid)) {
      $properties['vid'] = $vid;
    }
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties($properties);
    $term = reset($terms);

    return !empty($term) ? TRUE : FALSE;
  }

  /**
   * Find term by name and vid.
   *
   * @param mixed $name
   *   Term name.
   * @param string $vid
   *   Term vid.
   *
   * @return int
   *   Term id.
   */
  public function getTidByName($name, string $vid): int {
    $properties = [];
    if (!empty($name)) {
      $properties['name'] = $name;
    }
    if (!empty($vid)) {
      $properties['vid'] = $vid;
    }
    $terms = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties($properties);

    $term = reset($terms);
    if (!empty($term)) {
      return $term->id();
    }
    else {
      $term = Term::create([
        'name' => $name,
        'vid' => $vid,
      ]);
      $term->save();
      return $term->id();
    }
  }

  /**
   * Get Product.
   *
   * @param srint $product_id
   *   The widen colloective product id.
   *
   * @return array
   *   The product.
   */
  public function getProduct(string $product_id): array {
    $url = $this->apiUrl() . '/products/' . $product_id;
    try {
      // Make the HTTP request.
      $response = $this->httpClient->request('GET', $url, ['headers' => $this->headers()]);
      // Check the HTTP status code.
      if ($response->getStatusCode() != 200) {
        $this->loggerFactory->get('acquia_dam_integration')->error('HTTP request failed with status code: ' . $response->getStatusCode());
      }
      // Decode the JSON response.
      $result = Json::decode($response->getBody());
      return $result ? $result : [];
    }
    catch (\Exception $e) {
      $this->loggerFactory->get('acquia_dam_integration')->error('Exception occurred: ' . $e->getMessage());
    }
  }


  /**
   * Get product category ids.
   *
   * @param array $categories
   *   Product categories.
   *
   * @return array
   *   Term ids
   */
  private function getProductCategoryIds(array $categories): array
  {
    $tids = [];
    if (empty($categories)) {
      return [];
    }

    $tids = [];
    $categories = reset($categories);
    if (isset($categories['product_category_id'])) {
      $term = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties([
        'field_product_category_id' => $categories['product_category_id'],
        'vid' => 'product_category',
      ]);
      $tids[] = reset($term)->id();
    }
    if (isset($categories['sub_category']['product_category_id'])) {
      $term = $this->entityTypeManager
        ->getStorage('taxonomy_term')
        ->loadByProperties([
          'field_product_category_id' => $categories['sub_category']['product_category_id'],
          'vid' => 'product_category',
        ]);
      $tids[] = reset($term)->id();
    }
    return $tids;
  }

  /**
   * Get Media IDs.
   *
   * @param array $asset_ids
   *   DAM media asset ids.
   * @param string $bundle
   *   DAM media bundle.
   *
   * @return array
   *   Media ids.
   */
  private function getMediaIds(array $asset_ids, string $bundle) {
    $mids = [];

    foreach ($asset_ids as $asset_id) {
      // Check if media already exists.
      if ($this->checkExistingAssets($asset_id)) {
        $mids[] = $this->checkExistingAssets($asset_id);
      } else {
        // Create media.
        $media = Media::create([
          'bundle' => $bundle,
          MediaSourceField::SOURCE_FIELD_NAME => [
            'asset_id' => $asset_id,
          ],
        ]);
        $media->save();

        if ($media) {
          $mids[] = $media->id();
        }
      }
    }

    return $mids;
  }

  /**
   * Check assets that already exist in Drupal.
   *
   * @param string $id
   *   Assets id.
   *
   * @return int|null
   *   Media id.
   */
  private function checkExistingAssets(string $id): ?int {
    // Assets that already exist in Drupal.
    $query = $this->entityTypeManager
      ->getStorage('media')
      ->getQuery();

    $entity_ids = $query->condition('acquia_dam_asset_id', $id)
      ->range(0, 1)
      ->accessCheck(FALSE)
      ->execute();

    return !empty($entity_ids) ? reset($entity_ids) : NULL;
  }

  /**
   * Creating fields array.
   *
   * @param array $item
   *   Widen response item.
   * @param string $type
   *   Product/variation.
   * @param string $langcode
   *   The language code.
   *
   * @return array
   *   Fields array.
   */
  public function fieldsArray(array $item, string $type, string $langcode): array {
    // Load term of product type by product type id.
    $productTypeId = $this->entityTypeManager
      ->getStorage('taxonomy_term')
      ->loadByProperties([
          'field_product_type_id' => $item['product_type']['product_type_id']
        ]);

    $productTypeId = reset($productTypeId);

    // Product categories.
    $productCategories = $this->getProductCategoryIds(reset($item['product_categories']));

    // Fields array.
    $fieldArr = [
      'type' => 'product',
      'title' => $item['name'],
      'field_product_id' => $item['product_id'],
      'uid' => 1,
      'field_product_type' => [$productTypeId->id()],
      'field_product_category' => $productCategories,
      'changed' => strtotime($item['last_updated_timestamp']),
      'created' => strtotime($item['created_date']),
      'field_sku' => $item['sku']
    ];

    // Targetting assets, color and description attribute values only.
    foreach ($item['attributes'] as $attribute) {
      if ($attribute['type'] == "text" && $attribute['name'] == "Color" && $attribute['attribute_group']['name'] == "Specifications") {
        $fieldArr['field_attr_color'] = $attribute['values'][0]  ?? '';
      }
      elseif ($attribute['type'] == "rich_text" && $attribute['name'] == "Description" && $attribute['attribute_group']['name'] == "General") {
        $fieldArr['body'] = [
          'value' => $attribute['values'][0] ?? '',
          'format' => 'full_html',
        ];
      }
      elseif ($attribute['type'] == "asset") {
        $fieldArr['field_attr_assets'] = $this->getMediaIds($attribute['values'], 'acquia_dam_image_asset');
      }
    }

    return $fieldArr;
  }

}
