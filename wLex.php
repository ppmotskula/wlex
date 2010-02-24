<?php

/**
 * wLex
 *
 * Provides main application logic and a few utility functions.
 */
class wLex
{

    /**
     * string $content
     * string $title
     *
     * Hold the page content and page title.
     */
    public $content = '';
    public $title = '';

    /**
     * array $request
     *
     * Contains the parameters with which wLex instance was created.
     * This is normally set to be equal with $_REQUEST but can be modified,
     * e.g. when wLex is used via command-line or otherwise.
     */
    protected $request;

    /**
     * string $menu_set
     *
     * Used by htmlOut() to determine which main menu item to make active.
     */
    protected $menu_set;

    /**
     * protected float $timer
     *
     * Used for performance tracking.
     */
    protected $timer;


    /**
     * void __construct([array $request])
     *
     * Main loop of wLex. Calls different subroutines according to
     * the arguments given to it. Each subroutine must set
     * $this->content and should set $this->title.
     *
     * If $request is not given, $this->request is set to $_REQUEST.
     */
    public function __construct($request)
    {
        // initialise timer
        $this->timer = microtime(TRUE);

        // set $this->request to $request if provided, $_REQUEST if not
        // WARNING: we don't check whether $request is an array
        if (func_num_args == 0) {
            $this->request = $_REQUEST;
        } else {
            $this->request = $request;
        }

        // main loop
        if (isset($this->request['act'])) {
            $this->menu_set = 'act';
            if (! isset($this->request['old'])) {
                $this->showAct();
            } else {
                $this->showDiff();
            }
        } elseif (isset($this->request['new'])) {
            $this->menu_set = 'new';
            $this->listNew();
        } elseif (isset($this->request['old'])) {
            $this->menu_set = 'old';
            $this->listOld();
        } elseif (isset($this->request['cat'])) {
            $this->menu_set = 'cat';
            $this->listCat();
        } elseif (isset($this->request['src'])) {
            $this->menu_set = 'src';
            $this->searchPage();
        } elseif (isset($this->request['man'])) {
            $this->menu_set = 'man';
            $this->showManual();
        } else {
            $this->menu_set = '';
            $this->homePage();
        }
    }


    /**
     * protected void showAct()
     *
     * Returns the printout of an Act (if found),
     * or that of an ActList containing the applicable search results.
     */
    protected function showAct()
    {
        // define local variables for shorter code
        $_act = $this->request['act'];
        $_now = $this->request['now'];
        $_src = $this->request['src'] == 'txt';
        global $TITLE;

        if ($_now == '') {
            // no date given, today implied, try local list first

            $acts = new ActList(TRUE);
            $act = $acts->getActByAbbr($_act)
            or $act = $acts->getActByTitle($_act);
            if ($act instanceof ActInfo) {
                // found in local list, show it
                $this->content = $act->printOut(TRUE);
                $this->title = "{$act->title} - $TITLE";
                return;
            }
        }

        // not found in local list (or date given) -- go searching
        if (! $acts instanceof ActList) {
            $acts = new ActList(TRUE);
        }
        $act = $acts->getActByAbbr($_act);
        $_act = ($act ? $act->title : $_act);
        unset($acts);
        $acts = new ActList();
        $acts->doSearch($_act, $_now, $_src);
        if (count($acts->acts) == 1) {
            // single act found, display it
            foreach ($acts->acts as $act) {
                $this->content = $act->printOut(TRUE);
                $this->title =
                        "{$act->title} " .
                        ($_now > '' ? "($_now) " : "") .
                        "- $TITLE";
                return;
            }
        }

        // several search results
        if (count($acts->acts) > 1) {
            foreach ($acts->acts as $act) {
                if ($act->title == $_act) {
                    // exact match found, display it
                    $this->content = $act->printOut(TRUE);
                    $this->title =
                            "{$act->title} " .
                            ($_now > '' ? "($_now) " : "") .
                            "- $TITLE";
                    return;
                }
            }
        }

        // no exact match, show list
        $this->menu_set = 'src';
        $this->content = "<div id=\"text\">\n{$acts->printSort()}\n</div><!-- /text -->\n";
        $this->title = (
            $_now
            ? "? $_act ($_now) - $TITLE"
            : "? $_act - $TITLE"
        );
        return;
    }


