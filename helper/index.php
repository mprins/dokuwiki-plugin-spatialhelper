<?php

/*
 * Copyright (c) 2011-2024 Mark C. Prins <mprins@users.sf.net>
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

use dokuwiki\Extension\Plugin;
use dokuwiki\Logger;
use geoPHP\Geometry\Point;

/**
 * DokuWiki Plugin spatialhelper (index component).
 *
 * @license BSD license
 * @author  Mark Prins
 */
class helper_plugin_spatialhelper_index extends Plugin
{
    /**
     * directory for index files.
     *
     * @var string
     */
    protected string $idx_dir = '';

    /**
     * spatial index, we'll look up list/array so we can do spatial queries.
     * entries should be: array("geohash" => {"id1","id3",})
     *
     * @var array
     */
    protected array $spatial_idx = [];

    /**
     * Constructor, initialises the spatial index.
     * @throws Exception
     */
    public function __construct()
    {
        if (plugin_load('helper', 'geophp') === null) {
            $message = 'Required geophp plugin is not available';
            msg($message, -1);
        }

        global $conf;
        $this->idx_dir = $conf ['indexdir'];
        // test if there is a spatialindex, if not build one for the wiki
        if (!@file_exists($this->idx_dir . '/spatial.idx')) {
            // creates and stores the index
            $this->generateSpatialIndex();
        } else {
            $this->spatial_idx = unserialize(
                io_readFile($this->idx_dir . '/spatial.idx', false),
                ['allowed_classes' => false]
            );
            Logger::debug('done loading spatial index', $this->spatial_idx);
        }
    }

    /**
     * (re-)Generates the spatial index by running through all the pages in the wiki.
     *
     * @throws Exception
     * @todo add an option to erase the old index
     */
    final public function generateSpatialIndex(): bool
    {
        global $conf;
        require_once(DOKU_INC . 'inc/search.php');
        $pages = [];
        search($pages, $conf ['datadir'], 'search_allpages', []);
        foreach ($pages as $page) {
            $this->updateSpatialIndex($page ['id']);
        }
        // media
        $media = [];
        search($media, $conf['mediadir'], 'search_media', []);
        foreach ($media as $medium) {
            if ($medium['isimg']) {
                $this->indexImage($medium['id']);
            }
        }
        return true;
    }

