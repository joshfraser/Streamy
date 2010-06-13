<?php

// script to break out of the infinite loop that is holding a connection open to twitter

require_once "Cache.php";

$cache = new Cache();
$cache->set("pid", 10000);

?>