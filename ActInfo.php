<?php

/**
 * ActInfo
 *
 * Represents an act data record.
 */
class ActInfo
{
    /**
     * Act's properties: eRT ID, issuer, type, title, number, valid from,
     * valid until, abbreviation, as-of date, text
     */
    public $id = '';
    public $issuer = '';
    public $type = '';
    public $title = '';
    public $nr = '';
    public $valid = '';
    public $until = '';
    public $abbr = '';
    public $as_of = '';
    public $text = '';


    /**
     * void __construct(string $id[, string $issuer, string $type,
     *      string $title, string $nr, string $valid, string $until,
     *      string $abbr, string $as_of])
     *
     * Constructs a new ActInfo from one tab-delimited string or eight
     * individual string values. Dates must be formatted as 'd.m.Y'.
     */
    public function __construct(
        $id,
        $issuer = '',
        $type   = '',
        $title  = '',
        $nr     = '',
        $valid  = '',
        $until  = '',
        $abbr   = '',
        $as_of  = ''
    )
    {
        // if there was just a single argument, explode it
        if (func_num_args() == 1) {
            $args = explode("\t", trim($id));
            $id     = $args[0];
            $issuer = $args[1];
            $type   = $args[2];
            $title  = $args[3];
            $nr     = $args[4];
            $valid  = $args[5];
            $until  = $args[6];
            $abbr   = $args[7];
            $as_of  = $args[8];
        }

        $this->id       = trim($id);
        $this->issuer   = trim($issuer);
        $this->type     = trim($type);
        $this->title    = trim($title);
        $this->nr       = trim($nr);
        $this->valid    = trim($valid);
        $this->until    = trim($until);
        $this->abbr     = trim($abbr);
        $this->as_of    = trim($as_of);

    }


    /**
     * string asString()
     *
     * Returns the act's fields as a tab-delimited string.
     */
    public function asString()
    {
        return implode("\t", array(
            $this->id,
            $this->issuer,
            $this->type,
            $this->title,
            $this->nr,
            $this->valid,
            $this->until,
            $this->abbr,
            $this->as_of
        ));
    }


    /**
     * string shortLink()
     *
     * Returns a short link to the act page.
     * Act ID is used as link to static pages, otherwise abbreviation or title
     */
    public function shortLink()
    {
        global $STATIC, $HOME;
        if ($STATIC) {
            $link = "./{$this->id}.html";
        } else {
            $link = ($this->abbr > '' ? $this->abbr : $this->title);
            $link = "$HOME/" . urlencode($link);
            $link .= ($this->as_of > '' ? "/{$this->as_of}" : '');
        }
        $link = "<a href=\"$link\">{$this->title}</a>";
        return $link;
    }


    /**
     * string longLink()
     *
     * Returns a full-line link to the act page.
     */
    public function longLink()
    {
        $link =
            "{$this->shortLink()} "
            . (
                $this->type == 'seadus'
                ? ($this->abbr > '' ? "[{$this->abbr}] " : '')
                : "[{$this->issuer} - {$this->type} nr {$this->nr}] "
            )
            . "({$this->valid} - "
            . ($this->until > '' ? $this->until : '...' )
            . ")"
        ;
        return $link;
    }


