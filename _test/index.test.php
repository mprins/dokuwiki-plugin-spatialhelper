<?php

namespace dokuwiki\plugin\spatialhelper\test;

use DokuWikiTest;
use TestUtils;

/**
 * Tests for the class helper_plugin_spatialhelper_index of the spatialhelper plugin.
 *
 * @group plugin_spatialhelper
 * @group plugins
 */
class index_test extends DokuWikiTest
{

    protected $pluginsEnabled = array('spatialhelper');

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

    /**
     * Test data provider.
     * @return array
     * @see index_test::test_convertDMStoD
     *
     * @see index_test::test_convertDMStoD
     */
    final public static function convertDMStoDTestdata(): array
    {
        return array(
            array(
                array(0 => '52/1', 1 => '31/1', 2 => '2/1', 3 => 'N',),
                52.5172,
                'Latitude in Europe'
            ),
            array(
                array(0 => '13/1', 1 => '30/1', 2 => '38/1', 3 => 'E',),
                13.5105,
                'Longitude in Europe'
            ),
            array(
                array(0 => '50/1', 1 => '34251480/1000000', 2 => '0/1', 3 => 'N',),
                50.5708,
                'Latitude in North America'
            ),
            array(
                array(0 => '109/1', 1 => '28041300/1000000', 2 => '0/1', 3 => 'W',),
                -109.4673,
                'Longitude in North America'
            ),
        );
    }

    final public function setUp(): void
    {
        parent::setUp();

        global $conf;
        $conf['allowdebug'] = 1;
        $conf['cachetime'] = -1;
    }

    /**
     * @dataProvider convertDMStoDTestdata
     */
    final public function test_convertDMStoD(array $input, float $expected_output, string $msg): void
    {
        $index = plugin_load('helper', 'spatialhelper_index');
        self::assertInstanceOf('helper_plugin_spatialhelper_index', $index);

        $actual_output = $index->convertDMStoD($input);

        self::assertEqualsWithDelta($expected_output, $actual_output, 0.0001, $msg);
    }

    final public function test_ImageWithoutGeotag(): void
    {
        $index = plugin_load('helper', 'spatialhelper_index');
        self::assertInstanceOf('helper_plugin_spatialhelper_index', $index);

        $actual_output = $index->getCoordsFromExif(':vesder_eupen_no_gps.jpg');
        self::assertFalse($actual_output, 'Expected no geotag to be found');
    }

    final public function test_ImageWithGeotag(): void
    {
        $index = plugin_load('helper', 'spatialhelper_index');
        self::assertInstanceOf('helper_plugin_spatialhelper_index', $index);

        // lat/lon: 37°4'36.12",31°39'21.96" or x/y: 31.6561,37.0767
        $actual_output = $index->getCoordsFromExif(':manavgat_restaurant_handost_with_gps.jpg');

        self::assertNotNull($actual_output, 'Expected a geotag to be found');
        self::assertNotFalse($actual_output, 'Expected a geotag to be found');
        self::assertEqualsWithDelta(31.6561, $actual_output->x(), 0.0001);
        self::assertEqualsWithDelta(37.0767, $actual_output->y(), 0.0001);
    }
}
