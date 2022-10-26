<?php
/*
 * Copyright (c) 2011-2017 Mark C. Prins <mprins@users.sf.net>
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
 * DokuWiki Plugin spatialhelper (index component).
 *
 * @license BSD license
 * @author  Mark Prins
 */
class helper_plugin_spatialhelper_index extends DokuWiki_Plugin {
    /**
     * directory for index files.
     *
     * @var string
     */
    protected $idx_dir = '';

    /**
     * spatial index, well lookup list/array so we can do spatial queries.
     * entries should be: array("geohash" => {"id1","id3",})
     *
     * @var array
     */
    protected $spatial_idx = array();

    /**
     * handle to the geoPHP plugin.
     */
    protected $geophp;

    /**
     * Constructor, initialises the spatial index.
     */
    public function __construct() {
        if(!$geophp = plugin_load('helper', 'geophp')) {
            $message = 'helper_plugin_spatialhelper_index::spatialhelper_index: geophp plugin is not available.';
            msg($message, -1);
        }

        global $conf;
        $this->idx_dir = $conf ['indexdir'];
        // test if there is a spatialindex, if not build one for the wiki
        if(!@file_exists($this->idx_dir . '/spatial.idx')) {
            // creates and stores the index
            $this->generateSpatialIndex();
        } else {
            $this->spatial_idx = unserialize(io_readFile($this->idx_dir . '/spatial.idx', false), false);
            dbglog($this->spatial_idx, 'done loading spatial index');
        }
    }

    /**
     * (re-)Generates the spatial index by running through all the pages in the wiki.
     *
     * @todo add an option to erase the old index
     */
    public function generateSpatialIndex(): bool {
        global $conf;
        require_once(DOKU_INC . 'inc/search.php');
        $pages = array();
        search($pages, $conf ['datadir'], 'search_allpages', array());
        foreach($pages as $page) {
            $this->updateSpatialIndex($page ['id']);
        }
        // media
        $media = array();
        search($media, $conf ['mediadir'], 'search_media', array());
        foreach($media as $medium) {
            if($medium ['isimg']) {
                $this->indexImage($medium);
            }
        }
        return true;
    }

    /**
     * Update the spatial index for the page.
     *
     * @param string $id
     *          the document ID
     * @throws Exception
     */
    public function updateSpatialIndex(string $id): bool {
        $geotags = p_get_metadata($id, 'geo');
        if(empty ($geotags)) {
            return false;
        }
        if(empty ($geotags ['lon']) || empty ($geotags ['lat'])) {
            return false;
        }
        dbglog($geotags, "Geo metadata found for page $id");
        $geometry = new geoPHP\Geometry\Point($geotags ['lon'], $geotags ['lat']);
        $geohash  = $geometry->out('geohash');
        dbglog('Update index for geohash: ' . $geohash);
        return $this->addToIndex($geohash, $id);
    }

    /**
     * Store the hash/id entry in the index.
     *
     * @param string $geohash
     * @param string $id
     *          page or media id
     * @return bool true when succesful
     */
    private function addToIndex(string $geohash, string $id): bool {
        $pageIds = array();
        // check index for key/geohash
        if(!array_key_exists($geohash, $this->spatial_idx)) {
            dbglog("Geohash $geohash not in index, just add $id.");
            $pageIds [] = $id;
        } else {
            dbglog('Geohash for document is in index, find it.');
            // check the index for document
            $knownHashes = $this->findHashesForId($id, $this->spatial_idx);
            if(empty ($knownHashes)) {
                dbglog("No index record found for document $id, just add");
                $pageIds    = $this->spatial_idx [$geohash];
                $pageIds [] = $id;
            }
            // TODO shortcut, need to make sure there is only one element, if not the index is corrupt
            $knownHash = $knownHashes [0];

            if($knownHash === $geohash) {
                dbglog("Document $id was found in index and has the same geohash, nothing to do.");
                return true;
            }

            if(!empty ($knownHash)) {
                dbglog("Document/media $id was found in index but has different geohash (it moved).");
                $knownIds = $this->spatial_idx [$knownHash];
                dbglog($knownIds, "Known id's for this hash:");
                // remove it from the old geohash element
                $i = array_search($id, $knownIds);
                dbglog('Unsetting:' . $knownIds [$i]);
                unset ($knownIds [$i]);
                $this->spatial_idx [$knownHash] = $knownIds;
                // set on new geohash element
                $pageIds    = $this->spatial_idx [$geohash];
                $pageIds [] = $id;
            }
        }
        // store and save
        $this->spatial_idx [$geohash] = $pageIds;
        return $this->saveIndex();
    }