    /**
     * protected void showDiff()
     *
     * Calculates the diff between two versions of a single act.
     */
    protected function showDiff()
    {
        // local variables for shorter code
        $_act = $this->request['act'];
        $_now = $this->request['now'];
        $_old = $this->request['old'];
        global $TITLE;

        // set page title
        $this->title = "$_act ($_now / $_old) - $TITLE";

        // check whether $_act was a known abbreviation, expand if yes
        $acts = new ActList(TRUE);
        $act = $acts->getActByAbbr($_act);
        $_act = ($act ? $act->title : $_act);
        unset($acts);

        // load new act's text into $new
        $acts = new ActList();
        $acts->doSearch($_act, $_now);
        if (count($acts->acts) == 1) {
            // single act found, use it
            foreach ($acts->acts as $act) {
                $new = $act->textForDiff();
            }
        } elseif (count($acts->acts) > 1) {
            foreach ($acts->acts as $act) {
                if ($act->title == $_act) {
                    // exact match found, use it
                    $new = $act->textForDiff();
                    break;
                }
            }
        }
        unset($acts);

        // load old act's text into $old
        $acts = new ActList();
        $acts->doSearch($_act, $_old);
        if (count($acts->acts) == 1) {
            // single act found, use it
            foreach ($acts->acts as $act) {
                $old = $act->textForDiff();
            }
        } elseif (count($acts->acts) > 1) {
            foreach ($acts->acts as $act) {
                if ($act->title == $_act) {
                    // exact match found, use it
                    $old = $act->textForDiff();
                    break;
                }
            }
        }
        unset($acts);

        // check if both texts got loaded
        if ($old == '' || $new == '') {
            // one or both texts could not be loaded, bail out
            global $HOME;
            $this->content = "Ei leidnud võrreldavaid akte (<a href=\"$HOME/man/\">miks?</a>).";
            return;
        }


        // do the diff

        // Text_Diff would throw tons of errors with Strict Standards
        error_reporting(E_ALL ^ E_NOTICE);
        require_once 'Text/Diff.php';
        require_once 'Text/Diff/Renderer/inline.php';
        $diff = new Text_Diff('auto', array(split("\n", $old), split("\n", $new)));
        $renderer = new Text_Diff_Renderer_inline();
        $txt = $renderer->render($diff);

        // undo temporary changes created by diff and textForDiff
        $txt = htmlspecialchars_decode($txt);
        $txt = preg_replace('# ([.,:;!?)])#m', "$1", $txt);
        $txt = preg_replace('#([(]) #m', "$1", $txt);

        // add TOC of changes
        $lines = explode("\n", $txt);
        $cnum = 0;
        $toc = '<p class="pg">';
        foreach ($lines as &$line) {
            if (preg_match('#<(?:ins|del)>#', $line) == 1) {
                $cnum++;
                // change found, add anchor and TOC link
                $line = "<span class=\"diff\"><a name=\"c$cnum\"></a>[$cnum]</span>$line";
                $toc .= "<a href=\"#c$cnum\">$cnum</a> ";
            }
        }
        $toc .= "</p>\n";
        $txt = "<h1>$_act (<ins>$_now</ins> / <del>$_old</del>)</h1>\n" .
                implode("\n", $lines);
        $toc = "<p class=\"ttl\"><a href=\"#top\">Muudatused</a></p>\n$toc";

        // try to fix "loose" <ins> and <del>
        $lines = explode("\n", $txt);
        $ins = FALSE;
        $del = FALSE;
        foreach ($lines as &$line) {

            // open unopened /ins
            if ($ins) {
                $line = "<ins>$line";
            }

            // open unopened /del
            if ($del) {
                $line = "<del>$line";
            }

            // close unclosed ins
            if (
                (($tagA = strrpos($line, '<ins>')) !== FALSE) &&
                (
                    !($tagZ = strrpos($line, '</ins>')) ||
                    ($tagA > $tagZ)
                )
            ) {
                $line = "$line</ins>";
                $ins = TRUE;
            } else {
                $ins = FALSE;
            }

            // close unclosed del
            if (
                (($tagA = strrpos($line, '<del>')) !== FALSE) &&
                (
                    !($tagZ = strrpos($line, '</del>')) ||
                    ($tagA > $tagZ)
                )
            ) {
                $line = "$line</del>";
                $del = TRUE;
            } else {
                $del = FALSE;
            }

        }

        // enclose each line in <p>...</p>, trying to identify special formats
        global $NUMA, $NUMR, $NSUP, $SNUM;

        foreach ($lines as &$line) {

            if (preg_match("#^(?:.*?<(?:ins|del|/span)>)?§#", $line) == 1) {
                $line = "<p class=\"pg\">$line</p>";
                continue;
            }

            if (preg_match("#^(?:.*?<(?:ins|del|/span)>)?$SNUM\.? (?:osa|peat(?:ü|Ü)kk|jagu|jaotis|alljaotis)#", $line) == 1) {
                $line = "<p class=\"ptk\">$line</p>";
                continue;
            }

            if (preg_match("#^(?:.*?<(?:ins|del|/span)>)?(?:[A-Za-zÕÄÖÜšŽõäöüšž ])+(?:(?:<sup>)?1(?:</sup>)?)?$#", $line) == 1) {
                $line = "<p class=\"ptk\">$line</p>";
                continue;
            }

            $line = "<p>$line</p>";
        }

        $txt = implode("\n", $lines);

        // output result to $this->content
        $this->content = <<<END
<div id="toc">
$toc
</div><!-- /toc -->
<div id="txt">
<a name="top"></a>
$txt
</div><!-- /txt -->

END;
        return;
    }


