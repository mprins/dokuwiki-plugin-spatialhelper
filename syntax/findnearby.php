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
 * DokuWiki Plugin dokuwikispatial (findnearby Syntax Component).
 *
 * @license BSD license
 * @author  Mark C. Prins <mprins@users.sf.net>
 */
class syntax_plugin_spatialhelper_findnearby extends DokuWiki_Syntax_Plugin {
    /**
     *
     * @see DokuWiki_Syntax_Plugin::getType()
     */
    public function getType(): string {
        return 'substition';
    }

    /**
     * Return 'normal' so this syntax can be rendered inline.
     *
     * @see DokuWiki_Syntax_Plugin::getPType()
     */
    public function getPType(): string {
        return 'normal';
    }

    /**
     *
     * @see Doku_Parser_Mode::getSort()
     */
    public function getSort(): int {
        return 307;
    }

    /**
     * define our special pattern: {{findnearby>Some linkt text or nothing}}.
     *
     * @see Doku_Parser_Mode::connectTo()
     */
    public function connectTo($mode): void {
        $this->Lexer->addSpecialPattern('\{\{findnearby>.*?\}\}', $mode, 'plugin_spatialhelper_findnearby');
    }

    /**
     * look up the page's geo metadata and pass that on to render.
     *
     * @param string       $match   The text matched by the patterns
     * @param int          $state   The lexer state for the match
     * @param int          $pos     The character position of the matched text
     * @param Doku_Handler $handler The Doku_Handler object
     * @return  bool|array Return an array with all data you want to use in render, false don't add an instruction
     *
     * @see DokuWiki_Syntax_Plugin::handle()
     */
    public function handle($match, $state, $pos, Doku_Handler $handler) {
        $data     = array();
        $data [0] = trim(substr($match, strlen('{{findnearby>'), -2));
        if(strlen($data [0]) < 1) {
            $data [0] = $this->getLang('search_findnearby');
        }
        $meta = p_get_metadata(getID(), 'geo');
        if($meta) {
            if($meta ['lat'] && $meta ['lon']) {
                $data [1] = array(
                    'do'  => 'findnearby',
                    'lat' => $meta ['lat'],
                    'lon' => $meta ['lon']
                );
            } elseif($meta ['geohash']) {
                $data [1] = array(
                    'do'      => 'findnearby',
                    'geohash' => $meta ['geohash']
                );
            }
            return $data;
        }
        return false;
    }

    /**
     * Render a link to a search page.
     *
     * @see DokuWiki_Syntax_Plugin::render()
     */
    public function render($format, Doku_Renderer $renderer, $data): bool {
        if($data === false) {
            return false;
        }

        if($format === 'xhtml') {
            $renderer->doc .= '<a href="' . wl(getID(), $data [1]) . '" class="findnearby">' . hsc($data [0]) . '</a>';
            return true;
        } elseif($format === 'metadata') {
            return false;
        } elseif($format === 'odt') {
            // don't render anything in ODT
            return false;
        }
        return false;
    }
}