    /**
     * Update the spatial index for the page.
     *
     * @param string $id
     *          the document ID
     * @param bool $verbose
     *         if true, echo debug info
     * @throws Exception
     */
    final public function updateSpatialIndex(string $id, bool $verbose = false): bool
    {
        $geotags = p_get_metadata($id, 'geo');
        if (empty($geotags)) {
            if ($verbose) echo "No geo metadata found for page $id" . DOKU_LF;
            return false;
        }
        if (empty($geotags ['lon']) || empty($geotags ['lat'])) {
            if ($verbose) echo "No valid geo metadata found for page $id" . DOKU_LF;
            return false;
        }
        Logger::debug("Geo metadata found for page $id", $geotags);
        $geometry = new Point($geotags ['lon'], $geotags ['lat']);
        $geohash = $geometry->out('geohash');
        Logger::debug('Update index for geohash: ' . $geohash);
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
    private function addToIndex(string $geohash, string $id): bool
    {
        $pageIds = [];
        // check index for key/geohash
        if (!array_key_exists($geohash, $this->spatial_idx)) {
            Logger::debug("Geohash $geohash not in index, just add $id.");
            $pageIds [] = $id;
        } else {
            Logger::debug('Geohash for document is in index, find it.');
            // check the index for document
            $knownHashes = $this->findHashesForId($id, $this->spatial_idx);
            if ($knownHashes === []) {
                Logger::debug("No index record found for document $id, adding it to the index.");
                $pageIds = $this->spatial_idx [$geohash];
                $pageIds [] = $id;
            }
            // TODO shortcut, need to make sure there is only one element, if not the index is corrupt
            $knownHash = $knownHashes [0];

            if ($knownHash === $geohash) {
                Logger::debug("Document $id was found in index and has the same geohash, nothing to do.");
                return true;
            }

            if (!empty($knownHash)) {
                Logger::debug("Document/media $id was found in index but has different geohash (it moved).");
                $knownIds = $this->spatial_idx [$knownHash];
                Logger::debug("Known id's for this hash:", $knownIds);
                // remove it from the old geohash element
                $i = array_search($id, $knownIds);
                Logger::debug('Unsetting:' . $knownIds [$i]);
                unset($knownIds [$i]);
                $this->spatial_idx [$knownHash] = $knownIds;
                // set on new geohash element
                $pageIds = $this->spatial_idx[$geohash];
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
     * @param array $index
     *          spatial index
     */
    final public function findHashesForId(string $id, array $index): array
    {
        $hashes = [];
        foreach ($index as $hash => $docIds) {
            if (in_array($id, $docIds)) {
                $hashes [] = $hash;
            }
        }
        Logger::debug("Found the following hashes for $id (should only be 1)", $hashes);
        return $hashes;
    }

    /**
     * Save spatial index.
     */
    private function saveIndex(): bool
    {
        return io_saveFile($this->idx_dir . '/spatial.idx', serialize($this->spatial_idx));
    }

    /**
     * Add an index entry for this file having EXIF / IPTC data.
     *
     * @param $imgId
     *          a Dokuwiki image id
     * @return bool true when image was successfully added to the index.
     * @throws Exception
     * @see http://www.php.net/manual/en/function.iptcparse.php
     * @see http://php.net/manual/en/function.exif-read-data.php
     *
     */
    final public function indexImage(string $imgId): bool
    {
        // test for supported files (jpeg only)
        if (
            (!str_ends_with(strtolower($imgId), '.jpg')) &&
            (!str_ends_with(strtolower($imgId), '.jpeg'))
        ) {
            Logger::debug("indexImage:: " . $imgId . " is not a supported image file.");
            return false;
        }

        $geometry = $this->getCoordsFromExif($imgId);
        if (!$geometry) {
            return false;
        }
        $geohash = $geometry->out('geohash');
        // TODO truncate the geohash to something reasonable, otherwise they are
        //   useless as an indexing mechanism eg. u1h73weckdrmskdqec3c9 is far too
        //   precise, limit at ~9 as most GPS are not submeter accurate
        return $this->addToIndex($geohash, 'media__' . $imgId);
    }

    /**
     * retrieve GPS decimal coordinates from exif.
     *
     * @param string $id
     * @return Point|false
     * @throws Exception
     */
    final public function getCoordsFromExif(string $id): Point|false
    {
        $exif = exif_read_data(mediaFN($id), 0, true);
        if (!$exif || empty($exif ['GPS'])) {
            return false;
        }

        $lat = $this->convertDMStoD(
            [
                $exif ['GPS'] ['GPSLatitude'] [0],
                $exif ['GPS'] ['GPSLatitude'] [1],
                $exif ['GPS'] ['GPSLatitude'] [2],
                $exif ['GPS'] ['GPSLatitudeRef'] ?? 'N'
            ]
        );

        $lon = $this->convertDMStoD(
            [
                $exif ['GPS'] ['GPSLongitude'] [0],
                $exif ['GPS'] ['GPSLongitude'] [1],
                $exif ['GPS'] ['GPSLongitude'] [2],
                $exif ['GPS'] ['GPSLongitudeRef'] ?? 'E'
            ]
        );

        return new Point($lon, $lat);
    }

    /**
     * convert DegreesMinutesSeconds to Decimal degrees.
     *
     * @param array $param array of rational DMS
     */
    final  public function convertDMStoD(array $param): float
    {
        //        if (!(is_array($param))) {
        //            $param = [$param];
        //        }
        $deg = $this->convertRationaltoFloat($param [0]);
        $min = $this->convertRationaltoFloat($param [1]) / 60;
        $sec = $this->convertRationaltoFloat($param [2]) / 60 / 60;
        // Hemisphere (N, S, W or E)
        $hem = ($param [3] === 'N' || $param [3] === 'E') ? 1 : -1;

        return $hem * ($deg + $min + $sec);
    }

    final public function convertRationaltoFloat(string $param): float
    {
        // rational64u
        $nums = explode('/', $param);
        if ((int)$nums[1] > 0) {
            return (float)$nums[0] / (int)$nums[1];
        }

        return (float)$nums[0];
    }

    /**
     * Deletes the page from the index.
     *
     * @param string $id document ID
     */
    final public function deleteFromIndex(string $id): void
    {
        // check the index for document
        $knownHashes = $this->findHashesForId($id, $this->spatial_idx);
        if ($knownHashes === []) {
            return;
        }

        // TODO shortcut, need to make sure there is only one element, if not the index is corrupt
        $knownHash = $knownHashes [0];
        $knownIds = $this->spatial_idx [$knownHash];
        $i = array_search($id, $knownIds);
        Logger::debug("removing: $knownIds[$i] from the index.");
        unset($knownIds [$i]);
        $this->spatial_idx [$knownHash] = $knownIds;
        if (empty($this->spatial_idx [$knownHash])) {
            unset($this->spatial_idx [$knownHash]);
        }
        $this->saveIndex();
    }
}