    /**
     * protected void listNew()
     *
     * Lists new (recently added or modified) acts.
     */
    protected function listNew()
    {
        $acts = new ActList(TRUE);
        $this->content = <<<END
<div id="text">
<h2>Värsked seadused</h2>
<p>Kõik seadused viimase redaktsiooni jõustumise järjekorras, uuemad eespool:</p>
{$acts->printNew()}
</div><!-- /text -->

END;
        global $TITLE;
        $this->title = "$TITLE: värsked seadused";
    }


    /**
     * protected void listOld()
     *
     * Lists old (soon to be modified) acts.
     */
    protected function listOld()
    {
        $acts = new ActList(TRUE);
        $this->content = <<<END
<div id="text">
<h2>Vanad seadused</h2>
<p>Lähiajal "vanaks minevad" seadused järgmise redaktsiooni jõustumise järjekorras:</p>
{$acts->printUpcoming()}
</div><!-- /text -->

END;
        global $TITLE;
        $this->title = "$TITLE: vanad seadused";
    }


    /**
     * protected void listCat()
     *
     * Listing of the systematic catalog.
     */
    protected function listCat()
    {
        $acts = new SysCat(TRUE);
        $this->content = $acts->printSys(TRUE);
        global $TITLE;
        $this->title = "$TITLE: süstemaatiline kataloog";
    }

    /**
     * protected void searchPage()
     *
     * Search form.
     */
    protected function searchPage()
    {
        global $HOME;

        // set $now to whatever was given in request, or today's date
        $now = ($this->request['now'] ? $this->request['now'] : datetodmy(time()));

        $this->content = <<<END
<div id="text">
<h2>Terviktekstide otsing e-Riigi Teatajast</h2>
<form action="$HOME/q"><table>
<tr><td>Otsi:</td><td><input type="text" name="act" size="40" /></td></tr>
<tr><td>seisuga:</td><td><input type="text" name="now" value="$now" size="10" /></td></tr>
<tr><td></td><td><input type="checkbox" name="src" value="txt" /> nii aktide pealkirjadest kui tekstidest</td></tr>
<tr><td></td><td><input type="submit" value="Otsi!" /></td></tr>
</table></form>
</div><!-- /text -->

END;
        global $TITLE;
        $this->title = "$TITLE: otsing";
    }


    /**
     * protected void homePage()
     *
     * Home page.
     */
    protected function homePage()
    {
        global $HOME, $SCAT_DB, $TITLE, $BANNER;
        $cat_updated = date('d.m.y H:i', filemtime($SCAT_DB));
        $scat = new SysCat(TRUE);
        $this->content = <<<END
<div id="text">

END;

        if ($BANNER > '') {
            $this->content .= <<<END
<div id="banner">
$BANNER
</div><!-- /banner -->

END;
        }

        $this->content .= <<<END
<h2>Värsked (hiljuti muudetud) seadused:</h2>
{$scat->acts->printNew(10)}
<ul><li><a href="$HOME/new/">veel...</a></li></ul>
<h2>Vanad (varsti muutuvad) seadused:</h2>
{$scat->acts->printUpcoming(10)}
<ul><li><a href="$HOME/old/">veel...</a></li></ul>
<p>Kataloog viimati uuendatud {$cat_updated}</p>
</div><!-- /text -->

END;
        $this->title = "$TITLE: Eesti seadused";
    }


