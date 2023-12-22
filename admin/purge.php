<?php

use dokuwiki\Extension\AdminPlugin;
use dokuwiki\Form\Form;

/*
 * Copyright (c) 2014-2023 Mark C. Prins <mprins@users.sf.net>
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
 * DokuWiki Plugin spatialhelper (Admin Component).
 * This component purges and recreates the spatial index and sitemaps.
 *
 * @author Mark Prins
 */
class admin_plugin_spatialhelper_purge extends AdminPlugin
{
    /**
     *
     * @see DokuWiki_Admin_Plugin::getMenuSort()
     */
    final  public function getMenuSort(): int
    {
        return 801;
    }

    final  public function getMenuIcon(): string
    {
        $plugin = $this->getPluginName();
        return DOKU_PLUGIN . $plugin . '/admin/purge.svg';
    }

    /**
     * purge and regenerate the index and sitemaps.
     *
     * @see DokuWiki_Admin_Plugin::handle()
     */
    final  public function handle(): void
    {
        if (isset($_REQUEST ['purgeindex'])) {
            global $conf;
            $path = $conf ['indexdir'] . '/spatial.idx';
            if (file_exists($path) && unlink($path)) {
                msg($this->getLang('admin_purged_tiles'), 0);
            }
        }

        $indexer = plugin_load('helper', 'spatialhelper_index');
        if (isset($indexer)) {
            $indexer->generateSpatialIndex();
        }

        $sitemapper = plugin_load('helper', 'spatialhelper_sitemap');
        if (isset($sitemapper)) {
            $sitemapper->createKMLSitemap($this->getConf('media_kml'));
            $sitemapper->createGeoRSSSitemap($this->getConf('media_georss'));
        }
    }

    /**
     * render the form for this plugin.
     *
     * @see DokuWiki_Admin_Plugin::html()
     */
    final  public function html(): void
    {
        echo $this->locale_xhtml('admin_purge_intro');

        $form = new Form(
            ['id' => 'spatialhelper__purgeform', 'method' => 'post']
        );
        $form->setHiddenField('purgeindex', 'true');
        $form->addButton('submit', $this->getLang('admin_submit'))
            ->attr('title', $this->getLang('admin_submit'));
        echo $form->toHTML();
    }
}
