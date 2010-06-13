<?php

// a PHP client library for the twitter streaming API
// as defined at http://apiwiki.twitter.com/Streaming-API-Documentation
// written by Josh Fraser | joshfraser.com | josh@eventvue.com
// Released under Apache License 2.0

class Streamy {
    
    // user details
    public $username;
    public $password;
    public $format; // just json for now
    
    // debugging & error reporting
    public $alert_callback;
    public $logging = true;
    public $error_log = "log.txt";
    
    // connection details
    public $pid;
    public $connect_time; 
    public $disconnect_time; 
    public $total_tweet_count = 0;
    public $tweet_count;
    public $current_rate;
    public $fp;
    public $current_id;  // largest tweet-id we've seen
    
    // use memcache for async notifications
    public $cache;
    
    // stop after X tweets or X seconds
    public $limit;
    
    // cache delete notices so we can respect delete notifications even if they arrive before the tweet
    public $deletion_cache = array();
    public $deletion_cache_ttl = 3600;   // default set to 3600 secs (1 hr)
    public $gc_last_called;

    // create a new streaming object
    public function __construct($username, $password, $format = "json") {
        
        if (!isset($username) || !isset($password))
            throw new Exception('Please specify your account credentials');
            
        $this->username = $username;
        $this->password = $password;
        $this->format = strtolower($format);

        // memcache message layer
        $this->cache = new Cache();
        $this->gc_last_called = time();
    }
    
    // generate the post_data query params from an array 
    // ex "track=apple,pear,grape" or "follow=joshfraser,eventvue" 
    public static function format_post_data($type, $params_array) {
        return "{$type}=".implode(",", $params_array);
    }
    
    // returns general tweets at the following levels:
    //  - firehose: all tweets (requires contract)
    //  - gardenhose: lots of public statuses (requires contract)
    //  - spritzer: some public statuses (publically available)
    public function firehose($callback, $level = "spritzer") {
        $uri = "/{$level}.{$this->format}";
        return $this->connection_manager($callback, "GET", $uri);
    }
    
    // returns tweets from a specified subset of users at the following levels:
    //  - birdbog: up to 200,000 users (requires contract)
    //  - shadow: up to 50,000 users (requires contract)
    //  - follow: up to 200 users (publically available)
    public function follow($callback, $post_data, $level = "follow", $refresh_callback = false, $refresh_secs = false) {
        $uri = "/{$level}.{$this->format}";
        return $this->connection_manager($callback, "POST", $uri, $post_data, $refresh_callback, $refresh_secs);
    }

    // returns tweets that match a specified set of keywords at the following levels:
    //  - track: up to 50 keywords (publically available)
	//  (I'm assuming they are going to add more levels over time)
    public function track($callback, $post_data, $level = "track", $refresh_callback = false, $refresh_secs = false) {
        $uri = "/{$level}.{$this->format}";
        return $this->connection_manager($callback, "POST", $uri, $post_data, $refresh_callback, $refresh_secs);
    }
    
