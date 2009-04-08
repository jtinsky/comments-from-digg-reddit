<?php
/*
Plugin Name: Comments from Digg, Reddit
Plugin URI: http://valums.com/wordpress-comments-digg-reddit/
Description: This plugin imports comments about your posts from Digg and Reddit.
Version: 0.2
Author: Andrew Valums
Author URI: http://valums.com
*/
error_reporting(E_ALL);
add_action("wp", array('CommentGetter', 'init'));
class CommentGetter {
	
	// Data about the current post
	private static $postID;
	private static $postURL;	
	private static $postData = array(
		// last update attempt using time() function
		'lastAttempt' => 0,
		// last successful update (remote time)
		'lastUpdate' => array(
			'digg' => 0,
			'reddit' => 0
		)
	);	
	// Do not get comments for posts older than (days)
	private static $stopUpdatingAfter = 30; 
	// Time to recieve a request from APIs
	private static $readTimeout = 4;
	// If script excedes this time limit it will stop
	// quering Digg API for comment subtrees
	private static $maxExecutionTime = 15;
	// (seconds) How often do we need to query digg and reddit?	 
	private static $refreshInterval = 600;
	// Time offsets from gmt time used by WP
	private static $timeOffsets = array(
		'digg' => 2,
		'reddit' => -5
	);	
	// Time when init function starts
	private static $startTime;
	// Number of remote API calls
	private static $connections = 0;
	// Snoopy instance (Needed for API requests)
	private static $snoopy;
	
	static function init(){
		self::$startTime = time();
		
		// Get current Post ID
		global $post;		
		
		// Stop getting comments for posts which were modified
		// more than some time ago
		if (time() > strtotime($post->post_modified) + self::$stopUpdatingAfter * 24 * 60 * 60){
			return;			
		}

		if ($post->comment_status == 'closed'){
			return;
		}
		
		self::$postID = $post->ID;
		self::$postURL = get_permalink(self::$postID);
		
		// Get post data from post meta
		$data = get_post_meta(self::$postID, 'commentGetterData', true);
		if ( ! $data){
			// Install default data
			add_post_meta(self::$postID, 'commentGetterData', self::$postData);
		} else {
			self::$postData = $data;
		}
		
		

		// Check if we need to update comments
		if (self::$postData['lastAttempt'] < time() - self::$refreshInterval){
			
			self::$postData['lastAttempt'] = time();
			
			// Update database, so no more connections to server will open
			update_post_meta(self::$postID, 'commentGetterData', self::$postData);	
						
			self::updateComments('reddit');
			self::updateComments('digg');
			
			// Update data with new lastUpdate values
			update_post_meta(self::$postID, 'commentGetterData', self::$postData);						
		}
		
		// Fix avatars
		add_filter('get_avatar', array('CommentGetter', 'getAvatar'), 10, 5);		
	}
	
	static function getJSON($url){
		self::$connections++;
		
		// Create snoopy instance if not yet created
		if ( ! isset(self::$snoopy)){			
			require_once(ABSPATH . 'wp-includes/class-snoopy.php');
			self::$snoopy = new Snoopy;
			self::$snoopy->read_timeout = self::$readTimeout;			
		}
		
		$success = self::$snoopy->fetch($url);

		// Sometimes problem occur when headers are not separated from content
		if ($success && !self::$snoopy->timed_out && self::$snoopy->headers){
			return json_decode(self::$snoopy->results, true);
		}
				
		return false;
	}
		
	static function updateComments($type){
		switch ($type){
		
		case 'digg';
			// First we need to get page ID
			$url = 'http://services.digg.com/stories/?link=' . self::$postURL . '&appkey=http://valums.com&type=json';
			$data = self::getJSON($url);
			
			// If we have comments about this page
			if (isset($data['total']) && $data['total'] > 0){
				$diggPostID = $data['stories'][0]['id'];		
				
				// Get comments
				$url = 'http://services.digg.com/story/' . $diggPostID . '/comments?appkey=http://valums.com&type=json&count=100&sort=date-asc';
				$data = self::getJSON($url);
				
				if ( ! isset($data['count'])){
					return false;
				}
				
				// Add comments to the wordpress 
				$latest = self::addCommentTree($type, $data, 0, $diggPostID);				
			}
			break;
			
		case 'reddit';
			// First we need to get page ID
			$url = 'http://www.reddit.com/api/info.json?count=1&url=' . self::$postURL;
			$data = self::getJSON($url);
			// Check if request was successful
			if ( ! $data){
				return false;
			}
			$count = $data['data']['children'][0]['data']['num_comments'];
					
			// If we have comments about this page
			if ($count > 0){				
				
				$id = $data['data']['children'][0]['data']['id'];
				
				// Get comments
				$url = 'http://www.reddit.com/comments/' . $id . '/.json';
				$data = self::getJSON($url);			
				if ( ! $data){
					return false;	
				}
				$comments = $data[1]['data']['children'];
				
				// Add comments to the wordpress 
				$latest = self::addCommentTree($type, $comments);

			}
			break;				
		}
		
		if (isset($latest)){
			self::$postData['lastUpdate'][$type] = max($latest, self::$postData['lastUpdate'][$type]);
		}
	}
	
