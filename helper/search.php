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
 * DokuWiki Plugin spatialhelper (search Helper Component)
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

class helper_plugin_spatialhelper_search extends DokuWiki_Plugin {
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
	 * constructor.
	 */
	function helper_plugin_spatialhelper_search() {
		dbglog('initialize','--- spatialhelper_search::helper_plugin_spatialhelper_search ---');
		global $conf;
		$this->idx_dir=$conf['indexdir'];
		dbglog($this->idx_dir);
		// for now just assume there is a valid index and load it
		dbglog("loading spatial index");
		$this->spatial_idx = unserialize(io_readFile($fn=$this->idx_dir.'/spatial.idx', false));
		dbglog($this->spatial_idx, "done loading spatial index");
	}

	function findNearbyLatLon($lat,$lon){
		return $this->findNearby(Geohash:: encode($lat, $lon));
	}

	function findNearby($geohash) {
		dbglog("Looking for $geohash", "--- spatialhelper_search::findNearby ---");
		$docIds=array();
		// find adjacent blocks
		// $adjacent = Geohash::adjacent($geohash, $dir);
		// for each subhash in $adjacent
		// get all the pages findForSubHash($subhash)
		//$subhash='';
		//$found = array_filter/array_keys (
		//	$this->$spatial_idx , function($geohash) use ($subhash) { return ( $subhash is part of $geohash); }  );
		// sort all the pages using the sort key
		// return the list
		return array();
	}

	private  function findHashesForSubHash($subhash) {
		$hashes = array();
		//using http://www.php.net/manual/en/function.array-filter.php
		return $hashes;
	}



	/**
	 * Calculate a new coordinate based on start, distance and bearing
	 *
	 * @param $start array - start coordinate as decimal lat/lon pair
	 * @param $dist  float - distance in kilometers
	 * @param $brng  float - bearing in degrees (compass direction)
	 */
	function geo_destination($start,$dist,$brng){
		$lat1 = _toRad($start[0]);
		$lon1 = _toRad($start[1]);
		// http://en.wikipedia.org/wiki/Earth_radius
		// average earth radius in km
		$dist = $dist/6371.01;
		$brng = _toRad($brng);

		$lon2 = $lon1 + atan2(sin($brng)*sin($dist)*cos($lat1),		cos($dist)-sin($lat1)*sin($lat2));
		$lon2 = fmod(($lon2+3*pi()),(2*pi())) - pi();

		return array(_toDeg($lat2),_toDeg($lon2));
	}

	private function _toRad($deg){
		return $deg * pi() / 180;
	}

	private function _toDeg($rad){
		return $rad * 180 / pi();
	}
}