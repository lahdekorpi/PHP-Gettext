<?php

namespace Ashrey\Gettext;

/*
 * Copyright (c) 2014 David Soria Parra & Alberto Berroteran
 *
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */
use SplFileInfo;

/**
 * Gettext implementation in PHP.
 *
 * @copyright (c) 2009 David Soria Parra <sn_@gmx.net>
 * @author David Soria Parra <sn_@gmx.net>
 */
class PHP extends Base
{
    /**
     * First magic word in the MO header.
     */
    const MAGIC1 = 0xde120495;

    /**
     * First magic word in the MO header.
     */
    const MAGIC2 = 0x950412de;

    protected $dir;
    protected $domain;
    protected $locale;
    protected $translationTable = array();
    protected $parsed = array();

    /**
     * Initialize a new gettext class.
     *
     * @param string $mofile The file to parse
     */
    public function __construct($directory, $domain, $locale)
    {
        $this->dir = $directory;
        $this->domain = $domain;
        $this->locale = $locale;
    }

    /**
     * Parse the MO file header and returns the table
     * offsets as described in the file header.
     *
     * If an exception occured, null is returned. This is intentionally
     * as we need to get close to ext/gettexts beahvior.
     *
     * @param resource $fp The open file handler to the MO file
     *
     * @return An array of offset
     */
    private function parseHeader($fp)
    {
        $data = fread($fp, 8);
        $header = unpack('lmagic/lrevision', $data);

        if ((int) self::MAGIC1 != $header['magic']
           && (int) self::MAGIC2 != $header['magic']) {
            return;
        }

        if (0 != $header['revision']) {
            return;
        }

        $data = fread($fp, 4 * 5);
        $offsets = unpack('lnum_strings/lorig_offset/'
                          .'ltrans_offset/lhash_size/lhash_offset', $data);

        return $offsets;
    }

    /**
     * Parse and reutnrs the string offsets in a a table. Two table can be found in
     * a mo file. The table with the translations and the table with the original
     * strings. Both contain offsets to the strings in the file.
     *
     * If an exception occured, null is returned. This is intentionally
     * as we need to get close to ext/gettexts beahvior.
     *
     * @param resource $fp     The open file handler to the MO file
     * @param int      $offset The offset to the table that should be parsed
     * @param int      $num    The number of strings to parse
     *
     * @return array of offsets
     */
    private function parseOffsetTable($fp, $offset, $num)
    {
        if (fseek($fp, $offset, SEEK_SET) < 0) {
            return array();
        }

        $table = array();
        for ($i = 0; $i < $num; ++$i) {
            $data = fread($fp, 8);
            $table[] = unpack('lsize/loffset', $data);
        }

        return $table;
    }

    /**
     * Parse a string as referenced by an table. Returns an
     * array with the actual string.
     *
     * @param resource $fp    The open file handler to the MO fie
     * @param array    $entry The entry as parsed by parseOffsetTable()
     *
     * @return Parsed string
     */
    private function parseEntry($fp, $entry)
    {
        if (fseek($fp, $entry['offset'], SEEK_SET) < 0) {
            return;
        }
        if ($entry['size'] > 0) {
            return fread($fp, $entry['size']);
        }

        return '';
    }

    /**
     * Parse the MO file.
     */
    private function parse($locale, $domain)
    {
        $this->translationTable[$locale][$domain] = array();
        $mofile = new SplFileInfo(sprintf('%s/%s/LC_MESSAGES/%s.mo', $this->dir, $locale, $domain));
        $cachefile = new SplFileInfo(sprintf('%s/%s/LC_MESSAGES/%s.ser', $this->dir, $locale, $domain));
        if (!$mofile->isFile() || $mofile->getSize() < 4 * 7) {
            //nothing
        } elseif (static::generate($mofile, $cachefile)) {
            $this->generateFile($mofile, $cachefile, $locale, $domain);
        } else {
            $tmp = file_get_contents($cachefile->getRealPath());
            $this->translationTable[$locale][$domain] = unserialize($tmp);
        }
        $this->parsed[$locale][$domain] = true;
    }

    /**
     * Return if cache is usable.
     *
     * @param SpleFileInfo $file  .mo file
     * @param SpleFileInfo $cache serialize file
     *
     * @return bool
     */
    public static function generate(SplFileInfo $file, SplFileInfo $cache)
    {
        return !$cache->isReadable() || $cache->getMTime() < $file->getMTime();
    }