    /**
     * Looks up the geohash(es) for the document in the index.
     *
     * @param String $id
     *          document ID
     * @param array  $index
     *          spatial index
     */
    public function findHashesForId(string $id, array $index): array {
        $hashes = array();
        foreach($index as $hash => $docIds) {
            if(in_array($id, $docIds, false)) {
                $hashes [] = $hash;
            }
        }
        dbglog($hashes, "Found the following hashes for $id (should only be 1)");
        return $hashes;
    }

    /**
     * Save spatial index.
     */
    private function saveIndex(): bool {
        return io_saveFile($this->idx_dir . '/spatial.idx', serialize($this->spatial_idx));
    }

    /**
     * Add an index entry for this file having EXIF / IPTC data.
     *
     * @param $img
     *          a Dokuwiki image
     * @return bool true when image was succesfully added to the index.
     * @throws Exception
     * @see http://www.php.net/manual/en/function.iptcparse.php
     * @see http://php.net/manual/en/function.exif-read-data.php
     *
     */
    public function indexImage($img): bool {
        // test for supported files (jpeg only)
        if(
            (substr($img ['file'], -strlen('.jpg')) !== '.jpg') &&
            (substr($img ['file'], -strlen('.jpeg')) !== '.jpeg')) {
            return false;
        }

        $geometry = $this->getCoordsFromExif($img ['id']);
        if(!$geometry) {
            return false;
        }
        $geohash = $geometry->out('geohash');
        // TODO truncate the geohash to something reasonable, otherwise they are
        // useless as an indexing mechanism eg. u1h73weckdrmskdqec3c9 is far too
        // precise, limit at ~9 as most GPS are not submeter accurate
        return $this->addToIndex($geohash, 'media__' . $img ['id']);
    }

    /**
     * retrieve GPS decimal coordinates from exif.
     *
     * @param string $id
     * @return geoPHP\Geometry\Point|false
     * @throws Exception
     */
    public function getCoordsFromExif(string $id) {
        $exif = exif_read_data(mediaFN($id), 0, true);
        if(empty ($exif ['GPS'])) {
            return false;
        }

        $lat = $this->convertDMStoD(
            array(
                $exif ['GPS'] ['GPSLatitude'] [0],
                $exif ['GPS'] ['GPSLatitude'] [1],
                $exif ['GPS'] ['GPSLatitude'] [2],
                $exif ['GPS'] ['GPSLatitudeRef']
            )
        );

        $lon = $this->convertDMStoD(
            array(
                $exif ['GPS'] ['GPSLongitude'] [0],
                $exif ['GPS'] ['GPSLongitude'] [1],
                $exif ['GPS'] ['GPSLongitude'] [2],
                $exif ['GPS'] ['GPSLongitudeRef']
            )
        );

        return new geoPHP\Geometry\Point($lon, $lat);
    }

    /**
     * convert DegreesMinutesSeconds to Decimal degrees.
     *
     * @param array $param array of rational DMS
     * @return float
     */
    public function convertDMStoD(array $param): float {
        if(!is_array($param)) {
            $param = array($param);
        }
        $deg = $this->convertRationaltoFloat($param [0]);
        $min = $this->convertRationaltoFloat($param [1]) / 60;
        $sec = $this->convertRationaltoFloat($param [2]) / 60 / 60;
        // Hemisphere (N, S, W or E)
        $hem = ($param [3] === 'N' || $param [3] === 'E') ? 1 : -1;
        return $hem * ($deg + $min + $sec);
    }

    public function convertRationaltoFloat($param): float {
        // rational64u
        $nums = explode('/', $param);
        if((int) $nums[1] > 0) {
            return (float) $nums[0] / (int) $nums[1];
        } else {
            return (float) $nums[0];
        }
    }

    /**
     * Deletes the page from the index.
     *
     * @param string $id document ID
     */
    public function deleteFromIndex(string $id): void {
        // check the index for document
        $knownHashes = $this->findHashesForId($id, $this->spatial_idx);
        if(empty ($knownHashes)) {
            return;
        }

        // TODO shortcut, need to make sure there is only one element, if not the index is corrupt
        $knownHash = $knownHashes [0];
        $knownIds  = $this->spatial_idx [$knownHash];
        $i         = array_search($id, $knownIds);
        dbglog("removing: $knownIds[$i] from the index.");
        unset ($knownIds [$i]);
        $this->spatial_idx [$knownHash] = $knownIds;
        if(empty ($this->spatial_idx [$knownHash])) {
            // dbglog ( "removing key: $knownHash from the index." );
            unset ($this->spatial_idx [$knownHash]);
        }
        $this->saveIndex();
    }
}