    /**
     * bool loadText([bool $force_reload = FALSE])
     *
     * Loads act's text from eRT and parses it into $this->text
     * if $this->text is empty or $force_reload is TRUE.
     *
     * Returns TRUE if successful, FALSE if not.
     */
    public function loadText($force_reload = FALSE)
    {
        // don't reload unless you have to
        if (($this->text > '') && !$force_reload) {
            return TRUE;
        }

        // fetch text from local cache if found
        global $CACHE_DB;
        $cacheDb = dirname(__FILE__) . "/$CACHE_DB";
        try  {
            $db = new PDO("sqlite:$cacheDb");
            $sql = $db->prepare('SELECT text FROM acts WHERE id = ?');
            if ($sql) {
                $sql->execute(array($this->id));
                if ($this->text = $sql->fetchColumn()) {
                    return TRUE;
                }
            }
        } catch (PDOException $e) {
            // ignore missing database
            throw $e;
        }

        // fetch act's text from e-Riigi Teataja
        global $ERT_HOME;
        $url = "$ERT_HOME/ert.jsp?link=print&akt_vorminduseta=1&id={$this->id}";
        $page = new WebPage($url, TRUE);
        if (!$page) {
            // page loading failed, bail out
            $this->text = '';
            return FALSE;
        }

        // define pseudo-constant variables for simplifying the PCRE patterns
        global $NUMA, $NUMR, $NSUP, $SNUM, $BR, $PBR, $SSEP, $PARA, $DATE;

        // clean up eRT's html and remove any unnecessary fluff

        // pass through tidy
        $page->tidy("-bc -asxml -wrap 0 -utf8", TRUE);
        $text = $page->body();

        // replace "fancy" paragraphs with plain ones
        $text = preg_replace('#<p .*?>#m', '<p>', $text);
        // remove <span> and <div>
        $text = preg_replace('#</?(?:span|div).*?>#m', '', $text);
        // remove <b>, <i>, <strong>, and <em>
        $text = preg_replace('#</?(?:b|i|strong|em)>#m', '', $text);
        // remove top-of-page spacer
        $text = preg_replace('#<img.*?<br />\n<br />#m', '', $text);
        // multiple spaces to single space
        $text = preg_replace('# *(\s) *#m', "$1", $text);
        // remove spaces between § and -
        $text = str_replace('§ -', '$-', $text);
        // remove blank-starting, blank-ending, and all-blank paragraphs
        $text = preg_replace('#<p>(?:\s|<br />)*#m', '<p>', $text);
        $text = preg_replace('#(?:\s|<br />)*</p>#m', '</p>', $text);
        $text = preg_replace('#<p></p>\s*#m', '', $text);
        // remove repeated <br />
        $text = preg_replace('#\s*<br />(?:\s*<br />)+#m', '<br />', $text);
        // remove empty lines
        $text = preg_replace('#\n\s*\n#m', "\n", $text);
        // remove spaces from within dates
        // do it twice as one pass doesn't catch all occurrences
        $text = preg_replace("#($NUMA)\. +($NUMA)#m", "$1.$2", $text);
        $text = preg_replace("#($NUMA)\. +($NUMA)#m", "$1.$2", $text);
        // remove spaces before superscripted numbers
        $text = preg_replace("# *<sup> *($NUMA) *</sup>#m",
                "<sup>$1</sup>", $text);
        // remove extra spaces inside parentheses
        $text = str_replace(array('( ', ' )'), array('(', ')'), $text);

        // fix exceptional idiocies

        // remove spacer
        $text = preg_replace("#<img src=.*?\n<table.*?<br />\n#m", '', $text);
        // enacment notice as part of <p> containing the title
        $text = preg_replace("#<br /> ?\n(Vastu võetud)#m", "</p>\n<p>$1", $text);
        // subsection title as part of <p> containing the section title
        $text = preg_replace("#<br />\n($SNUM\. (?:peat(?:ü|Ü)kk|jagu|jaotis|alljaotis))#mi",
                "</p>\n<p>$1", $text);
        // p-s in separate <p>-s instead of those of their containing lg-s or pg-s
        $text = preg_replace("#</p>\n<p>($SNUM\))#m", "<br />\n$1", $text);
        // merge all enactment comments into preceding <p>
        $text = preg_replace("#</p>\s*<p>\[#m", "<br />\n[", $text);
        // no breaking after dash
        $text = preg_replace("#-\s*<br />\s*#m", '- ', $text);


        // identify and apply formatting to special paragraphs

        // act title
        $text = preg_replace("#\n<p>(.*?)($NSUP?)</p>#mi",
                "\n<p class=\"ttl\">$1$2</p>", $text, 1);

        // osa-ptk-jgu-jts-ajt standard format: (I|1.) type(. |\n)title
        $text = preg_replace("#\n<p>($SNUM\.? )(osa)$SSEP(.*?)(</p>|<br />)#mi",
                "\n<p class=\"osa\">$1$2. $3$4", $text);
        $text = preg_replace("#\n<p>($SNUM\.? )(peat(?:ü|Ü)kk)$SSEP(.*?)(</p>|<br />)#mi",
                "\n<p class=\"ptk\">$1$2. $3$4", $text);
        $text = preg_replace("#\n<p>($SNUM\.? )(jagu)$SSEP(.*?)(</p>|<br />)#mi",
                "\n<p class=\"jgu\">$1$2. $3$4", $text);
        $text = preg_replace("#\n<p>($SNUM\.? )(jaotis)$SSEP(.*?)(</p>|<br />)#mi",
                "\n<p class=\"jts\">$1$2. $3$4", $text);
        $text = preg_replace("#\n<p>($SNUM\.? )(alljaotis)$SSEP(.*?)(</p>|<br />)#mi",
                "\n<p class=\"ajt\">$1$2. $3$4", $text);

        // osa special format: I title
        $text = preg_replace("#\n<p>($NUMR$NSUP? .*?)</p>#mi",
                "\n<p class=\"osa\">$1</p>", $text);

        // osa special format: ÜLDOSA | ERIOSA
        $text = preg_replace("#\n<p>((?:Ü|ü)ldosa|eriosa)</p>#mi",
                "\n<p class=\"osa\">$1</p>", $text);

        // jgu special format: 1. title
        $text = preg_replace("#\n<p>($NUMA$NSUP?\. .*?[^.])</p>#mi",
                "\n<p class=\"jgu\">$1</p>", $text);

        // pg: PARA 1. title
        $text = preg_replace("#\n<p>($PARA)($SNUM)(\..*?| \[.*?|)#mi",
                "\n<p class=\"pg\">§ $2$3", $text);

        // pg: PARA-d 1-2 title
        $text = preg_replace("#\n<p>($PARA(?:-|i)d )($SNUM-$SNUM)(.*?)#mi",
                "\n<p class=\"pg\">§-d $2$3", $text);

        // inlined comments: []
        $text = preg_replace("#(\[.*?\])#mi",
                "<span class=\"rem\">$1</span>", $text);

        // attachments: Lisa $NUM
        $text = preg_replace("#\n<p>(Lisa $NUM)#m",
                "\n<p class=\"att\">$1", $text);

        // enactment notices and backlinks
        global $HOME;
        // use hard-coded $home when creating offline pages
        $home = $HOME ? $HOME : 'http://kasulik.info/wlex';
        $text = preg_replace(
            "#<p>($DATE.*?($DATE)|Vastu võetud $DATE.*? jõustunud (?:($DATE)\.?(?: a\.)?|vastavalt .*?))</p>#mi",
            (
                "<p class=\"x\">$1" .
                '<span class="noprint">' .
                " -- <a href=\"$home/" .
                ($this->abbr > '' ? $this->abbr : $this->title) .
                "/$2$3" .
                '">vaata</a> - ' .
                "<a href=\"$home/" .
                ($this->abbr > '' ? $this->abbr : $this->title) .
                '/' .
                ($this->as_of > '' ? $this->as_of : datetodmy(time())) .
                "/$2$3" .
                '">võrdle</a>' .
                '</span>' .
                '</p>'
            ),
            $text
        );

        // add source reference & attribution notice
        global $PROG_ID, $NOTICE, $ERT_HOME;
        $src = "$ERT_HOME/act.jsp?id={$this->id}";
        $text = <<<END
<p class="rem">Ametlik tekst: <a href="$src">$src</a><br />
Vormindus: $PROG_ID $NOTICE</p>$text

END;

        // success
        $this->text = $text;
        $this->_cacheAct();
        return TRUE;
    }


