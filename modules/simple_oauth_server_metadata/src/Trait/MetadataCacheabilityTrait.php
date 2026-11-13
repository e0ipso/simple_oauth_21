<?php

declare(strict_types=1);

namespace Drupal\simple_oauth_server_metadata\Trait;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableDependencyTrait;
use Drupal\Core\Cache\CacheableMetadata;

/**
 * Combines extension baseline cacheability with the latest generation's data.
 */
trait MetadataCacheabilityTrait {

  use CacheableDependencyTrait;

  /**
   * Dependencies of the latest generation, separate from subclass defaults.
   */
  private ?CacheableMetadata $generationCacheability = NULL;

  /**
   * {@inheritdoc}
   */
  public function getCacheTags() {
    return Cache::mergeTags($this->cacheTags, $this->generationCacheability?->getCacheTags() ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheContexts() {
    return Cache::mergeContexts($this->cacheContexts, $this->generationCacheability?->getCacheContexts() ?? []);
  }

  /**
   * {@inheritdoc}
   */
  public function getCacheMaxAge() {
    return Cache::mergeMaxAges($this->cacheMaxAge, $this->generationCacheability?->getCacheMaxAge() ?? Cache::PERMANENT);
  }

}
