<?php
/*
 * Copyright (c) 2016 Mark C. Prins <mprins@users.sf.net>
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
 * General tests for the spatialhelper plugin.
 *
 * @group plugin_spatialhelper
 * @group plugins
 */
class general_plugin_spatialhelper_test extends DokuWikiTest {

    protected $pluginsEnabled = array('spatialhelper');

    /**
     * Simple test to make sure the plugin.info.txt is in correct format.
     */
    public function test_plugininfo(): void {
        $file = __DIR__ . '/../plugin.info.txt';
        self::assertFileExists($file);

        $info = confToHash($file);

        self::assertArrayHasKey('base', $info);
        self::assertArrayHasKey('author', $info);
        self::assertArrayHasKey('email', $info);
        self::assertArrayHasKey('date', $info);
        self::assertArrayHasKey('name', $info);
        self::assertArrayHasKey('desc', $info);
        self::assertArrayHasKey('url', $info);

        self::assertEquals('spatialhelper', $info['base']);
        self::assertRegExp('/^https?:\/\//', $info['url']);
        self::assertTrue(mail_isvalid($info['email']));
        self::assertRegExp('/^\d\d\d\d-\d\d-\d\d$/', $info['date']);
        self::assertTrue(false !== strtotime($info['date']));
    }

    /**
     * test if plugin is loaded.
     */
    public function test_plugin_spatialhelper_isloaded(): void {
        global $plugin_controller;
        self::assertContains(
            'spatialhelper', $plugin_controller->getList(), "spatialhelper plugin is loaded"
        );
    }
}
