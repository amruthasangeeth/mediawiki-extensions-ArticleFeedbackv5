<?php
/**
 * "wfGetDB" implementation to power DataModel.
 *
 * This class connects to a single database setup with master/slaves
 * architecture.
 *
 * @author     Matthias Mullie <mmullie@wikimedia.org>
 * @version    $Id$
 */
class DataModelBackendLBFactory extends DataModelBackend {
	/**
	 * @var array [bool]
	 */
	protected static $written = array();

	/**
	 * @param $wiki String: the wiki ID, or false for the current wiki
	 * @return LoadBalancer
	 */
	public function getLB( $wiki = false ) {
		return wfGetLB( $wiki );
	}

	/**
	 * Wrapper function for wfGetDB.
	 *
	 * @param $db Integer: index of the connection to get. May be DB_MASTER for the
	 *            master (for write queries), DB_SLAVE for potentially lagged read
	 *            queries, or an integer >= 0 for a particular server.
	 * @param $groups Mixed: query groups. An array of group names that this query
	 *                belongs to. May contain a single string if the query is only
	 *                in one group.
	 * @param $wiki String: the wiki ID, or false for the current wiki
	 */
	public function getDB( $db, $groups = array(), $wiki = false ) {
		/*
		 * Since we'll save a flag to indicate if a certain wiki has been written
		 * to, we'll want to be certain that this data is accurate, and we don't
		 * want a lower-down function to determine that false will result in
		 * wfWikiID().
		 * Let's make sure that false also translates to a literal wiki name (e.g.
		 * "enwiki", or whatever wfWikiID() results in); this way, if we access 2
		 * the same wiki in 2 different ways, we'll still know for sure if data
		 * has been written to that database already.
		 */
		$wikiId = ( $wiki === false ) ? wfWikiID() : $wiki;

		$lb = $this->getLB( $wiki );

		if ( $db === DB_MASTER ) {
			// mark that we're writing data
			static::$written[$wikiId] = true;
		} elseif ( isset( static::$written[$wikiId] ) && static::$written[$wikiId] ) {
			if ( $db === DB_SLAVE ) {
				/*
				 * Let's keep querying master to make sure we have up-to-date
				 * data (waiting for slaves to sync up might take some time)
				 */
				$db = DB_MASTER;
			} else {
				/*
				 * If another db is requested and we already requested master,
				 * make sure this slave has caught up!
				 */
				$lb->waitFor( $lb->getMasterPos() );
				static::$written[$wikiId] = false;
			}
		}

		return $lb->getConnection( $db, $groups, $wiki );
	}

	/**
	 * Before caching data read from backend, we have to make sure that the
	 * content read is in fact "cacheable" (e.g. not read from a lagging slave)
	 *
	 * @return bool
	 */
	public function allowCache() {
		return !$this->getLB()->getLaggedSlaveMode();
	}

	/**
	 * Query to fetch entries from DB.
	 *
	 * @param mixed $id The id(s) to fetch
	 * @param mixed $shard The corresponding shard value(s)
	 * @return ResultWrapper
	 */
	public function get( $id = null, $shard = null ) {
		// query conditions
		$conds = array();
		if ( $id ) {
			$conds[$this->idColumn] = $id;
		}
		if ( $shard ) {
			$conds[$this->shardColumn] = $shard;
		}

		return $this->getDB( DB_SLAVE )->select(
			$this->table,
			'*',
			$conds,
			__METHOD__,
			array()
		);
	}

	/**
	 * Insert entry.
	 *
	 * @param DataModel $entry
	 * @return int
	 */
	public function insert( DataModel $entry ) {
		return $this->getDB( DB_MASTER )->insert(
			$this->table,
			$entry->toArray(),
			__METHOD__
		);
	}

	/**
	 * Update entry.
	 *
	 * @param DataModel $entry
	 * @return int
	 */
	public function update( DataModel $entry ) {
		$data = $entry->toArray();
		unset( $data[$this->shardColumn] );

		return $this->getDB( DB_MASTER )->update(
			$this->table,
			$data,
			array(
				$this->idColumn => $entry->{$this->idColumn},
				$this->shardColumn => $entry->{$this->shardColumn}
			),
			__METHOD__
		);
	}

	/**
	 * Delete entry.
	 *
	 * @param DataModel $entry
	 * @return int
	 */
	public function delete( DataModel $entry ) {
		return $this->getDB( DB_MASTER )->delete(
			$this->table,
			array(
				$this->idColumn => $entry->{$this->idColumn},
				$this->shardColumn => $entry->{$this->shardColumn}
			),
			__METHOD__
		);
	}

