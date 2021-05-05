<?php

namespace dokuwiki\plugin\spatialhelper\test;

use DokuWikiTest;
use helper_plugin_spatialhelper_index;

/**
 * Tests for the class helper_plugin_spatialhelper_index of the spatialhelper plugin
 *
 * @group plugin_spatialhelper
 * @group plugins
 */
class index_test extends DokuWikiTest {

    protected $pluginsEnabled = array('spatialhelper');

    /**
     * Testdata for @return array
     * @see index_test::test_convertDMStoD
     *
     */
    public static function convertDMStoDTestdata(): array {
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

    /**
     * @dataProvider convertDMStoDTestdata
     */
    public function test_convertDMStoD(array $input, float $expected_output, string $msg): void {
        $index = plugin_load('helper', 'spatialhelper_index');
        assert($index instanceof helper_plugin_spatialhelper_index);

        $actual_output = $index->convertDMStoD($input);

        self::assertEqualsWithDelta($expected_output, $actual_output, 0.0001, $msg);
    }
}
