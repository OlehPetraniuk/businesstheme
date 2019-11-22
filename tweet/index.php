<?php


if(empty($_POST)) { die('Only POST is allowed.'); }

require './../tweet.php';

class ezTweet {
	/*************************************** config ***************************************/

	// Enable caching
	private $cache_enabled = false;

	// Cache interval (minutes)
	private $cache_interval = 15;

	// Path to writable cache directory
	private $cache_dir = './cache/';

	// Enable debugging
	private $debug = false;

	/**************************************************************************************/

	public function __construct() {
		// Initialize paths and etc.
		$this->pathify($this->cache_dir);
		$this->pathify($this->lib);
		$this->message = '';

		// Set server-side debug params
		if($this->debug === true) {
			error_reporting(-1);
		} else {
			error_reporting(0);
		}
	}

	public function fetch() {
		echo json_encode(
			array(
				'response' => json_decode($this->getJSON(), true),
				'message' => ($this->debug) ? $this->message : false
			)
		);
	}

	private function getJSON() {
		if($this->cache_enabled === true) {
			$CFID = $this->generateCFID();
			$cache_file = $this->cache_dir.$CFID;

			if(file_exists($cache_file) && (filemtime($cache_file) > (time() - 60 * intval($this->cache_interval)))) {
				return file_get_contents($cache_file, FILE_USE_INCLUDE_PATH);
			} else {

				$JSONraw = $this->getTwitterJSON();
				$JSON = $JSONraw['response'];

				// Don't write a bad cache file if there was a CURL error
				if($JSONraw['errno'] != 0) {
					$this->consoleDebug($JSONraw['error']);
					return $JSON;
				}

				if($this->debug === true) {
					// Check for twitter-side errors
					$pj = json_decode($JSON, true);
					if(isset($pj['errors'])) {
						foreach($pj['errors'] as $error) {
							$message = 'Twitter Error: "'.$error['message'].'", Error Code #'.$error['code'];
							$this->consoleDebug($message);
						}
						return false;
					}
				}

				if(is_writable($this->cache_dir) && $JSONraw) {
					if(file_put_contents($cache_file, $JSON, LOCK_EX) === false) {
						$this->consoleDebug("Error writing cache file");
					}
				} else {
					$this->consoleDebug("Cache directory is not writable");
				}
				return $JSON;
			}
		} else {
			$JSONraw = $this->getTwitterJSON();

			if($this->debug === true) {
				// Check for CURL errors
				if($JSONraw['errno'] != 0) {
					$this->consoleDebug($JSONraw['error']);
				}

				// Check for twitter-side errors
				$pj = json_decode($JSONraw['response'], true);
				if(isset($pj['errors'])) {
					foreach($pj['errors'] as $error) {
						$message = 'Twitter Error: "'.$error['message'].'", Error Code #'.$error['code'];
						$this->consoleDebug($message);
					}
					return false;
				}
			}
			return $JSONraw['response'];
		}
    }
    private function getTwitterJSON() {
	global $consumer_key, $consumer_secret, $access_token, $access_token_secret;

		$tmhOAuth = new tmhOAuth(array(
			'host'                  => $_POST['request']['host'],
			'consumer_key'          => $consumer_key,
			'consumer_secret'       => $consumer_secret,
			'user_token'            => $access_token,
			'user_secret'           => $access_token_secret,
			'curl_ssl_verifypeer'   => false
		));

		$url = $_POST['request']['url'];
		$params = $_POST['request']['parameters'];

		$tmhOAuth->request('GET', $tmhOAuth->url($url), $params);
		return $tmhOAuth->response;
	}

	private function generateCFID() {
		// The unique cached filename ID
		return md5(serialize($_POST)).'.json';
	}

	private function pathify(&$path) {
		// Ensures our user-specified paths are up to snuff
		$path = realpath($path).'/';
	}

	private function consoleDebug($message) {
		if($this->debug === true) {
			$this->message .= 'tweet.js: '.$message."\n";
		}
	}
}

$ezTweet = new ezTweet;
$ezTweet->fetch();