	/**
	 * Adds comment tree from Digg or Reddit to WP database.
	 * The diggPostID is required to iterate over the tree from Digg API
	 * Returned time is a remote server time!
	 * 
	 * @param $type digg or reddit
	 * @param $comments Comment data from APIs
	 * @param $wpParent Comment parent of the current tree in WP
	 * @param $diggPostID ID of the current post in Digg database
	 * @return The time latest comment in this tree was created 
	 */	
	static function addCommentTree($type, $comments, $wpParent = 0, $diggPostID = false){
		// When the latest commment was added to remote page
		$latest = 0;
		
		if ($type == 'digg'){
			// Check if we have comments
			if ( ! isset($comments['count']) || $comments['count'] == 0){
				return 0;
			}
			$comments  = $comments['comments'];				
		}

		foreach ($comments as $comment){
			$data = self::getCommentData($type, $comment);
										
			$latest = max($latest, intval($data['remoteTime']));
			// Convert remove time to WP time
			$wpTime = self::toWordpressTime($data['remoteTime'], $type);			

			// Check if this comment was created after our last update
			if ($data['remoteTime'] > self::$postData['lastUpdate'][$type]){
				if ($type == 'reddit' && $data['author'] == '[deleted]'){
					// do not insert deleted comments and their subtrees
					continue;
				}
				
				$commentData = array(
					'comment_parent' => $wpParent,
					'comment_date' => $wpTime,
					'comment_type' => 'comment',	
					'comment_author' => $data['author'],
					'comment_author_email' => '',
					'comment_author_url' => $data['url'],
					'comment_agent' => 'http://valums.com',
					'comment_content' => $data['content'],
					'comment_post_ID' => self::$postID
				);
								
				//$commentData['comment_approved'] = wp_allow_comment($commentData);				
				$wpCommentID = wp_insert_comment($commentData);
				
			// We don't need comment ID unless we have a subtree of comments	
			} else if ($data['replies'] > 0){
				$wpCommentID = self::getCommentByTime($wpTime);
			}				
								
			if ($data['replies'] > 0 && $wpCommentID){
				$children = self::getCommentChildren($type, $comment, $diggPostID);
				
				if ($children){	
					$latest = max($latest, self::addCommentTree($type, $children,  $wpCommentID, $diggPostID));
				}
			}			
		}
		
		return $latest;	
	}
	
	static function getCommentData($type, $comment){
		switch ($type){			
		case 'digg';
			return array(
				'remoteTime' => $comment['date'],
				'replies' => $comment['replies'],
				'author' => $comment['user'],
				'url' => 'http://digg.com/users/' . $comment['user'],
				'content' => $comment['content']
			);						
		case 'reddit';
			return array(
				'remoteTime' => $comment['data']['created'],
				'replies' => count($comment['data']['replies']),
				'author' => $comment['data']['author'],
				'url' => 'http://www.reddit.com/user/'. $comment['data']['author'] . '/',
				'content' => $comment['data']['body']
			);	
		}
	}
	
	static function getCommentChildren($type, $comment, $diggPostID){
		switch ($type){			
		case 'digg';
			// Prevent script from getting subcomments
			// if time limit was reached
			if (self::$startTime + self::$maxExecutionTime < time()){
				return 0; 
			}
						
			$url = 'http://services.digg.com/story/' . $diggPostID . '/comment/' . $comment['id'] . '/replies?appkey=http://valums.com&type=json&count=100&sort=date-asc';
			return self::getJSON($url);
		case 'reddit';
			return $comment['data']['replies']['data']['children'];
		}
	}
	
	static function getCommentByTime($time, $type = 'comment'){
		global $wpdb;
		
		$sql = "SELECT comment_ID 
				FROM $wpdb->comments
				WHERE comment_post_ID = %d 
					AND comment_date = %s
					AND comment_type = %s
				LIMIT 1";
		$sql = $wpdb->prepare($sql, self::$postID, $time, $type);
		return $wpdb->get_var($sql);	
	}
	
	static function toWordpressTime($time, $site){
		$time += self::$timeOffsets[$site] * 3600 - get_option('gmt_offset') * 3600; 
		return date("Y-m-d H:i:s", $time);
	}
	
	static function getAvatar($avatar, $comment = false, $size = '96', $default = '', $alt = false){
		if ( false === $alt)
			$safe_alt = '';
		else
			$safe_alt = attribute_escape( $alt );
	
		if ( !is_numeric($size) )
			$size = '96';

		if (is_object($comment)){
			$pattern = "/http:\/\/digg.com\/users\/(\w{4,15})/";
			
			preg_match($pattern, $comment->comment_author_url, $matches);
			if (count($matches)){
				$out = 'http://digg.com/users/' . $matches[1] . '/';
				// s - 16, m - 30, h - 120
				if ($size < 20){
					$out .= 's.png';
				} else if ($size < 40){
					$out .= 'm.png';
				} else {
					$out .= 'h.png';
				}
				return "<img alt='{$safe_alt}' src='{$out}' class='avatar avatar-{$size} photo' height='{$size}' width='{$size}' />";
			}
		}
		
		return $avatar;
	}

}
?>