    /**
     * string printOut([bool $with_toc = FALSE])
     *
     * Returns act's formatted text (with or without TOC)
     */
    public function printOut($with_toc = FALSE)
    {
        // make sure we have a text to work with
        $this->loadText();

        if ($with_toc) {
            // add TOC as requested

            // define pseudo-constant variables for simplifying the PCRE patterns
            global $NUMA, $NUMR, $NSUP, $SNUM, $PBR;

            // start with clean $toc & $txt
            $toc = '';
            $txt = '';

            // work the text line by line
            foreach (explode("\n", $this->text) as $line) {
                if (preg_match(
                        "#^(<p class=\"pg\">)(<ins>|<del>)?§ ($SNUM)(.*?)(</ins>|</del>)?(</p>|<br />)$#",
                        $line, $m)) {
                    // paragraph: add target & linked TOC entry
                    $para = $m[1];
                    $tagA = $m[2];
                    $pnum = $m[3];
                    $text = $m[4];
                    $tagZ = $m[5];
                    $lend = $m[6];
                    $nx = str_replace(
                            array('<sup>',  '</sup>'),
                            array('.',      ''),
                            $pnum);
                    if ($tagA == '<del>') {
                        // special anchors for deleted paragraphs (in diff)
                        $nx .= "x";
                    }
                    $txt .= "<a name=\"p$nx\"></a>$para{$tagA}§ $pnum$text$tagZ$lend\n";
                    $text = preg_replace('#<a .*?>#', '', $text);
                    $text = str_replace('</a>', '', $text);
                    $toc .= "$para<a href=\"#p$nx\">{$tagA}§ $pnum$text$tagZ</a></p>\n";
                    continue;
                }
                if (preg_match(
                        "#^(<p class=\")(osa|ptk|jgu|jts|ajt)(\".*?)($PBR)$#",
                        $line, $m)) {
                    // subtitle: add non-linked TOC entry
                    $toc .= "{$m[1]}{$m[2]}{$m[3]}</p>\n";
                    $txt .= "$line\n";
                    continue;
                }
                if (preg_match(
                        "#^<p class=\"ttl\">(.*?)(?:$PBR)$#",
                        $line, $m)) {
                    // title: add TOC title
                    $ttl = preg_replace("#$NSUP#", '', $m[1]);
                    $toc .= "<p class=\"ttl\"><a href=\"#top\">$ttl</a></p>\n";
                    $txt .= "$line\n";
                    continue;
                }
                if (preg_match(
                        "#^(<p class=\"att\">)(.*?)($PBR)$#",
                        $line, $m)) {
                    // attachment: add linked TOC entry
                    $tmp = $m[2];
                    $tmp = str_replace('Lisa ', 'L', $tmp);
                    $txt .= "<a name=\"$tmp\"></a>{$m[1]}{$m[2]}{$m[3]}\n";
                    $toc .= "{$m[1]}<a href=\"#$tmp\">{$m[2]}</p>\n";
                    continue;
                }
                // any other line: don't add TOC entry
                $txt .= "$line\n";
            }

            // remove comment spans from TOC
            $toc = preg_replace("#<span .*?</span>#m", '', $toc);

            return <<<END
<div id="toc">
$toc
</div><!-- /toc -->
<div id="txt">
<a name="top"></a>
$txt
</div><!-- /txt -->

END;
        } else {
            // no TOC needed
            return <<<END
<div id="text">
{$this->text}
</div><!-- /text -->

END;
        }

    }

