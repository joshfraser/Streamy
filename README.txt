Streamy is a PHP Twitter Streaming API Library from Josh Fraser

josh@eventvue.com
http://www.onlineaspect.com

This library is released under the Apache 2.0 open-source license.

Features:

 - holds a connection open to twitter's streaming API
 - build in rate-limiting code that backs off if errors are encountered 
 - uses a callback to process tweets asynchronously as they are received
 - uses memcache as a cross-machine semaphore to make sure we only keep one connection at a time (easily changeable)
 - built in cache to properly remove tweets that receive delete notifications before the tweet arrives
 - ability to auto-quit after X number of tweets (handy for debugging)
 - logging for debugging
 - garbage collection  


