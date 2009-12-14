<?php

require_once('include.php');

$scat = new SysCat;
doSysCat();
doSiteMap();
doTweetNew();


function doSysCat() {
    // Reload syscat. This may take several minutes.
    global $scat;
    $timer = time();
    if (
        $scat->loadRemote() &&
        $scat->saveLocal()
    ) {
        echo "Syscat refreshed, time: " . (time() - $timer) . " s.\n";
        return TRUE;
    } else {
        echo "Failed to refresh systematic catalog.\nHave you created the 'data' directory?\n";
        return FALSE;
    }
}

function doSiteMap() {
    // Generate sitemaps.xml
    global $scat;
    global $ADDRESS;
    $today = datetoymd(time());
    $sitemap = <<<END
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
   <url>
      <loc>$ADDRESS</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$ADDRESS/new/</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$ADDRESS/old/</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$ADDRESS/cat/</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$ADDRESS/man</loc>
   </url>
   <url>
      <loc>$ADDRESS/src</loc>
   </url>

END;

    foreach ($scat->acts->acts as $act) {
        $aref = ($act->abbr > '' ? $act->abbr : urlencode($act->title));
        $adate = datetoymd(dmytodate($act->valid));
        $sitemap .= <<<END
    <url>
        <loc>$ADDRESS/$aref</loc>
        <lastmod>$adate</lastmod>
    </url>

END;
    }

    $sitemap .= "</urlset>\n";

    global $SITEMAP;
    if (file_put_contents($SITEMAP, $sitemap)) {
        echo <<<END
sitemap.xml refreshed.
Make sure you include the following line in your /robots.txt:
Sitemap: $ADDRESS/$SITEMAP

END;
        return TRUE;
    } else {
        echo "Failed to update sitemap.xml.\n";
        return FALSE;
    }
}

function doTweetNew() {
    // tweet about new acts IF twitter parameters are set
    global $scat;
    global $ADDRESS;
    global $TW_USER;
    global $TW_PASS;
    if ($TW_USER > '' && $TW_PASS > '') {
        $today = datetodmy(time());
        foreach ($scat->acts->acts as $act) {
            if ($act->valid == $today) {
                doTweet(
                    "Värske {$act->title}: $ADDRESS/" .
                    ($act->abbr > '' ? $act->abbr : urlencode($act->title))
                );
                $got_new = TRUE;
            }
        }
        if (!$got_new) {
            doTweet("Seadusrindel muutusteta");
        }
        echo "Twitter updated.\n";
        return TRUE;
    } else {
        echo "Twitter credentials not set, no tweeting.\n";
        return FALSE;
    }
}

function doTweet($tweet) {
    // post a message to Twitter
    global $TW_USER;
    global $TW_PASS;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'http://twitter.com/statuses/update.xml');
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, "status=$tweet");
    curl_setopt($ch, CURLOPT_USERPWD, "$TW_USER:$TW_PASS");
    $buffer = curl_exec($ch);
    curl_close($ch);
    // check for success or failure
    return (!empty($buffer));
}

?>
