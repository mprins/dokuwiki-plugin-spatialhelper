<?php
/*
 * Copyright (c) 2011-2016 Mark C. Prins <mprins@users.sf.net>
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
if (!defined('DOKU_INC')) {
	die ();
}
if (!defined('DOKU_PLUGIN')) {
	define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
}
if (!defined('DOKU_LF')) {
	define('DOKU_LF', "\n");
}
require_once (DOKU_PLUGIN . 'action.php');

/**
 * DokuWiki Plugin dokuwikispatial (Action Component).
 *
 * @license BSD license
 * @author Mark C. Prins <mprins@users.sf.net>
 */
class action_plugin_spatialhelper extends DokuWiki_Action_Plugin {

	/**
	 * Register for events.
	 *
	 * @param Doku_Event_Handler $controller
	 *        	DokuWiki's event controller object. Also available as global $EVENT_HANDLER
	 */
	public function register(Doku_Event_Handler $controller) {
		// listen for page add / delete events
		// http://www.dokuwiki.org/devel:event:indexer_page_add
		$controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handle_indexer_page_add');
		$controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, '_removeFromIndex');

		// http://www.dokuwiki.org/devel:event:sitemap_generate
		$controller->register_hook('SITEMAP_GENERATE', 'BEFORE', $this, 'handle_sitemap_generate_before');
		// using after will only trigger us if a sitemap was actually created
		$controller->register_hook('SITEMAP_GENERATE', 'AFTER', $this, 'handle_sitemap_generate_after');

