<?php
/*
 * Copyright (c) 2014 Mark C. Prins <mprins@users.sf.net>
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
if (! defined ( 'DOKU_LF' ))
	define ( 'DOKU_LF', "\n" );

/**
 * DokuWiki Plugin spatialhelper (sitemap Component).
 *
 * @license BSD license
 * @author Mark Prins
 */
class helper_plugin_spatialhelper_sitemap extends DokuWiki_Plugin {
	var $spatial_idx = array ();

	/**
	 * constructor, load spatial index.
	 */
	function __construct() {
		// parent::__construct();
		global $conf;
		$idx_dir = $conf ['indexdir'];
		if (! @file_exists ( $idx_dir . '/spatial.idx' )) {
			$indexer = plugin_load ( 'helper', 'spatialhelper_index' );
			$indexer->generateSpatialIndex ();
		}
		$this->spatial_idx = unserialize ( io_readFile ( $fn = $idx_dir . '/spatial.idx', false ) );
	}
	function getMethods() {
		return array (
				'name' => 'createGeoRSSSitemap',
				'desc' => 'create a spatial sitemap in GeoRSS format.',
				'params' => array (
						'path' => 'string'
				),
				'return' => array (
						'success' => 'boolean'
				)
		);
		$result [] = array (
				'name' => 'createKMLSitemap',
				'desc' => 'create a spatial sitemap in KML format.',
				'params' => array (
						'path' => 'string'
				),
				'return' => array (
						'success' => 'boolean'
				)
		);
	}

	/**
	 * Create a GeoRSS Simple sitemap.
	 *
	 * @param $mediaID id
	 *        	for the GeoRSS file
	 */
	function createGeoRSSSitemap($mediaID) {
		global $conf;

		$RSSstart = '<?xml version="1.0" encoding="utf-8"?>' . DOKU_LF;
		$RSSstart = '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:georss="http://www.georss.org/georss">' . DOKU_LF;
		$RSSstart .= '<title>' . $conf ['title'] . ' spatial feed</title>' . DOKU_LF;
		$RSSstart .= '<subtitle>' . $conf ['tagline'] . '</subtitle>' . DOKU_LF;
		$RSSstart .= '<link href="' . DOKU_URL . '" />' . DOKU_LF;
		$RSSstart .= '<link href="' . ml ( $mediaID, '', true, '&amp;', true ) . '" rel="self" />' . DOKU_LF;
		$RSSstart .= '<updated>' . date ( DATE_RSS ) . '</updated>' . DOKU_LF;
		// $RSSstart .= '<id></id>'.DOKU_LF;
		$RSSend = '</feed>' . DOKU_LF;

		// TODO NOTE if $mediaID is namespaced the directory may need to be created, use $conf dmode&fmode
		@touch ( mediaFN ( $mediaID ) );
		$fh = fopen ( mediaFN ( $mediaID ), 'w' );
		fwrite ( $fh, $RSSstart );

		foreach ( $this->spatial_idx as $idxEntry ) {
			// get list of id's
			foreach ( $idxEntry as $id ) {
				// for document item in the index
				if (strpos ( $id, 'media__', 0 ) !== 0) {
					// public and non-hidden pages only
					if (isHiddenPage ( $id ))
						continue;
					if (auth_aclcheck ( $id, '', '' ) < AUTH_READ)
						continue;

					$meta = p_get_metadata ( $id );

					// $desc = p_render ( 'xhtml', p_get_instructions($meta ['description'] ['abstract']), $info );
					$desc = strip_tags ( $meta ['description'] ['abstract'] );

					$entry = '<entry>' . DOKU_LF;
					$entry .= '  <title>' . $meta ['title'] . '</title>' . DOKU_LF;
					$entry .= '  <summary>' . $desc . '</summary>' . DOKU_LF;
					$entry .= '  <georss:point>' . $meta ['geo'] ['lat'] . ' ' . $meta ['geo'] ['lon'] . '</georss:point>' . DOKU_LF;
					if ($meta ['geo'] ['alt']) {
						$entry .= '  <georss:elev>' . $meta ['geo'] ['alt'] . '</georss:elev>' . DOKU_LF;
					}
					$entry .= '  <link href="' . wl ( $id ) . '" />' . DOKU_LF;
					$entry .= '  <author><name>' . $meta ['user'] . '</name></author>' . DOKU_LF;
					$entry .= '  <updated>' . date_iso8601($meta ['date'] ['modified']) . '</updated>' . DOKU_LF;
					$entry .= '  <id>' . $id . '</id>' . DOKU_LF;
					$entry .= '</entry>' . DOKU_LF;
					fwrite ( $fh, $entry );
				}
			}
		}

		fwrite ( $fh, $RSSend );
		return fclose ( $fh );
	}

