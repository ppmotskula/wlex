<?php

/**
 * ActList
 *
 * Represents the database of known acts
 */
class ActList
{
    /**
     * array $acts
     *
     * Array containing the list of acts.
     */
    public $acts = array();


    /**
     * void __construct([bool $autoload = FALSE])
     */
    public function __construct($autoload = FALSE)
    {
        if ($autoload) {
            $this->loadLocal();
        }
    }


    /**
     * bool loadLocal()
     *
     * Loads the list of acts from local file $ACTS_DB.
     * Returns TRUE if successful, FALSE if not.
     */
    public function loadLocal()
    {
        global $ACTS_DB;
        if (! $lines = @file($ACTS_DB)) {
            // couldn't open, bail out
            return FALSE;
        }

        foreach ($lines as $line) {
            if (trim($line) > '') {
                $act = new ActInfo($line);
                $this->acts[$act->id] = $act;
                unset($act);
            }
        }

        return TRUE;

    }


    /**
     * bool saveLocal()
     *
     * Saves the list of acts to local file $ACTS_DB.
     * Returns TRUE if successful, FALSE if not.
     */
    public function saveLocal()
    {
        $lines = '';

        foreach ($this->acts as $act) {
            $lines .= $act->asString() . "\n";
        }

        global $ACTS_DB;
        return @file_put_contents($ACTS_DB, $lines);

    }


    /**
     * bool loadRemote(string $url = NULL[, string $as_of = ''])
     *
     * Populates $this->acts with all the results on the eRT search
     * page specified by $url, and any subsequent pages if any.
     *
     * Returns TRUE if successful, FALSE if not.
     *
     * searchRes does not currently check that $url refers to the
     * _first_ page of search results.
     */
    public function loadRemote($url = NULL, $as_of = '')
    {
        if (! $url) {
            return FALSE;
        }

        // load abbreviations into an array from file if file found
        global $ABBR_DB;
        if ($file = @file($ABBR_DB)) {

            foreach ($file as $line) {
                $pair = explode("\t", rtrim($line));
                $abbr[mb_convert_case($pair[0], MB_CASE_LOWER, 'UTF-8')] =
                    $pair[1];
            }

            unset($file);
        }

        unset($this->acts);

        do { // process search results page by page

            $page = new WebPage($url);

            // check if there are any more pages to process
            if (preg_match(
                    '#>[0-9]+ - ([0-9]+) / ([0-9]+)<#',
                    $page->body(),
                    $matches)) {
                $more_pages = ($matches[1] != $matches[2]);
            } else {
                $more_pages = FALSE;
            }

            // clean up results page
            $tmp = str_replace(
                    '<tr align="left" valign="top" bgcolor="#FFFFFF" style="padding-top: 2px;padding-bottom: 2px">',
                    '#WLEX#',
                    $page->body())
            ;
            if (! strpos($tmp, '#WLEX#')) {
                // no search results were found, bail out
                return FALSE;
            }
            // continue cleanup
            $tmp = substr($tmp, strpos($tmp, '#WLEX#'));
            $tmp = preg_replace('#(?:<(?!/tr).*?>)+#is', "\t", $tmp);
            $tmp = substr($tmp, 0, strpos($tmp, "\t</tr>\t</tr>"));
            $tmp = str_replace("\r", " ", $tmp);
            $tmp = str_replace("\n", " ", $tmp);
            $tmp = str_replace("#WLEX#\t", "\n", $tmp);
            $tmp = str_replace("\t</tr>", '', $tmp);
            $tmp = str_replace('&nbsp;&nbsp;', "\t", $tmp);
            $tmp = str_replace('&nbsp;', '', $tmp);
            $tmp = str_replace('...', '', $tmp);
            $tmp = str_replace('  ', ' ', $tmp);
            $tmp = trim($tmp);

            // push results into $acts
            foreach (explode("\n", $tmp) as $line) {

                $items = explode("\t", html_entity_decode(trim($line)));

                // remove trailing '¹' from the end of some act titles
                $items[3] = rtrim($items[3], " \t\n\r\0\x0b¹");

                $this->acts[trim($items[0])] = new ActInfo(
                    trim($items[0]), // id
                    trim($items[1]), // issuer
                    trim($items[2]), // type
                    trim($items[3]), // title
                    trim($items[4]), // nr
                    trim($items[5]), // valid
                    trim($items[6]), // until
                    $abbr[mb_convert_case(
                            trim($items[3]), MB_CASE_LOWER, 'UTF-8')], // abbr
                    $as_of // as_of
                );
            }

            // if there are any more pages, add or increment &numberLink
            if ($more_pages) {

                if (! strpos($url, '&numberLink=')) {
                    $url .= '&numberLink=2';
                } else {
                    $url = preg_replace(
                            '#&numberLink=([0-9]+)#e',
                            "'&numberLink=' . ($1 + 1)",
                            $url);
                }

            }

        } while ($more_pages);

        return TRUE;

    }


