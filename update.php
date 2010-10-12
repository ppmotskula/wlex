<?php

require_once('include.php');

$scat = new SysCat();
doSysCat();
doStatic();
doSiteMap();
doTweetNew();

function doSysCat() {
    // Reload syscat. This may take several minutes.
    $timer = time();

    global $scat;
    
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

function doStatic() {
    // Recreate static pages. This may take several minutes.
    $timer = time();
    
    global $scat, $STATIC;
    $STATIC = TRUE;
    
    $app = new wLex(array('cat' => TRUE));
    file_put_contents('./wLex/index.html', $app->htmlOut());
    
    foreach ($scat->acts->acts as $act) {
        $actRef = $act->abbr ? $act->abbr : $act->title;
        $actFile = "./wLex/{$act->id}.html";
        $app = new wLex(array('act' => $actRef));
        file_put_contents($actFile, $app->htmlOut());
    }
    
    $STATIC = FALSE;
    
    echo "Static pages updated, time: " . (time() - $timer) . " s.\n"; 
}

function doSiteMap() {
    // Generate sitemaps.xml
    global $scat, $HOME;
    $today = datetoymd(time());
    $sitemap = <<<END
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
   <url>
      <loc>$HOME</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$HOME/new/</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$HOME/old/</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$HOME/cat/</loc>
      <lastmod>$today</lastmod>
      <changefreq>daily</changefreq>
   </url>
   <url>
      <loc>$HOME/man</loc>
   </url>
   <url>
      <loc>$HOME/src</loc>
   </url>

END;

    foreach ($scat->acts->acts as $act) {
        $aref = ($act->abbr > '' ? $act->abbr : urlencode($act->title));
        $adate = datetoymd(dmytodate($act->valid));
        $sitemap .= <<<END
    <url>
        <loc>$HOME/$aref</loc>
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
Sitemap: $HOME/$SITEMAP

END;
        return TRUE;
    } else {
        echo "Failed to update sitemap.xml.\n";
        return FALSE;
    }
}

function doTweetNew() {
    // tweet about new acts IF twitter parameters are set
    global $scat, $HOME, $TW_USER, $TW_PASS;
    $today = datetodmy(time());
    foreach ($scat->acts->acts as $act) {
        if ($act->valid == $today) {
            doTweet(
                "Värske {$act->title}: $HOME/" .
                ($act->abbr > '' ? $act->abbr :
                urlencode(str_replace(' ', '+', $act->title)))
            );
        }
    }
}

function doTweet($tweet) {
    global $TW_OAUTH;
    require_once 'lib/tmhOAuth/tmhOAuth.php';
    if (is_array($TW_OAUTH)) {
        $tmhOAuth = new tmhOAuth($TW_OAUTH);
        
        // post a message to Twitter
        echo "Tweeting: '$tweet'... ";
        $tmhOAuth->request('POST', $tmhOAuth->url('1/statuses/update'), array(
            'status' => $tweet,
        ));
        
        // return success/failure
        if ($tmhOAuth->response['code'] == 200) {
            echo "OK\n";
        } else {
            echo "FAILED\n";
        }
    }
    echo "Not tweeting '$tweet'.\n";
}
