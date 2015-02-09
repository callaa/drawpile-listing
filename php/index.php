<?php
error_reporting(E_ALL);
header("Cache-Control: max-age=1");

require('config.php');

$path = explode('/', rtrim($_GET['request'], '/'));

# API utilities
class ApiException extends Exception {
	public $code = 404;
	public $error = "";
	public $message = "";

	public function __construct($code, $error, $message) {
		$this->code = $code;
		$this->error = $error;
		$this->message = $message;
	}
}

function init_db() {
	$db = new PDO(PDO_ADDRESS, PDO_USERNAME, PDO_PASSWORD, array(
		\PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone='+0:00';",
		\PDO::ATTR_EMULATE_PREPARES => false,
		\PDO::ATTR_STRINGIFY_FETCHES => false
	));

	$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_WARNING);

	return $db;
}

function get_json_body() {
	if($_SERVER['CONTENT_TYPE'] !== 'application/json') {
		throw new ApiException(415, 'json', 'Body content type must be application/json, not ' . $_SERVER['CONTENT_TYPE']);
	}
	
	$json = json_decode(file_get_contents('php://input'), true);
	if($json === null) {
		throw new ApiException(400, 'json', 'Body content is not valid JSON!');
	}

	return $json;
}

function get_required($data, $key, $type) {
	if(array_key_exists($key, $data)) {
		$val = trim($data[$key]);
		settype($val, $type);
		return $val;
	} else {
		throw new ApiException(422, "BADDATA", "Missing property: " . $key);
	}
}

function get_optional($data, $key, $default, $type, &$array=null) {
	if(array_key_exists($key, $data)) {
		$val = trim($data[$key]);
		settype($val, $type);

		if($array !== null) {
			$array[$key] = $val;
		}
		return $val;

	} else {
		if($default !== null && $array !== null) {
			$array[$key] = $default;
		}

		return $default;
	}
}

function random_string() {
	# Not really cryptographically secure, but probably good enough for our use.
	$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
	$size = strlen($chars) - 1;
	$str = "";
	for($i = 0; $i < 32; $i++) {
		$str .= $chars[rand(0, $size)]; 
	}
	return $str;
}

function check_request_update_key($db, $id) {
	$sql =
'SELECT update_key
FROM drawpile_sessions
WHERE id=:id AND unlisted=0 AND last_active >= TIMESTAMPADD(MINUTE, -' . SESSION_TIMEOUT_MINUTES . ', CURRENT_TIMESTAMP)';

	$q = $db->prepare($sql);
	$q->execute(array("id" => $id));

	$session_key = $q->fetch(PDO::FETCH_NUM);
	if($session_key === FALSE) {
		throw new ApiException(404, "NOTFOUND", "Session ID not found");
	}

	if($_SERVER['HTTP_X_UPDATE_KEY'] !== $session_key[0]) {
		throw new ApiException(403, "BADKEY", "Incorrect session key");
	}
}

function validate_hostname($host) {
	# First check if the hostname is an IP address
	if(filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_IPV6)) {
		
		if(!ALLOW_PRIVATE_IP && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4|FILTER_FLAG_IPV6|FILTER_FLAG_NO_PRIV_RANGE|FILTER_FLAG_NO_RES_RANGE)) {
			throw new ApiException(422, 'LOCALIP', 'Private host address!');
		}

		return True;
	}
	
	# No? then maybe a domain name
	# http://stackoverflow.com/questions/1755144/how-to-validate-domain-name-in-php
	if(CHECK_HOSTNAME) {
		# Check that host name really exists.
		# This check is optional, because some web hosts disable the gethostbyname function
		if(!filter_var(gethostbyname($host), FILTER_VALIDATE_IP)) {
			throw new ApiException(422, 'BADDATA', 'Invalid host address');
		}
	} else {
		# Just check if the hostname looks like a real domain name
		# Note: this is slightly modified from the example in the linked SO answer.
		# The hostname should include at least one dot, so addresses like "localhost"
		# are not accepted.
		if(!preg_match("/^([a-z\d](-*[a-z\d])*)(\.([a-z\d](-*[a-z\d])*))+$/i", $host)
			|| strlen($host) < 0 || strlen($host) > 253
			|| !preg_match("/^[^\.]{1,63}(\.[^\.]{1,63})*$/", $host)) {
			throw new ApiException(422, 'BADDATA', 'Invalid host address');
		}
	}

	return True;
}

