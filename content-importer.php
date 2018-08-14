<?php

use Drupal\node\Entity\Node;
use Drupal\taxonomy\Entity\Term;

/**
 * Implements hook_cron().
 */
function content_import_cron() {

  // Do not run in an http request.
  if (PHP_SAPI !== 'cli') {
    return;
  }
  //Make sure we are only running once every 30mins.
  $last_update = \Drupal::state()->get('content_import.last_update');
  if (!empty($last_update) && gmdate('U') - $last_update < (1800)) {
    return;
  }

  \Drupal::state()->set('content_import.last_update', gmdate('U'));

    //get environtment and set drupal http client

  $client = \Drupal::httpClient();

  $config = \Drupal::config('content_import.settings');
  $domain = $config->get('domain');

  if (empty($domain)) {
    return;
  }
  $source_ids = [];
  foreach ($domain as $brand => $baseuri) {

    $query = [
      'include' => 'field_headshot,field_locations_serviced,field_markets_serviced,field_providers_specialties',
      'fields[file--file]'            => 'url',
      'fields[node--location]'        => 'title',
      'fields[taxonomy_term--market]' => 'name',
      'fields[node--specialty]'       => 'title',
      'filter[status][value]'         => '1'
    ];

    $jsonuri = rtrim($baseuri, '/') . '/jsonapi/node/care_provider?'. http_build_query($query);

    $completed = false;
    while ($completed === false) {
      if(empty($jsonuri)) {
        break;
      }

      try {
        $response = $client->get($jsonuri);
        $blob = (string) $response->getBody();
        $blob = json_decode($blob, TRUE);
        $included = $blob['included'];
        $data = $blob['data'];

        if (empty($blob)) {
          $completed = true;
          continue;
        }

        $brand_tid = content_get_tid([$brand], 'brands');
        $brand_tid = $brand_tid[0];

        // Extract all of our source_ids from the response.
        $source_ids = array_merge($source_ids, array_map(function ($item) use($brand_tid) {
          return $brand_tid . ':' . $item['id'];
        }, $data));

        foreach ($data as $item) {
          $care_provider = content_import_get_entity($item, $brand_tid);
          $headshot_ids = content_get_related_content_ids($item['relationships']['field_headshot']['data'], true);
          $field_headshot = content_get_related_content($headshot_ids, $included, 'url', $baseuri);
          $market_ids = content_get_related_content_ids($item['relationships']['field_markets_serviced']['data']);
          $field_markets_serviced = content_get_related_content($market_ids, $included, 'name');
          $specialty_ids = content_get_related_content_ids($item['relationships']['field_providers_specialties']['data']);
          $field_providers_specialties = content_get_related_content($specialty_ids, $included);
          $location_ids = content_get_related_content_ids($item['relationships']['field_locations_serviced']['data']);
          $field_locations_serviced = content_get_related_content($location_ids, $included);

          $market_tid = $field_markets_serviced ? content_get_tid($field_markets_serviced, 'markets') : NULL;
          $locations_nid = $field_locations_serviced ? content_import_get_nid($field_locations_serviced, 'location') : NULL;
          $specialty_nid = $field_providers_specialties ? content_import_get_nid($field_providers_specialties, 'specialty') : NULL;
          $headshot_url = $field_headshot[0] ? $field_headshot[0] : NULL;

          $care_provider->set('field_care_provider_brand', $brand_tid);
          $care_provider->setTitle($item['attributes']['title']);
          $care_provider->set('field_biography', $item['attributes']['body']);
          $care_provider->set('field_associations', $item['attributes']['field_associations']);
          $care_provider->set('field_education', $item['attributes']['field_education']);
          $care_provider->set('field_headshot', $headshot_url);
          $care_provider->set('field_locations_serviced', $locations_nid);
          $care_provider->set('field_markets_serviced', $market_tid);
          $care_provider->set('field_providers_specialties', $specialty_nid);

          try {
            $care_provider->save();
          } catch (\Exception $e) {
            watchdog_exception('content_import`', $e);
          }
        }
    }

    catch (RequestException $e) {
      watchdog_exception('content_import`', $e);
    }

    $jsonuri = isset($blob['links']['next']) ? $blob['links']['next'] : null;
    }


  }

  // Fetch a list of entity_ids to be deleted.
  $query = \Drupal::entityQuery('node');
  $query->condition('type', 'care_provider');
  $query->condition('field_source_id', $source_ids, 'NOT IN');
  $entity_ids = $query->execute();
  // Delete any locations that were not in the list.
  if (!empty($entity_ids)) {
    entity_delete_multiple('node', $entity_ids);
  }

  \Drupal::logger('content_import')->info('Content imported successfully');
}

/**
 * Load the location node if it exists, otherwise create a new one.
 *
 * @param array $data
 *   The data for this location.
 *
 * @return Drupal\node\Entity\Node
 *   A location node.
 */
