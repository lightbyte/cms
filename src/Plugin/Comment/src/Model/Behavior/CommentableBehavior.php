<?php
/**
 * Licensed under The GPL-3.0 License
 * For full copyright and license information, please see the LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @since	 2.0.0
 * @author	 Christopher Castro <chris@quickapps.es>
 * @link	 http://www.quickappscms.org
 * @license	 http://opensource.org/licenses/gpl-3.0.html GPL-3.0 License
 */
namespace Comment\Model\Behavior;

use Cake\Event\Event;
use Cake\ORM\Behavior;
use Cake\ORM\Query;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\Utility\Inflector;

/**
 * Allow entities to be commented.
 *
 */
class CommentableBehavior extends Behavior {

/**
 * The table this behavior is attached to.
 *
 * @var Table
 */
	protected $_table;

/**
 * Enable/Diable this behavior.
 *
 * @var boolean
 */
	protected $_enabled = false;

/**
 * Default configuration.
 *
 * These are merged with user-provided configuration when the behavior is used.
 *
 * @var array
 */
	protected $_defaultConfig = [
		'implementedFinders' => [
			'comments' => 'findComments',
		],
		'implementedMethods' => [
			'bindComments' => 'bindComments',
			'unbindComments' => 'unbindComments',
		],
		'order' => ['created' => 'DESC'],
	];

/**
 * Constructor.
 *
 * Here we associate `Comments` table to the
 * table this behavior is attached to.
 *
 * @param \Cake\ORM\Table $table The table this behavior is attached to.
 * @param array $config The config for this behavior.
 */
	public function __construct(Table $table, array $config = []) {
		$this->_table = $table;
		$this->_table->hasMany('Comments', [
			'className' => 'Comment.Comments',
			'foreignKey' => 'entity_id',
			'conditions' => [
				'table_alias' => strtolower($this->_table->alias()),
				'status >' => 0
			],
			'joinType' => 'LEFT',
			'dependent' => true
		]);

		parent::__construct($table, $config);
	}

/**
 * Attaches comments to each entity on find operation.
 *
 * @param \Cake\Event\Event $event
 * @param \Cake\ORM\Query $query
 * @param array $options
 * @param boolean $primary
 * @return void
 */
	public function beforeFind(Event $event, $query, $options, $primary) {
		if ($this->_enabled) {
			if ($query->count() > 0) {
				$pk = $this->_table->primaryKey();
				$tableAlias = Inflector::underscore($this->_table->alias());

				$query->contain([
					'Comments' => function ($query) {
						return $query->find('threaded')->order($this->config('order'));
					}
				]);

				// TODO: try to move this to CounterCacheBehavior to reduce DB queries
				$query->mapReduce(function ($entity, $key, $mapReduce) use ($pk, $tableAlias) {
					$entityId = $entity->{$pk};
					$entity->set('comment_count',
						TableRegistry::get('Comment.Comments')->find()
							->where(['entity_id' => $entityId, 'table_alias' => $tableAlias])
							->count()
					);
					$mapReduce->emit($entity, $key);
				});
			}
		}
	}

/**
 * Get comments for the given entity.
 *
 * ### Usage:
 *
 *     // in your controller, gets comments for user whose id equals 2
 *     $userComments = $this->Users->find('comments', ['for' => 2]);
 *
 * @param \Cake\ORM\Query $query
 * @param array $options
 * @return array Threaded list of comments
 * @throws \InvalidArgumentException When the 'for' key is not passed in $options
 */
	public function findComments(Query $query, $options) {
		$config = $this->config();
		$pk = $this->_table->primaryKey();
		$tableAlias = $this->_table->alias();

		if (empty($options['for'])) {
			throw new \InvalidArgumentException("The 'for' key is required for find('children')");
		}

		$comments = $query
			->select(["{$tableAlias}.{$pk}"])
			->where(["{$tableAlias}.{$pk}" => $options['for']])
			->contain([
				'Comments' => function ($q) use ($config) {
					return $q->find('threaded')->order($config['order']);
				}
			])
			->first();

		if ($comments) {
			return $comments->comments;
		}

		return [];
	}

/**
 * Enables this behavior.
 *
 * Comments will be attached to entities.
 *
 * @return void
 */
	public function bindComments() {
		$this->_enabled = true;
	}

/**
 * Disables this behavior.
 *
 * Comments won't be attached to entities.
 *
 * @return void
 */
	public function unbindComments() {
		$this->_enabled = false;
	}

}