// tmhOAuth.php -----------------------------------------------------------------------------------
/**
 * tmhOAuth
 *
 * An OAuth 1.0A library written in PHP.
 * The library supports file uploading using multipart/form as well as general
 * REST requests. OAuth authentication is sent using the an Authorization Header.
 *
 * @author themattharris
 * @version 0.7.4
 *
 * 19 February 2013
 */
class tmhOAuth {
  const VERSION = '0.7.4';

  var $response = array();

  /**
   * Creates a new tmhOAuth object
   *
   * @param string $config, the configuration to use for this request
   * @return void
   */
  public function __construct($config=array()) {
    $this->params = array();
    $this->headers = array();
    $this->auto_fixed_time = false;
    $this->buffer = null;

    // default configuration options
    $this->config = array_merge(
      array(
        // leave 'user_agent' blank for default, otherwise set this to
        // something that clearly identifies your app
        'user_agent'                 => '',
        // default timezone for requests
        'timezone'                   => 'UTC',

        'use_ssl'                    => true,
        'host'                       => 'api.twitter.com',

        'consumer_key'               => '',
        'consumer_secret'            => '',
        'user_token'                 => '',
        'user_secret'                => '',
        'force_nonce'                => false,
        'nonce'                      => false, // used for checking signatures. leave as false for auto
        'force_timestamp'            => false,
        'timestamp'                  => false, // used for checking signatures. leave as false for auto

        // oauth signing variables that are not dynamic
        'oauth_version'              => '1.0',
        'oauth_signature_method'     => 'HMAC-SHA1',

        // you probably don't want to change any of these curl values
        'curl_connecttimeout'        => 30,
        'curl_timeout'               => 10,

        // for security this should always be set to 2.
        'curl_ssl_verifyhost'        => 2,
        // for security this should always be set to true.
        'curl_ssl_verifypeer'        => true,

        // you can get the latest cacert.pem from here http://curl.haxx.se/ca/cacert.pem
        'curl_cainfo'                => dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cacert.pem',
        'curl_capath'                => dirname(__FILE__),

        'curl_followlocation'        => false, // whether to follow redirects or not

        // support for proxy servers
        'curl_proxy'                 => false, // really you don't want to use this if you are using streaming
        'curl_proxyuserpwd'          => false, // format username:password for proxy, if required
        'curl_encoding'              => '',    // leave blank for all supported formats, else use gzip, deflate, identity

        // streaming API
        'is_streaming'               => false,
        'streaming_eol'              => "\r\n",
        'streaming_metrics_interval' => 60,

        // header or querystring. You should always use header!
        // this is just to help me debug other developers implementations
        'as_header'                  => true,
        'debug'                      => false,
      ),
      $config
    );
    $this->set_user_agent();
    date_default_timezone_set($this->config['timezone']);
  }

  /**
   * Sets the useragent for PHP to use
   * If '$this->config['user_agent']' already has a value it is used instead of one
   * being generated.
   *
   * @return void value is stored to the config array class variable
   */
  private function set_user_agent() {
    if (!empty($this->config['user_agent']))
      return;

    if ($this->config['curl_ssl_verifyhost'] && $this->config['curl_ssl_verifypeer']) {
      $ssl = '+SSL';
    } else {
      $ssl = '-SSL';
    }

    $ua = 'tmhOAuth ' . self::VERSION . $ssl . ' - //github.com/themattharris/tmhOAuth';
    $this->config['user_agent'] = $ua;
  }
  /**
   * Generates a random OAuth nonce.
   * If 'force_nonce' is true a nonce is not generated and the value in the configuration will be retained.
   *
   * @param string $length how many characters the nonce should be before MD5 hashing. default 12
   * @param string $include_time whether to include time at the beginning of the nonce. default true
   * @return void value is stored to the config array class variable
   */
  private function create_nonce($length=12, $include_time=true) {
    if ($this->config['force_nonce'] == false) {
      $sequence = array_merge(range(0,9), range('A','Z'), range('a','z'));
      $length = $length > count($sequence) ? count($sequence) : $length;
      shuffle($sequence);

      $prefix = $include_time ? microtime() : '';
      $this->config['nonce'] = md5(substr($prefix . implode('', $sequence), 0, $length));
    }
  }