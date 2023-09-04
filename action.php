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

use dokuwiki\Sitemap\Item;

/**
 * DokuWiki Plugin dokuwikispatial (Action Component).
 *
 * @license BSD license
 * @author  Mark C. Prins <mprins@users.sf.net>
 */
class action_plugin_spatialhelper extends DokuWiki_Action_Plugin {

    /**
     * Register for events.
     *
     * @param Doku_Event_Handler $controller
     *          DokuWiki's event controller object. Also available as global $EVENT_HANDLER
     */
    public function register(Doku_Event_Handler $controller): void {
        // listen for page add / delete events
        // http://www.dokuwiki.org/devel:event:indexer_page_add
        $controller->register_hook('INDEXER_PAGE_ADD', 'BEFORE', $this, 'handleIndexerPageAdd');
        $controller->register_hook('IO_WIKIPAGE_WRITE', 'BEFORE', $this, 'removeFromIndex');

        // http://www.dokuwiki.org/devel:event:sitemap_generate
        $controller->register_hook('SITEMAP_GENERATE', 'BEFORE', $this, 'handleSitemapGenerateBefore');
        // using after will only trigger us if a sitemap was actually created
        $controller->register_hook('SITEMAP_GENERATE', 'AFTER', $this, 'handleSitemapGenerateAfter');

        // handle actions we know of
        $controller->register_hook('ACTION_ACT_PREPROCESS', 'BEFORE', $this, 'handleActionActPreprocess', array());
        // handle HTML eg. /dokuwiki/doku.php?id=start&do=findnearby&geohash=u15vk4
        $controller->register_hook(
            'TPL_ACT_UNKNOWN', 'BEFORE', $this, 'findnearby', array(
                                 'format' => 'HTML'
                             )
        );
        // handles AJAX/json eg: jQuery.post("/dokuwiki/lib/exe/ajax.php?id=start&call=findnearby&geohash=u15vk4");
        $controller->register_hook(
            'AJAX_CALL_UNKNOWN', 'BEFORE', $this, 'findnearby', array(
                                   'format' => 'JSON'
                               )
        );

        // listen for media uploads and deletes
        $controller->register_hook('MEDIA_UPLOAD_FINISH', 'BEFORE', $this, 'handleMediaUploaded', array());
        $controller->register_hook('MEDIA_DELETE_FILE', 'BEFORE', $this, 'handleMediaDeleted', array());

        $controller->register_hook('TPL_METAHEADER_OUTPUT', 'BEFORE', $this, 'handleMetaheaderOutput');
        $controller->register_hook('PLUGIN_POPULARITY_DATA_SETUP', 'AFTER', $this, 'popularity');
    }

    /**
     * Update the spatial index for the page.
     *
     * @param Doku_Event $event
     *          event object
     * @param mixed      $param
     *          the parameters passed to register_hook when this handler was registered
     */
    public function handleIndexerPageAdd(Doku_Event $event, $param): void {
        // $event→data['page'] – the page id
        // $event→data['body'] – empty, can be filled by additional content to index by your plugin
        // $event→data['metadata'] – the metadata that shall be indexed. This is an array where the keys are the
        //    metadata indexes and the value a string or an array of strings with the values.
        //    title and relation_references will already be set.
        $id      = $event->data ['page'];
        $indexer = plugin_load('helper', 'spatialhelper_index');
        $entries = $indexer->updateSpatialIndex($id);
    }

    /**
     * Update the spatial index, removing the page.
     *
     * @param Doku_Event $event
     *          event object
     * @param mixed      $param
     *          the parameters passed to register_hook when this handler was registered
     */
    public function removeFromIndex(Doku_Event $event, $param): void {
        // event data:
        // $data[0] – The raw arguments for io_saveFile as an array. Do not change file path.
        // $data[0][0] – the file path.
        // $data[0][1] – the content to be saved, and may be modified.
        // $data[1] – ns: The colon separated namespace path minus the trailing page name. (false if root ns)
        // $data[2] – page_name: The wiki page name.
        // $data[3] – rev: The page revision, false for current wiki pages.

        dbglog($event->data, "Event data in removeFromIndex.");
        if(@file_exists($event->data [0] [0])) {
            // file not new
            if(!$event->data [0] [1]) {
                // file is empty, page is being deleted
                if(empty ($event->data [1])) {
                    // root namespace
                    $id = $event->data [2];
                } else {
                    $id = $event->data [1] . ":" . $event->data [2];
                }
                $indexer = plugin_load('helper', 'spatialhelper_index');
                if($indexer) {
                    $indexer->deleteFromIndex($id);
                }
            }
        }
    }

    /**
     * Add a new SitemapItem object that points to the KML of public geocoded pages.
     *
     * @param Doku_Event $event
     * @param mixed      $param
     */
    public function handleSitemapGenerateBefore(Doku_Event $event, $param): void {
        $path                     = mediaFN($this->getConf('media_kml'));
        $lastmod                  = @filemtime($path);
        $event->data ['items'] [] = new Item(ml($this->getConf('media_kml'), '', true, '&amp;', true), $lastmod);
        //dbglog($event->data ['items'],
        //  "Added a new SitemapItem object that points to the KML of public geocoded pages.");
    }

