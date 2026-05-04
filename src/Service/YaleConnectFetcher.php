<?php

declare(strict_types=1);

namespace Drupal\yse_connectrequester\Service;

use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\RequestException;
use Psr\Log\LoggerInterface;

/**
 * Fetches and processes event data from the YaleConnect RSS API.
 */
class YaleConnectFetcher {

  /**
   * The YaleConnect RSS events endpoint.
   */
  const API_BASE_URL = 'https://yaleconnect.yale.edu/rss_events';

  /**
   * Constructs a YaleConnectFetcher.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client.
   * @param \Psr\Log\LoggerInterface $logger
   *   The logger channel.
   */
  public function __construct(
    protected ClientInterface $httpClient,
    protected LoggerInterface $logger,
  ) {}

  /**
   * Fetches upcoming events from the YaleConnect API.
   *
   * @param int $cutoff_days
   *   Number of days ahead to fetch events for.
   * @param string $api_secret
   *   The API secret sent as X-CG-API-Secret header.
   * @param string $group_type_ids
   *   Comma-separated group type IDs.
   *
   * @return array|null
   *   Normalized array of event arrays, or NULL on failure.
   */
  public function fetchEvents(int $cutoff_days, string $api_secret, string $group_type_ids): ?array {
    $cutoff_date = new \DateTime("+{$cutoff_days} days");
    $url = self::API_BASE_URL . '?' . http_build_query([
      'time_range' => 'upcoming_only',
      'event_starts_before' => $cutoff_date->format('Y-m-d'),
      'group_type_ids' => $group_type_ids,
      'ignore_location_privacy' => '1',
    ]);

    try {
      $response = $this->httpClient->request('GET', $url, [
        'headers' => ['X-CG-API-Secret' => $api_secret],
        'verify' => FALSE,
      ]);
      $body = (string) $response->getBody();
    }
    catch (RequestException $e) {
      $this->logger->error('YaleConnect API request failed: @message', [
        '@message' => $e->getMessage(),
      ]);
      return NULL;
    }

    $xml = simplexml_load_string($body);
    if ($xml === FALSE) {
      $this->logger->error('YaleConnect API returned unparseable XML.');
      return NULL;
    }

    $array = json_decode(json_encode($xml, JSON_FORCE_OBJECT), TRUE);

    if (empty($array['channel']['item'])) {
      return [];
    }

    return $this->normalizeItems($array['channel']['item']);
  }

  /**
   * Normalizes the raw channel items array to a consistently indexed list.
   *
   * The YaleConnect RSS feed returns a single item as an associative array
   * rather than a numeric array of items. This method ensures a consistent
   * numeric-indexed array is always returned.
   *
   * @param array $raw_items
   *   The raw channel item array from the parsed XML.
   *
   * @return array
   *   Numeric-indexed array of event arrays.
   */
  public function normalizeItems(array $raw_items): array {
    // A single item has string keys (associative); multiple items are numeric.
    if (array_keys($raw_items) !== range(0, count($raw_items) - 1)) {
      return [$raw_items];
    }
    return $raw_items;
  }

  /**
   * Filters events for the given audience using the permissive check.
   *
   * Events with no YSE audience tags pass through. Events with YSE tags must
   * match the target audience and must not be excluded.
   *
   * @param string $email_audience
   *   The audience label (e.g. 'YSE Students', 'YSE Faculty/Staff', 'YSE').
   * @param string|array $event_tags
   *   The event topic tags as a comma-separated string or array.
   *
   * @return bool
   *   TRUE if the event should be shown to this audience.
   */
  public function audienceCheck(string $email_audience, string|array $event_tags): bool {
    if (is_array($event_tags)) {
      $event_tags = implode(',', $event_tags);
    }

    // No tags means the event is open to all.
    if (!$event_tags) {
      return TRUE;
    }

    // Tags are present but none are YSE-specific — show to all.
    if (strpos($event_tags, 'YSE ') === FALSE) {
      return TRUE;
    }

    // Tags include a YSE audience scope — check for exclusion first.
    if (strpos($event_tags, 'Exclude from YSE ') !== FALSE) {
      return FALSE;
    }

    // Show only if the target audience is explicitly included.
    return strpos($event_tags, $email_audience) !== FALSE;
  }

  /**
   * Filters events for the given audience using the strict filter.
   *
   * Only shows events that have a tag explicitly matching the audience. Events
   * tagged "Exclude from YSE" are always hidden regardless of other tags.
   *
   * @param string|array $display_audiences
   *   The audience label(s) to match against.
   * @param string|array $event_tags
   *   The event topic tags as a comma-separated string or array.
   *
   * @return bool
   *   TRUE if the event should be shown to this audience.
   */
  public function audienceFilter(string|array $display_audiences, string|array $event_tags): bool {
    $audiences_array = is_array($display_audiences) ? $display_audiences : [$display_audiences];

    if (is_array($event_tags)) {
      $event_tags_string = implode(',', $event_tags);
      $event_tags_array = $event_tags;
    }
    else {
      $event_tags_string = $event_tags;
      $event_tags_array = explode(',', $event_tags);
    }

    $display = FALSE;
    foreach ($audiences_array as $audience) {
      if (in_array($audience, $event_tags_array)) {
        $display = TRUE;
        break;
      }
    }

    // Exclusion tag overrides all other tags.
    if (strpos($event_tags_string, 'Exclude from YSE') !== FALSE) {
      $display = FALSE;
    }

    return $display;
  }

  /**
   * Adds computed properties to each event array and filters by audience.
   *
   * @param array $events
   *   Normalized list of raw event arrays.
   * @param string $email_audience
   *   The audience label to filter for.
   * @param string $method
   *   Either 'check' (permissive) or 'filter' (strict).
   *
   * @return array{all: object[], for_audience: object[]}
   *   Keyed array with all events and audience-filtered events as objects.
   */
  public function prepareListings(array $events, string $email_audience, string $method): array {
    $all = [];
    $for_audience = [];

    foreach ($events as $event_array) {
      $event = (object) $event_array;
      $event->start_datetime = new \DateTime($event->eventStartDateTime);
      $event->end_datetime = new \DateTime($event->eventEndDateTime);
      $event->date = $event->start_datetime->format('Y-m-d');
      $all[] = $event;

      $passes = $method === 'filter'
        ? $this->audienceFilter($email_audience, $event->eventTopics ?? '')
        : $this->audienceCheck($email_audience, $event->eventTopics ?? '');

      if ($passes) {
        $for_audience[] = $event;
      }
    }

    return ['all' => $all, 'for_audience' => $for_audience];
  }

}
