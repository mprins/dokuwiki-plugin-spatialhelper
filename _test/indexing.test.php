<?php
/*
 * Copyright (c) 2024 Mark C. Prins <mprins@users.sf.net>
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
 * Tests for the spatialhelper plugin.
 *
 * @group plugin_spatialhelper
 * @group plugins
 *
 * @noinspection AutoloadingIssuesInspection
 * @phpcs:disable Squiz.Classes.ValidClassName.NotCamelCaps
 */
class indexing_test extends DokuWikiTest
{
    /**
     * copy data and add pages to the index.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        TestUtils::rcopy(TMP_DIR, __DIR__ . '/data/');
    }

    final public function setUp(): void
    {
        $this->pluginsEnabled = array(
            'geophp',
            'geotag',
            'spatialhelper'
        );

        global $conf;
        $conf['allowdebug'] = 1;
        $conf['dontlog'] = [];
        $conf['cachetime'] = -1;

        parent::setUp();

        $indexer = plugin_load('helper', 'spatialhelper_index');
        self::assertInstanceOf('helper_plugin_spatialhelper_index', $indexer);

        $data = [];
        search($data, $conf['datadir'], 'search_allpages', array('skipacl' => true));

        foreach ($data as $val) {
            idx_addPage($val['id']);
            $indexer->updateSpatialIndex($val['id']);
        }
    }

    /**
     * @throws Exception if anything goes wrong
     */
    final public function testIndexed(): void
    {
        // render the page
        $request = new TestRequest();
        $response = $request->get(array('id' => 'geotag'));

        // test metadata
        self::assertEquals(
            '52.132633;5.291266;9',
            $response->queryHTML('meta[name="geo.position"]')->attr('content')
        );
        self::assertEquals(
            '52.132633, 5.291266',
            $response->queryHTML('meta[name="ICBM"]')->attr('content')
        );

        // test the geohash and index values
        self::assertStringStartsWith(
            'u17b86kyx7jv',
            $response->queryHTML('meta[name="geo.geohash"]')->attr('content')
        );
    }


    final public function testIndexFileExists(): void
    {
        self::assertFileExists(TMP_DIR . '/data/index/spatial.idx');
    }

    final public function testIndexFileNotEmpty(): void
    {
        self::assertGreaterThan(0, filesize(TMP_DIR . '/data/index/spatial.idx'));
    }

    final public function testSearchNearby(): void
    {
        $search = plugin_load('helper', 'spatialhelper_search');
        self::assertInstanceOf('helper_plugin_spatialhelper_search', $search);

        $result = $search->findNearby('u17b86kyx7');
        self::assertIsArray($result);
        self::assertNotEmpty($result);
        self::assertEmpty($result['media']);
        self::assertEquals('geotag', $result['pages'][0]['id']);
        self::assertEquals('u17b86kyx7', $result['geohash']);
        self::assertEquals(0.6, $result['precision']);
        self::assertEqualsWithDelta(52.1326, $result['lat'], 0.001);
        self::assertEqualsWithDelta(5.2912, $result['lon'], 0.001);
    }
}
