<?php
/*
 * Copyright (c) 2011-2014 Mark C. Prins <mprins@users.sf.net>
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
if (! defined ( 'DOKU_INC' ))
	die ();
if (! defined ( 'DOKU_PLUGIN' ))
	define ( 'DOKU_PLUGIN', DOKU_INC . 'lib/plugins/' );
require_once DOKU_PLUGIN . 'spatialhelper/Geohash.php';

/**
 * DokuWiki Plugin spatialhelper (index Helper Component).
 *
 * @license BSD license
 * @author Mark Prins
 */
class helper_plugin_spatialhelper_index extends DokuWiki_Plugin {
	/**
	 * directory for index files.
	 *
	 * @var string
	 */
	var $idx_dir = '';

	/**
	 * spatial index, well lookup list/array so we can do spatial queries.
	 * entries should be: array("geohash" => {"id1","id3",})
	 *
	 * @var array
	 */
	var $spatial_idx = array ();

	/**
	 * Constructor, initialises the spatial index.
	 */
	function helper_plugin_spatialhelper_index() {
		dbglog ( 'initialize', '--- spatialhelper_index::helper_plugin_spatialhelper_index ---' );
		global $conf;
		$this->idx_dir = $conf ['indexdir'];
		// test if there is a spatialindex, if not build one for the wiki
		if (! @file_exists ( $this->idx_dir . '/spatial.idx' )) {
			dbglog ( 'Creating spatial index' );
			// creates and stores the index
			$this->generateSpatialIndex ();
			dbglog ( 'Done creating spatial index' );
		} else {
			dbglog ( 'loading spatial index' );
			$this->spatial_idx = unserialize ( io_readFile ( $this->idx_dir . '/spatial.idx', false ) );
			dbglog ( $this->spatial_idx, 'done loading spatial index' );
		}
	}

	/**
	 * Update the spatial index for the page.
	 *
	 * @param string $id
	 *        	the document ID
	 */
	function updateSpatialIndex($id) {
		dbglog ( 'reading geo metadata', '--- spatialhelper_index::updateSpatialIndex ---' );
		$geotags = p_get_metadata ( $id, 'geo' );
		// TODO we need just lat/lon, so check for those
		// (and maybe validate the values -90/90 and -180/180)
		if (empty ( $geotags )) {
			dbglog ( "No geo metadata found for page '$id', done" );
			return true;
		}
		dbglog ( $geotags, "Geo metadata found for page $id" );
		$geometry = new Point($geotags['lon'],$geotags['lat']);
		$geohash = $geometry->out('geohash');
		dbglog('Update index for geohash: '.$geohash);
		$succes=$this->_addToIndex($geohash, $id);
	}

	/**
	 * Looks up the geohash(es) for the document in the index.
	 *
	 * @param String $id
	 *        	document ID
	 * @param array $index
	 *        	spatial index
	 */
	function findHashesForId($id, $index) {
		$hashes = array ();
		foreach ( $index as $hash => $docIds ) {
			dbglog ( $docIds, "Inspecting element $hash for $id" );
			if (in_array ( $id, $docIds, false )) {
				dbglog ( "Adding $hash to the list of results for $id" );
				$hashes [] = $hash;
			}
		}
		dbglog ( $hashes, "Found the following hashes for $id (should only be 1)" );
		return $hashes;
	}
	/**
	 * Deletes the page from the index.
	 *
	 * @param String $id
	 *        	document ID
	 */
	function deleteFromIndex($id) {
		// check the index for document
		$knownHashes = $this->findHashesForId ( $id, $this->spatial_idx );
		if (empty ( $knownHashes ))
			return;

			// TODO shortcut, need to make sure there is only one element, if not the index is corrupt
		$knownHash = $knownHashes [0];
		$knownIds = $this->spatial_idx [$knownHash];
		$i = array_search ( $id, $knownIds );
		dbglog ( "removing: $knownIds[$i] from the index." );
		unset ( $knownIds [$i] );
		$this->spatial_idx [$knownHash] = $knownIds;
		if (empty ( $this->spatial_idx [$knownHash] )) {
			dbglog ( "removing key: $knownHash from the index." );
			unset ( $this->spatial_idx [$knownHash] );
		}
		$succes=$this->_saveIndex ();
	}
	/**
	 * Save spatial index
	 */
	private function _saveIndex() {
		dbglog ( $this->spatial_idx, '--- spatialhelper_index::_saveIndex ---' );
		return io_saveFile ( $this->idx_dir . '/spatial.idx', serialize ( $this->spatial_idx ) );
	}