    /**
     * protected void showManual()
     *
     * Loads online documentation if provided, builtin manual if not.
     */
    protected function showManual()
    {
        global $PROG_ID, $NOTICE, $MANPAGE;
        $man = @file_get_contents($MANPAGE)
        or $man = "<p>Abiinfo faili ($MANPAGE) ei leitud.</p>\n";
        $this->content = <<<END
<div id="text">
$man
<hr />
<pre>
$PROG_ID $NOTICE
</pre>
</div><!-- /text -->

END;
        global $TITLE;
        $this->title = "$TITLE: abiinfo";
    }


    /**
     * string htmlOut()
     *
     * Creates output-ready HTML page based on $content and $title
     */
    public function htmlOut()
    {
        global $HOME, $TITLE, $TAGLINE, $PROG_ID, $NOTICE;

        $quicksearch = <<<END
<form style="float:right" action="$HOME/q"><div>
    <input type="text" name="act" value="{$this->request['act']}" title="Kirjuta siia otsitav tekst ja vajuta Enter" />
    <input type="submit" value="otsi!" />
</div></form>

END;

        $menubar =
            "<div id=\"menu\">\n$quicksearch<ul>"
            . '<li><a class="menu-0' . ($this->menu_set == '' ? ' menu-1' : '') . '" href="'.$HOME.'/">avaleht</a></li>'
            . '<li><a ' . ($this->menu_set == 'new' ? ' class="menu-1"' : '') . ' href="'.$HOME.'/new/" title="Hiljuti lisatud või muudetud seadused">värsked</a></li>'
            . '<li><a' . ($this->menu_set == 'old' ? ' class="menu-1"' : '') . ' href="'.$HOME.'/old/" title="Seadused, mille muudatused lähiajal jõustuvad">vanad</a></li>'
            . '<li><a' . ($this->menu_set == 'cat' ? ' class="menu-1"' : '') . ' href="'.$HOME.'/cat/" title="Seaduste süstemaatiline kataloog">kataloog</a></li>'
            . '<li><a class="menu-2' . ($this->menu_set == 'man' ? ' menu-1' : '') . '" href="'.$HOME.'/man/" title="'.$TITLE.': abiteave">abi</a></li>'
            ."</ul></div>\n"
        ;

        global $GA_ID;
        if ($GA_ID > '') {
            $google_analytics = <<<END
<script type="text/javascript">

  var _gaq = _gaq || [];
  _gaq.push(['_setAccount', '$GA_ID']);
  _gaq.push(['_trackPageview']);

  (function() {
    var ga = document.createElement('script'); ga.type = 'text/javascript'; ga.async = true;
    ga.src = ('https:' == document.location.protocol ? 'https://ssl' : 'http://www') + '.google-analytics.com/ga.js';
    (document.getElementsByTagName('head')[0] || document.getElementsByTagName('body')[0]).appendChild(ga);
  })();

</script>

END;
        }

        $html = <<<END
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Strict//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-strict.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
    <title>{$this->title}</title>
    <link rel="icon" href="$HOME/favicon.ico" type="image/x-icon" />
    <link rel="shortcut icon" href="$HOME/favicon.ico" type="image/x-icon" />
    <link rel="stylesheet" type="text/css" href="$HOME/style.css" />
    <!--[if lt IE 7]>
    <link rel="stylesheet" type="text/css" href="$HOME/ie6.css" />
    <script type="text/javascript">onload = function() { header.focus() }</script>
    <![endif]-->
    <meta name="generator" content="$PROG_ID" />
$google_analytics</head>
<body>
<div id="header">
<h1>$TITLE$TAGLINE</h1>
$menubar
</div><!-- /header -->
<div id="wrapper">
END
        . $this->content
        . <<<END
</div><!-- /wrapper -->

END
        . "<div class=\"timer\">"
        . (round(microtime(TRUE) - $this->timer, 3))
        . "</div>\n"
. <<<END
</body>
</html>

END;
        return $html;
    }

}

?>
