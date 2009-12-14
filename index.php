<?php

require_once('include.php');

// main application
$app = new wLex($_REQUEST);
echo $app->htmlOut();

?>
