<?php

set_time_limit(0);

require_once "Cache.php";
require_once "Streamy.php";

define('TWITTER_USERNAME', '');
define('TWITTER_PASSWORD', '');

$track_list = array();

// process & store tweets & deletion notices
// this is passed as a callback function to Streamy
function store_tweet($tweet) {    

    // received a notification to delete a tweet
    if (isset($tweet['delete'])) {
        
        $ext_id = $tweet['delete']['status']['user_id'];    // twitter user ID
        $ext_item_id = $tweet['delete']['status']['id'];    // status ID
        
        // add your own code to delete tweet
        
    } else { 
            
        // add your own code to store the $tweet
        // just prints it out in this example
        print_r($tweet);
        echo "<hr>";
                
    }
}

// callback function to check for new updates to the list of keywords to track (returns either a list or false)
function refresh($return_now = null) {

    global $track_list;
    
	$cache = new Cache();
	$md5_key = md5("track_queue");
	$track_queue = $cache->get($md5_key);
	if (count($track_queue) <= 0) {
        // nothing changed.  don't reconnect
	    return false;
	}
	
	// add your own code to refresh the track_list from your DB
	$track_list = array("php","code","awesome");
	
	$track_list = array_merge($track_list,$track_queue);
	$cache->set($md5_key,array());

    return Streamy::format_post_data("track",$track_list);
}

function init() {
    global $track_list;
    
	// add your own code to refresh the track_list from your DB
    $track_list = array("php","code","awesome");
	
    return Streamy::format_post_data("track",$track_list);
}

$s = new Streamy(TWITTER_USERNAME,TWITTER_PASSWORD);
// automatically die after receiving 100 tweets
$s->limit = 10;
// set a custom TTL for the deletion cache
$s->deletion_cache_ttl = 3600;
// pass the callback functions & initial track list to Streamy
$s->track("store_tweet",init(),"refresh",30); 

?>