    /**
     * Create a spatial sitemap or attach the geo/kml map to the sitemap.
     *
     * @param Doku_Event $event
     *          event object, not used
     * @param mixed      $param
     *          parameter array, not used
     */
    public function handleSitemapGenerateAfter(Doku_Event $event, $param): bool {
        // $event→data['items']: Array of SitemapItem instances, the array of sitemap items that already
        //      contains all public pages of the wiki
        // $event→data['sitemap']: The path of the file the sitemap will be saved to.
        if($helper = plugin_load('helper', 'spatialhelper_sitemap')) {
            // dbglog($helper, "createSpatialSitemap loaded helper.");

            $kml = $helper->createKMLSitemap($this->getConf('media_kml'));
            $rss = $helper->createGeoRSSSitemap($this->getConf('media_georss'));

            if(!empty ($this->getConf('sitemap_namespaces'))) {
                $namespaces = array_map('trim', explode("\n", $this->getConf('sitemap_namespaces')));
                foreach($namespaces as $namespace) {
                    $kmlN = $helper->createKMLSitemap($namespace . $this->getConf('media_kml'));
                    $rssN = $helper->createGeoRSSSitemap($namespace . $this->getConf('media_georss'));
                    dbglog(
                        $kmlN && $rssN,
                        "handleSitemapGenerateAfter, created KML / GeoRSS sitemap in $namespace, succes: "
                    );
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
     *          event object
     * @param mixed      $param
     *          not used
     */
    public function handleActionActPreprocess(Doku_Event $event, $param): void {
        if($event->data !== 'findnearby') {
            return;
        }
        $event->preventDefault();
    }

    /**
     * handle findnearby action.
     *
     * @param Doku_Event $event
     *          event object
     * @param mixed      $param
     *          associative array with keys
     *          'format'=> HTML | JSON
     */
    public function findnearby(Doku_Event $event, $param): void {
        if($event->data !== 'findnearby') {
            return;
        }
        $event->preventDefault();
        $results = array();
        global $INPUT;
        if($helper = plugin_load('helper', 'spatialhelper_search')) {
            if($INPUT->has('lat') && $INPUT->has('lon')) {
                $results = $helper->findNearbyLatLon($INPUT->param('lat'), $INPUT->param('lon'));
            } elseif($INPUT->has('geohash')) {
                $results = $helper->findNearby($INPUT->str('geohash'));
            } else {
                $results = array(
                    'error' => hsc($this->getLang('invalidinput'))
                );
            }
        }

        $showMedia = $INPUT->bool('showMedia', true);

        switch($param['format']) {
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
    private function printJSON(array $searchresults): void {
        require_once DOKU_INC . 'inc/JSON.php';
        $json = new JSON();
        header('Content-Type: application/json');
        print $json->encode($searchresults);
    }

    /**
     * Print seachresults as HTML lists.
     *
     * @param array $searchresults
     * @param bool  $showMedia
     */
    private function printHTML(array $searchresults, bool $showMedia = true): void {
        $pages   = (array) ($searchresults ['pages']);
        $media   = (array) $searchresults ['media'];
        $lat     = (float) $searchresults ['lat'];
        $lon     = (float) $searchresults ['lon'];
        $geohash = (string) $searchresults ['geohash'];

        if(isset ($searchresults ['error'])) {
            print '<div class="level1"><p>' . hsc($searchresults ['error']) . '</p></div>';
            return;
        }

        // print a HTML list
        print '<h1>' . $this->getLang('results_header') . '</h1>' . DOKU_LF;
        print '<div class="level1">' . DOKU_LF;
        if(!empty ($pages)) {
            $pagelist = '<ol>' . DOKU_LF;
            foreach($pages as $page) {
                $pagelist .= '<li>' . html_wikilink(
                        ':' . $page ['id'], useHeading('navigation') ? null :
                        noNS($page ['id'])
                    ) . ' (' . $this->getLang('results_distance_prefix')
                    . $page ['distance'] . '&nbsp;m) ' . $page ['description'] . '</li>' . DOKU_LF;
            }
            $pagelist .= '</ol>' . DOKU_LF;

            print '<h2>' . $this->getLang('results_pages') . hsc(
                    ' lat;lon: ' . $lat . ';' . $lon
                    . ' (geohash: ' . $geohash . ')'
                ) . '</h2>';
            print '<div class="level2">' . DOKU_LF;
            print $pagelist;
            print '</div>' . DOKU_LF;
        } else {
            print '<p>' . hsc($this->getLang('nothingfound')) . '</p>';
        }

        if(!empty ($media) && $showMedia) {
            $pagelist = '<ol>' . DOKU_LF;
            foreach($media as $m) {
                $opts       = array();
                $link       = ml($m ['id'], $opts, false, '&amp;', false);
                $opts ['w'] = '100';
                $src        = ml($m ['id'], $opts);
                $pagelist   .= '<li><a href="' . $link . '"><img src="' . $src . '"></a> ('
                    . $this->getLang('results_distance_prefix') . $page ['distance'] . '&nbsp;m) ' . hsc($desc)
                    . '</li>' . DOKU_LF;
            }
            $pagelist .= '</ol>' . DOKU_LF;

            print '<h2>' . $this->getLang('results_media') . hsc(
                    ' lat;lon: ' . $lat . ';' . $lon
                    . ' (geohash: ' . $geohash . ')'
                ) . '</h2>' . DOKU_LF;
            print '<div class="level2">' . DOKU_LF;
            print $pagelist;
            print '</div>' . DOKU_LF;
        }
        print '<p>' . $this->getLang('results_precision') . $searchresults ['precision'] . ' m. ';
        if(strlen($geohash) > 1) {
            $url = wl(
                getID(), array(
                           'do'      => 'findnearby',
                           'geohash' => substr($geohash, 0, -1)
                       )
            );
            print '<a href="' . $url . '" class="findnearby">' . $this->getLang('search_largerarea') . '</a>.</p>'
                . DOKU_LF;
        }
        print '</div>' . DOKU_LF;
    }

    /**
     * add media to spatial index.
     *
     * @param Doku_Event $event
     * @param mixed      $param
     */
    public function handleMediaUploaded(Doku_Event $event, $param): void {
        // data[0] temporary file name (read from $_FILES)
        // data[1] file name of the file being uploaded
        // data[2] future directory id of the file being uploaded
        // data[3] the mime type of the file being uploaded
        // data[4] true if the uploaded file exists already
        // data[5] (since 2011-02-06) the PHP function used to move the file to the correct location

        dbglog($event->data, "handleMediaUploaded::event data");

        // check the list of mimetypes
        // if it's a supported type call appropriate index function
        if(substr_compare($event->data [3], 'image/jpeg', 0)) {
            $indexer = plugin_load('helper', 'spatialhelper_index');
            if($indexer) {
                $indexer->indexImage($event->data [2]);
            }
        }
        // TODO add image/tiff
        // TODO kml, gpx, geojson...
    }

    /**
     * removes the media from the index.
     */
    public function handleMediaDeleted(Doku_Event $event, $param): void {
        // data['id'] ID data['unl'] unlink return code
        // data['del'] Namespace directory unlink return code
        // data['name'] file name data['path'] full path to the file
        // data['size'] file size

        dbglog($event->data, "handleMediaDeleted::event data");

        // remove the media id from the index
        $indexer = plugin_load('helper', 'spatialhelper_index');
        if($indexer) {
            $indexer->deleteFromIndex('media__' . $event->data ['id']);
        }
    }

    /**
     * add a link to the spatial sitemap files in the header.
     *
     * @param Doku_Event $event
     *          the DokuWiki event. $event->data is a two-dimensional
     *          array of all meta headers. The keys are meta, link and script.
     * @param mixed      $param
     *
     * @see http://www.dokuwiki.org/devel:event:tpl_metaheader_output
     */
    public function handleMetaheaderOutput(Doku_Event $event, $param): void {
        // TODO maybe test for exist
        $event->data ["link"] [] = array(
            "type"  => "application/atom+xml",
            "rel"   => "alternate",
            "href"  => ml($this->getConf('media_georss')),
            "title" => "Spatial ATOM Feed"
        );
        $event->data ["link"] [] = array(
            "type"  => "application/vnd.google-earth.kml+xml",
            "rel"   => "alternate",
            "href"  => ml($this->getConf('media_kml')),
            "title" => "KML Sitemap"
        );
    }

    /**
     * Add spatialhelper popularity data.
     *
     * @param Doku_Event $event
     *          the DokuWiki event
     */
    final public function popularity(Doku_Event $event): void {
        $versionInfo                                     = getVersionData();
        $plugin_info                                     = $this->getInfo();
        $event->data['spatialhelper']['version']         = $plugin_info['date'];
        $event->data['spatialhelper']['dwversion']       = $versionInfo['date'];
        $event->data['spatialhelper']['combinedversion'] = $versionInfo['date'] . '_' . $plugin_info['date'];
    }

    /**
     * Calculate a new coordinate based on start, distance and bearing
     *
     * @param $start array
     *               - start coordinate as decimal lat/lon pair
     * @param $dist  float
     *               - distance in kilometers
     * @param $brng  float
     *               - bearing in degrees (compass direction)
     */
    private function geoDestination(array $start, float $dist, float $brng): array {
        $lat1 = $this->toRad($start [0]);
        $lon1 = $this->toRad($start [1]);
        // http://en.wikipedia.org/wiki/Earth_radius
        // average earth radius in km
        $dist = $dist / 6371.01;
        $brng = $this->toRad($brng);

        $lon2 = $lon1 + atan2(sin($brng) * sin($dist) * cos($lat1), cos($dist) - sin($lat1) * sin($lat2));
        $lon2 = fmod(($lon2 + 3 * M_PI), (2 * M_PI)) - M_PI;

        return array(
            $this->toDeg($lat2),
            $this->toDeg($lon2)
        );
    }

    private function toRad(float $deg): float {
        return $deg * M_PI / 180;
    }

    private function toDeg(float $rad): float {
        return $rad * 180 / M_PI;
    }
}