# API calls
$API_getRoot = array(
	'GET' => function($path) {
		return array(
			"api_name" => "drawpile-session-list",
			"version" => "1.0",
			"name" => LISTING_SERVER_NAME,
			"description" => LISTING_SERVER_DESCRIPTION,
			"favicon" => LISTING_SERVER_FAVICON
		);
	}
);

$API_sessions = array(
	### Get session list
	'GET' => function($path) {
		$db = init_db();

		$sql = 
'SELECT host, port, session_id AS id, protocol, owner, title, users, password, started
FROM drawpile_sessions
WHERE unlisted=0 AND last_active >= TIMESTAMPADD(MINUTE, -' . SESSION_TIMEOUT_MINUTES . ', CURRENT_TIMESTAMP)
';

		$params = array();

		if(isset($_GET['title']) && $_GET['title'] != "") {
			$sql .= " AND title LIKE :title";
			$params['title'] = '%' . str_replace('%', '', $_GET['title']) . '%';
		}

		if(isset($_GET['protocol']) && $_GET['protocol'] != "") {
			$sql .= " AND protocol=:protocol";
			$params['protocol'] = $_GET['protocol'];
		}

		$sql .= " ORDER BY title ASC";

		$q = $db->prepare($sql);
		$q->execute($params);

		$result = $q->fetchAll(PDO::FETCH_ASSOC);
		# Correct some types
		foreach($result as &$row) {
			$row['password'] = $row['password'] ? true: false;
			$row['port'] = (int)$row['port'];
			$row['users'] = (int)$row['users'];
		}
		return $result;
	},

	### Announce a new session
	'POST' => function($path) {
		$data = get_json_body();

		# Validate
		$params = array(
			'host' => get_optional($data, 'host', '', 'string'),
			'port' => get_optional($data, 'port', 27750, 'int'),
			'session_id' => get_required($data, 'id', 'string'),
			'protocol' => get_required($data, 'protocol', 'string'),
			'title' => get_required($data, 'title', 'string'),
			'users' => get_optional($data, 'users', 0, 'int'),
			'owner' => get_required($data, 'owner', 'string'),
			'password' => get_optional($data, 'password', 0, 'int'),
			'update_key' => random_string(),
			'client_ip' => $_SERVER['REMOTE_ADDR']
		);

		if($params['host'] == '') {
			# TODO check X-Forwarded-For too
			$params['host'] = $_SERVER['REMOTE_ADDR'];
		}

		validate_hostname($params['host']);

		if(!preg_match("/\\A[a-zA-Z0-9:-]{1,64}\\z/", $params['session_id'])) {
			throw new ApiException(422, 'BADDATA', 'Invalid session ID');
		}

		if($params['port'] <= 0 || $params['port'] >= 65536) {
			throw new ApiException(422, 'BADDATA', 'Invalid port number');
		}

		# Rate limiting
		$db = init_db();
		$q = $db->prepare(
'SELECT COUNT(id) FROM drawpile_sessions
WHERE client_ip=:ip AND last_active >= TIMESTAMPADD(MINUTE, -' . SESSION_TIMEOUT_MINUTES . ', CURRENT_TIMESTAMP)');

		$q->execute(array("ip" => $params['client_ip']));

		$session_count = $q->fetch(PDO::FETCH_NUM)[0];
		if($session_count >= RATE_LIMIT) {
			throw new ApiException(429, "RATELIMIT", "You have announced too many sessions (' . $session_count . ') too quickly!");
		}

		# Check for duplicates
		$q = $db->prepare(
'SELECT COUNT(id) FROM drawpile_sessions
WHERE host=:host AND port=:port AND session_id=:id AND unlisted=0 AND last_active >= TIMESTAMPADD(MINUTE, -' . SESSION_TIMEOUT_MINUTES . ', CURRENT_TIMESTAMP)');


		$q->execute(array(
			"host" => $params['host'],
			"port" => $params['port'],
			"id" => $params['session_id'],
		));

		$session_count = $q->fetch(PDO::FETCH_NUM)[0];
		if($session_count > 0) {
			throw new ApiException(429, "DUPLICATE", "Session already listed");
		}

		# Insert to database
		$q = $db->prepare(
'INSERT INTO drawpile_sessions (
	host, port, session_id, protocol, title, users, password, owner,
	update_key, client_ip, started, last_active
	)
VALUES (
	:host, :port, :session_id, :protocol, :title, :users, :password, :owner,
	:update_key, :client_ip, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
	)'
		);

		if(!$q->execute($params)) {
			throw new ApiException(500, 'error', 'database error');
		}

		return array(
			'id' => (int)$db->lastInsertId(),
			'key' => $params['update_key']
		);
	}
);

$API_session = array(
	### Refresh an announcement
	'PUT' => function($path) {
		$data = get_json_body();
		$db = init_db();
		$id = $path[1];

		# Validate input
		check_request_update_key($db, $id);

		$params = array(
			'id' => $id
		);
		get_optional($data, 'title', null, 'string', $params);
		get_optional($data, 'users', null, 'int', $params);
		get_optional($data, 'password', null, 'int', $params);
		get_optional($data, 'owner', null, 'string', $params);

		# Update
		$sql = 'UPDATE drawpile_sessions SET last_active=CURRENT_TIMESTAMP';

		if(array_key_exists('title', $params)) {
			$sql .= ", title=:title";
		}

		if(array_key_exists('users', $params)) {
			$sql .= ", users=:users";
		}

		if(array_key_exists('password', $params)) {
			$sql .= ", password=:password";
		}

		if(array_key_exists('owner', $params)) {
			$sql .= ", owner=:owner";
		}

		$sql .= " WHERE id=:id";

		$q = $db->prepare($sql);

		if(!$q->execute($params)) {
			throw new ApiException(500, 'error', 'database error');
		}

		return array("status" => "ok");
	},

	### Unlist an announcement
	'DELETE' => function($path) {
		$db = init_db();
		$id = $path[1];

		check_request_update_key($db, $id);

		$sql = 'UPDATE drawpile_sessions SET unlisted=1 WHERE id=:id';

		$q = $db->prepare($sql);

		if(!$q->execute(array("id" => $id))) {
			throw new ApiException(500, 'error', 'database error');
		}
	},
);


# Api call wrapper
function handle_apicall($handler, $path) {
	$method = $_SERVER['REQUEST_METHOD'];

	if(array_key_exists($method, $handler)) {
		try {
			if($method === 'HEAD') {
				$jsondoc = $handler['GET']($path);
			} else {
				$jsondoc = $handler[$method]($path);
			}
		} catch(ApiException $e) {
			header($_SERVER["SERVER_PROTOCOL"] . ' ' . $e->code);
			$method = 'GET';
			$jsondoc = array(
				'error' => $e->error,
				'message' => $e->message
			);
		}

		if($method !== 'DELETE') {
			$json = json_encode($jsondoc, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

			header('Content-Type: application/json');

			if($method !== 'HEAD') {
				echo $json;
			}
		}

	} else {
		# Method now allowed
		$methods = array_keys($handler);
		if(array_key_exists('GET', $handler)) {
			array_push($methods, 'HEAD');
		}
		header('Allow: ' . implode(', ', $methods));
		http_response_code(405);
	}

	exit;
}

# Router
if($path[0] === "") {
	handle_apicall($API_getRoot, $path);

} else if($path[0] === "sessions") {
	if(count($path) === 2) {
		handle_apicall($API_session, $path);
	} else if(count($path) === 1) {
		handle_apicall($API_sessions, $path);
	}
}

# No path handler found: show 404 page
header($_SERVER["SERVER_PROTOCOL"]." 404 Not Found");
echo "404 - not found";
?>
