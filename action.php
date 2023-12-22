<?php

/*
 * Copyright (c) 2011-2020 Mark C. Prins <mprins@users.sf.net>
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

use dokuwiki\Extension\ActionPlugin;
use dokuwiki\Extension\Event;
use dokuwiki\Extension\EventHandler;
use dokuwiki\Logger;
use dokuwiki\Sitemap\Item;

/**
 * DokuWiki Plugin dokuwikispatial (Action Component).
 *
 * @license BSD license
 * @author  Mark C. Prins <mprins@users.sf.net>
 */
class action_plugin_spatialhelper extends ActionPlugin
{
    /**
     * Register for events.
     *
     * @param Doku_Event_Handler $controller
     *          DokuWiki's event controller object.
     */
    final public function register(EventHandler $controller): void
    {
        // listen for page add / delete events
        // http://www.dokuwiki.org/devel:event:indexer_page_add
        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handleIndexerPageAdd');
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, 'removeFromIndex');

        // http://www.dokuwiki.org/devel:event:sitemap_generate
        $controller->register_hook('SITEMAP_GENERATE', 'BEFORE', $this, 'handleSitemapGenerateBefore');
        // using after will only trigger us if a sitemap was actually created
        $controller->register_hook('SITEMAP_GENERATE', 'AFTER', $this, 'handleSitemapGenerateAfter');

        // handle actions we know of
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleActionActPreprocess', []);
        // handle HTML eg. /dokuwiki/doku.php?id=start&do=findnearby&geohash=u15vk4
        $controller->register_hook(
            'TPL_ACT_UNKNOWN',
            'BEFORE',
            $this,
            'findnearby',
            ['format' => 'HTML']
        );
        // handles AJAX/json eg: jQuery.post("/dokuwiki/lib/exe/ajax.php?id=start&call=findnearby&geohash=u15vk4");
        $controller->register_hook(
            'AJAX_CALL_UNKNOWN',
            'BEFORE',
            $this,
            'findnearby',
            ['format' => 'JSON']
        );