		// handle actions we know of
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handle_action_act_preprocess', array());
		// handle HTML eg. /dokuwiki/doku.php?id=start&do=findnearby&geohash=u15vk4
		$controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, '_findnearby', array(
				'format' => 'HTML'
		));
		// handles AJAX/json eg: jQuery.post("/dokuwiki/lib/exe/ajax.php?id=start&call=findnearby&geohash=u15vk4");
		$controller->register_hook('AJAX_CALL_UNKNOWN', 'BEFORE', $this, '_findnearby', array(
				'format' => 'JSON'
		));

		// listen for media uploads and deletes
		$controller->register_hook('MEDIA_UPLOAD_FINISH', 'BEFORE', $this, '_handle_media_uploaded', array());
		$controller->register_hook('MEDIA_DELETE_FILE', 'BEFORE', $this, '_handle_media_deleted', array());

		$controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handle_metaheader_output');
	}

	/**
	 * Update the spatial index for the page.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param object $param
	 *        	the parameters passed to register_hook when this handler was registered
	 */
	public function handle_indexer_page_add(Doku_Event $event, $param) {
		// $event→data['page'] – the page id
		// $event→data['body'] – empty, can be filled by additional content to index by your plugin
		// $event→data['metadata'] – the metadata that shall be indexed. This is an array where the keys are the metadata indexes and the value a string or an array of strings with the values. title and relation_references will already be set.
		$id = $event->data ['page'];
		$indexer = & plugin_load('helper', 'spatialhelper_index');
		$entries = $indexer->updateSpatialIndex($id);
	}

	/**
	 * Update the spatial index, removing the page.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param object $param
	 *        	the parameters passed to register_hook when this handler was registered
	 */
	public function _removeFromIndex(Doku_Event & $event, $param) {
		// event data:
		// $data[0] – The raw arguments for io_saveFile as an array. Do not change file path.
		// $data[0][0] – the file path.
		// $data[0][1] – the content to be saved, and may be modified.
		// $data[1] – ns: The colon separated namespace path minus the trailing page name. (false if root ns)
		// $data[2] – page_name: The wiki page name.
		// $data[3] – rev: The page revision, false for current wiki pages.

		dbglog($event->data, "Event data in _removeFromIndex.");
		if (@file_exists($event->data [0] [0])) {
			// file not new
			if (!$event->data [0] [1]) {
				// file is empty, page is being deleted
				if (empty ($event->data [1])) {
					// root namespace
					$id = $event->data [2];
				} else {
					$id = $event->data [1] . ":" . $event->data [2];
				}
				$indexer = & plugin_load('helper', 'spatialhelper_index');
				if ($indexer) {
					$indexer->deleteFromIndex($id);
				}
			}
		}
	}

	/**
	 * Add a new SitemapItem object that points to the KML of public geocoded pages.
	 *
	 * @param Doku_Event $event
	 * @param unknown $param
	 */
	public function handle_sitemap_generate_before(Doku_Event $event, $param) {
		$path = mediaFN($this->getConf('media_kml'));
		$lastmod = @filemtime($path);
		$event->data ['items'] [] = new SitemapItem(ml($this->getConf('media_kml'), '', true, '&amp;', true), $lastmod);
		//dbglog($event->data ['items'], "Added a new SitemapItem object that points to the KML of public geocoded pages.");
	}

	/**
	 * Create a spatial sitemap or attach the geo/kml map to the sitemap.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference, not used
	 * @param mixed $param
	 *        	parameter array, not used
	 */
	public function handle_sitemap_generate_after(Doku_Event $event, $param) {
		// $event→data['items']: Array of SitemapItem instances, the array of sitemap items that already contains all public pages of the wiki
		// $event→data['sitemap']: The path of the file the sitemap will be saved to.
		if ($helper = & plugin_load('helper', 'spatialhelper_sitemap')) {
			// dbglog($helper, "createSpatialSitemap loaded helper.");

			$kml = $helper->createKMLSitemap($this->getConf('media_kml'));
			$rss = $helper->createGeoRSSSitemap($this->getConf('media_georss'));
			
			if (!empty ($this->getConf('sitemap_namespaces'))) {
				$namespaces = array_map('trim',explode("\n",$this->getConf('sitemap_namespaces')));
				foreach ($namespaces as $namespace) {
					$kmlN = $helper->createKMLSitemap($namespace . $this->getConf('media_kml'));
					$rssN = $helper->createGeoRSSSitemap($namespace . $this->getConf('media_georss'));
					dbglog( $kmlN && $rssN, "handle_sitemap_generate_after, created KML / GeoRSS sitemap in $namespace, succes: ");
				}  
			}
			return $kml && $rss;
		} else {
			dbglog($helper, "createSpatialSitemap NOT loaded helper.");
		}
	}

	/**
	 * trap findnearby action.
	 * This addional handler is required as described at: https://www.dokuwiki.org/devel:event:tpl_act_unknown
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param mixed $param
	 *        	not used
	 */
	public function handle_action_act_preprocess(Doku_Event $event, $param) {
		if ($event->data != 'findnearby') {
					return;
		}
		$event->preventDefault();
	}

	/**
	 * handle findnearby action.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param mixed $param
	 *        	associative array with keys
	 *        	'format'=> HTML | JSON
	 */
	public function _findnearby(Doku_Event & $event, $param) {
		if ($event->data != 'findnearby') {
					return;
		}
		$event->preventDefault();

		global $INPUT;
		if ($helper = & plugin_load('helper', 'spatialhelper_search')) {
			if ($INPUT->has('lat') && $INPUT->has('lon')) {
				$results = $helper->findNearbyLatLon($INPUT->param('lat'), $INPUT->param('lon'));
			} elseif ($INPUT->has('geohash')) {
				$results = $helper->findNearby($INPUT->str('geohash'));
			} else {
				$results = array(
						'error' => hsc($this->getLang('invalidinput'))
				);
			}
		}

		$showMedia = $INPUT->bool('showMedia', true);

		switch ($param['format']) {
			case 'JSON' :
				$this->printJSON($results);
				break;
			case 'HTML' :
			// fall through to default
			default :
				$this->printHTML($results, $showMedia);
				break;
		}
	}

	/**
	 * Print seachresults as HTML lists.
	 *
	 * @param array $searchresults
	 */
	private function printJSON($searchresults) {
		require_once DOKU_INC . 'inc/JSON.php';
		$json = new JSON();
		header('Content-Type: application/json');
		print $json->encode($searchresults);
	}

	/**
	 * Print seachresults as HTML lists.
	 *
	 * @param array $searchresults
	 * @param boolean $showMedia
	 */
	private function printHTML($searchresults, $showMedia = true) {
		$pages = ( array ) ($searchresults ['pages']);
		$media = ( array ) $searchresults ['media'];
		$lat = ( float ) $searchresults ['lat'];
		$lon = ( float ) $searchresults ['lon'];
		$geohash = ( string ) $searchresults ['geohash'];

		if (isset ($searchresults ['error'])) {
			print '<div class="level1"><p>' . hsc($results ['error']) . '</p></div>';
			return;
		}

		// print a HTML list
		print '<h1>' . $this->getLang('results_header') . '</h1>' . DOKU_LF;
		print '<div class="level1">' . DOKU_LF;
		if (!empty ($pages)) {
			$pagelist = '<ol>' . DOKU_LF;
			foreach ($pages as $page) {
				$pagelist .= '<li>' . html_wikilink(':' . $page ['id'], useHeading('navigation') ? null : noNS($page ['id'])) . ' (' . $this->getLang('results_distance_prefix') . $page ['distance'] . '&nbsp;m) ' . $page ['description'] . '</li>' . DOKU_LF;
			}
			$pagelist .= '</ol>' . DOKU_LF;

			print '<h2>' . $this->getLang('results_pages') . hsc(' lat;lon: ' . $lat . ';' . $lon . ' (geohash: ' . $geohash . ')') . '</h2>';
			print '<div class="level2">' . DOKU_LF;
			print $pagelist;
			print '</div>' . DOKU_LF;
		} else {
			print '<p>' . hsc($this->getLang('nothingfound')) . '</p>';
		}

		if (!empty ($media) && $showMedia) {
			$pagelist = '<ol>' . DOKU_LF;
			foreach ($media as $m) {
				$opts = array();
				$link = ml($m ['id'], $opts, false, '&amp;', false);
				$opts ['w'] = '100';
				$src = ml($m ['id'], $opts);
				$pagelist .= '<li><a href="' . $link . '"><img src="' . $src . '"></a> (' . $this->getLang('results_distance_prefix') . $page ['distance'] . '&nbsp;m) ' . hsc($desc) . '</li>' . DOKU_LF;
			}
			$pagelist .= '</ol>' . DOKU_LF;

			print '<h2>' . $this->getLang('results_media') . hsc(' lat;lon: ' . $lat . ';' . $lon . ' (geohash: ' . $geohash . ')') . '</h2>' . DOKU_LF;
			print '<div class="level2">' . DOKU_LF;
			print $pagelist;
			print '</div>' . DOKU_LF;
		}
		print '<p>' . $this->getLang('results_precision') . $searchresults ['precision'] . ' m. ';
		if (strlen($geohash) > 1) {
			$url = wl(getID(), array(
					'do' => 'findnearby',
					'geohash' => substr($geohash, 0, - 1)
			));
			print '<a href="' . $url . '" class="findnearby">' . $this->getLang('search_largerarea') . '</a>.</p>' . DOKU_LF;
		}
		print '</div>' . DOKU_LF;
	}

	/**
	 * add media to spatial index.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param unknown $param
	 */
	public function _handle_media_uploaded(Doku_Event & $event, $param) {
		// data[0] temporary file name (read from $_FILES)
		// data[1] file name of the file being uploaded
		// data[2] future directory id of the file being uploaded
		// data[3] the mime type of the file being uploaded
		// data[4] true if the uploaded file exists already
		// data[5] (since 2011-02-06) the PHP function used to move the file to the correct location

		dbglog($event->data, "_handle_media_uploaded::event data");

		// check the list of mimetypes
		// if it's a supported type call appropriate index function
		if (substr_compare($event->data [3], 'image/jpeg', 0)) {
			$indexer = plugin_load('helper', 'spatialhelper_index');
			if ($indexer) {
				$indexer->indexImage($event->data [2], $event->data [1]);
			}
		}
		// TODO add image/tiff
		// TODO kml, gpx, geojson...
	}

	/**
	 * removes the media from the index.
	 */
	public function _handle_media_deleted(Doku_Event & $event, $param) {
		// data['id'] ID data['unl'] unlink return code
		// data['del'] Namespace directory unlink return code
		// data['name'] file name data['path'] full path to the file
		// data['size'] file size

		dbglog($event->data, "_handle_media_deleted::event data");

		// remove the media id from the index
		$indexer = & plugin_load('helper', 'spatialhelper_index');
		if ($indexer) {
			$indexer->deleteFromIndex('media__' . $event->data ['id']);
		}
	}

	/**
	 * add a link to the spatial sitemap files in the header.
	 *
	 * @param Doku_Event $event
	 *        	the DokuWiki event. $event->data is a two-dimensional
	 *        	array of all meta headers. The keys are meta, link and script.
	 * @param unknown_type $param
	 *
	 * @see http://www.dokuwiki.org/devel:event:tpl_metaheader_output
	 */
	public function handle_metaheader_output(Doku_Event $event, $param) {
		// TODO maybe test for exist
		$event->data ["link"] [] = array(
				"type" => "application/atom+xml",
				"rel" => "alternate",
				"href" => ml($this->getConf('media_georss')),
				"title" => "Spatial ATOM Feed"
		);
		$event->data ["link"] [] = array(
				"type" => "application/vnd.google-earth.kml+xml",
				"rel" => "alternate",
				"href" => ml($this->getConf('media_kml')),
				"title" => "KML Sitemap"
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
	private function _geo_destination($start, $dist, $brng) {
		$lat1 = _toRad($start [0]);
		$lon1 = _toRad($start [1]);
		// http://en.wikipedia.org/wiki/Earth_radius
		// average earth radius in km
		$dist = $dist / 6371.01;
		$brng = _toRad($brng);

		$lon2 = $lon1 + atan2(sin($brng) * sin($dist) * cos($lat1), cos($dist) - sin($lat1) * sin($lat2));
		$lon2 = fmod(($lon2 + 3 * pi()), (2 * pi())) - pi();

		return array(
				_toDeg($lat2),
				_toDeg($lon2)
		);
	}
	private function _toRad($deg) {
		return $deg * pi() / 180;
	}
	private function _toDeg($rad) {
		return $rad * 180 / pi();
	}
}
