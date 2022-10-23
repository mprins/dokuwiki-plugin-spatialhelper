<?php
/*
 * Copyright (c) 2014-2016 Mark C. Prins <mprins@users.sf.net>
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
 * DokuWiki Plugin spatialhelper (sitemap Component).
 *
 * @license BSD license
 * @author  Mark Prins
 */
class helper_plugin_spatialhelper_sitemap extends DokuWiki_Plugin {
    /**
     * spatial index.
     */
    private $spatial_idx;

    /**
     * constructor, load spatial index.
     */
    public function __construct() {
        global $conf;
        $idx_dir = $conf['indexdir'];
        if(!@file_exists($idx_dir . '/spatial.idx')) {
            $indexer = plugin_load('helper', 'spatialhelper_index');
            if($indexer !== null) {
                $indexer->generateSpatialIndex();
            }
        }
        $this->spatial_idx = unserialize(io_readFile($fn = $idx_dir . '/spatial.idx', false));
    }

    final public function getMethods(): array {
        $result[] = array(
            'name'   => 'createGeoRSSSitemap',
            'desc'   => 'create a spatial sitemap in GeoRSS format.',
            'params' => array(
                'path' => 'string'
            ),
            'return' => array(
                'success' => 'boolean'
            )
        );
        $result[] = array(
            'name'   => 'createKMLSitemap',
            'desc'   => 'create a spatial sitemap in KML format.',
            'params' => array(
                'path' => 'string'
            ),
            'return' => array(
                'success' => 'boolean'
            )
        );
        return $result;
    }

    /**
     * Create a GeoRSS Simple sitemap (Atom).
     *
     * @param string $mediaID id
     *                        for the GeoRSS file
     */
    final public function createGeoRSSSitemap(string $mediaID): bool {
        global $conf;
        $namespace = getNS($mediaID);

        $idTag = 'tag:' . parse_url(DOKU_URL, PHP_URL_HOST) . ',';

        $RSSstart = '<?xml version="1.0" encoding="UTF-8"?>' . DOKU_LF;
        $RSSstart .= '<feed xmlns="http://www.w3.org/2005/Atom" xmlns:georss="http://www.georss.org/georss" ';
        $RSSstart .= 'xmlns:dc="http://purl.org/dc/elements/1.1/">' . DOKU_LF;
        $RSSstart .= '<title>' . $conf['title'] . ' spatial feed</title>' . DOKU_LF;
        if(!empty($conf['tagline'])) {
            $RSSstart .= '<subtitle>' . $conf['tagline'] . '</subtitle>' . DOKU_LF;
        }
        $RSSstart .= '<dc:publisher>' . $conf['title'] . '</dc:publisher>' . DOKU_LF;
        $RSSstart .= '<link href="' . DOKU_URL . '" />' . DOKU_LF;
        $RSSstart .= '<link href="' . ml($mediaID, '', true, '&amp;', true) . '" rel="self" />' . DOKU_LF;
        $RSSstart .= '<updated>' . date(DATE_ATOM) . '</updated>' . DOKU_LF;
        $RSSstart .= '<id>' . $idTag . date("Y-m-d") . ':' . parse_url(ml($mediaID), PHP_URL_PATH)
            . '</id>' . DOKU_LF;
        $RSSstart .= '<rights>' . $conf['license'] . '</rights>' . DOKU_LF;

        $RSSend = '</feed>' . DOKU_LF;

        io_createNamespace($mediaID, 'media');
        @touch(mediaFN($mediaID));
        @chmod(mediaFN($mediaID), $conf['fmode']);
        $fh = fopen(mediaFN($mediaID), 'wb');
        fwrite($fh, $RSSstart);

        foreach($this->spatial_idx as $idxEntry) {
            // get list of id's
            foreach($idxEntry as $id) {
                // for document item in the index
                if(strpos($id, 'media__', 0) !== 0) {
                    if($this->skipPage($id, $namespace)) {
                        continue;
                    }

                    $meta = p_get_metadata($id);

                    // $desc = p_render('xhtmlsummary', p_get_instructions($meta['description']['abstract']), $info);
                    $desc = strip_tags($meta['description']['abstract']);

                    $entry = '<entry>' . DOKU_LF;
                    $entry .= '  <title>' . $meta['title'] . '</title>' . DOKU_LF;
                    $entry .= '  <summary>' . $desc . '</summary>' . DOKU_LF;
                    $entry .= '  <georss:point>' . $meta['geo']['lat'] . ' ' . $meta['geo']['lon']
                        . '</georss:point>' . DOKU_LF;
                    if(isset($meta['geo']['alt'])) {
                        $entry .= '  <georss:elev>' . $meta['geo']['alt'] . '</georss:elev>' . DOKU_LF;
                    }
                    $entry .= '  <link href="' . wl($id) . '" rel="alternate" type="text/html" />' . DOKU_LF;
                    if(empty($meta['creator'])) {
                        $meta['creator'] = $conf['title'];
                    }
                    $entry .= '  <author><name>' . $meta['creator'] . '</name></author>' . DOKU_LF;
                    $entry .= '  <updated>' . date_iso8601($meta['date']['modified']) . '</updated>' . DOKU_LF;
                    $entry .= '  <published>' . date_iso8601($meta['date']['created']) . '</published>' . DOKU_LF;
                    $entry .= '  <id>' . $idTag . date("Y-m-d", $meta['date']['modified']) . ':'
                        . parse_url(wl($id), PHP_URL_PATH) . '</id>' . DOKU_LF;
                    $entry .= '</entry>' . DOKU_LF;
                    fwrite($fh, $entry);
                }
            }
        }

        fwrite($fh, $RSSend);
        return fclose($fh);
    }

