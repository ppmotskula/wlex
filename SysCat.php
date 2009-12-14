<?php

/**
 * class SysCat
 *
 * Represents the systematic catalog of acts.
 */
class SysCat
{
    /**
     * array $scat holds the systematic catalog
     */
    public $scat=array();

    /**
     * ActList $acts holds the list of acts
     */
    public $acts;


    /**
     * void __construct([bool $autoload = FALSE])
     *
     * Initialises $acts
     */
    public function __construct($autoload = FALSE)
    {
        $this->acts = new ActList(FALSE);
        if ($autoload) {
            $this->loadLocal();
        }
    }


    /**
     * bool loadLocal()
     *
     * Loads the systematic catalog from local file $SCAT_DB
     * and acts list from $ACTS_DB.
     * Returns TRUE if successful, FALSE if not.
     */
    public function loadLocal()
    {
        global $SCAT_DB;
        if (! $data = @file_get_contents($SCAT_DB)) {
            // failed to load, bail out
            return FALSE;
        }
        $this->scat = unserialize($data);
        $this->acts->loadLocal();
    }


    /**
     * bool saveLocal()
     *
     * Saves the systematic catalog into local file $SCAT_DB
     * and list of acts into $ACTS_DB.
     * Returns TRUE if successful, FALSE if not.
     */
    public function saveLocal()
    {
        global $SCAT_DB;
        return @file_put_contents($SCAT_DB, serialize($this->scat))
                && $this->acts->saveLocal();
    }


    /**
     * bool loadRemote()
     *
     * Populates systematic catalog and list of acts from eRT.
     * Returns TRUE if successful, FALSE if not.
     */
    public function loadRemote()
    {
        unset($this->scat);
        unset($this->acts);
        $this->acts = new ActList;

        global $ERT_HOME;
        $page = new WebPage("$ERT_HOME/ert.jsp?link=jaotusyksused-form", TRUE);

        if (! $page->body()) {
            // remote catalog unreachable, bail out
            return FALSE;
        }

        preg_match_all('#<td class="jaotusyksus".*?>(.*?)</td>#is',
                $page->body(),
                $matches);

        foreach ($matches[1] as $match) {
            if (strpos($match, '<b>') === 0) {
                // category
                $cat = trim(substr($match, 3, -4));
                $this->scat[] = array(
                    'name' => $cat,
                    'cats' => array(),
                );
                $category =& $this->scat[count($this->scat)-1];
            } elseif (strpos($match, '<a href="') === 0) {
                // subcategory
                $subcat = trim(substr($match, 101, -4));
                $subcat_url = substr($match, 9, 90);
                $category['cats'][] = array(
                    'name' => $subcat,
                    'acts' => array(),
                );
                $subcategory =& $category['cats'][count($category['cats'])-1];
                $acts = new ActList;
                $acts->loadRemote($subcat_url);
                $this->acts->acts = $this->acts->acts + $acts->acts;
                foreach ($acts->acts as $act) {
                    $subcategory['acts'][] = $act->id;
                }
                unset($acts);
            }
        }

        return TRUE;
    }


    /**
     * string printSys()
     *
     * returns a printout of systematic catalog, together with a
     * table of contents if so requested via $toc
     */
    public function printSys()
    {
        $res = '';
        $toc = '';
        $cat = 0;
        foreach ($this->scat as $category) {
            $cat++;
            $sub=0;
            $res .= "<p class=\"ptk\"><a name=\"$cat\">{$category['name']}</a></p>\n";
            $toc .= "<p class=\"ptk\"><a href=\"#$cat\">{$category['name']}</a></p>\n";
            foreach ($category['cats'] as $subcategory) {
                $sub++;
                $res .= "<p class=\"pg\"><a name=\"$cat.$sub\">{$subcategory['name']}</a></p>\n";
                $toc .= "<p class=\"pg\"><a href=\"#$cat.$sub\">{$subcategory['name']}</a></p>\n";
                foreach ($subcategory['acts'] as $id) {
                    $res    .= "<p class=\"x\">{$this->acts->getActById($id)->longLink()}</p>\n";
                }
            }
        }

        return <<<END
<div id="toc">$toc</div><!-- /toc -->
<div id="txt">$res</div><!-- /txt -->
END;

    }

}

?>
