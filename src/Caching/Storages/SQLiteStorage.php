<?php

/**
 * This file is part of the Nette Framework (http://nette.org)
 * Copyright (c) 2004 David Grudl (http://davidgrudl.com)
 */

namespace Nette\Caching\Storages;

use Nette;
use Nette\Caching\Cache;


/**
 * SQLite storage.
 */
class SQLiteStorage extends Nette\Object implements Nette\Caching\IStorage
{
	/** @var \PDO */
	private $pdo;


	public function __construct($path = ':memory:')
	{
		$this->pdo = new \PDO('sqlite:' . $path, NULL, NULL, [\PDO::ATTR_PERSISTENT => TRUE]);
		$this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
		$this->pdo->exec('
			PRAGMA foreign_keys = ON;
			CREATE TABLE IF NOT EXISTS cache (
				key BLOB NOT NULL PRIMARY KEY,
				data BLOB NOT NULL,
				expire INTEGER,
				slide INTEGER
			);
			CREATE TABLE IF NOT EXISTS tags (
				key BLOB NOT NULL REFERENCES cache ON DELETE CASCADE,
				tag BLOB NOT NULL
			);
			CREATE INDEX IF NOT EXISTS cache_expire ON cache(expire);
			CREATE INDEX IF NOT EXISTS tags_key ON tags(key);
			CREATE INDEX IF NOT EXISTS tags_tag ON tags(tag);
			PRAGMA synchronous = OFF;
		');
	}


	/**
	 * Read from cache.
	 * @param  string key
	 * @return mixed|NULL
	 */
	public function read($key)
	{
		$stmt = $this->pdo->prepare('SELECT data, slide FROM cache WHERE key=? AND (expire IS NULL OR expire >= ?)');
		$stmt->execute([$key, time()]);
		if ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
			if ($row['slide'] !== NULL) {
				$this->pdo->prepare('UPDATE cache SET expire = ? + slide WHERE key=?')->execute([time(), $key]);
			}
			return unserialize($row['data']);
		}
	}


	/**
	 * Prevents item reading and writing. Lock is released by write() or remove().
	 * @param  string key
	 * @return void
	 */
	public function lock($key)
	{
	}


	/**
	 * Writes item into the cache.
	 * @param  string key
	 * @param  mixed  data
	 * @param  array  dependencies
	 * @return void
	 */
	public function write($key, $data, array $dependencies)
	{
		$expire = isset($dependencies[Cache::EXPIRATION]) ? $dependencies[Cache::EXPIRATION] + time() : NULL;
		$slide = isset($dependencies[Cache::SLIDING]) ? $dependencies[Cache::EXPIRATION] : NULL;

		$this->pdo->exec('BEGIN TRANSACTION');
		$this->pdo->prepare('REPLACE INTO cache (key, data, expire, slide) VALUES (?, ?, ?, ?)')
			->execute([$key, serialize($data), $expire, $slide]);

		if (!empty($dependencies[Cache::TAGS])) {
			foreach ((array) $dependencies[Cache::TAGS] as $tag) {
				$arr[] = $key;
				$arr[] = $tag;
			}
			$this->pdo->prepare('INSERT INTO tags (key, tag) SELECT ?, ?' . str_repeat('UNION SELECT ?, ?', count($arr) / 2 - 1))
				->execute($arr);
		}
		$this->pdo->exec('COMMIT');
	}


	/**
	 * Removes item from the cache.
	 * @param  string key
	 * @return void
	 */
	public function remove($key)
	{
		$this->pdo->prepare('DELETE FROM cache WHERE key=?')
			->execute([$key]);
	}


	/**
	 * Removes items from the cache by conditions & garbage collector.
	 * @param  array  conditions
	 * @return void
	 */
	public function clean(array $conditions)
	{
		if (!empty($conditions[Cache::ALL])) {
			$this->pdo->prepare('DELETE FROM cache')->execute();

		} else {
			$sql = 'DELETE FROM cache WHERE expire < ?';
			$args = [time()];

			if (!empty($conditions[Cache::TAGS])) {
				$tags = (array) $conditions[Cache::TAGS];
				$sql .= ' OR key IN (SELECT key FROM tags WHERE tag IN (?' . str_repeat(',?', count($tags) - 1) . '))';
				$args = array_merge($args, $tags);
			}

			$this->pdo->prepare($sql)->execute($args);
		}
	}

}