    /**
     * generate a file to cache.
     *
     * @param SpleFileInfo $file   .mo file
     * @param SpleFileInfo $cache  serialize file
     * @param string       $locale locale
     * @param string       $domain domain
     */
    protected function generateFile(SplFileInfo $file, SplFileInfo $cache, $locale, $domain)
    {
        /* check for filesize */
        $fp = fopen($file->getRealPath(), 'rb');
        $offsets = $this->parseHeader($fp);
        if (is_null($offsets) || $file->getSize() < 4 * ($offsets['num_strings'] + 7)) {
            fclose($fp);

            return;
        }
        $table = $this->parseOffsetTable($fp, $offsets['trans_offset'], $offsets['num_strings']);
        if (is_null($table)) {
            fclose($fp);

            return;
        }
        $this->generateTables($fp, $locale, $domain, $table, $offsets);
        file_put_contents($cache->getPathName(), serialize($this->translationTable[$locale][$domain]));
        fclose($fp);
    }

    /**
     * Generate the tables.
     *
     * @param resource $fp
     * @param string   $locale
     * @param string   $domain
     * @param array    $table
     * @param array    $offset
     */
    protected function generateTables($fp, $locale, $domain, array $table, array $offsets)
    {
        $transTable = array();
        foreach ($table as $idx => $entry) {
            $transTable[$idx] = $this->parseEntry($fp, $entry);
        }

        $table = $this->parseOffsetTable($fp, $offsets['orig_offset'],
                    $offsets['num_strings']);
        foreach ($table as $idx => $entry) {
            $entry = $this->parseEntry($fp, $entry);
            $formes = explode(chr(0), $entry);
            $translation = explode(chr(0), $transTable[$idx]);
            foreach ($formes as $form) {
                $this->translationTable[$locale][$domain][$form] = $translation;
            }
        }
    }

    /**
     * Return a translated string.
     *
     * If the translation is not found, the original passed message
     * will be returned.
     *
     * @return Translated message
     */
    public function gettext($msg)
    {
        $this->parseIf();
        if (array_key_exists($msg, $this->translationTable[$this->locale][$this->domain])) {
            return $this->translationTable[$this->locale][$this->domain][$msg][0];
        }

        return $msg;
    }

    /**
     * Parse a file if are no parsed.
     */
    protected function parseIf()
    {
        if (empty($this->parsed[$this->locale][$this->domain])) {
            $this->parse($this->locale, $this->domain);
        }
    }

    /**
     * Overrides the domain for a single lookup.
     *
     * If the translation is not found, the original passed message
     * will be returned.
     *
     * @param string $domain The domain to search in
     * @param string $msg    The message to search for
     *
     * @return Translated string
     */
    public function dgettext($domain, $msg)
    {
        if (!@$this->parsed[$this->locale][$domain]) {
            $this->parse($this->locale, $domain);
        }

        if (array_key_exists($msg, $this->translationTable[$this->locale][$domain])) {
            return $this->translationTable[$this->locale][$domain][$msg][0];
        }

        return $msg;
    }

    /**
     * Return a translated string in it's plural form.
     *
     * Returns the given $count (e.g second, third,...) plural form of the
     * given string. If the id is not found and $num == 1 $msg is returned,
     * otherwise $msg_plural
     *
     * @param string $msg        The message to search for
     * @param string $msg_plural A fallback plural form
     * @param int    $count      Which plural form
     *
     * @return Translated string
     */
    public function ngettext($msg, $msg_plural, $count)
    {
        $this->parseIf();
        $msg = (string) $msg;

        if (array_key_exists($msg, $this->translationTable[$this->locale][$this->domain])) {
            $translation = $this->translationTable[$this->locale][$this->domain][$msg];
            /* the gettext api expect an unsigned int, so we just fake 'cast' */
            if ($count <= 0 || count($translation) < $count) {
                $count = count($translation);
            }

            return $translation[$count - 1];
        }

        /* not found, handle count */
        if (1 == $count) {
            return $msg;
        } else {
            return $msg_plural;
        }
    }

    /**
     * Override the current domain for a single plural message lookup.
     *
     * Returns the given $count (e.g second, third,...) plural form of the
     * given string. If the id is not found and $num == 1 $msg is returned,
     * otherwise $msg_plural
     *
     * @param string $domain     The domain to search in
     * @param string $msg        The message to search for
     * @param string $msg_plural A fallback plural form
     * @param int    $count      Which plural form
     *
     * @return Translated string
     */
    public function dngettext($domain, $msg, $msg_plural, $count)
    {
        if (!@$this->parsed[$this->locale][$domain]) {
            $this->parse($this->locale, $domain);
        }

        $msg = (string) $msg;

        if (array_key_exists($msg, $this->translationTable[$this->locale][$domain])) {
            $translation = $this->translationTable[$this->locale][$domain][$msg];
            /* the gettext api expect an unsigned int, so we just fake 'cast' */
            if ($count <= 0 || count($translation) < $count) {
                $count = count($translation);
            }

            return $translation[$count - 1];
        }

        /* not found, handle count */
        if (1 == $count) {
            return $msg;
        } else {
            return $msg_plural;
        }
    }
}
