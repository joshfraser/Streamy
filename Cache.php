<?php

// a caching object used by Streamy for cross-process & cross-machine communication
// i used this encapsulation to make it easy to switch out memcache
// for an alternative data transport method (flat file, database, etc)
// if extending, the only required functions are ->get(key) and ->set(key, value);

class Cache extends Memcache {

	public function __construct() {
        
        $sn = $_SERVER['SERVER_NAME'];
        $sa = $_SERVER['SERVER_ADDR'];

        // local instances
        if ($sn == "localhost" || strpos($sn, "local.") || substr($sa,0,7) == "10.0.1." || $sa == "127.0.0.1") {
            
            $this->addServer('localhost', 11211);
    
		// production instances
        } else {
            
            $this->addServer('localhost', 11211);

        }
	}
}

?>