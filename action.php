<?php
/*
 * Copyright (c) 2011 Mark C. Prins <mc.prins@gmail.com>
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
 * DokuWiki Plugin dokuwikispatial (Action Component)
 *
 * @license BSD license
 * @author  Mark C. Prins <mc.prins@gmail.com>
 */
if(!defined('DOKU_INC')) die();

if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'action.php');

class action_plugin_spatialhelper extends DokuWiki_Action_Plugin {

	/**
	 * Register for events.
	 *
	 * @param Doku_Event_Handler $controller DokuWiki's event controller object. Also available as global $EVENT_HANDLER
	 */
	public function register(Doku_Event_Handler &$controller) {
		// http://www.dokuwiki.org/devel:event:indexer_page_add
		$controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, '_updateSpatialIndex');
		// http://www.dokuwiki.org/devel:event:sitemap_generate
		// $controller->register_hook('SITEMAP_GENERATE', 'BEFORE', $this, '_createspatialsitemap');
		// http://www.dokuwiki.org/devel:event:sitemap_ping
		//$controller->register_hook('SITEMAP_PING', 'AFTER', $this, '_ping');
		$controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, '_trap_action', array());
		$controller->register_hook('TPL_ACT_UNKNOWN', 'BEFORE', $this, '_handle_tpl_act', array());
	}

	/**
	 * Update the spatial index for the page.
	 * @param Doku_Event $event event object by reference
	 * @param object $param the parameters passed to register_hook when this handler was registered
	 */
	function _updateSpatialIndex(Doku_Event &$event, $param) {
		$version = getVersionData();
		dbglog($version, "dokuwiki version data");
		$id="";
		if ($version['date'] < '2011-03-06') {
			/*
			  Anteater and previous
			 $event→data[0] – the page id
			 $event→data[1] – empty, can be filled by additional content to index by your plugin
			 */
			$id = $event->data[0];
		} else {
			/*
			 As of 2011-03-06 the data structure has been changed to:

			 $event→data['page'] – the page id
			 $event→data['body'] – empty, can be filled by additional content to index by your plugin
			 $event→data['metadata'] – the metadata that shall be indexed. This is an array where the keys are the metadata indexes and the value a string or an array of strings with the values. title and relation_references will already be set.
			 */
			$id = $event->data['page'];
		}
		dbg("start update index for page: $id",'--- action_plugin_spatialhelper::_updateSpatialIndex ---');
		dbg("loading helper spatialhelper_index.");
		$indexer = plugin_load('helper', 'spatialhelper_index');

		if (!$indexer) dbglog("plugin not found. \$indexer = $indexer");
		if($indexer) {
			dbglog("loaded helper spatialhelper_index.");
			$entries = $indexer->updateSpatialIndex($id);
			dbglog("Done indexing, entries: $entries",'--- action_plugin_spatialhelper::_updateSpatialIndex ---');
		}
	}

	/**
	 * Create a spatial sitemap or attach the geo/kml map to the sitemap
	 * @param Doku_Event $event event object by reference
	 * @param mixed $param not used
	 */
	function _createspatialsitemap(Doku_Event &$event, $param) {
		/*
		 $event→data['items']: Array of SitemapItem instances, the array of sitemap items that already contains all public pages of the wiki
		 $event→data['sitemap']: The path of the file the sitemap will be saved to.
		 */
		;
	}

	/**
	 * trap findnearby action.
	 * @param Doku_Event $event event object by reference
	 * @param mixed $param not used
	 */
	function _trap_action(&$event, $param) {
		if($event->data != 'findnearby') return;
		$event->preventDefault();
	}

	/**
	 * handle findnearby action.
	 * @param Doku_Event $event event object by reference
	 * @param mixed $param not used
	 */
	function _handle_tpl_act(Doku_Event &$event, $param) {
		global $lang;
		if($event->data != 'findnearby') return;
		$event->preventDefault();

		$tagns = $this->getConf('namespace');
		$flags = explode(',', trim($this->getConf('pagelist_flags')));

		// TODO findNearbyLatLon()
		$geohash = trim(str_replace($this->getConf('namespace').':', '', $_REQUEST['geohash']));

		if ($helper = &plugin_load('helper', 'spatialhelper_search')) {
			$results = $helper->findNearby($geohash);
			$ids=(array)($results[0]);
			$bbox=(array)($results[1]);
			foreach ($ids as $id){
				$pages[]=array('id'=>$id);
			}
		}

		if(!empty($pages)) {
			// let Pagelist Plugin do the work for us
			if (plugin_isdisabled('pagelist') || (!$pagelist = plugin_load('helper', 'pagelist'))) {
				msg($this->getLang('missing_pagelistplugin'), -1);
				return false;
			}

			$pagelist->setFlags($flags);
			$pagelist->startList();
			foreach ($pages as $page) {
				$pagelist->addPage($page);
			}
			// TODO convert geohash to lat/lon
			print '<h1>Geohash: ' . str_replace('_', ' ', $_REQUEST['geohash']) . ' ('.$bbox['center'][0].';'.$bbox['center'][1].')</h1>' . DOKU_LF;
			print '<div class="level1">' . DOKU_LF;
			print $pagelist->finishList();
			print '</div>' . DOKU_LF;

		} else {
			print '<div class="level1"><p>' . $lang['nothingfound'] . '</p></div>';
		}
	}
}