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
 */
class indexing_test extends DokuWikiTest
{

    protected $pluginsEnabled = array('geotag', 'spatialhelper');

    /**
     * copy data and add pages to the index.
     */
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        global $conf;
        $conf['allowdebug'] = 1;

        TestUtils::rcopy(TMP_DIR, __DIR__ . '/data/');
    }

    final public function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf['allowdebug'] = 1;
        $conf['cachetime'] = -1;

        $data = array();
        search($data, $conf['datadir'], 'search_allpages', array('skipacl' => true));

        $verbose = false;
        $force = false;
        foreach ($data as $val) {
            idx_addPage($val['id'], $verbose, $force);
        }
    }

    final public function test_indexed(): void
    {
        // render the page
        $request = new TestRequest();
        $response = $request->get(array('id' => 'geotag'), '/doku.php');

        $this->assertEquals('52.132633;5.291266;9', $response->queryHTML('meta[name="geo.position"]')->attr('content'));
        // TODO test the geohash and index values
    }
}