	/**
	 * Fetch a list.
	 *
	 * @param string $name The list name (see <datamodel>::$lists)
	 * @param mixed $shard Get only data for a certain shard value
	 * @param int $offset The offset to start fetching entries from
	 * @param int $limit The amount of entries to fetch
	 * @param string $sort Sort to apply to list
	 * @param string $order Sort the list ASC or DESC
	 * @return ResultWrapper
	 */
	public function getList( $name, $shard = null, $offset = null, $limit, $sort = null, $order ) {
		$dbr = $this->getDB( DB_SLAVE );

		$tables = array();
		$vars = array();
		$conds = array();
		$options = array();

		$tables[] = $this->table;

		$vars[] = '*';

		/*
		 * This class does not really allow for sharding data over multiple
		 * servers (since wfGetDB will only return one write master).
		 * As a result, even if no specific shard column is specified (in
		 * which case we'd have to query all servers), we don't need to do
		 * anything special here: out data is on only 1 server.
		 * If we were sharding for real, multiple servers would have to be
		 * queried & the results combined ;)
		 */
		if ( $shard ) {
			$conds[$this->shardColumn] = $shard;
		}

		// "where"
		$conditions = $this->getConditions( $name );
		$conds += $conditions;

		// "order by"
		$sort = $this->getSort( $sort );
		$options['ORDER BY'] = array();
		if ( $sort ) {
			$options['ORDER BY'][] = "$sort $order";
		}
		$options['ORDER BY'][] = "$this->idColumn $order";

		// "offset"-alternative
		if ( $sort ) {
			$vars['offset_value'] = $sort;
		}

		$options['LIMIT'] = $limit;
		list( $sortOffset, $idOffset ) = $this->unpackOffset( $offset );
		if ( $idOffset !== null ) {
			$direction = $order == 'ASC' ? '>' : '<';
			$sortOffset = $dbr->addQuotes( $sortOffset );
			$idOffset = $dbr->addQuotes( $idOffset );
			if ( $sort && $sortOffset ) {
				// sort offset defined; add to conditions
				$conds[] = "
					($sort $direction $sortOffset) OR
					($sort = $sortOffset AND $this->idColumn $direction= $idOffset)";
			} elseif ( !$sort && $idOffset ) {
				$conds[] = "$this->idColumn $direction= $idOffset";
			}
		}

		return $dbr->select(
			$tables,
			$vars,
			$conds,
			__METHOD__,
			$options
		);
	}

	/**
	 * Get the amount of entries in a certain list.
	 *
	 * @param string $name The list name (see <datamodel>::$lists)
	 * @param mixed $shard Get only data for a certain shard value
	 * @return array
	 */
	public function getCount( $name, $shard = null ) {
		$dbr = $this->getDB( DB_SLAVE );

		$tables = array();
		$vars = array();
		$conds = array();
		$options = array();

		$tables[] = $this->table;

		$vars[] = 'COUNT(*)';

		/*
		 * This class does not really allow for sharding data over multiple
		 * servers (since wfGetDB will only return one write master).
		 * As a result, even if no specific shard column is specified (in
		 * which case we'd have to query all servers), we don't need to do
		 * anything special here: out data is on only 1 server.
		 * If we were sharding for real, multiple servers would have to be
		 * queried & the results combined ;)
		 */
		if ( $shard ) {
			$conds[$this->shardColumn] = $shard;
		}

		// "where"
		$conditions = $this->getConditions( $name );
		$conds += $conditions;

		return (int) $dbr->selectField(
			$tables,
			$vars,
			$conds,
			__METHOD__,
			$options
		);
	}

	/**
	 * Evaluate an entry to possible conditions.
	 *
	 * Before updating data, DataModel will want to re-evaluate en entry to
	 * all possible conditions, to know which caches need to be purged/updated.
	 *
	 * @param DataModel $entry
	 * @return ResultWrapper
	 */
	public function evaluateConditions( DataModel $entry ) {
		$class = $this->datamodel;

		// get list of all conditions
		$conditions = array();
		foreach ( $class::$lists as $list => $properties ) {
			$conditions = array_merge( $conditions, $class::getListConditions( $list ) );
		}

		// sorts and conditions are to be treated alike for this purpose
		$conditions = array_merge( $conditions, array_values( $class::$sorts ) );

		return $this->getDB( DB_SLAVE )->selectRow(
			$this->table,
			array_unique( $conditions ),
			array(
				$this->idColumn => $entry->{$this->idColumn},
				$this->shardColumn => $entry->{$this->shardColumn},
			),
			__METHOD__
		);
	}
}