        // listen for media uploads and deletes
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'BEFORE', $this, 'handleMediaUploaded', []);
        $controller->register_hook('MEDIA_DELETE_FILE', 'BEFORE', $this, 'handleMediaDeleted', []);

        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleMetaheaderOutput');
        $controller->register_hook('PLUGIN_POPULARITY_DATA_SETUP', 'AFTER', $this, 'popularity');
    }

    /**
     * Update the spatial index for the page.
     *
     * @param Doku_Event $event
     *          event object
     */
    final  public function handleIndexerPageAdd(Event $event): void
    {
        // $event→data['page'] – the page id
        // $event→data['body'] – empty, can be filled by additional content to index by your plugin
        // $event→data['metadata'] – the metadata that shall be indexed. This is an array where the keys are the
        //    metadata indexes and the value a string or an array of strings with the values.
        //    title and relation_references will already be set.
        $id = $event->data ['page'];
        $indexer = plugin_load('helper', 'spatialhelper_index');
        $indexer->updateSpatialIndex($id);
    }

    /**
     * Update the spatial index, removing the page.
     *
     * @param Doku_Event $event
     *          event object
     */
    final    public function removeFromIndex(Event $event): void
    {
        // event data:
        // $data[0] – The raw arguments for io_saveFile as an array. Do not change file path.
        // $data[0][0] – the file path.
        // $data[0][1] – the content to be saved, and may be modified.
        // $data[1] – ns: The colon separated namespace path minus the trailing page name. (false if root ns)
        // $data[2] – page_name: The wiki page name.
        // $data[3] – rev: The page revision, false for current wiki pages.

        Logger::debug("Event data in removeFromIndex.", $event->data);
        if (@file_exists($event->data [0] [0])) {
            // file not new
            if (!$event->data [0] [1]) {
                // file is empty, page is being deleted
                if (empty($event->data [1])) {
                    // root namespace
                    $id = $event->data [2];
                } else {
                    $id = $event->data [1] . ":" . $event->data [2];
                }
                $indexer = plugin_load('helper', 'spatialhelper_index');
                if ($indexer !== null) {
                    $indexer->deleteFromIndex($id);
                }
            }
        }
    }

    /**
     * Add a new SitemapItem object that points to the KML of public geocoded pages.
     *
     * @param Doku_Event $event
     */
    final   public function handleSitemapGenerateBefore(Event $event): void
    {
        $path = mediaFN($this->getConf('media_kml'));
        $lastmod = @filemtime($path);
        $event->data ['items'] [] = new Item(ml($this->getConf('media_kml'), '', true, '&amp;', true), $lastmod);
    }

    /**
     * Create a spatial sitemap or attach the geo/kml map to the sitemap.
     *
     * @param Doku_Event $event
     *          event object, not used
     */
    final   public function handleSitemapGenerateAfter(Event $event): bool
    {
        // $event→data['items']: Array of SitemapItem instances, the array of sitemap items that already
        //      contains all public pages of the wiki
        // $event→data['sitemap']: The path of the file the sitemap will be saved to.
        if (($helper = plugin_load('helper', 'spatialhelper_sitemap')) !== null) {
            $kml = $helper->createKMLSitemap($this->getConf('media_kml'));
            $rss = $helper->createGeoRSSSitemap($this->getConf('media_georss'));

            if (!empty($this->getConf('sitemap_namespaces'))) {
                $namespaces = array_map('trim', explode("\n", $this->getConf('sitemap_namespaces')));
                foreach ($namespaces as $namespace) {
                    $kmlN = $helper->createKMLSitemap($namespace . $this->getConf('media_kml'));
                    $rssN = $helper->createGeoRSSSitemap($namespace . $this->getConf('media_georss'));
                    Logger::debug(
                        "handleSitemapGenerateAfter, created KML / GeoRSS sitemap in $namespace, succes: ",
                        $kmlN && $rssN
                    );
                }
            }
            return $kml && $rss;
        }
        return false;
    }

    /**
     * trap findnearby action.
     * This additional handler is required as described at: https://www.dokuwiki.org/devel:event:tpl_act_unknown
     *
     * @param Doku_Event $event
     *          event object
     */
    final  public function handleActionActPreprocess(Event $event): void
    {
        if ($event->data !== 'findnearby') {
            return;
        }
        $event->preventDefault();
    }

    /**
     * handle findnearby action.
     *
     * @param Doku_Event $event
     *          event object
     * @param array $param
     *          associative array with keys
     *          'format'=> HTML | JSON
     * @throws JsonException if anything goes wrong with JSON encoding
     */
    final  public function findnearby(Event $event, array $param): void
    {
        if ($event->data !== 'findnearby') {
            return;
        }
        $event->preventDefault();
        $results = [];
        global $INPUT;
        if (($helper = plugin_load('helper', 'spatialhelper_search')) !== null) {
            if ($INPUT->has('lat') && $INPUT->has('lon')) {
                $results = $helper->findNearbyLatLon($INPUT->param('lat'), $INPUT->param('lon'));
            } elseif ($INPUT->has('geohash')) {
                $results = $helper->findNearby($INPUT->str('geohash'));
            } else {
                $results = ['error' => hsc($this->getLang('invalidinput'))];
            }
        }

        $showMedia = $INPUT->bool('showMedia', true);

        switch ($param['format']) {
            case 'JSON':
                $this->printJSON($results);
                break;
            case 'HTML':
                // fall through to default
            default:
                $this->printHTML($results, $showMedia);
                break;
        }
    }

    /**
     * Print seachresults as HTML lists.
     *
     * @param array $searchresults
     * @throws JsonException if anything goes wrong with JSON encoding
     */
    private function printJSON(array $searchresults): void
    {
        header('Content-Type: application/json');
        echo json_encode($searchresults, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
    }

    /**
     * Print seachresults as HTML lists.
     *
     * @param array $searchresults
     * @param bool $showMedia
     */
    private function printHTML(array $searchresults, bool $showMedia = true): void
    {
        $pages = (array)($searchresults ['pages']);
        $media = (array)$searchresults ['media'];
        $lat = (float)$searchresults ['lat'];
        $lon = (float)$searchresults ['lon'];
        $geohash = (string)$searchresults ['geohash'];

        if (isset($searchresults ['error'])) {
            echo '<div class="level1"><p>' . hsc($searchresults ['error']) . '</p></div>';
            return;
        }

        // print a HTML list
        echo '<h1>' . $this->getLang('results_header') . '</h1>' . DOKU_LF;
        echo '<div class="level1">' . DOKU_LF;
        if ($pages !== []) {
            $pagelist = '<ol>' . DOKU_LF;
            foreach ($pages as $page) {
                $pagelist .= '<li>' . html_wikilink(
                        ':' . $page ['id'],
                        useHeading('navigation') ? null :
                            noNS($page ['id'])
                    ) . ' (' . $this->getLang('results_distance_prefix')
                    . $page ['distance'] . '&nbsp;m) ' . $page ['description'] . '</li>' . DOKU_LF;
            }
            $pagelist .= '</ol>' . DOKU_LF;

            echo '<h2>' . $this->getLang('results_pages') . hsc(
                    ' lat;lon: ' . $lat . ';' . $lon
                    . ' (geohash: ' . $geohash . ')'
                ) . '</h2>';
            echo '<div class="level2">' . DOKU_LF;
            echo $pagelist;
            echo '</div>' . DOKU_LF;
        } else {
            echo '<p>' . hsc($this->getLang('nothingfound')) . '</p>';
        }

        if ($media !== [] && $showMedia) {
            $pagelist = '<ol>' . DOKU_LF;
            foreach ($media as $m) {
                $opts = [];
                $link = ml($m ['id'], $opts, false);
                $opts ['w'] = '100';
                $src = ml($m ['id'], $opts);
                $pagelist .= '<li><a href="' . $link . '"><img src="' . $src . '" alt=""></a> ('
                    . $this->getLang('results_distance_prefix') . $m ['distance'] . '&nbsp;m) '
                    . '</li>' . DOKU_LF;
            }
            $pagelist .= '</ol>' . DOKU_LF;

            echo '<h2>' . $this->getLang('results_media') . hsc(
                    ' lat;lon: ' . $lat . ';' . $lon
                    . ' (geohash: ' . $geohash . ')'
                ) . '</h2>' . DOKU_LF;
            echo '<div class="level2">' . DOKU_LF;
            echo $pagelist;
            echo '</div>' . DOKU_LF;
        }
        echo '<p>' . $this->getLang('results_precision') . $searchresults ['precision'] . ' m. ';
        if (strlen($geohash) > 1) {
            $url = wl(
                getID(),
                ['do' => 'findnearby', 'geohash' => substr($geohash, 0, -1)]
            );
            echo '<a href="' . $url . '" class="findnearby">' . $this->getLang('search_largerarea') . '</a>.</p>'
                . DOKU_LF;
        }
        echo '</div>' . DOKU_LF;
    }

    /**
     * add media to spatial index.
     *
     * @param Doku_Event $event
     */
    final  public function handleMediaUploaded(Event $event): void
    {
        // data[0] temporary file name (read from $_FILES)
        // data[1] file name of the file being uploaded
        // data[2] future directory id of the file being uploaded
        // data[3] the mime type of the file being uploaded
        // data[4] true if the uploaded file exists already
        // data[5] (since 2011-02-06) the PHP function used to move the file to the correct location

        Logger::debug("handleMediaUploaded::event data", $event->data);

        // check the list of mimetypes
        // if it's a supported type call appropriate index function
        if (substr_compare($event->data [3], 'image/jpeg', 0)) {
            $indexer = plugin_load('helper', 'spatialhelper_index');
            if ($indexer !== null) {
                $indexer->indexImage($event->data [2]);
            }
        }
        // TODO add image/tiff
        // TODO kml, gpx, geojson...
    }

    /**
     * removes the media from the index.
     * @param Doku_Event $event event object with data
     */
    final   public function handleMediaDeleted(Event $event): void
    {
        // data['id'] ID data['unl'] unlink return code
        // data['del'] Namespace directory unlink return code
        // data['name'] file name data['path'] full path to the file
        // data['size'] file size

        // remove the media id from the index
        $indexer = plugin_load('helper', 'spatialhelper_index');
        if ($indexer !== null) {
            $indexer->deleteFromIndex('media__' . $event->data ['id']);
        }
    }

    /**
     * add a link to the spatial sitemap files in the header.
     *
     * @param Doku_Event $event the DokuWiki event. $event->data is a two-dimensional array of all meta headers.
     * The keys are meta, link and script.
     *
     * @see http://www.dokuwiki.org/devel:event:tpl_metaheader_output
     */
    final  public function handleMetaheaderOutput(Event $event): void
    {
        // TODO maybe test for exist
        $event->data ["link"] [] = ["type" => "application/atom+xml", "rel" => "alternate",
            "href" => ml($this->getConf('media_georss')), "title" => "Spatial ATOM Feed"];
        $event->data ["link"] [] = ["type" => "application/vnd.google-earth.kml+xml", "rel" => "alternate",
            "href" => ml($this->getConf('media_kml')), "title" => "KML Sitemap"];
    }

    /**
     * Add spatialhelper popularity data.
     *
     * @param Doku_Event $event
     *          the DokuWiki event
     */
    final public function popularity(Event $event): void
    {
        $versionInfo = getVersionData();
        $plugin_info = $this->getInfo();
        $event->data['spatialhelper']['version'] = $plugin_info['date'];
        $event->data['spatialhelper']['dwversion'] = $versionInfo['date'];
        $event->data['spatialhelper']['combinedversion'] = $versionInfo['date'] . '_' . $plugin_info['date'];
    }
}
