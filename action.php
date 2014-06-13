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
	public function register(Doku_Event_Handler &$controller) {
		// listen for page add / delete
		// http://www.dokuwiki.org/devel:event:indexer_page_add
		$controller->register_hook ( 'INDEXER_PAGE_ADD', 'BEFORE', $this, '_updateSpatialIndex' );
		$controller->register_hook ( 'IO_WIKIPAGE_WRITE', 'BEFORE', $this, '_removeFromIndex' );

		// http://www.dokuwiki.org/devel:event:sitemap_generate
		$controller->register_hook ( 'SITEMAP_GENERATE', 'BEFORE', $this, '_createspatialsitemap' );
		// http://www.dokuwiki.org/devel:event:sitemap_ping
		// $controller->register_hook('SITEMAP_PING', 'AFTER', $this, '_ping');
		// handle actions we know of
		$controller->register_hook ( 'ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_trap_action', array () );
		$controller->register_hook ( 'TPL_ACT_UNKNOWN', 'BEFORE', $this, '_findnearby', array () );
		// listen for media uploads and deletes
		$controller->register_hook ( 'MEDIA_UPLOAD_FINISH', 'BEFORE', $this, '_handle_media_uploaded', array () );
		$controller->register_hook ( 'MEDIA_DELETE_FILE', 'BEFORE', $this, '_handle_media_deleted', array () );
	}

	/**
	 * Update the spatial index for the page.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param object $param
	 *        	the parameters passed to register_hook when this handler was registered
	 */
	function _updateSpatialIndex(Doku_Event &$event, $param) {
		// $version = getVersionData();
		// dbglog($version, "dokuwiki version data");
		$id = "";
		// if ($version['date'] < '2011-03-06') {
		// /*
		// Anteater and previous
		// $event→data[0] – the page id
		// $event→data[1] – empty, can be filled by additional content to index by your plugin
		// */
		// $id = $event->data[0];
		// } else {
		/*
		 * As of 2011-03-06 the data structure has been changed to:
		 * $event→data['page'] – the page id
		 * $event→data['body'] – empty, can be filled by additional content to index by your plugin
		 * $event→data['metadata'] – the metadata that shall be indexed. This is an array where the keys are the metadata indexes and the value a string or an array of strings with the values. title and relation_references will already be set.
		 */
		$id = $event->data ['page'];
		// }
		dbg ( "start update spatial index for page: $id", '--- action_plugin_spatialhelper::_updateSpatialIndex ---' );
		$indexer = plugin_load ( 'helper', 'spatialhelper_index' );

		if ($indexer) {
			$entries = $indexer->updateSpatialIndex ( $id );
			dbglog ( "Done indexing, entries: $entries", '--- action_plugin_spatialhelper::_updateSpatialIndex ---' );
		} else {
			dbglog ( $indexer, '--- action_plugin_spatialhelper::_updateSpatialIndex: spatial indexer not found ---' );
		}
	}

	/**
	 * Update the spatial index, removing the page.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param object $param
	 *        	the parameters passed to register_hook when this handler was registered
	 */
	function _removeFromIndex(Doku_Event &$event, $param) {
		/*
		 * event data:
		 * $data[0] – The raw arguments for io_saveFile as an array. Do not change file path.
		 * $data[0][0] – the file path.
		 * $data[0][1] – the content to be saved, and may be modified.
		 * $data[1] – ns: The colon separated namespace path minus the trailing page name. (false if root ns)
		 * $data[2] – page_name: The wiki page name.
		 * $data[3] – rev: The page revision, false for current wiki pages.
		 */
		// dbglog($event->data,"Event data in _removeFromIndex.");
		if (@file_exists ( $event->data [0] [0] )) {
			// file not new
			if (! $event->data [0] [1]) {
				// file is empty, page is being deleted
				if (empty ( $event->data [1] )) {
					// root namespace
					$id = $event->data [2];
				} else {
					$id = $event->data [1] . ":" . $event->data [2];
				}
				$indexer = plugin_load ( 'helper', 'spatialhelper_index' );
				if ($indexer) {
					dbglog ( "loaded helper spatialhelper_index. Deleting $id from index" );
					$indexer->deleteFromIndex ( $id );
				}
			}
		}
	}

	/**
	 * Create a spatial sitemap or attach the geo/kml map to the sitemap.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param mixed $param
	 *        	not used
	 */
	private function _createspatialsitemap(Doku_Event &$event, $param) {
		/*
		 * $event→data['items']: Array of SitemapItem instances, the array of sitemap items that already contains all public pages of the wiki
		 * $event→data['sitemap']: The path of the file the sitemap will be saved to.
		 */
		dbglog ( $event->data ['items'], "Array of SitemapItem instances, the array of sitemap items that already contains all public pages of the wiki" );
		dbglog ( $event->data ['sitemap'], "The path of the file the sitemap will be saved to." );
	}

	/**
	 * trap findnearby action.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param mixed $param
	 *        	not used
	 */
	function _trap_action(&$event, $param) {
		if ($event->data != 'findnearby')
			return;
		$event->preventDefault ();
	}

	/**
	 * handle findnearby action.
	 *
	 * @param Doku_Event $event
	 *        	event object by reference
	 * @param mixed $param
	 *        	not used
	 */
	function _findnearby(Doku_Event &$event, $param) {
		global $lang;
		if ($event->data != 'findnearby')
			return;
		$event->preventDefault ();

		$tagns = $this->getConf ( 'namespace' );
		$flags = explode ( ',', trim ( $this->getConf ( 'pagelist_flags' ) ) );

		// TODO findNearbyLatLon()
		$geohash = trim ( str_replace ( $this->getConf ( 'namespace' ) . ':', '', $_REQUEST ['geohash'] ) );

		if ($helper = &plugin_load ( 'helper', 'spatialhelper_search' )) {
			$results = $helper->findNearby ( $geohash );
			$ids = ( array ) ($results [0]);
			$location = ( string ) ($results [1]);
			foreach ( $ids as $id ) {
				$pages [] = array (
						'id' => $id
				);
			}
		}
		// use html_buildlist() instead for media...

		if (! empty ( $pages )) {
			// let Pagelist Plugin do the work for us
			if (plugin_isdisabled ( 'pagelist' ) || (! $pagelist = plugin_load ( 'helper', 'pagelist' ))) {
				msg ( $this->getLang ( 'missing_pagelistplugin' ), - 1 );
				return false;
			}

			$pagelist->setFlags ( $flags );
			$pagelist->startList ();
			foreach ( $pages as $page ) {
				$pagelist->addPage ( $page );
			}
			// TODO convert geohash to lat/lon
			print '<h1>Geohash: ' . str_replace ( '_', ' ', $_REQUEST ['geohash'] ) . ' (lat,lon: ' . $location . ')</h1>' . DOKU_LF;
			print '<div class="level1">' . DOKU_LF;
			print $pagelist->finishList ();
			print '</div>' . DOKU_LF;
		} else {
			print '<div class="level1"><p>' . $lang ['nothingfound'] . '</p></div>';
		}
	}
	function _handle_media_uploaded(Doku_Event &$event, $param) {
		/*
		 * data[0] temporary file name (read from $_FILES)
		 * data[1] file name of the file being uploaded
		 * data[2] future directory id of the file being uploaded
		 * data[3] the mime type of the file being uploaded
		 * data[4] true if the uploaded file exists already
		 * data[5] (since 2011-02-06) the PHP function used to move the file to the correct location
		 */

		// check the list of mimetypes
		// if it's a supported type call appropriate index function
		dbglog ( "checking uploaded media with mimetype " . $event->data [3] );
		dbglog ( $event->data, "_handle_media_uploaded::event data" );
		// if(stristr('image/',$event->data[3])){
		if (substr_compare ( $event->data [3], 'image/jpeg', 0 ))
		// TODO add image/tiff
		{

			$indexer = plugin_load ( 'helper', 'spatialhelper_index' );
			if ($indexer) {
				dbglog ( "Loaded helper spatialhelper_index." );
				$indexer->indexImage ( $event->data [2], $event->data [1] );
			}
		}
		// TODO kml, gpx, geojson...
	}

	/**
	 * removes the media from the index.
	 */
	function _handle_media_deleted(Doku_Event &$event, $param) {
		/*
		 * data['id'] ID
		 * data['unl'] unlink return code
		 * data['del'] Namespace directory unlink return code
		 * data['name'] file name
		 * data['path'] full path to the file
		 * data['size'] file size
		 */
		dbglog ( $event->data, "_handle_media_deleted::event data" );
		$id = $event->data ['id'];
		// remove the id from the index
		$indexer = plugin_load ( 'helper', 'spatialhelper_index' );
		if ($indexer) {
			dbglog ( "Loaded helper spatialhelper_index. Deleting media $id from index" );
			$indexer->deleteFromIndex ( $id );
		}
	}
}