    /**
     * bool doSearch(string $what[, string $date = ''[, bool $fulltext = FALSE]])
     *
     * Searches for $what as of $date (or today if no $date given).
     * If $fulltext is TRUE, searches entire texts, otherwise titles.
     * Calls loadRemote with the resulting URL.
     */
    public function doSearch($what, $date = '', $fulltext = FALSE)
    {

        // check arguments
        if ($what == '') {
            // this should never happen
            return FALSE;
        } elseif ($date == '') {
            // no date given, use today's date
            $when = datetodmy(time());
        } else {
            // check given date
            if (dmytodate($date)) {
                $when = $date;
            } else {
                // invalid date given, bail out
                return FALSE;
            }
        }

        // explode date
        $tmp = explode('.', $when);
        $date_d = $tmp[0];
        $date_m = $tmp[1];
        $date_y = $tmp[2];

        // create search URL
        global $ERT_HOME;
        $url =
            "$ERT_HOME/ert.jsp?link=searchRes&date_day=$date_d&date_month=$date_m&date_year=$date_y&"
            . ($fulltext ? "text" : "title")
            . "="
            . $what
        ;
        return $this->loadRemote($url, $date);
    }


    /**
     * ActInfo getActById(string $id)
     *
     * Returns an ActInfo with id $id, or FALSE if unsuccessful.
     */
    public function getActById($id)
    {
        foreach ($this->acts as $act) {
            if ($act->id == $id) {
                return $act;
            }
        }

        return FALSE;

    }


    /**
     * ActInfo getActByAbbr(string $abbr)
     *
     * Returns an ActInfo with abbr $abbr, or FALSE if unsuccessful.
     */
    public function getActByAbbr($abbr)
    {
        foreach ($this->acts as $act) {
            if ($act->abbr == $abbr) {
                return $act;
            }
        }

        return FALSE;

    }


    /**
     * ActInfo getActByTitle(string $title)
     *
     * Returns an ActInfo with title $title, or FALSE if unsuccessful.
     */
    public function getActByTitle($title)
    {
        $title = urlencode(mb_convert_case($title, MB_CASE_LOWER, 'UTF-8'));

        foreach ($this->acts as $act) {
            if (urlencode(
                mb_convert_case($act->title, MB_CASE_LOWER, 'UTF-8')
            ) == $title) {
                return $act;
            }
        }

        return FALSE;

    }


    /**
     * string printOut(int $limit = 0)
     *
     * Returns a printout of the acts list, limiting it to first
     * $limit acts (0 means no limit)
     */
    public function printOut($limit = 0)
    {
        if ($limit == 0) {
            $limit = count($this->acts);
        }
        $line = 0;
        $res = array();

        if (count($this->acts)) {
            // at least one act found
            foreach ($this->acts as $act) {
                if ($limit > $line++) {
                    $res[] = $act->longLink();
                }
            }
        } else {
            // nothing found
            global $HOME;
            $res[] = "Akte ei leitud (<a href=\"$HOME/man\">miks?</a>).";
        }

        $ret = "<ul>\n";
        foreach ($res as $ref) {
            $ret .= "<li>$ref</li>\n";
        }
        $ret .= "</ul>\n";

        return $ret;
    }


    /**
     * string printNew(int $limit = 0)
     *
     * Returns a printout of new (recently added/updated) acts,
     * limiting it to first $limit acts (0 means no limit).
     */
    public function printNew($limit = 0)
    {
        foreach ($this->acts as $key => $row) {
            $act_valid[$key] = datetoymd(dmytodate($row->valid));
            $act_title[$key] = $row->title;
        }
        array_multisort($act_valid, SORT_DESC, $act_title, SORT_ASC, $this->acts);

        return $this->printOut($limit);

    }


    /**
     * string printUpcoming(int $limit = 0)
     *
     * Returns a printout of old (soon to be changed) acts,
     * limiting it to first $limit lines (0 means no limit).
     */
    public function printUpcoming($limit = 0)
    {
        foreach ($this->acts as $key => $row) {
            $act_until[$key] = (
                $row->until > ''
                ? datetoymd(dmytodate($row->until))
                : 'x'
            );
            $act_title[$key] = $row->title;
        }
        array_multisort($act_until, SORT_ASC, $act_title, SORT_ASC, $this->acts);

        // remove acts that do not have an upcoming version
        while ($this->acts[count($this->acts) - 1]->until == '') {
            array_pop($this->acts);
        }

        // add 'vaata - võrdle'
        $list = $this->printOut($limit);
        global $DATE, $HOME;
        $today = datetodmy(time());

        $lines = explode("\n", $list);
        foreach ($lines as &$line) {
            if (preg_match("#<a href=\"$HOME/(.*?)\".*?- ($DATE)\).*#", $line, $m) == 1) {
                $actref = $m[1];
                $olddate = $m[2];
                $newdate = datetodmy(dmytodate($olddate) + 86400);
                $line = preg_replace(
                        '#</li>#',
                        " -- <a href=\"$HOME/$actref/$newdate/\">vaata</a>" .
                        " - <a href=\"$HOME/$actref/$newdate/$today/\">võrdle</a>" .
                        '</li>', $line);
            }
        }
        $list = implode("\n", $lines);

        return $list;

    }


    /**
     * string printSort(int $limit = 0)
     *
     * Returns a printout of acts sorted by type (laws first) and name,
     * limiting it to first $limit lines (0 means no limit).
     */
    public function printSort($limit = 0)
    {
        if (count($this->acts) > 0) {
            foreach ($this->acts as $key => $row) {
                $act_type[$key] = ($row->type == 'seadus');
                $act_title[$key] = $row->title;
            }
            array_multisort($act_type, SORT_DESC, $act_title, SORT_ASC, $this->acts);
        }

        return $this->printOut($limit);

    }

}

?>
