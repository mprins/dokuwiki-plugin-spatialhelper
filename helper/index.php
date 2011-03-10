<?php
/*
 * Copyright (c) 2008-2011 Mark C. Prins <mc.prins@gmail.com>
 *
 * Permission to use, copy, modify, and distribute this software for any
 * purpose with or without fee is hereby granted, provided that the above
 * copyright notice and this permission notice appear in all copies.
 *
 * THE SOFTWARE IS PROVIDED "AS IS" AND THE AUTHOR DISCLAIMS ALL WARRANTIES
 * WITH REGARD TO THIS SOFTWARE INCLUDING ALL IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS. IN NO EVENT SHALL THE AUTHOR BE LIABLE FOR
 * ANY SPECIAL, DIRECT, INDIRECT, OR CONSEQUENTIAL DAMAGES OR ANY DAMAGES
 * WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS, WHETHER IN AN
 * ACTION OF CONTRACT, NEGLIGENCE OR OTHER TORTIOUS ACTION, ARISING OUT OF
 * OR IN CONNECTION WITH THE USE OR PERFORMANCE OF THIS SOFTWARE.
 */

/**
 * DokuWiki Plugin spatialhelper (index Helper Component)
 *
 * @license BSD
 * @author Mark Prins
 */

// must be run within Dokuwiki
if (!defined('DOKU_INC')) die();

if (!defined('DOKU_LF')) define('DOKU_LF', "\n");
if (!defined('DOKU_TAB')) define('DOKU_TAB', "\t");
if (!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');

require_once DOKU_PLUGIN.'spatialhelper/Geohash.php';


class helper_plugin_spatialhelper_index extends DokuWiki_Plugin {
	/**
	 * directory for index files
	 * @var string
	 */
	var $idx_dir    = '';
	/**
	 * spatial index, well lookup list so we can do spatial queries.
	 * entries should be:
	 * array("geohash" => ["id",])
	 * @var array
	 */
	var $spatial_idx  = array();


	/**
	 * Constructor
	 */
	function helper_plugin_spatialhelper_index() {
		dbglog('initialize','--- spatialhelper_index::helper_plugin_spatialhelper_index ---');
		global $conf;
		$this->idx_dir=$conf['indexdir'];

		if (!@file_exists($this->idx_dir.'/spatial.idx')){
			dbglog('Creating spatial index');
			$this->generateSpatialIndex();
			dbglog('Done creating spatial index');
		} else {
			dbglog('loading spatial index');
			$this->$spatial_idx = unserialize(io_readFile($this->idx_dir.'/spatial.idx', false));
			dbglog($this->$spatial_idx,'done loading spatial index');
		}
	}

	/**
	 * update the spatial index for the page.
	 * @param string $id the page ID
	 */
	function updateSpatialIndex($id) {
		dbglog('reading getoags','--- spatialhelper_index::updateSpatialIndex ---');
		$geotags = p_get_metadata($id, 'geo');

		if(empty($geotags)) {
			dbglog("No geotags found for page $id, done");
			return true;
		}
		dbglog($geotags,"Geotags found for page $id");
		$geohash = Geohash::encode($geotags['lat'],$geotags['lon']);
		dbglog('Update index for geohash: '.$geohash);

		$pageIds = array();
		// check index for key
		if (!array_key_exists($geohash, $this->spatial_idx )) {
			dbglog('Geohash not in index, just add.');
			$pageIds[] = $id;

		} else {
			dbglog('Geohash for document is in index, find it.');
			// check index for document
			$knownHashes = $this->findHashesForId($id, $this->spatial_idx);
			if(empty($knownHashes)){
				dbglog("No index record found for document $id, just add");
				$pageIds=$this->spatial_idx[$geohash];
				$pageIds[] = $id;
			}
			
			$knownHash=$knownHashes[0];

			if ($knownHash == $geohash){
				dbglog('Document '.$id.' was found in index and has the same geohash, done');
				return true;
			}

			
			if (!empty($knownHash)) {
				dbglog('Document '.$id.' was found in index but has different geohash (it moved).');
				// need to move document to the correct index
				$knownIds = $this->spatial_idx[$knownHash];
				dbglog($knownIds,"Known page id's for this hash:");

				$i = array_search($id,$knownIds);
				dbglog('Unsetting:'.$knownIds[$i]);
				unset($knownIds[$i]);
				$this->spatial_idx[$knownHash]=$knownIds;

				$pageIds=$this->spatial_idx[$geohash];
				$pageIds[] = $id;
				dbglog($pageIds,"page id's for this hash:");
			}
		}
		// store and save
		$this->spatial_idx[$geohash] = $pageIds;
		return $this->_saveIndex();
	}

	function findHashesForId($id,$index) {
		$hashes=array();
		foreach ($index as $hash => $docIds){
			dbglog($docIds, "Inspecting element $hash for $id");
			if(in_array($id, $docIds, false)){
				dbglog("Adding $hash to the list of results for $id");
				$hashes[]=$hash;
			}
		}
		dbglog($hashes,"Found the following hashes for $id");
		return $hashes;
	}

	/**
	 * Save spatial index
	 */
	private function _saveIndex() {
		dbglog($this->spatial_idx,'--- spatialhelper_index::_saveIndex ---');
		return io_saveFile($this->idx_dir.'/spatial.idx', serialize($this->spatial_idx));
	}

	/**
	 * Lock the indexer.
	 *
	 * @author Tom N Harris <tnharris@whoopdedo.org>
	 */
	private function _lock() {
		global $conf;
		$status = true;
		$run = 0;
		$lock = $conf['lockdir'].'/_spatial.lock';
		while (!@mkdir($lock, $conf['dmode'])) {
			usleep(50);
			if(is_dir($lock) && time()-@filemtime($lock) > 60*5){
				// looks like a stale lock - remove it
				if (!@rmdir($lock)) {
					$status = "removing the stale lock failed";
					return false;
				} else {
					$status = "stale lock removed";
				}
			}elseif($run++ == 1000){
				// we waited 5 seconds for that lock
				return false;
			}
		}
		if ($conf['dperm'])
		chmod($lock, $conf['dperm']);
		return $status;
	}

	/**
	 * Release the indexer lock.
	 *
	 * @author Tom N Harris <tnharris@whoopdedo.org>
	 */
	private function _unlock() {
		global $conf;
		@rmdir($conf['lockdir'].'/_spatial.lock');
		return true;
	}
	/**
	 * (re-)Generates the spatial index by running through all the pages of the wiki.
	 */
	function generateSpatialIndex() {
		global $conf;
		require_once (DOKU_INC.'inc/search.php');
		$pages = array();
		search($pages, $conf['datadir'], 'search_allpages', array());
		foreach ($pages as $page) {
			$this->updateSpatialIndex($page['id']);
		}
		return true;
	}
}