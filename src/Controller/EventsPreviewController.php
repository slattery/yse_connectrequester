<?php

declare(strict_types=1);

namespace Drupal\yse_connectrequester\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\yse_connectrequester\Service\YaleConnectFetcher;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Renders YaleConnect event email preview pages.
 */
class EventsPreviewController extends ControllerBase {

  /**
   * Maps audience route segment to display data.
   */
  const AUDIENCE_MAP = [
    'staff' => [
      'label' => 'YSE Faculty/Staff',
      'bg_color' => 'fc0',
      'footer_because' => ' you are a member of the YSE community.',
    ],
    'students' => [
      'label' => 'YSE Students',
      'bg_color' => '99ccff',
      'footer_because' => ' you are a member of the YSE community.',
    ],
    'yse' => [
      'label' => 'YSE',
      'bg_color' => '',
      'footer_because' => ' you are a member of the YSE community.',
    ],
  ];

  /**
   * Constructs an EventsPreviewController.
   *
   * @param \Drupal\yse_connectrequester\Service\YaleConnectFetcher $fetcher
   *   The YaleConnect fetcher service.
   */
  public function __construct(
    protected YaleConnectFetcher $fetcher,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('yse_connectrequester.fetcher'),
    );
  }

  /**
   * Renders the standard daily events email preview (permissive audience check).
   *
   * @param string $audience
   *   One of 'yse', 'staff', or 'students'.
   *
   * @return array
   *   A render array.
   */
  public function dailyEventsEmail(string $audience = 'yse'): array {
    return $this->buildPreview($audience, 'check');
  }

  /**
   * Renders the filtered daily events email preview (strict audience filter).
   *
   * @param string $audience
   *   One of 'yse', 'staff', or 'students'.
   *
   * @return array
   *   A render array.
   */
  public function dailyEventsEmailFiltered(string $audience = 'yse'): array {
    return $this->buildPreview($audience, 'filter');
  }

  /**
   * Builds the preview render array for either filtering method.
   *
   * @param string $audience
   *   Audience segment from the URL.
   * @param string $method
   *   'check' for permissive or 'filter' for strict audience filtering.
   *
   * @return array
   *   A render array.
   */
  protected function buildPreview(string $audience, string $method): array {
    $config = $this->config('yse_connectrequester.settings');
    $api_secret = $config->get('api_secret') ?? '';
    $group_type_ids = $config->get('group_type_ids') ?? '4951287,35203';
    $cutoff_days = $method === 'filter'
      ? (int) ($config->get('cutoff_days_filtered') ?? 14)
      : (int) ($config->get('cutoff_days_email') ?? 13);

    $audience_data = self::AUDIENCE_MAP[$audience] ?? self::AUDIENCE_MAP['yse'];
    $email_audience = $audience_data['label'];

    $events = $this->fetcher->fetchEvents($cutoff_days, $api_secret, $group_type_ids);

    if ($events === NULL) {
      return [
        '#markup' => '<div style="text-align:center;font-size:18px;"><h3>Sorry for the Glitch</h3><p>The YaleConnect API could not be reached. Please try again in a few seconds.</p></div>',
      ];
    }

    $listings = $this->fetcher->prepareListings($events, $email_audience, $method);

    return [
      '#theme' => 'yse_connectrequester_events',
      '#email_audience' => $email_audience,
      '#admin_header_bg_color' => $audience_data['bg_color'],
      '#footer_because' => $audience_data['footer_because'],
      '#listings' => $listings['all'],
      '#listings_for_audience' => $listings['for_audience'],
    ];
  }

}
