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
if (! defined ( 'DOKU_LF' ))
	define ( 'DOKU_LF', "\n" );
if (! defined ( 'DOKU_TAB' ))
	define ( 'DOKU_TAB', "\t" );
if (! defined ( 'DOKU_PLUGIN' ))
	define ( 'DOKU_PLUGIN', DOKU_INC . 'lib/plugins/' );

/**
 * DokuWiki Plugin spatialhelper (search Helper Component).
 *
 * @license BSD license
 * @author Mark Prins
 */
class helper_plugin_spatialhelper_search extends DokuWiki_Plugin {
	/**
	 * directory for index files
	 *
	 * @var string
	 */
	var $idx_dir = '';
	/**
	 * spatial index, well lookup list so we can do spatial queries.
	 * entries should be:
	 * array("geohash" => ["id",])
	 *
	 * @var array
	 */
	var $spatial_idx = array ();
	var $geophp;

	/**
	 * constructor.
	 */
	function helper_plugin_spatialhelper_search() {
		dbglog ( 'initialize', '--- spatialhelper_search::helper_plugin_spatialhelper_search ---' );
		global $conf;
		$this->idx_dir = $conf ['indexdir'];
		dbglog ( $this->idx_dir );
		// for now just assume there is a valid index and load it
		dbglog ( "loading spatial index" );
		$this->spatial_idx = unserialize ( io_readFile ( $fn = $this->idx_dir . '/spatial.idx', false ) );
		dbglog ( $this->spatial_idx, "done loading spatial index" );

		if (! $geophp = &plugin_load ( 'helper', 'geophp' )) {
			$message = 'helper_plugin_spatialhelper_search::spatialhelper_search: geophp plugin is not available.';
			msg ( $message, - 1 );
			dbglog ( $geophp, $message );
			return "";
		}
	}

	/**
	 * Find locations based on the coordinate pair.
	 *
	 * @param numeric $lat
	 *        	The y coordinate (or latitude)
	 * @param numeric $lon
	 *        	The x coordinate (or longitude)
	 */
	function findNearbyLatLon($lat, $lon) {
		$geometry = new Point ( $lon, $lat );
		return $this->findNearby ( $geometry->out ( 'geohash' ) );
	}

	/**
	 * finds nearby elements in the index based on the geohash.
	 * returns a list of documents and the bunding box.
	 *
	 * @param String $geohash
	 * @return multitype:multitype:multitype:NULL multitype:
	 */
	function findNearby($geohash) {
		dbglog ( "Looking for $geohash", "--- spatialhelper_search::findNearby ---" );

		$decodedPoint = geoPHP::load ( $geohash, 'geohash' );
		dbglog ( $decodedPoint, "decoded geohash" );

		$docIds = array ();
		// find adjacent blocks
		$adjacent = array ();
		$adjacent ['center'] = $geohash;
		$adjacent ['top'] = Geohash::adjacent ( $adjacent ['center'], 'top' );
		$adjacent ['bottom'] = Geohash::adjacent ( $adjacent ['center'], 'bottom' );
		$adjacent ['right'] = Geohash::adjacent ( $adjacent ['center'], 'right' );
		$adjacent ['left'] = Geohash::adjacent ( $adjacent ['center'], 'left' );
		$adjacent ['topleft'] = Geohash::adjacent ( $adjacent ['left'], 'top' );
		$adjacent ['topright'] = Geohash::adjacent ( $adjacent ['right'], 'top' );
		$adjacent ['bottomright'] = Geohash::adjacent ( $adjacent ['right'], 'bottom' );
		$adjacent ['bottomleft'] = Geohash::adjacent ( $adjacent ['left'], 'bottom' );

		dbglog ( $adjacent, "adjacent geo hashes" );
		// find all the pages in the index that overlap with the adjacent hashes
		foreach ( $adjacent as $adjHash ) {
			if (is_array ( $this->spatial_idx )) {
				foreach ( $this->spatial_idx as $_geohash => $_docIds ) {
					if (strstr ( $_geohash, $adjHash )) {
						dbglog ( "Found adjacent geo hash: $adjHash in $_geohash" );
						// if $adjHash similar to geohash
						$docIds = array_merge ( $docIds, $_docIds );
					}
				}
			}
		}

		// TODO sort all the pages using the sort key?
		// return the list
		dbglog ( $docIds, "found docIDs" );
		// lat,lon
		$location = $decodedPoint->y () . ',' . $decodedPoint->x ();
		// dbglog($location, "location");
		return array (
				array_unique($docIds),
				$location
		);
	}

	/**
	 * Calculate a new coordinate based on start, distance and bearing
	 *
	 * @param $start array
	 *        	- start coordinate as decimal lat/lon pair
	 * @param $dist float
	 *        	- distance in kilometers
	 * @param $brng float
	 *        	- bearing in degrees (compass direction)
	 */
	function geo_destination($start, $dist, $brng) {
		$lat1 = _toRad ( $start [0] );
		$lon1 = _toRad ( $start [1] );
		// http://en.wikipedia.org/wiki/Earth_radius
		// average earth radius in km
		$dist = $dist / 6371.01;
		$brng = _toRad ( $brng );

		$lon2 = $lon1 + atan2 ( sin ( $brng ) * sin ( $dist ) * cos ( $lat1 ), cos ( $dist ) - sin ( $lat1 ) * sin ( $lat2 ) );
		$lon2 = fmod ( ($lon2 + 3 * pi ()), (2 * pi ()) ) - pi ();

		return array (
				_toDeg ( $lat2 ),
				_toDeg ( $lon2 )
		);
	}
	private function _toRad($deg) {
		return $deg * pi () / 180;
	}
	private function _toDeg($rad) {
		return $rad * 180 / pi ();
	}
}