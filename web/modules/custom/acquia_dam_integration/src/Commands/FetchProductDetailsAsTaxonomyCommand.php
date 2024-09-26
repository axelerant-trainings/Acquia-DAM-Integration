<?php

namespace Drupal\acquia_dam_integration\Commands;

use Drupal\acquia_dam_integration\Service\AcquiaDamApiUtility;
use Drupal\Component\Serialization\Json;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\taxonomy\Entity\Term;
use Drush\Commands\DrushCommands;
use GuzzleHttp\ClientInterface;

/**
 * Drush commands that fetches product type and category terms.
 */
class FetchProductDetailsAsTaxonomyCommand extends DrushCommands {

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
   * Logger Factory.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $loggerFactory;

  protected $typeId;
  protected $apiUrl;
  protected $manageParentTerm = FALSE;

  protected $counter = [
    'terms_created' => 0,
  ];


  /**
   * Constructs a new command instance.
   *
   * @param \Drupal\acquia_dam_integration\Service\AcquiaDamApiUtility $api_utility
   *   The Acquia DAM API Utility.
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client service.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   * ;
   */
  public function __construct(AcquiaDamApiUtility $api_utility, ClientInterface $http_client, LoggerChannelFactoryInterface $logger_factory) {
    parent::__construct();
    $this->ApiUtility = $api_utility;
    $this->httpClient = $http_client;
    $this->loggerFactory = $logger_factory->get('acquia_dam_integration');
  }

  /**
   * Create product categories from the Acquia DAM.
   *
   * @param string|null $type_id
   *   The parent product term indicator.
   *
   * @usage dam-pim-cpt type
   *   Create product type terms.
   * @usage dam-pim-cpt category
   *   Create product category terms.
   *
   * @command dam-pim:create-product-terms
   * @aliases dam-pim-cpt
   */
  public function manageCreatingProductTerms(string $type_id) : void {
    $this->typeId = $type_id;

    if ($type_id === 'category') {
      $this->apiUrl = $this->ApiUtility->apiUrl() . '/product-categories';
      $this->manageParentTerm = TRUE;
      $this->createProductTaxonomyTerms();
    }
    else if ($type_id === 'type') {
      $this->apiUrl = $this->ApiUtility->apiUrl() . '/product-types';
      $this->createProductTaxonomyTerms();
    }
    else {
      $this->logger()->error(dt('product detail @type_id not recognized.', [
        '@type_id' => $type_id
      ]));
      return;
    }

    $this->logger()->success(dt('Total @count product @type taxonomy terms created.', [
      '@count' => $this->counter['terms_created'],
      '@type' => $this->typeId,
    ]));
  }

  /**
   * Handles creation of taxonomy terms.
   */
  public function createProductTaxonomyTerms(?string $product_parent_term_id = NULL, ?int $parent_term_id = NULL) : void {

    // Parent product cat id of widen collective.
    $product_parent_term_id = $product_parent_term_id ? '?parent_product_category_id=' . $product_parent_term_id : NULL;
    $url = $this->apiUrl . $product_parent_term_id;
    try {
      $response = $this->httpClient->request('GET', $url, ['headers' => $this->ApiUtility->headers()]);
      if ($response->getStatusCode() == 200) {
        $result = Json::decode($response->getBody());

        if (isset($result['items'])) {
          $this->processTaxonomyTerms($result['items'], $parent_term_id, $result['total_count']);
        }
      }
    }
    catch (\Exception $e) {
      $this->logger()->error('Exception occurred: ' . $e->getMessage());
    }
  }

  /**
   * Process product taxonomy terms.
   *
   * @param array $items
   *   The items array.
   * @param int|Null $parent_term_id
   *   The parent term id.
   * @param int $count
   *   Total result count.
   */
  protected function processTaxonomyTerms(array $items, ?int $parent_term_id, int $count): void {
    $term_list = [];
    $vocab_id = 'product_' . $this->typeId;
    $field_id = 'field_product_' . $this->typeId . '_id';
    foreach ($items as $item) {
      $field_value = $item['product_' . $this->typeId . '_id'];
      if (!$this->ApiUtility->checkExistingTerm($field_value, $vocab_id, $field_id)) {

        // Create respective taxonomy terms.
        $term = Term::create([
          'name' => $item['name'],
          'vid' => $vocab_id,
          $field_id => $field_value,
          'parent' => ['target_id' => $parent_term_id],
        ]);
        $term->save();

        if ($term->id()) {
          $term_list[$field_value] = $item['name'];
          $this->logger()->success(dt('Created @term_name product @type taxonomy terms.', [
            '@term_name' => $item['name'],
            '@type' => $this->typeId,
          ]));
          $this->counter['terms_created']++;

          if ($this->manageParentTerm) {
            // Add child terms.
            $this->createProductTaxonomyTerms($field_value, $term->id());
          }
        }
      }
    }
  }

}
