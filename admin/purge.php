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
if (!defined('DOKU_INC'))
	die ();
if (!defined('DOKU_PLUGIN'))
	define('DOKU_PLUGIN', DOKU_INC . 'lib/plugins/');
require_once DOKU_PLUGIN . 'admin.php';
/**
 * DokuWiki Plugin spatialhelper (Admin Component).
 * This component purges and recreates the spatial index and sitemaps.
 *
 * @author Mark Prins
 */
class admin_plugin_spatialhelper_purge extends DokuWiki_Admin_Plugin {

	/**
	 *
	 * @see DokuWiki_Admin_Plugin::getMenuSort()
	 */
	public function getMenuSort() {
		return 801;
	}
	/**
	 * admin use only.
	 *
	 * @return true
	 *
	 * @see DokuWiki_Admin_Plugin::forAdminOnly()
	 */
	public function forAdminOnly() {
		return true;
	}

	/**
	 * purge and regenerate the index and sitemaps.
	 *
	 * @see DokuWiki_Admin_Plugin::handle()
	 */
	public function handle() {
		global $conf;
		if (isset ($_REQUEST ['purgeindex'])) {
			global $conf;
			$path = $conf ['indexdir'] . '/spatial.idx';
			if (file_exists($path)) {
				if (unlink($path)) {
					msg($this->getLang('admin_purged_tiles'), 0);
				}
			}
		}

		$indexer = plugin_load('helper', 'spatialhelper_index');
		$indexer->generateSpatialIndex();

		$sitemapper = plugin_load('helper', 'spatialhelper_sitemap');
		$sitemapper->createKMLSitemap($this->getConf('media_kml'));
		$sitemapper->createGeoRSSSitemap($this->getConf('media_georss'));
	}

	/**
	 * render the form for this plugin.
	 *
	 * @see DokuWiki_Admin_Plugin::html()
	 */
	public function html() {
		echo $this->locale_xhtml('admin_purge_intro');

		$form = new Doku_Form(array(
				'id' => 'spatialhelper__purgeform',
				'method' => 'post'
		));
		$form->addHidden('purgeindex', 'true');

		$form->addElement(form_makeButton('submit', 'admin', $this->getLang('admin_submit'), array(
				'title' => $this->getLang('admin_submit')
		)));
		$form->printForm();
	}
}