    /**
     * will return true for non-public or hidden pages or pages that are not below or in the namespace.
     */
    private function skipPage(string $id, string $namespace): bool {
        dbglog("helper_plugin_spatialhelper_sitemap::skipPage, check for $id in $namespace");
        if(isHiddenPage($id)) {
            return true;
        }
        if(auth_aclcheck($id, '', null) < AUTH_READ) {
            return true;
        }

        if(!empty($namespace)) {
            // only if id is in or below namespace
            if(0 !== strpos(getNS($id), $namespace)) {
                // dbglog("helper_plugin_spatialhelper_sitemap::skipPage, skipping $id, not in $namespace");
                return true;
            }
        }
        return false;
    }

    /**
     * Create a KML sitemap.
     *
     * @param string $mediaID id for the KML file
     */
    final public function createKMLSitemap(string $mediaID): bool {
        global $conf;
        $namespace = getNS($mediaID);

        $KMLstart = '<?xml version="1.0" encoding="UTF-8"?>' . DOKU_LF;
        $KMLstart .= '<kml xmlns="http://www.opengis.net/kml/2.2" ';
        $KMLstart .= 'xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" ';
        $KMLstart .= 'xmlns:atom="http://www.w3.org/2005/Atom"';
        $KMLstart .= ' xsi:schemaLocation="http://www.opengis.net/kml/2.2 ';
        $KMLstart .= 'http://schemas.opengis.net/kml/2.2.0/ogckml22.xsd">' . DOKU_LF;
        $KMLstart .= '<Document id="root_doc">' . DOKU_LF;
        $KMLstart .= '<name>' . $conf['title'] . ' spatial sitemap</name>' . DOKU_LF;
        $KMLstart .= '<atom:link href="' . DOKU_URL . '" rel="related" type="text/html" />' . DOKU_LF;
        $KMLstart .= '<!-- atom:updated>' . date(DATE_ATOM) . '</atom:updated -->' . DOKU_LF;
        $KMLstart .= '<Style id="icon"><IconStyle><color>ffffffff</color><scale>1</scale>';
        $KMLstart .= '<Icon><href>'
            . DOKU_BASE . 'lib/plugins/spatialhelper/wikiitem.png</href></Icon></IconStyle></Style>' . DOKU_LF;

        $KMLend = '</Document>' . DOKU_LF . '</kml>';

        io_createNamespace($mediaID, 'media');
        @touch(mediaFN($mediaID));
        @chmod(mediaFN($mediaID), $conf['fmode']);

        $fh = fopen(mediaFN($mediaID), 'wb');
        fwrite($fh, $KMLstart);

        foreach($this->spatial_idx as $idxEntry) {
            // get list of id's
            foreach($idxEntry as $id) {
                // for document item in the index
                if(strpos($id, 'media__', 0) !== 0) {
                    if($this->skipPage($id, $namespace)) {
                        continue;
                    }

                    $meta = p_get_metadata($id);

                    // $desc = p_render('xhtmlsummary', p_get_instructions($meta['description']['abstract']), $info);
                    $desc = '<p>' . strip_tags($meta['description']['abstract']) . '</p>';
                    $desc .= '<p><a href="' . wl($id, '', true) . '">' . $meta['title'] . '</a></p>';

                    // create an entry and store it
                    $plcm = '<Placemark id="crc32-' . hash('crc32', $id) . '">' . DOKU_LF;
                    $plcm .= '  <name>' . $meta['title'] . '</name>' . DOKU_LF;
                    // TODO escape quotes in: title="' . $meta['title'] . '"
                    $plcm .= '  <atom:link href="' . wl($id, '' . true) . '" rel="alternate" type="text/html" />'
                        . DOKU_LF;
                    if(!empty($meta['creator'])) {
                        $plcm .= '  <atom:author><atom:name>' . $meta['creator'] . '</atom:name></atom:author>'
                            . DOKU_LF;
                    }

                    $plcm .= '  <description><![CDATA[' . $desc . ']]></description>' . DOKU_LF;
                    $plcm .= '  <styleUrl>#icon</styleUrl>' . DOKU_LF;

                    $plcm .= '  <Point><coordinates>' . $meta['geo']['lon'] . ',' . $meta['geo']['lat'];
                    if(isset($meta['geo']['alt'])) {
                        $plcm .= ',' . $meta['geo']['alt'];
                    }
                    $plcm .= '</coordinates></Point>' . DOKU_LF;
                    $plcm .= '</Placemark>' . DOKU_LF;

                    fwrite($fh, $plcm);
                }
            }
        }
        fwrite($fh, $KMLend);
        return fclose($fh);
    }
}
