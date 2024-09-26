<?php

namespace Drupal\acquia_dam_integration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\DependencyInjection\ContainerInterface;
use GuzzleHttp\ClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

class AcquiaDAMController extends ControllerBase {

  /**
   * The HTTP client to fetch data.
   *
   * @var \GuzzleHttp\ClientInterface
   */
  protected $httpClient;

  /**
   * Constructs an AcquiaDAMController object.
   *
   * @param \GuzzleHttp\ClientInterface $http_client
   *   The HTTP client.
   */
  public function __construct(ClientInterface $http_client) {
    $this->httpClient = $http_client;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container)
  {
    return new static(
      $container->get('http_client')
    );
  }

  /**
   * Fetch products from Acquia DAM.
   *
   * @return \Symfony\Component\HttpFoundation\JsonResponse
   *   The JSON response containing products.
   */
  public function fetchProducts() {
    $config = $this->config('acquia_dam_integration.settings');
    $api_key = $config->get('api_key');
    $api_url = $config->get('api_url');
    $api_endpoint = $api_url . '/products/blgx6r8l5vq6';

    try {
      $response = $this->httpClient->request('GET', $api_endpoint, [
        'headers' => [
          'Authorization' => 'Bearer ' . $api_key,
        ],
      ]);
      $data = json_decode($response->getBody()->getContents(), TRUE);
    }
    catch (\Exception $e) {
      return new JsonResponse(['error' => $e->getMessage()], 500);
    }

    return new JsonResponse($data);
  }
}
