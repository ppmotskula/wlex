<?php
/**
 * Usage: php tweet.php 'your tweet'
 *
 * This script allows you to test the validity of your Twitter
 * credentials in your config.php, and tweet anything from command line.
 */

require_once('config.php');
doTweet($argv[1]);

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
    } else {
        echo "Not tweeting '$tweet'.\n";
    }
}