    /**
     * string textForDiff()
     *
     * Returns act in diff-friendly plaintext format
     */
    public function textForDiff()
    {
        // make sure we have a text to work with
        $this->loadText();
        $txt = $this->text;

        // remove tags a, span
        $txt = preg_replace('#</?(?:a|span)(?: .*?)?>#m', '', $txt);
        // remove '-- vaata - võrdle'
        $txt = str_replace('-- vaata - võrdle', '', $txt);
        // remove comments []
        $txt = preg_replace('#(?:<br />\s*)*\[.*?\]\s*#m', '', $txt);

        // convert <br /> and </p>
        $txt = preg_replace('#<br />\s*#', "\n", $txt);
        $txt = preg_replace('#</p>\s*#', "\n", $txt);
        // remove <p>
        $txt = preg_replace('#<p(?: .*?)?>#', '', $txt);

        // space-out some punctuation
        $txt = preg_replace('#([.,:;!?)])#m', " $1", $txt);
        $txt = preg_replace('#([(])#m', "$1 ", $txt);

        return $txt;
    }

    /**
     * Attempts to save act's text into local cache, creating it if needed
     *
     * @return bool TRUE if successful
     */
    protected function _cacheAct()
    {
        global $CACHE_DB;

        if (!$CACHE_DB) {
            // $CACHE_DB not defined
            return FALSE;
        }

        $cacheDb = dirname(__FILE__) . "/$CACHE_DB";
        try  {
            $db = new PDO("sqlite:$cacheDb");
            // create table if not there already
            $sql = $db->prepare(<<<END
CREATE TABLE IF NOT EXISTS acts (
    id INTEGER PRIMARY KEY ON CONFLICT REPLACE,
    title TEXT,
    valid TEXT,
    abbr TEXT,
    text TEXT
)
END
);
            $sql->execute();

            // insert or replace $id, $text into database
            $sql = $db->prepare('INSERT INTO acts VALUES (?, ?, ?, ?, ?)');
            $sql->execute(array(
                $this->id,
                $this->title,
                $this->valid,
                $this->abbr,
                $this->text
            ));
        } catch (PDOException $e) {
            // database creation/update error
            throw $e;
            return FALSE;
        }

        // success
        return TRUE;
    }

}

?>
