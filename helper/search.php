<?php

/*
 * Copyright (c) 2011-2023 Mark C. Prins <mprins@users.sf.net>
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
use geoPHP\Adapter\GeoHash;
use geoPHP\Geometry\LineString;
use geoPHP\Geometry\Point;

/**
 * DokuWiki Plugin spatialhelper (Search component).
 *
 * @license BSD license
 * @author  Mark Prins
 */
class helper_plugin_spatialhelper_search extends Plugin
{
    /**
     * spatial index.
     *
     * @var array
     */
    protected $spatial_idx = [];
    /**
     * Precision, Distance of Adjacent Cell in Meters.
     *
     * @see https://stackoverflow.com/questions/13836416/geohash-and-max-distance
     *
     * @var float
     */
    private $precision = [5_003_530, 625441, 123264, 19545, 3803, 610, 118, 19, 3.7, 0.6];

    /**
     * constructor; initialize/load spatial index.
     */
    public function __construct()
    {
        global $conf;

        if (plugin_load('helper', 'geophp', false, true) === null) {
            $message =
                'helper_plugin_spatialhelper_search::spatialhelper_search: required geophp plugin is not available.';
            msg($message, -1);
        }

        $idx_dir = $conf ['indexdir'];
        if (!@file_exists($idx_dir . '/spatial.idx')) {
            plugin_load('helper', 'spatialhelper_index');
        }

        $this->spatial_idx = unserialize(io_readFile($idx_dir . '/spatial.idx', false), ['allowed_classes' => false]);
    }

    /**
     * Find locations based on the coordinate pair.
     *
     * @param float $lat
     *          The y coordinate (or latitude)
     * @param float $lon
     *          The x coordinate (or longitude)
     * @throws Exception
     */
    final public function findNearbyLatLon(float $lat, float $lon): array
    {
        $geometry = new Point($lon, $lat);
        return $this->findNearby($geometry->out('geohash'), $geometry);
    }

    /**
     * finds nearby elements in the index based on the geohash.
     * returns a list of documents and the bounding box.
     *
     * @param string $geohash
     * @param Point|null $p
     *          optional point
     * @return array of ...
     * @throws Exception
     */
    final public function findNearby(string $geohash, Point $p = null): array
    {
        $_geohashClass = new Geohash();
        if (!$p instanceof Point) {
            $decodedPoint = $_geohashClass->read($geohash);
        } else {
            $decodedPoint = $p;
        }

        // find adjacent blocks
        $adjacent = [];
        $adjacent ['center'] = $geohash;
        $adjacent ['top'] = Geohash::adjacent($adjacent ['center'], 'top');
        $adjacent ['bottom'] = Geohash::adjacent($adjacent ['center'], 'bottom');
        $adjacent ['right'] = Geohash::adjacent($adjacent ['center'], 'right');
        $adjacent ['left'] = Geohash::adjacent($adjacent ['center'], 'left');
        $adjacent ['topleft'] = Geohash::adjacent($adjacent ['left'], 'top');
        $adjacent ['topright'] = Geohash::adjacent($adjacent ['right'], 'top');
        $adjacent ['bottomright'] = Geohash::adjacent($adjacent ['right'], 'bottom');
        $adjacent ['bottomleft'] = Geohash::adjacent($adjacent ['left'], 'bottom');
        Logger::debug("adjacent geo hashes", $adjacent);

        // find all the pages in the index that overlap with the adjacent hashes
        $docIds = [];
        foreach ($adjacent as $adjHash) {
            if (is_array($this->spatial_idx)) {
                foreach ($this->spatial_idx as $_geohash => $_docIds) {
                    if (strpos($_geohash, (string)$adjHash) !== false) {
                        // if $adjHash similar to geohash
                        $docIds = array_merge($docIds, $_docIds);
                    }
                }
            }
        }
        $docIds = array_unique($docIds);
        Logger::debug("found docIDs", $docIds);

        // create associative array of pages + calculate distance
        $pages = [];
        $media = [];
        $indexer = plugin_load('helper', 'spatialhelper_index');

        foreach ($docIds as $id) {
            if (strpos($id, 'media__') === 0) {
                $id = substr($id, strlen('media__'));
                if (auth_quickaclcheck($id) >= /*AUTH_READ*/ 1) {
                    $point = $indexer->getCoordsFromExif($id);
                    $line = new LineString(
                        [
                            $decodedPoint,
                            $point
                        ]
                    );
                    $media [] = ['id' => $id, 'distance' => (int)($line->greatCircleLength()),
                        'lat' => $point->y(), 'lon' => $point->x()];
                }
            } elseif (auth_quickaclcheck($id) >= /*AUTH_READ*/ 1) {
                $geotags = p_get_metadata($id, 'geo');
                $point = new Point($geotags ['lon'], $geotags ['lat']);
                $line = new LineString(
                    [
                        $decodedPoint,
                        $point
                    ]
                );
                $pages [] = ['id' => $id, 'distance' => (int)($line->greatCircleLength()),
                    'description' => p_get_metadata($id, 'description')['abstract'],
                    'lat' => $geotags ['lat'], 'lon' => $geotags ['lon']];
            }
        }

        // sort all the pages/media using distance
        usort(
            $pages,
            static fn($a, $b) => strnatcmp($a ['distance'], $b ['distance'])
        );
        usort(
            $media,
            static fn($a, $b) => strnatcmp($a ['distance'], $b ['distance'])
        );

        if (strlen($geohash) < 10) {
            $precision = $this->precision[strlen($geohash)];
        } else {
            $precision = $this->precision[9];
        }
        return [
            'pages' => $pages,
            'media' => $media,
            'lat' => $decodedPoint->y(),
            'lon' => $decodedPoint->x(),
            'geohash' => $geohash,
            'precision' => $precision
        ];
    }
}