function content_import_get_entity(array $data, $source_prefix) {
  $source_id = $source_prefix . ':' . $data['id'];

  $query = \Drupal::entityQuery('node');
  $query->condition('field_source_id', $source_id);
  $query->condition('type', 'care_provider');
  $entity_ids = $query->execute();
  if (!empty($entity_ids)) {
    $nid = reset($entity_ids);
    return Node::load($nid);
  }

  return Node::create([
    'type' => 'care_provider',
    'uid' => 1,
    'status' => 1,
    'field_source_id' => $source_id
  ]);

}

//create helper to get ids
function content_get_related_content_ids($data, $top_level = false, $debug = false) {
  $ids = [];
  if (!empty($data)) {
    if ($top_level) {
      if (!empty($data['id'])) {
        $ids[] = $data['id'];
      }
    } else {
      foreach ($data as $datapoint) {
        if (!empty($datapoint['id'])) {
          $ids[] = $datapoint['id'];
        }
      }
    }
  }

  if ($debug) {
    var_dump($ids);
  }

  return $ids;
}

//create helper function that looks up id and returns the relationship value.
function content_get_related_content($field_ids, array $related_fields, $key = 'title', $headshot_domain = NULL, $debug = false) {

    $result = array();

    foreach($field_ids as $field_id) {
      foreach($related_fields as $related_field) {

        if ($field_id === $related_field['id']) {

          if ($related_field['type'] === 'file--file') {
            $url = rtrim($headshot_domain, '/') . $related_field['attributes'][$key];
            $result[] = $url;
          } else {
            $result[] = $related_field['attributes'][$key];
          }

        }

      }

    }

    if ($debug) {
      var_dump($result);
    }

    return $result;
}

/**
 * Returns a term_id for a combination of term name and vocabulary machine name.
 *
 * If the term does not exist, it will be created.
 *
 * @param array $name
 *   The term name.
 * @param string $vocabulary_machine_name
 *   The machine name of the vocabulary.
 *
 * @return int
 *   The term id of the existing or just created term
 */
function content_get_tid(array $names, $vocabulary_machine_name) {

  if (!is_array($names)) {return NULL;}

  foreach ($names as $i => $name) {
    $name = trim($name);
    if (empty($name)) {
      unset($names[$i]);
    }
  }

  if(!count($names)) {
    return NULL;
  }

    $vocab = entity_load('taxonomy_vocabulary', $vocabulary_machine_name);
    if (empty($vocab)) {
      \Drupal::logger('content_import')->error('Attempted to lookup a term for a vocabulary that does not exist: ' . $vocabulary_machine_name);
      return NULL;
    }

    $terms = array();
    $missing_terms = array();
    $new_terms = array();
    $set_before = false;

    foreach ($names as $name) {
      $term_storage = \Drupal::entityTypeManager()->getStorage('taxonomy_term');
      $term = $term_storage->loadByProperties(['name' => $name, 'vid' => $vocabulary_machine_name]);

      $term = reset($term);

      if ($term) {
        $terms[] = $term->id();
        $parent = $term_storage->loadParents($term->id());
        if ($parent && !$set_before) {
          $set_before = true;
          $terms[] = reset($parent)->id();
        }
      } else {
        $missing_terms[] = $name;
      }
    }

    if (count($terms) === count($names)) {
      return $terms;
    }

    foreach ($missing_terms as $missing_term) {
      $term = Term::create([
        'name' => $missing_term,
        'vid' => $vocabulary_machine_name,
      ]);
      $term->save();
      $new_terms[] = $term->id();
    }

    return array_merge($terms, $new_terms);

  }

/**
 * Returns a node_id for a combination of title and content type.
 *
 * If the content does not exist, it will be created. Note that duplicate titles
 * will only return the first value.
 *
 * @param array $title
 *   The node title.
 * @param string $type
 *   The machine name of the content_type.
 *
 * @return int
 *   The node id of the existing or just created node
 */
function content_import_get_nid(array $titles, $type, $debug = false) {

  if (!is_array($titles)) {return NULL;}

  foreach ($titles as $i => $title) {
    $title = trim($title);
    if (empty($title)) {
      unset($titles[$i]);
    }
  }

  if(!count($titles)) {
    return NULL;
  }

  $nodes = array();
  $missing_nodes = array();
  $new_nodes = array();

  foreach ($titles as $title) {
    $node = \Drupal::entityTypeManager()
    ->getStorage('node')
    ->loadByProperties(['title' => $title, 'type' => $type]);

    $node = reset($node);

    if ($node) {
      if ($debug) {
        var_dump($node->id());
      }
      $nodes[] = $node->id();
    } else {
      $missing_nodes[] = $title;
    }
  }

  if (count($nodes) === count($titles)) {
    return $nodes;
  }

  foreach ($missing_nodes as $missing_node) {
    $node = Node::create([
      'uid'   => 1,
      'status' => 1,
      'title' => $missing_node,
      'type'  => $type,
    ]);
    $node->save();
    $new_nodes[] = $node->id();
  }

  return array_merge($nodes, $new_nodes);

}