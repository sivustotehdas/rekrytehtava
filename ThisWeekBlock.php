<?php
/**
 * @file
 * Contains \Drupal\custom\Plugin\Block\ThisWeekBlock.
 */
namespace Drupal\custom\Plugin\Block;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Database\Connection;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityViewBuilderInterface;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;

/**
 * @Block(
 *   id = "custom_this_week",
 *   admin_label = @Translation("This week block"),
 * )
 */
class ThisWeekBlock extends BlockBase implements ContainerFactoryPluginInterface {
  /**
   * The node storage.
   *
   * @var EntityStorageInterface
   */
  protected $nodeStorage;


  /**
   * The view builder.
   *
   * @var EntityViewBuilderInterface
   */
  protected $viewBuilder;

  /**
   * {@inheritdoc}
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, Connection $database, EntityStorageInterface $node_storage, EntityViewBuilderInterface $view_builder) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);

    $this->nodeStorage = $node_storage;
    $this->viewBuilder = $view_builder;
    $this->database = $database;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $entity_type_manager = $container->get('entity_type.manager');

    return new static(
      $configuration, $plugin_id, $plugin_definition,
      $container->get('database'),
      $entity_type_manager->getStorage('node'),
      $entity_type_manager->getViewBuilder('node')
    );
  }

  public function build() {
    // get the current node (frontpage node)
    $node = \Drupal::routeMatch()->getParameter('node');
    if (empty($node) or $node->bundle() != 'etusivu') {
      return NULL;
    }

    // build the output
    $build = [
      '#type' => 'container',
      '#attributes' => [
        'class' => ['this-week-wrapper']
      ]
    ];

    $nodes = $this->getNodes();

    if (!count($nodes)) {
      return NULL;
    }

    // build the view
    $build['nodes'] = $this->viewBuilder->viewMultiple($nodes, 'carousel');

    foreach (['esitys', 'tapahtuma', 'kurssi'] as $bundle) {
      $build['#cache']['tags'][] = 'node_type:'.$bundle;
    }

    $build['#cache']['tags'][] = 'sivustotehdas:weekly';

    foreach ($nodes as $node) {
      $build['#cache']['tags'][] = 'node:'.$node->id();
    }

    return $build;
  }

  private function getNodes() {
    $start_of_week = date('Y-m-d H:i:s', strtotime('this week monday'));
    $end_of_week = date('Y-m-d H:i:s', strtotime('this week monday + 7 days'));

    $date_start_of_week = new DrupalDateTime($start_of_week);
    $date_end_of_week = new DrupalDateTime($end_of_week);

    $sql_date_format = 'Y-m-d\TH:i:s';
    $sql_start_of_week = $date_start_of_week->format($sql_date_format);
    $sql_end_of_week = $date_end_of_week->format($sql_date_format);

    $field_tapahtuma_ajat_nodes = $this->getFieldTapahtumaAjatNodes($sql_start_of_week, $sql_end_of_week);
    $field_ilmoittautuminen_paattyy_nodes = $this->getFieldIlmoittautuminenPaattyyNodes($sql_start_of_week, $sql_end_of_week);

    $nodes = array_merge($field_tapahtuma_ajat_nodes, $field_ilmoittautuminen_paattyy_nodes);

    // sort
    $_nodes = [];
    foreach ($nodes as $node) {
      if ($node->hasField('field_ilmoittautuminen_paattyy')) {
        $sort_by_t = strtotime($node->field_ilmoittautuminen_paattyy->value);
      } else {
        $sort_by_t = strtotime($node->field_tapahtuma_ajat_ref->entity->field_aika->value);
      }
      $sort_key = sprintf('%d_%06d', $sort_by_t, $node->id());
      $_nodes[$sort_key] = $node;
    }

    ksort($_nodes);
    $nodes = array_values($_nodes);

    return $nodes;
  }

  private function getFieldTapahtumaAjatNodes($sql_start_of_week, $sql_end_of_week) {
    // allowed bundles
    $bundles = ['esitys', 'tapahtuma'];

    $query = $this->database->select('paragraph__field_aika', 'field_aika');

    // date conditions: if end value is null, start value should be inside the
    // week range. If end value is not null, start should be smaller than end of week
    // and end value bigger than start of week.
    $or = $query->orConditionGroup();
    $and1 = $query->andConditionGroup();
    $and2 = $query->andConditionGroup();
    $and1->isNull('field_aika.field_aika_end_value');
    $and1->condition('field_aika.field_aika_value', $sql_start_of_week, '>=');
    $and1->condition('field_aika.field_aika_value', $sql_end_of_week, '<');

    $and2->isNotNull('field_aika.field_aika_end_value');
    $and2->condition('field_aika.field_aika_value', $sql_end_of_week, '<');
    $and2->condition('field_aika.field_aika_end_value', $sql_start_of_week, '>');

    $or->condition($and1);
    $or->condition($and2);

    $query->condition($or);

    // limit to published parent nodes
    $query->join('paragraphs_item_field_data', 'item_field_data', 'item_field_data.id = field_aika.entity_id');
    // NOTE: paragraphs parent_id is a string, node id is a bigint - we have to type cast when using postgresql.
    $query->join('node_field_data', 'node_field_data', 'node_field_data.nid = item_field_data.parent_id::INTEGER');
    $query->condition('node_field_data.status', NodeInterface::PUBLISHED);

    // current language only
    $query->condition('node_field_data.langcode', \Drupal::languageManager()->getCurrentLanguage()->getId());

    // limit content types
    $query->condition('node_field_data.type', $bundles, 'IN');

    // add nid to fields
    $query->fields('node_field_data', ['nid']);

    // order and range limit
    $query
      ->orderBy('field_aika.field_aika_value', 'ASC');

    // execute the query and fetch node ids
    $entity_ids = $query->execute()->fetchCol('nid');

    // load nodes
    if (!empty($entity_ids)) {
      $nodes = $this->nodeStorage->loadMultiple($entity_ids);
    } else {
      $nodes = [];
    }

    return $nodes;
  }

  private function getFieldIlmoittautuminenPaattyyNodes($sql_start_of_week, $sql_end_of_week) {
    $bundles = ['kurssi'];

    $limit = 10;

    $query = $this->$database->select('node__field_ilmoittautuminen_paattyy', 'field_ilmoittautuminen_paattyy');

    // date conditions: if end value is null, start value should be inside the
    // week range. If end value is not null, start should be smaller than end of week
    $query->condition('field_ilmoittautuminen_paattyy.field_ilmoittautuminen_paattyy_value', substr($sql_start_of_week, 0, 10), '>=');
    $query->condition('field_ilmoittautuminen_paattyy.field_ilmoittautuminen_paattyy_value', substr($sql_end_of_week, 0, 10), '<');

    $query->join('node_field_data', 'node_field_data', 'node_field_data.nid = field_ilmoittautuminen_paattyy.entity_id');
    $query->condition('node_field_data.status', NodeInterface::PUBLISHED);

    // current language only
    $query->condition('node_field_data.langcode', \Drupal::languageManager()->getCurrentLanguage()->getId());

    // limit content types
    $query->condition('node_field_data.type', $bundles, 'IN');

    // add nid to fields
    $query->fields('node_field_data', ['nid']);

    // order and range limit
    $query
      ->orderBy('field_ilmoittautuminen_paattyy.field_ilmoittautuminen_paattyy_value', 'ASC');

    // execute the query and fetch node ids
    $entity_ids = array_slice(array_unique($query->execute()->fetchCol('nid')), 0, $limit);

    // load nodes
    if (empty($entity_ids)) {
      $nodes = $this->nodeStorage->loadMultiple($entity_ids);
    } else {
      $nodes = [];
    }

    return $nodes;
  }
}
