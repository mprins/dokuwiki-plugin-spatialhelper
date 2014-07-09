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

/**
 * DokuWiki Plugin spatialhelper (Search component).
 *
 * @license BSD license
 * @author Mark Prins
 */
class helper_plugin_spatialhelper_search extends DokuWiki_Plugin {
	/**
	 * spatial index.
	 *
	 * @var array
	 */
	protected $spatial_idx = array ();

	/**
	 * Precision, Distance of Adjacent Cell in Meters.
	 *
	 * @see https://stackoverflow.com/questions/13836416/geohash-and-max-distance
	 *
	 * @var float
	 */
	private $precision = array (
			5003530,
			625441,
			123264,
			19545,
			3803,
			610,
			118,
			19,
			3.7,
			0.6
	);

	/**
	 * handle to the geoPHP plugin.
	 */
	protected $geophp;

	/**
	 * constructor; initialize/load spatial index.
	 */
	function __construct() {
		// parent::__construct ();
		global $conf;

		if (! $geophp = &plugin_load ( 'helper', 'geophp' )) {
			$message = 'helper_plugin_spatialhelper_search::spatialhelper_search: geophp plugin is not available.';
			msg ( $message, - 1 );
			return "";
		}

		$idx_dir = $conf ['indexdir'];
		if (! @file_exists ( $idx_dir . '/spatial.idx' )) {
			$indexer = plugin_load ( 'helper', 'spatialhelper_index' );
		}

		$this->spatial_idx = unserialize ( io_readFile ( $fn = $idx_dir . '/spatial.idx', false ) );
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
		return $this->findNearby ( $geometry->out ( 'geohash' ), $geometry );
	}

	/**
	 * finds nearby elements in the index based on the geohash.
	 * returns a list of documents and the bunding box.
	 *
	 * @param string $geohash
	 * @param Point $p
	 *        	optional point
	 * @return array of ...
	 */
	function findNearby($geohash, Point $p = null) {
		$_geohashClass = new Geohash ();
		if (! $p) {
			$decodedPoint = $_geohashClass->read ( $geohash );
		} else {
			$decodedPoint = $p;
		}

		// find adjacent blocks
		$adjacent = array ();
		$adjacent ['center'] = $geohash;
		$adjacent ['top'] = $_geohashClass->adjacent ( $adjacent ['center'], 'top' );
		$adjacent ['bottom'] = $_geohashClass->adjacent ( $adjacent ['center'], 'bottom' );
		$adjacent ['right'] = $_geohashClass->adjacent ( $adjacent ['center'], 'right' );
		$adjacent ['left'] = $_geohashClass->adjacent ( $adjacent ['center'], 'left' );
		$adjacent ['topleft'] = $_geohashClass->adjacent ( $adjacent ['left'], 'top' );
		$adjacent ['topright'] = $_geohashClass->adjacent ( $adjacent ['right'], 'top' );
		$adjacent ['bottomright'] = $_geohashClass->adjacent ( $adjacent ['right'], 'bottom' );
		$adjacent ['bottomleft'] = $_geohashClass->adjacent ( $adjacent ['left'], 'bottom' );
		// dbglog ( $adjacent, "adjacent geo hashes:" );

		// find all the pages in the index that overlap with the adjacent hashes
		$docIds = array ();
		foreach ( $adjacent as $adjHash ) {
			if (is_array ( $this->spatial_idx )) {
				foreach ( $this->spatial_idx as $_geohash => $_docIds ) {
					if (strstr ( $_geohash, $adjHash )) {
						// dbglog ( "Found adjacent geo hash: $adjHash in $_geohash" );
						// if $adjHash similar to geohash
						$docIds = array_merge ( $docIds, $_docIds );
					}
				}
			}
		}
		$docIds = array_unique ( $docIds );
		// dbglog ( $docIds, "found docIDs" );

		// create associative array of pages + calculate distance
		$pages = array ();
		$media = array ();
		$indexer = plugin_load ( 'helper', 'spatialhelper_index' );

		foreach ( $docIds as $id ) {
			if (strpos ( $id, 'media__', 0 ) === 0) {
				$id = substr ( $id, strlen ( 'media__' ) );
				if (auth_quickaclcheck ( $id ) >= /*AUTH_READ*/1) {
					$point = $indexer->getCoordsFromExif ( $id );
					$line = new LineString ( [
							$decodedPoint,
							$point
					] );
					$media [] = array (
							'id' => $id,
							'distance' => ( int ) ($line->greatCircleLength ()),
							'lat' => $point->y (),
							'lon' => $point->x ()
					// optionally add other meta such as tag, description...
										);
				}
			} else {
				if (auth_quickaclcheck ( $id ) >= /*AUTH_READ*/1) {
					$geotags = p_get_metadata ( $id, 'geo' );
					$point = new Point ( $geotags ['lon'], $geotags ['lat'] );
					$line = new LineString ( [
							$decodedPoint,
							$point
					] );
					$pages [] = array (
							'id' => $id,
							'distance' => ( int ) ($line->greatCircleLength ()),
							'description' => p_get_metadata ( $id, 'description' )['abstract'],
							'lat' => $geotags ['lat'],
							'lon' => $geotags ['lon']
					// optionally add other meta such as tag...
										);
				}
			}
		}

		// sort all the pages/media using distance
		usort ( $pages, function ($a, $b) {
			return strnatcmp ( $a ['distance'], $b ['distance'] );
		} );
		usort ( $media, function ($a, $b) {
			return strnatcmp ( $a ['distance'], $b ['distance'] );
		} );

		return array (
				'pages' => $pages,
				'media' => $media,
				'lat' => $decodedPoint->y (),
				'lon' => $decodedPoint->x (),
				'geohash' => $geohash,
				'precision' => $this->precision [strlen ( $geohash )]
		);
	}
}