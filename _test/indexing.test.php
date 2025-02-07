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

    protected $pluginsEnabled = array('geotag', 'geophp', 'spatialhelper');

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
        parent::setUp();

        global $conf;
        $conf['allowdebug'] = 1;
        $conf['dontlog'] = [];
        $conf['cachetime'] = -1;

        idx_addPage('geotag', true, true);
    }

    /**
     * @throws Exception if anything goes wrong
     */
    final public function testIndexed(): void
    {
        $indexer = plugin_load('helper', 'spatialhelper_index');
        $this->assertInstanceOf('helper_plugin_spatialhelper_index', $indexer);
        $this->assertTrue($indexer->updateSpatialIndex('geotag'));

        // render the page
        $request = new TestRequest();
        $response = $request->get(array('id' => 'geotag'), '/doku.php');

        // test metadata
        $this->assertEquals(
            '52.132633;5.291266;9',
            $response->queryHTML('meta[name="geo.position"]')->attr('content')
        );
        $this->assertEquals(
            '52.132633, 5.291266',
            $response->queryHTML('meta[name="ICBM"]')->attr('content')
        );

        // TODO / WIP test the geohash and index values
        $this->assertStringStartsWith(
        // u17b86kyx7j
            'u17b86k',
            $response->queryHTML('meta[name="geo.hash"]')->attr('content')
        );
    }
}