    //babysit the connection.  these are the gears that run the whole thing. 
    private function connection_manager($callback, $method, $uri, $post_data = false, $refresh_callback = false, $refresh_secs = 30) {
        
        // generate a random PID so we have a way to eliminate dupe connections
        $this->pid = rand(0,9999);
        $this->cache->set("pid", $this->pid);
        $this->log("new process started with an ID of ".$this->pid);
        
        $attempts = 0;
        $wait = 1;
        $params = "";
        $this->log("process started");
        do {
            if ($attempts > 0) {
                // guess how many tweets we've missed (round up 5%)
                $downtime = time() - $this->disconnect_time;
                $missed_tweets = $downtime * $this->current_rate * 1.05;
                // count param currently only works w/ Firehose, Birddog and Shadow
                if (stristr($uri, array('firehose','birddog','shadow')))
                    $params = "?count=".$missed_tweets;
                // don't assume we'll have the same error for every attempt
                $http_code = 200;
                $this->log("reconnecting.  attempt # $attempts");
            } else {
                $this->log("attempting to connect");
            }
            
            // open the connection
            $fp = fsockopen("stream.twitter.com", 80, $error_msg, $http_code);
            
            if ($fp) {
                
                $last_refresh = time();
            
                $header = "{$method} {$uri}{$params} HTTP/1.1\r\n";
                $header .= "Authorization: Basic ".base64_encode($this->username.":".$this->password)."\r\n";
                $header .= "Content-type: application/x-www-form-urlencoded\r\n";
                if ($post_data)
                    $header .= "Content-length: ".strlen($post_data)."\r\n";
                $header .= "Host: stream.twitter.com\r\n\r\n";
                fwrite($fp, $header);
                if ($post_data) {
                    $this->log($post_data);
                    fwrite($fp, $post_data);
                }
            
                // read the header & store the response code
                while($line = fgets($fp)) {
                    if (substr($line,0,7) == "HTTP/1.") {
                        preg_match("|^HTTP/[\d\.x]+ (\d+)|", $line, $resp);
                        $http_code = $resp[1];
                        $this->log("received response code: $http_code");
                    } else if ($line == "\r\n") {
                        break;
                    }
                }
            
                // reset our rate counters, # attempts & email flag
                if ($http_code{0} != '4') {
                    $this->connect_time = time();
                    $this->tweet_count = 0;
                    $attempts = 0;
                    $wait = 1;
                    // avoid sending multiple email notifications about errors
                    $notification_sent = false;
            
                    // loop indefinititely
                    while($line = fgets($fp)) {
                        
                        // ignore extra newlines & anything that doesn't look like JSON
                        if ($line == "\r\n" || $line{0} != "{")
                              continue;
           
                        // get an assoc array containing the tweet or delete notice
                        $tweet = json_decode($line,true);
                        if (isset($tweet)) {
                            // keep track of the current tweet id (not perfect by any means)
                            if (isset($tweet['id'])) {
                                
                                // quit after reading limit # of tweets -- very useful for debugging
                                // only counts real tweets (not deletes, etc)
                                if ($this->limit && $this->total_tweet_count++ >= $this->limit) {
                                    $this->log("disconnecting after $this->limit tweets");
                                    if ($this->fp)
                                        fclose($this->fp);
                                    break 2;   // break out of both loops
                                }
                                
                                $this->current_id = ($tweet['id'] > $this->current_id) ? $tweet['id'] : $this->current_id;
                            // store delete notification in a cache so we can respect twitter deletes even when out of order
                            } else if (isset($tweet['delete'])) {
                                $deleted_status_id = $tweet['delete']['status']['id'];
                                $this->deletion_cache[$deleted_status_id] = time();                             
                            } else if (isset($tweet['limit'])) {
                                $this->log("received a track limiting notice for ".$tweet['limit']['track']);
                            }
                            
                            // check the deletion cache and ignore tweet if we've previously received a deletion notice for it
                            if ($tweet['id'] && !isset($this->deletion_cache[$tweet['id']])) {
                                $callback($tweet);
                            }
                            
                        }
                        
                        // unlike total_tweet_count, this counter is reset each time you reconnect
                        // and includes deletes and other non-tweet notifications from twitter
                        $this->tweet_count++;
                        
                        // another process has started.  break out of both loops
                        // we use a memcache flag to detect this
                        $running_pid = $this->cache->get("pid");
                        if ($running_pid && $running_pid != $this->pid) {
                            $this->log("new connection detected ($running_pid).  breaking from original connection (".$this->pid.")");
                            if ($this->fp)
                                fclose($this->fp);
                            break 2;   // break out of both loops
                        }
                    
                        // check if we have new updates to the list of ppl to follow or keywords to track
                        // important to do this AFTER processing the current tweet
                        if ($refresh_callback) {
                            // throttle how often you check for new content according to $refresh_secs
                            $current_ts = time();
                            if ($current_ts - $refresh_secs > $last_refresh) {
                                $last_refresh = $current_ts;
                                $post_data = $refresh_callback();
                                // only reconnect if there are updates to the list
                                if ($post_data) {
                                    $this->log("new updates found.  breaking current connection to update");
                                    // we break out of this particular connection, but not the reconnect loop
                                    if ($this->fp)
                                        fclose($this->fp);
                                    break 1;
                                }
                            }
                        }
                        
                        // garbage collection
                        if (time() - $this->deletion_cache_ttl > $this->gc_last_called) {
                            $this->garbage_collection();
                            $this->gc_last_called = time();
                        }
                    }
                }
            }
            
            // if we get here, it means we lost our connection
            
            // executed right after we lose connection, but not on retries
            if ($this->disconnect_time == 0) {
                $this->disconnect_time = time();
                // get the # tweets/seconds before we lost our connection
                $this->current_rate();
            }
            
            // set a higher attempt cap for 4xx error codes than simple network issues
            $attempt_cap = ($http_code{0} == "4") ? 240 : 16;
            // double how long we wait before retrying up to $attempt_cap (0,2,4,8...$attempt_cap)
            $wait = (2*$wait);
            $attempts++;

            // send an admin notification if down for more than 15 minutes
            if ($wait > 900 && !$notification_sent) {
                $message = "Connection lost to twitter streaming API. Requires manual restart.";
                $this->log($message);
                // use the error reporting callback to report issue to admin
                if ($this->alert_callback) {
                    $this->alert_callback($message);
                }
                $notification_sent = true;
                break 2;
            }
        
            $this->log("waiting for $wait seconds");
            sleep(min($wait,$attempt_cap));
        
        } while (true);
    }
    
    // if logging is turned on, log to specified log file
    private function log($message) {
        if ($this->logging) {
            $message .= " at ".date("r")."\r\n";
            $fp = fopen($this->error_log,"a");  
            fputs($fp,$message);
        }
    }
    
    // average # of tweets being processed per second 
    private function current_rate() {
        $seconds_running = time() - $this->connect_time;
        // don't divide by 0.  that's bad.
        $this->current_rate = ($seconds_running > 0) ? ($this->tweet_count / $seconds_running) : $this->tweet_count;
    }
    
    // clean up old deletion notifications from the cache
    // important or you'll eventually run out of memory!!
    private function garbage_collection() {
        $deleted_count = 0;
        $delete_before = time() - $this->deletion_cache_ttl;
        foreach ($this->deletion_cache as $key => $value) {
            if ($value < $delete_before) {
                unset($this->deletion_cache[$key]);
                $deleted_count++;
            }
        }
        $this->log("Running garbage collection...  removed $deleted_count item(s).");
    }
}

?>