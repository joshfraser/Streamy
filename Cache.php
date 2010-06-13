<?php

// a caching object used by Streamy for cross-process & cross-machine communication
// i used this encapsulation to make it easy to switch out memcache
// for an alternative data transport method (flat file, database, etc)
// if extending, the only required functions are ->get(key) and ->set(key, value);

class Cache extends Memcache {

	public function __construct() {
        
        // local instances
        if ($_SERVER['SERVER_ADDR'] == "127.0.0.1") {
            
            $this->addServer('localhost', 11211);
    
		// production instances
        } else {
            
            $this->addServer('localhost', 11211);

        }
    }
}

?>