	/**
	 * (re-)Generates the spatial index by running through all the pages in the wiki.
	 *
	 * @todo add an option to erase the old index
	 * @todo also index media
	 */
	function generateSpatialIndex() {
		global $conf;
		require_once (DOKU_INC . 'inc/search.php');
		$pages = array ();
		search ( $pages, $conf ['datadir'], 'search_allpages', array () );
		foreach ( $pages as $page ) {
			$this->updateSpatialIndex ( $page ['id'] );
		}
		// media
		$media = array();
		search($media, $conf['mediadir'], 'search_media', array());
		foreach ($medium as $media) {
			dbglog($medium,"About to index medium");
			$this->indexImage($medium['id']);
		}
		return true;
	}

	/**
	 * Add an index entry for this file having EXIF / IPTC data.
	 * @param unknown_type $param
	 * @see http://www.php.net/manual/en/function.iptcparse.php
	 * @see http://php.net/manual/en/function.exif-read-data.php
	 */
	function indexImage($id, $fName) {
		//$id=$eventdata[2];
		//$fName=$eventdata[1];

		dbglog("Indexing image id: ".$id, "::indexImage");
		// Reading EXIF data:
		$exif = exif_read_data($fName,0,true);
		dbglog($exif,'=============== exif==================');
		if(empty($exif)){
			return true;
		}

		dbglog($exif['GPS']['GPSLatitude'], 'GPS lat');
		$lat = $this->_convertDMStoD(array($exif['GPS']['GPSLatitude'][0],
				$exif['GPS']['GPSLatitude'][1],
				$exif['GPS']['GPSLatitude'][2],
				$exif["GPS"]["GPSLatitudeRef"]));
		dbglog($lat, 'converted GPS lat');

		dbglog($exif['GPS']['GPSLongitude'], 'GPS lon');
		$lon = $this->_convertDMStoD(array($exif['GPS']['GPSLongitude'][0],
				$exif['GPS']['GPSLongitude'][1],
				$exif['GPS']['GPSLongitude'][2],
				$exif["GPS"]["GPSLongitudeRef"]));
		dbglog($lon, 'converted GPS lon');

		//$geohash = Geohash::encode($lat,$lon);
		$geometry = new Point($geotags['lon'],$geotags['lat']);
		$geohash = $geometry->out('geohash');
		$succes=$this->_addToIndex($geohash,  $id);
	}

	/**
	 * Store the hash/id entry in the index.
	 * @param string $geohash
	 * @param string $id
	 * @return boolean true when succesful
	 */
	private function _addToIndex($geohash, $id) {
		$pageIds = array();
		// check index for key/geohash
		if (!array_key_exists($geohash, $this->spatial_idx )) {
			dbglog('Geohash not in index, just add.');
			$pageIds[] = $id;
		} else {
			dbglog('Geohash for document is in index, find it.');
			// check the index for document
			$knownHashes = $this->findHashesForId($id, $this->spatial_idx);
			if(empty($knownHashes)){
				dbglog("No index record found for document $id, just add");
				$pageIds=$this->spatial_idx[$geohash];
				$pageIds[] = $id;
			}
			// TODO shortcut, need to make sure there is only one element, if not the index is corrupt
			$knownHash = $knownHashes[0];

			if ($knownHash == $geohash){
				dbglog("Document $id was found in index and has the same geohash, nothing to do.");
				return true;
			}

			if (!empty($knownHash)) {
				dbglog("Document $id was found in index but has different geohash (it moved).");
				// need to move document to the correct index
				$knownIds = $this->spatial_idx[$knownHash];
				dbglog($knownIds,"Known page id's for this hash:");
				// remove it from the old geohash element
				$i = array_search($id,$knownIds);
				dbglog('Unsetting:'.$knownIds[$i]);
				unset($knownIds[$i]);
				$this->spatial_idx[$knownHash]=$knownIds;
				// set on new geohash element
				$pageIds=$this->spatial_idx[$geohash];
				$pageIds[] = $id;
				dbglog($pageIds,"page id's for this hash:");
			}
		}
		// store and save
		$this->spatial_idx[$geohash] = $pageIds;
		return $this->_saveIndex();
	}

	private function _convertDMStoD($param){
		dbglog($param);
		if(!is_array($param)) $param = array($param);
		$deg = $this->_convertRationaltoFloat($param[0]);
		$min = $this->_convertRationaltoFloat($param[1])/60;
		$sec = $this->_convertRationaltoFloat($param[2])/60/60;
		$hem = $param[4]==('N'|'E') ? -1 :1 ; //Hemisphere (N, S, W or E)
		return $hem * $deg + $min + $sec;
	}

	private function _convertRationaltoFloat($param) {
		//rational64u
		$nums=explode('/',$param);
		return intval($nums[0])/intval($nums[1]);
	}
}