	/**
	 * Create a KML sitemap.
	 *
	 * @param $mediaID id
	 *        	for the KML file
	 */
	function createKMLSitemap($mediaID) {
		global $conf;

		$KMLstart = '<?xml version="1.0" encoding="UTF-8"?>' . DOKU_LF;
		// schemaLocation="http://schemas.opengis.net/kml/2.2.0/ogckml22.xsd"
		$KMLstart .= '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom"><Document>' . DOKU_LF;
		$KMLstart .= '<name>' . $conf ['title'] . ' spatial sitemap</name>' . DOKU_LF;
		$KMLstart .= '<atom:link rel="related" href="' . DOKU_URL . '" />' . DOKU_LF;
		$KMLstart .= '<Style id="icon"><IconStyle><color>ffffffff</color><scale>1</scale>';
		$KMLstart .= '<Icon><href>' . DOKU_BASE . 'lib/plugins/spatialhelper/wikiitem.png</href></Icon></IconStyle></Style>' . DOKU_LF;

		$KMLend = '</Document></kml>' . DOKU_LF;

		// TODO NOTE if $mediaID is namespaced the directory may need to be created
		@touch ( mediaFN ( $mediaID ) );
		$fh = fopen ( mediaFN ( $mediaID ), 'w' );
		fwrite ( $fh, $KMLstart );

		foreach ( $this->spatial_idx as $idxEntry ) {
			// get list of id's
			foreach ( $idxEntry as $id ) {
				// for document item in the index
				if (strpos ( $id, 'media__', 0 ) !== 0) {
					// public and non-hidden pages only
					if (isHiddenPage ( $id ))
						continue;
					if (auth_aclcheck ( $id, '', '' ) < AUTH_READ)
						continue;

					$meta = p_get_metadata ( $id );

					// $desc = p_render ( 'xhtml', p_get_instructions($meta ['description'] ['abstract']), $info );
					$desc = '<p>' . strip_tags ( $meta ['description'] ['abstract'] ) . '</p>';
					$desc .= '<p><a href="' . wl ( $id, '', true ) . '">' . $meta ['title'] . '</a></p>';

					// create an entry and store it
					$plcm = '<Placemark>' . DOKU_LF;
					$plcm .= '  <name>' . $meta ['title'] . '</name>' . DOKU_LF;
					$plcm .= '  <description><![CDATA[' . $desc . ']]></description>' . DOKU_LF;
					$plcm .= '  <Point><coordinates>' . $meta ['geo'] ['lon'] . ',' . $meta ['geo'] ['lat'];
					if ($meta ['geo'] ['alt']) {
						$plcm .= ',' . $meta ['geo'] ['alt'];
					}
					$plcm .= '</coordinates></Point>' . DOKU_LF;
					$plcm .= '  <atom:link href="' . wl ( $id, '' . true ) . '" />' . DOKU_LF;
					$plcm .= '  <atom:author><atom:name>' . $meta ['user'] . '</atom:name></atom:author>' . DOKU_LF;
					$plcm .= '  <atom:updated>' . date_iso8601($meta ['date'] ['modified']) . '</atom:updated>' . DOKU_LF;
					$plcm .= '  <styleUrl>#icon</styleUrl>' . DOKU_LF;
					$plcm .= '</Placemark>' . DOKU_LF;

					fwrite ( $fh, $plcm );
				}
			}
		}
		fwrite ( $fh, $KMLend );
		return fclose ( $fh );
	}
}
