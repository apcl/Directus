<?php
// Composer Autoloader
$loader = require 'vendor/autoload.php';
$loader->add("Directus", dirname(__FILE__) . "/core/");

// Non-autoload components
require dirname(__FILE__) . '/config.php';
require dirname(__FILE__) . '/core/functions.php';

// Define directus environment
defined('DIRECTUS_ENV')
    || define('DIRECTUS_ENV', (getenv('DIRECTUS_ENV') ? getenv('DIRECTUS_ENV') : 'production'));

switch (DIRECTUS_ENV) {
    case 'development_enforce_nonce':
    case 'development':
    case 'staging':
        break;
    case 'production':
    default:
        error_reporting(0);
        break;
}

$isHttps = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] != 'off';
$url = ($isHttps ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
define('HOST_URL', $url);

use Directus\Acl\Exception\UnauthorizedTableAlterException;
use Directus\Auth\Provider as Auth;
use Directus\Auth\RequestNonceProvider;
use Directus\Bootstrap;
use Directus\Db;
use Directus\Db\RowGateway\AclAwareRowGateway;
use Directus\Db\TableGateway\DirectusActivityTableGateway;
use Directus\Db\TableGateway\DirectusBookmarksTableGateway;
use Directus\Db\TableGateway\DirectusPreferencesTableGateway;
use Directus\Db\TableGateway\DirectusSettingsTableGateway;
use Directus\Db\TableGateway\DirectusUiTableGateway;
use Directus\Db\TableGateway\DirectusUsersTableGateway;
use Directus\Db\TableGateway\DirectusGroupsTableGateway;
use Directus\Db\TableGateway\DirectusMessagesTableGateway;
use Directus\Db\TableGateway\DirectusPrivilegesTableGateway;
use Directus\Db\TableGateway\DirectusMessagesRecipientsTableGateway;
use Directus\Db\TableGateway\RelationalTableGatewayWithConditions as TableGateway;
use Directus\Db\TableSchema;
use Directus\Files;
use Directus\Files\Upload;
use Directus\MemcacheProvider;
use Directus\Util;
use Directus\View\JsonView;
use Directus\View\ExceptionView;
use Directus\Db\TableGateway\DirectusIPWhitelist;
use Zend\Db\Sql\Expression;

// API Version shortcut for routes:
$v = API_VERSION;

/**
 * Slim App & Directus Providers
 */

$app = Bootstrap::get('app');
$requestNonceProvider = new RequestNonceProvider();

/**
 * Catch user-related exceptions & produce client responses.
 */

$app->config('debug', false);
$exceptionView = new ExceptionView();
$exceptionHandler = function (\Exception $exception) use ($app, $exceptionView) {
    $exceptionView->exceptionHandler($app, $exception);
};
$app->error($exceptionHandler);
// // Catch runtime erros etc. as well
// set_exception_handler($exceptionHandler);

// Routes which do not need protection by the authentication and the request
// nonce enforcement.
$authAndNonceRouteWhitelist = array(
    "auth_login",
    "auth_logout",
    "auth_session",
    "auth_clear_session",
    "auth_nonces",
    "auth_permissions",
    "debug_acl_poc",
);

$app->hook('slim.before.dispatch', function() use ($app, $requestNonceProvider, $authAndNonceRouteWhitelist) {
    /** Skip routes which don't require these protections */
    $routeName = $app->router()->getCurrentRoute()->getName();
    if(!in_array($routeName, $authAndNonceRouteWhitelist)) {
        /** Enforce required authentication. */
        if(!Auth::loggedIn()) {
            $app->halt(401, "You must be logged in to access the API.");
        }

        /** Enforce required request nonces. */
        if(!$requestNonceProvider->requestHasValidNonce()) {
            if('development' !== DIRECTUS_ENV) {
                $app->halt(401, "Invalid request (nonce).");
            }
        }

        /** Include new request nonces in the response headers */
        $response = $app->response();
        $newNonces = $requestNonceProvider->getNewNoncesThisRequest();
        $nonce_options = $requestNonceProvider->getOptions();
        $response[$nonce_options['nonce_response_header']] = implode($newNonces, ",");
    }
});

/**
 * Bootstrap Providers
 */

/**
 * @var \Zend\Db\Adapter
 */
$ZendDb = Bootstrap::get('ZendDb');

/**
 * @var \Directus\Acl
 */
$acl = Bootstrap::get('acl');

/**
 * Authentication
 */

$DirectusUsersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
Auth::setUserCacheRefreshProvider(function($userId) use ($DirectusUsersTableGateway) {
    $cacheFn = function () use ($userId, $DirectusUsersTableGateway) {
        return $DirectusUsersTableGateway->find($userId);
    };
    $cacheKey = MemcacheProvider::getKeyDirectusUserFind($userId);
    $user = $DirectusUsersTableGateway->memcache->getOrCache($cacheKey, $cacheFn, 10800);
    return $user;
});

if(Auth::loggedIn()) {
    $user = Auth::getUserRecord();
    $acl->setUserId($user['id']);
    $acl->setGroupId($user['group']);
}

/**
 * Request Payload
 */

$params = $_GET;
$requestPayload = json_decode($app->request()->getBody(), true);

/**
 * Extension Alias
 */
if(isset($_REQUEST['run_extension']) && $_REQUEST['run_extension']) {
    // Validate extension name
    $extensionName = $_REQUEST['run_extension'];
    if(!Bootstrap::extensionExists($extensionName)) {
        header("HTTP/1.0 404 Not Found");
        return JsonView::render(array('message' => 'No such extension.'));
    }
    // Validate request nonce
    if(!$requestNonceProvider->requestHasValidNonce()) {
        if('development' !== DIRECTUS_ENV) {
            header("HTTP/1.0 401 Unauthorized");
            return JsonView::render(array('message' => 'Unauthorized (nonce).'));
        }
    }
    $extensionsDirectory = APPLICATION_PATH . "/extensions";
    $responseData = require "$extensionsDirectory/$extensionName/api.php";
    $nonceOptions = $requestNonceProvider->getOptions();
    $newNonces = $requestNonceProvider->getNewNoncesThisRequest();
    header($nonceOptions['nonce_response_header'] . ': ' . implode($newNonces, ","));
    if(!is_array($responseData)) {
        throw new \RuntimeException("Extension $extensionName must return array, got " . gettype($responseData) . " instead.");
    }
    return JsonView::render($responseData);
}

/**
 * Slim Routes
 * (Collections arranged alphabetically)
 */

$app->post("/$v/auth/login/?", function() use ($app, $ZendDb, $acl, $requestNonceProvider) {

    $response = array(
        'message' => "Wrong username/password",
        'success' => false,
        'all_nonces' => $requestNonceProvider->getAllNonces()
    );

    if(Auth::loggedIn()) {
        $response['success'] = true;
        return JsonView::render($response);
    }

    $req = $app->request();
    $email = $req->post('email');
    $password = $req->post('password');
    $Users = new DirectusUsersTableGateway($acl, $ZendDb);
    $user = $Users->findOneBy('email', $email);

    // ------------------------------
    // Check if group needs whitelist
    $groupId = $user['group'];
    $directusGroupsTableGateway = new DirectusGroupsTableGateway($acl, $ZendDb);
    $group = $directusGroupsTableGateway->find($groupId);

    if (1 == $group['restrict_to_ip_whitelist']) {
        $directusIPWhitelist = new DirectusIPWhitelist($acl, $ZendDb);
        if (!$directusIPWhitelist->hasIP($_SERVER['REMOTE_ADDR'])) {
            return JsonView::render(array(
                'message' => 'Request not allowed from IP address',
                'success' => false,
                'all_nonces' => $requestNonceProvider->getAllNonces()
            ));
        }
    }

    if (!$user) {
        return JsonView::render($response);
    }

    // @todo: Login should fail on correct information when user is not active.
    $response['success'] = Auth::login($user['id'], $user['password'], $user['salt'], $password);

    // When the credentials are correct but the user is Inactive
    $userHasStatusColumn = array_key_exists(STATUS_COLUMN_NAME, $user);
    $isUserActive = false;
    if ($userHasStatusColumn && $user[STATUS_COLUMN_NAME] == STATUS_ACTIVE_NUM) {
        $isUserActive = true;
    }

    if ($response['success'] && !$isUserActive) {
        Auth::logout();
        $response['success'] = false;
        $response['message'] = 'You do not have access to this system';
        return JsonView::render($response);
    }

    if($response['success']) {
        unset($response['message']);
        $response['last_page'] = json_decode($user['last_page']);
        $set = array('last_login' => new Expression('NOW()'));
        $where = array('id' => $user['id']);
        $updateResult = $Users->update($set, $where);
        $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
        $Activity->recordLogin($user['id']);
    }
    JsonView::render($response);
})->name('auth_login');

$app->get("/$v/auth/logout(/:inactive)", function($inactive = null) use ($app) {
    if(Auth::loggedIn()) {
        Auth::logout();
    }
    if($inactive) {
      $app->redirect(DIRECTUS_PATH . "login.php?inactive=1");
    } else {
      $app->redirect(DIRECTUS_PATH . "login.php");
    }
})->name('auth_logout');

$app->get("/$v/auth/nonces/?", function() use ($app, $requestNonceProvider) {
    $all_nonces = $requestNonceProvider->getAllNonces();
    $response = array('nonces' => $all_nonces);
    JsonView::render($response);
})->name('auth_nonces');

// debug helper
$app->get("/$v/auth/session/?", function() use ($app) {
    if('production' === DIRECTUS_ENV) {
        return $app->halt('404');
    }
    JsonView::render($_SESSION);
})->name('auth_session');

// debug helper
$app->get("/$v/auth/clear-session/?", function() use ($app) {
    if('production' === DIRECTUS_ENV) {
        return $app->halt('404');
    }

    $_SESSION = array();
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
    JsonView::render($_SESSION);
})->name('auth_clear_session');

// debug helper
$app->post("/$v/auth/forgot-password/?", function() use ($app, $acl, $ZendDb) {
    if(!isset($_POST['email'])) {
        return JsonView::render(array(
            'success' => false,
            'message' => 'Invalid email address.'
        ));
    }

    $DirectusUsersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
    $user = $DirectusUsersTableGateway->findOneBy('email', $_POST['email']);

    if(false === $user) {
        return JsonView::render(array(
            'success' => false,
            'message' => "An account with that email address doesn't exist."
        ));
    }

    $password = uniqid();
    $set = array();
    $set['salt'] = uniqid();
    $set['password'] = Auth::hashPassword($password, $set['salt']);

    // Skip ACL
    $DirectusUsersTableGateway = new \Zend\Db\TableGateway\TableGateway('directus_users', $ZendDb);
    $affectedRows = $DirectusUsersTableGateway->update($set, array('id' => $user['id']));

    if(1 !== $affectedRows) {
        return JsonView::render(array(
            'success' => false
        ));
    }

    $mail = new Directus\Mail\Mailer();
    $mail->send(new Directus\Mail\ForgotPasswordMail($user['email'], $password));

    $success = true;
    return JsonView::render(array(
        'success' => $success
    ));

})->name('auth_permissions');

// debug helper
$app->get("/$v/auth/permissions/?", function() use ($app, $acl) {
    if('production' === DIRECTUS_ENV) {
        return $app->halt('404');
    }
    $groupPrivileges = $acl->getGroupPrivileges();
    JsonView::render(array('groupPrivileges' => $groupPrivileges));
})->name('auth_permissions');

$app->post("/$v/hash/?", function() use ($app) {
    if(!(isset($_POST['password']) && !empty($_POST['password']))) {
        return JsonView::render(array(
            'success' => false,
            'message' => 'Must provide password.'
        ));
    }
    $salt = isset($_POST['salt']) && !empty($_POST['salt']) ? $_POST['salt'] : '';
    $hashedPassword = Auth::hashPassword($_POST['password'], $salt);
    return JsonView::render(array(
        'success' => true,
        'password' => $hashedPassword
    ));
});

$app->get("/$v/privileges/:groupId/", function ($groupId) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserRecord();
    $myGroupId = $currentUser['group'];

    if ($myGroupId != 1) {
        throw new Exception('Permission denied');
    }

    $privileges = new DirectusPrivilegesTableGateway($acl, $ZendDb);;
    $response = $privileges->fetchPerTable($groupId);

    return JsonView::render($response);
});

$app->map("/$v/privileges/:groupId/?", function ($groupId) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserRecord();
    $myGroupId = $currentUser['group'];

    if ($myGroupId != 1) {
        throw new Exception('Permission denied');
    }

    if(isset($requestPayload['addTable'])) {
      $isTableNameAlphanumeric = preg_match("/[a-z0-9]+/i", $requestPayload['table_name']);
      $zeroOrMoreUnderscoresDashes = preg_match("/[_-]*/i", $requestPayload['table_name']);
      if (!($isTableNameAlphanumeric && $zeroOrMoreUnderscoresDashes)) {
          $app->response->setStatus(400);
          return JsonView::render(array('message'=> 'Invalid table name'));
      }
      unset($requestPayload['addTable']);
      try{
        $statusColumnName = STATUS_COLUMN_NAME;
        $createTableQuery = "CREATE TABLE `{$requestPayload['table_name']}` (
            id int(11) unsigned NOT NULL AUTO_INCREMENT,
            `{$statusColumnName}` tinyint(1) unsigned DEFAULT NULL,
            PRIMARY KEY(id)
        );";
        $ZendDb->query($createTableQuery, $ZendDb::QUERY_MODE_EXECUTE);
      }catch(\Exception $e){
      }
    }

    $privileges = new DirectusPrivilegesTableGateway($acl, $ZendDb);;
    $response = $privileges->insertPrivilege($requestPayload);

    return JsonView::render($response);
})->via('POST');

$app->map("/$v/privileges/:groupId/:privilegeId", function ($groupId, $privilegeId) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserRecord();
    $myGroupId = $currentUser['group'];

    if ($myGroupId != 1) {
        throw new Exception('Permission denied');
    }

    $privileges = new DirectusPrivilegesTableGateway($acl, $ZendDb);;

    if(isset($requestPayload['activeState'])) {
      if($requestPayload['activeState'] !== 'all') {
        $priv = $privileges->findByStatus($requestPayload['table_name'], $requestPayload['group_id'], $requestPayload['activeState']);
        if($priv) {
          $requestPayload['id'] = $priv['id'];
          $requestPayload['status_id'] = $priv['status_id'];
        } else {
          unset($requestPayload['id']);
          $requestPayload['status_id'] = $requestPayload['activeState'];
          $response = $privileges->insertPrivilege($requestPayload);
          return JsonView::render($response);
        }
      }
    }

    $response = $privileges->updatePrivilege($requestPayload);

    return JsonView::render($response);
})->via('PUT');

/**
 * ENTRIES COLLECTION
 */

$app->map("/$v/tables/:table/rows/?", function ($table) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserInfo();
    $id = null;
    $params['table_name'] = $table;
    $TableGateway = new TableGateway($acl, $table, $ZendDb);

    // any CREATE requests should md5 the email
    if("directus_users" === $table &&
       in_array($app->request()->getMethod(), array('POST')) &&
       array_key_exists('email',$requestPayload)
       ) {
        $avatar = DirectusUsersTableGateway::get_avatar($requestPayload['email']);
        $requestPayload['avatar'] = $avatar;
    }

    switch($app->request()->getMethod()) {
        // POST one new table entry
        case 'POST':
            $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
            $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;
            $newRecord = $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
            $params['id'] = $newRecord['id'];
            break;
        // PUT a change set of table entries
        case 'PUT':
            if(!is_numeric_array($requestPayload)) {
                $params['id'] = $requestPayload['id'];
                $requestPayload = array($requestPayload);
            }
            $TableGateway->updateCollection($requestPayload);
            break;
    }
    // GET all table entries
    $Table = new TableGateway($acl, $table, $ZendDb);
    $entries = $Table->getEntries($params);
    JsonView::render($entries);
})->via('GET', 'POST', 'PUT');

$app->get("/$v/tables/:table/typeahead/?", function($table, $query = null) use ($ZendDb, $acl, $params, $app) {
  $Table = new TableGateway($acl, $table, $ZendDb);

  if(!isset($params['columns'])) {
    $params['columns'] = '';
  }
  if(!array_key_exists('include_inactive', $params)) {
    $params[STATUS_COLUMN_NAME] = STATUS_ACTIVE_NUM;
  }

  $columns = ($params['columns']) ? explode(',', $params['columns']) : array();
  if(count($columns) > 0) {
    $params['group_by'] = $columns[0];

    if(isset($params['q'])) {
      $params['adv_where'] = "`{$columns[0]}` like '%{$params['q']}%'";
      $params['perPage'] = 50;
    }
  }

  if(!$query) {
    $entries = $Table->getEntries($params);
  }

  $entries = $entries['rows'];
  $response = [];
  foreach($entries as $entry) {
    $val = '';
    $tokens = array();
    foreach($columns as $col) {
      array_push($tokens, $entry[$col]);
    }
    $val = implode(' ', $tokens);
    array_push($response, array('value'=> $val, 'tokens'=> $tokens, 'id'=>$entry['id']));
  }
  JsonView::render($response);
});

$app->map("/$v/tables/:table/rows/:id/?", function ($table, $id) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserInfo();
    $params['table_name'] = $table;

    // any UPDATE requests should md5 the email
    if("directus_users" === $table &&
       in_array($app->request()->getMethod(), array('PUT', 'PATCH')) &&
       array_key_exists('email',$requestPayload)
       ) {
        $avatar = DirectusUsersTableGateway::get_avatar($requestPayload['email']);
        $requestPayload['avatar'] = $avatar;
    }

    $TableGateway = new TableGateway($acl, $table, $ZendDb);
    switch($app->request()->getMethod()) {
        // PUT an updated table entry
        case 'PATCH':
        case 'PUT':
            $requestPayload['id'] = $id;
            $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
            $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;
            $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
            break;
        // DELETE a given table entry
        case 'DELETE':
            echo $TableGateway->delete(array('id' => $id));
            return;
    }
    $params['id'] = $id;
    // GET a table entry
    $Table = new TableGateway($acl, $table, $ZendDb);
    $entries = $Table->getEntries($params);
    JsonView::render($entries);
})->via('DELETE', 'GET', 'PUT','PATCH');

/**
 * ACTIVITY COLLECTION
 */

// @todo: create different activity endpoints
// ex: /activity/:table, /activity/recents/:days
$app->get("/$v/activity/?", function () use ($params, $ZendDb, $acl) {
    $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
    $datetime = new DateTime('NOW', new DateTimeZone('GMT'));
    $datetime->modify('-30 days');
    // @todo move this to backbone collection
    if (!$params['adv_search']) {
      unset($params['perPage']);
      $params['adv_search'] = "datetime >= '" . $datetime->format('Y-m-d H:i:s') . "'";
    }
    $new_get = $Activity->fetchFeed($params);
    $new_get['active'] = $new_get['total'];
    JsonView::render($new_get);
});

/**
 * COLUMNS COLLECTION
 */

// GET all table columns, or POST one new table column

$app->map("/$v/tables/:table/columns/?", function ($table_name) use ($ZendDb, $params, $requestPayload, $app, $acl) {
    $params['table_name'] = $table_name;
    if($app->request()->isPost()) {
        /**
         * @todo  check if a column by this name already exists
         * @todo  build this into the method when we shift its location to the new layer
         */
        if(!$acl->hasTablePrivilege($table_name, 'alter')) {
            throw new UnauthorizedTableAlterException("Table alter access forbidden on table `$table_name`");
        }

        $tableGateway = new TableGateway($acl, $table_name, $ZendDb);
        $params['column_name'] = $tableGateway->addColumn($table_name, $requestPayload);
    }

    $response = TableSchema::getSchemaArray($table_name, $params);
    JsonView::render($response);
})->via('GET', 'POST');

// GET or PUT one column

$app->map("/$v/tables/:table/columns/:column/?", function ($table, $column) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    $params['column_name'] = $column;
    $params['table_name'] = $table;
    // This `type` variable is used on the client-side
    // Not need on server side.
    // @TODO: We should probably stop using it on the client-side
    unset($requestPayload['type']);
    // Add table name to dataset. @TODO more clarification would be useful
    // Also This would return an Error because of $row not always would be an array.
    foreach ($requestPayload as &$row) {
        if(is_array($row)) {
          $row['table_name'] = $table;
        }
    }
    if($app->request()->isPut()) {
        $TableGateway = new TableGateway($acl, 'directus_columns', $ZendDb);
        $TableGateway->updateCollection($requestPayload);
    }
    $response = TableSchema::getSchemaArray($table, $params);
    JsonView::render($response);
})->via('GET', 'PUT');

$app->post("/$v/tables/:table/columns/:column/?", function ($table, $column) use ($ZendDb, $acl, $requestPayload, $app) {
  $TableGateway = new TableGateway($acl, 'directus_columns', $ZendDb);
  $data = $requestPayload;
  // @TODO: check whether this condition is still needed
  if(isset($data['type'])) {
    $data['data_type'] = $data['type'];
    $data['relationship_type'] = $data['type'];
    unset($data['type']);
  }
  //$data['column_name'] = $data['junction_key_left'];
  $data['column_name'] = $column;
  $data['table_name'] = $table;
  $row = $TableGateway->findOneByArray(array('table_name'=>$table, 'column_name'=>$column));

  if ($row) {
    $data['id'] = $row['id'];
  }
  $newRecord = $TableGateway->manageRecordUpdate('directus_columns', $data, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
  $_POST['id'] = $newRecord['id'];
  JsonView::render($_POST);
});
/**
 * GROUPS COLLECTION
 */

/** (Optional slim route params break when these two routes are merged) */

$app->map("/$v/groups/?", function () use ($app, $ZendDb, $acl, $requestPayload) {
    // @TODO need PUT
    $GroupsTableGateway = new TableGateway($acl, 'directus_groups', $ZendDb);
    $tableName =  'directus_groups';
    $GroupsTableGateway = new TableGateway($acl, $tableName, $ZendDb);
    $currentUser = Auth::getUserInfo();
    switch ($app->request()->getMethod()) {
      case "POST":
        $newRecord = $GroupsTableGateway->manageRecordUpdate($tableName, $requestPayload);
        $newGroupId = $newRecord['id'];
        $newGroup = $GroupsTableGateway->find($newGroupId);
        $outputData = $newGroup;
        break;
      case "GET":
      default:
        $get_new = $GroupsTableGateway->getEntries();
        $outputData = $get_new;
    }

    JsonView::render($outputData);
})->via('GET','POST');

$app->get("/$v/groups/:id/?", function ($id = null) use ($ZendDb, $acl) {
    // @TODO need POST and PUT
    // Hardcoding ID temporarily
    is_null($id) ? $id = 1 : null;
    $Groups = new TableGateway($acl, 'directus_groups', $ZendDb);
    $get_new = $Groups->find($id);
    JsonView::render($get_new);
});

/**
 * FILES COLLECTION
 */

$app->map("/$v/files(/:id)/?", function ($id = null) use ($app, $ZendDb, $acl, $params, $requestPayload) {
    if(!is_null($id))
        $params['id'] = $id;

    $table = "directus_files";
    $currentUser = Auth::getUserInfo();
    $TableGateway = new TableGateway($acl, $table, $ZendDb);
    $activityLoggingEnabled = !(isset($_GET['skip_activity_log']) && (1 == $_GET['skip_activity_log']));
    $activityMode = $activityLoggingEnabled ? TableGateway::ACTIVITY_ENTRY_MODE_PARENT : TableGateway::ACTIVITY_ENTRY_MODE_DISABLED;

    switch ($app->request()->getMethod()) {
      case "POST":
        $requestPayload['user'] = $currentUser['id'];
        $requestPayload['date_uploaded'] = gmdate('Y-m-d H:i:s');

        // When the file is uploaded there's not a data key
        if (array_key_exists('data', $requestPayload)) {
            $Storage = new Files\Storage\Storage();
            $recordData = $Storage->saveData($requestPayload['data'], $requestPayload['name']);
            $requestPayload = array_merge($requestPayload, $recordData);
        }
        $newRecord = $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
        $params['id'] = $newRecord['id'];
        break;
      case "PATCH":
        $requestPayload['id'] = $id;
      case "PUT":
        if (!is_null($id)) {
          $TableGateway->manageRecordUpdate($table, $requestPayload, $activityMode);
          break;
        }
    }

    $Files = new TableGateway($acl, $table, $ZendDb);
    $get_new = $Files->getEntries($params);

    if (array_key_exists('rows', $get_new)) {
        foreach ($get_new['rows'] as &$row) {
          if(isset($row['date_uploaded'])) {
            $row['date_uploaded'] .= ' UTC';
          }
        }
    } else {
      if(isset($get_new['date_uploaded'])) {
        $get_new['date_uploaded'] .= ' UTC';
      }
    }

    JsonView::render($get_new);
})->via('GET','PATCH','POST','PUT');

/**
 * PREFERENCES COLLECTION
 */

$app->map("/$v/tables/:table/preferences/?", function($table) use ($ZendDb, $acl, $params, $requestPayload, $app) {
    $currentUser = Auth::getUserInfo();
    $params['table_name'] = $table;
    $Preferences = new DirectusPreferencesTableGateway($acl, $ZendDb);
    $TableGateway = new TableGateway($acl, 'directus_preferences', $ZendDb);
    switch ($app->request()->getMethod()) {
        case "PUT":
            $TableGateway->manageRecordUpdate('directus_preferences', $requestPayload, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
            break;
        case "POST":
            //If Already exists and not saving with title, then updateit!
            $existing = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $table, isset($requestPayload['title']) ? $requestPayload['title'] : null);
            if(!empty($existing)) {
              $requestPayload['id'] = $existing['id'];
            }
            $requestPayload['user'] = $currentUser['id'];
            $id = $TableGateway->manageRecordUpdate('directus_preferences', $requestPayload, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
            break;
        case "DELETE":
            if($requestPayload['user'] != $currentUser['id']) {
              return;
            }

            if(isset($requestPayload['id'])) {
              echo $TableGateway->delete(array('id' => $requestPayload['id']));
            } else if(isset($requestPayload['title']) && isset($requestPayload['table_name'])) {
              $jsonResponse = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $requestPayload['table_name'], $requestPayload['title']);
              if($jsonResponse['id']) {
                echo $TableGateway->delete(array('id' => $jsonResponse['id']));
              } else {
                echo 1;
              }
            }

            return;
    }

    //If Title is set then return this version
    if(isset($requestPayload['title'])) {
      $params['newTitle'] = $requestPayload['title'];
    }

    if(isset($params['newTitle'])) {
        $jsonResponse = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $table, $params['newTitle']);
        // $Preferences->updateDefaultByName($currentUser['id'], $table, $jsonResponse);
    } else {
        $jsonResponse = $Preferences->fetchByUserAndTableAndTitle($currentUser['id'], $table);
    }
    JsonView::render($jsonResponse);
})->via('GET','POST','PUT', 'DELETE');

$app->get("/$v/preferences/:table", function($table) use ($app, $ZendDb, $acl) {
  $currentUser = Auth::getUserInfo();
  $params['table_name'] = $table;
  $Preferences = new DirectusPreferencesTableGateway($acl, $ZendDb);
  $jsonResponse = $Preferences->fetchSavedPreferencesByUserAndTable($currentUser['id'], $table);
  JsonView::render($jsonResponse);
});

/**
 * BOOKMARKS COLLECTION
 */

$app->map("/$v/bookmarks(/:id)/?", function($id = null) use ($params, $app, $ZendDb, $acl, $requestPayload) {
  $currentUser = Auth::getUserInfo();
  $bookmarks = new DirectusBookmarksTableGateway($acl, $ZendDb);
  switch ($app->request()->getMethod()) {
    case "PUT":
      $bookmarks->updateBookmark($requestPayload);
      $id = $requestPayload['id'];
      break;
    case "POST":
      $requestPayload['user'] = $currentUser['id'];
      $id = $bookmarks->insertBookmark($requestPayload);
      break;
    case "DELETE":
      $bookmark = $bookmarks->fetchByUserAndId($currentUser['id'], $id);
      if($bookmark) {
        echo $bookmarks->delete(array('id' => $id));
      }
      return;
  }
  $jsonResponse = $bookmarks->fetchByUserAndId($currentUser['id'], $id);
  JsonView::render($jsonResponse);
})->via('GET', 'POST', 'PUT', 'DELETE');

/**
 * REVISIONS COLLECTION
 */

$app->get("/$v/tables/:table/rows/:id/revisions/?", function($table, $id) use ($acl, $ZendDb, $params) {
    $params['table_name'] = $table;
    $params['id'] = $id;
    $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
    $revisions = $Activity->fetchRevisions($id, $table);
    JsonView::render($revisions);
});

/**
 * SETTINGS COLLECTION
 */

$app->map("/$v/settings(/:id)/?", function ($id = null) use ($acl, $ZendDb, $params, $requestPayload, $app) {

    $Settings = new DirectusSettingsTableGateway($acl, $ZendDb);

    switch ($app->request()->getMethod()) {
        case "POST":
        case "PUT":
            $data = $requestPayload;
            unset($data['id']);
            $Settings->setValues($id, $data);
            break;
    }

    $settings_new = $Settings->fetchAll();
    $get_new = is_null($id) ? $settings_new : $settings_new[$id];

    JsonView::render($get_new);
})->via('GET','POST','PUT');

// GET and PUT table details
$app->map("/$v/tables/:table/?", function ($table) use ($ZendDb, $acl, $params, $requestPayload, $app) {
  $TableGateway = new TableGateway($acl, 'directus_tables', $ZendDb,null,null,null,'table_name');
  $ColumnsTableGateway = new TableGateway($acl, 'directus_columns', $ZendDb);
  /* PUT updates the table */
  if($app->request()->isPut()) {
    $data = $requestPayload;
    $table_settings = array(
      'table_name' => $data['table_name'],
      'hidden' => (int)$data['hidden'],
      'single' => (int)$data['single'],
      'is_junction_table' => (int)$data['is_junction_table'],
      'footer' => (int)$data['footer'],
      'primary_column' => array_key_exists('primary_column', $data) ? $data['primary_column'] : ''
    );

    //@TODO: Possibly pretty this up so not doing direct inserts/updates
    $set = $TableGateway->select(array('table_name' => $table))->toArray();

    //If item exists, update, else insert
    if(count($set) > 0) {
      $TableGateway->update($table_settings, array('table_name' => $table));
    } else {
      $TableGateway->insert($table_settings);
    }

    $column_settings = array();
    foreach ($data['columns'] as $col) {
      $columnData = array(
        'table_name' => $table,
        'column_name'=>$col['column_name'],
        'ui'=>$col['ui'],
        'hidden_input'=>$col['hidden_input'],
        'required'=>$col['required'],
        'master'=>$col['master'],
        'sort'=> array_key_exists('sort', $col) ? $col['sort'] : 99999,
        'comment'=> array_key_exists('comment', $col) ? $col['comment'] : ''
      );

      $existing = $ColumnsTableGateway->select(array('table_name' => $table, 'column_name' => $col['column_name']))->toArray();
      if(count($existing) > 0) {
        $columnData['id'] = $existing[0]['id'];
      }
      array_push($column_settings, $columnData);
    }
    $ColumnsTableGateway->updateCollection($column_settings);
  }
  $response = TableSchema::getTable($table);
  JsonView::render($response);
})->via('GET', 'PUT')->name('table_meta');

/**
 * UPLOAD COLLECTION
 */

$app->post("/$v/upload/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    // $Transfer = new Files\Transfer();
    $Storage = new Files\Storage\Storage();
    $result = array();
    foreach ($_FILES as $file) {
        // $fileData = $Transfer->acceptFile($file['tmp_name'], $file['name']);
        $fileData = array('caption'=>'','tags'=>'','location'=>'');
        $fileData = array_merge($fileData, $Storage->acceptFile($file['tmp_name'], $file['name']));
        $result[] = array(
            'type' => $fileData['type'],
            'name' => $fileData['name'],
            'title' => $fileData['title'],
            'tags' => $fileData['tags'],
            'caption' => $fileData['caption'],
            'location' => $fileData['location'],
            'charset' => $fileData['charset'],
            'size' => $fileData['size'],
            'width' => $fileData['width'],
            'height' => $fileData['height'],
            'date_uploaded' => $fileData['date_uploaded'] . ' UTC',
            'storage_adapter' => $fileData['storage_adapter']
        );
    }
    JsonView::render($result);
});

$app->post("/$v/upload/link/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $Storage = new Files\Storage\Storage();
    $result = array();
    if(isset($_POST['link'])) {
        $fileData = array('caption'=>'','tags'=>'','location'=>'');
        $fileData = array_merge($fileData, $Storage->acceptLink($_POST['link']));

        $result[] = array(
            'type' => $fileData['type'],
            'name' => $fileData['name'],
            'title' => $fileData['title'],
            'tags' => $fileData['tags'],
            'caption' => $fileData['caption'],
            'location' => $fileData['location'],
            'charset' => $fileData['charset'],
            'size' => $fileData['size'],
            'width' => $fileData['width'],
            'height' => $fileData['height'],
            'url' => (isset($fileData['url'])) ? $fileData['url'] : '',
            'data' => (isset($fileData['data'])) ? $fileData['data'] : null
            //'date_uploaded' => $fileData['date_uploaded'] . ' UTC',
            //'storage_adapter' => $fileData['storage_adapter']
        );
    }
    JsonView::render($result);
});

$app->get("/$v/messages/rows/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();

    if (isset($_GET['max_id'])) {
        $messagesRecipientsTableGateway = new DirectusMessagesRecipientsTableGateway($acl, $ZendDb);
        $ids = $messagesRecipientsTableGateway->getMessagesNewerThan($_GET['max_id'], $currentUser['id']);
        if (sizeof($ids) > 0) {
            $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
            $result = $messagesTableGateway->fetchMessagesInboxWithHeaders($currentUser['id'], $ids);
            return JsonView::render($result);
        } else {
            $result = $messagesRecipientsTableGateway->countMessages($currentUser['id']);
            return JsonView::render($result);
        }
    }

    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $result = $messagesTableGateway->fetchMessagesInboxWithHeaders($currentUser['id']);
    JsonView::render($result);
});

$app->get("/$v/messages/rows/:id/?", function ($id) use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();
    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $message = $messagesTableGateway->fetchMessageWithRecipients($id, $currentUser['id']);

    if (!isset($message)) {
        header("HTTP/1.0 404 Not Found");
        return JsonView::render(array('message' => 'Message not found.'));
    }

    JsonView::render($message);
});

$app->map("/$v/messages/rows/:id/?", function ($id) use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();
    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $messagesRecipientsTableGateway = new DirectusMessagesRecipientsTableGateway($acl, $ZendDb);

    $message = $messagesTableGateway->fetchMessageWithRecipients($id, $currentUser['id']);

    $ids = array($message['id']);
    $message['read'] = '1';

    foreach ($message['responses']['rows'] as &$response) {
        $ids[] = $response['id'];
        $response['read'] = "1";
    }

    $messagesRecipientsTableGateway->markAsRead($ids, $currentUser['id']);

    JsonView::render($message);
})->via('PATCH');

$app->post("/$v/messages/rows/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $currentUser = Auth::getUserInfo();

    // Unpack recipients
    $recipients = explode(',', $requestPayload['recipients']);
    $groupRecipients = array();
    $userRecipients = array();

    foreach($recipients as $recipient) {
        $typeAndId = explode('_', $recipient);
        if ($typeAndId[0] == 0) {
            $userRecipients[] = $typeAndId[1];
        } else {
            $groupRecipients[] = $typeAndId[1];
        }
    }

    if (count($groupRecipients) > 0) {
        $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
        $result = $usersTableGateway->findActiveUserIdsByGroupIds($groupRecipients);
        foreach($result as $item) {
            $userRecipients[] = $item['id'];
        }
    }

    $userRecipients[] = $currentUser['id'];

    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $id = $messagesTableGateway->sendMessage($requestPayload, array_unique($userRecipients), $currentUser['id']);

    if($id) {
      $Activity = new DirectusActivityTableGateway($acl, $ZendDb);
      $requestPayload['id'] = $id;
      $Activity->recordMessage($requestPayload, $currentUser['id']);
    }

    $mail = new Directus\Mail\Mailer();
    foreach($userRecipients as $recipient) {
        $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
        $user = $usersTableGateway->findOneBy('id', $recipient);

        if(isset($user) && $user['email_messages'] == 1) {
            $messageNotificationMail = new Directus\Mail\NotificationMail(
                                    $user['email'],
                                    $requestPayload['subject'],
                                    $requestPayload['message']
                                );

            $mail->send($messageNotificationMail);
            $mail->ClearAllRecipients();
        }
    }

    $message = $messagesTableGateway->fetchMessageWithRecipients($id, $currentUser['id']);

    JsonView::render($message);
});

$app->get("/$v/messages/recipients/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    $tokens = explode(' ', $_GET['q']);

    $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
    $users = $usersTableGateway->findUserByFirstOrLastName($tokens);

    $groupsTableGateway = new DirectusGroupsTableGateway($acl, $ZendDb);
    $groups = $groupsTableGateway->findUserByFirstOrLastName($tokens);

    $result = array_merge($groups, $users);

    JsonView::render($result);
});

$app->post("/$v/comments/?", function() use ($params, $requestPayload, $app, $acl, $ZendDb) {
  $currentUser = Auth::getUserInfo();
  $params['table_name'] = "directus_messages";
  $TableGateway = new TableGateway($acl, "directus_messages", $ZendDb);

  $groupRecipients = array();
  $userRecipients = array();

  preg_match_all('/@\[.*? /', $requestPayload['message'], $results);
  $results = $results[0];

  if(count($results) > 0) {
    foreach($results as $result) {
      $result = substr($result, 2);
      $typeAndId = explode('_', $result);
      if ($typeAndId[0] == 0) {
          $userRecipients[] = $typeAndId[1];
      } else {
          $groupRecipients[] = $typeAndId[1];
      }
    }

    if (count($groupRecipients) > 0) {
      $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
      $result = $usersTableGateway->findActiveUserIdsByGroupIds($groupRecipients);
      foreach($result as $item) {
        $userRecipients[] = $item['id'];
      }
    }

    $messagesTableGateway = new DirectusMessagesTableGateway($acl, $ZendDb);
    $id = $messagesTableGateway->sendMessage($requestPayload, array_unique($userRecipients), $currentUser['id']);
    $params['id'] = $id;

    preg_match_all('/@\[.*?\]/', $requestPayload['message'], $results);
    $messageBody = $requestPayload['message'];
    $results = $results[0];

    $recipientString = "";
    $len = count($results);
    $i = 0;
    foreach($results as $result) {
      $newresult = substr($result, 0, -1);
      $newresult = substr($newresult, strpos($newresult, " ") + 1);
      $messageBody = str_replace($result, $newresult, $messageBody);

      if($i == $len - 1) {
        if($i > 0) {
          $recipientString.=" and ".$newresult;
        } else {
          $recipientString.=$newresult;
        }
      } else {
        $recipientString.=$newresult.", ";
      }
      $i++;
    }

    $mail = new Directus\Mail\Mailer();
    foreach($userRecipients as $recipient) {
        $usersTableGateway = new DirectusUsersTableGateway($acl, $ZendDb);
        $user = $usersTableGateway->findOneBy('id', $recipient);

        if(isset($user) && $user['email_messages'] == 1) {
            $NotificationMail = new Directus\Mail\NotificationMail(
                                    $user['email'],
                                    $requestPayload['subject'],
                                    $requestPayload['message']
                                );

            $mail->send($NotificationMail);
      }
    }
  }

  $newRecord = $TableGateway->manageRecordUpdate("directus_messages", $requestPayload, TableGateway::ACTIVITY_ENTRY_MODE_DISABLED);
  $params['id'] = $newRecord['id'];

  // GET all table entries
  $entries = $TableGateway->getEntries($params);
  JsonView::render($entries);
});

/**
 * EXCEPTION LOG
 */
$app->post("/$v/exception/?", function () use ($params, $requestPayload, $app, $acl, $ZendDb) {
    print_r($requestPayload);die();
    $data = array(
        'server_addr'   =>$_SERVER['SERVER_ADDR'],
        'server_port'   =>$_SERVER['SERVER_PORT'],
        'user_agent'    =>$_SERVER['HTTP_USER_AGENT'],
        'http_host'     =>$_SERVER['HTTP_HOST'],
        'request_uri'   =>$_SERVER['REQUEST_URI'],
        'remote_addr'   =>$_SERVER['REMOTE_ADDR'],
        'page'          =>$requestPayload['page'],
        'message'       =>$requestPayload['message'],
        'user_email'    =>$requestPayload['user_email'],
        'type'          =>$requestPayload['type']
    );

    $ctx = stream_context_create(array(
        'http' => array(
            'method' => 'POST',
            'content' => "json=".json_encode($data)."&details=".$requestPayload['details']
        ))
    );

    $fp = @fopen($url, 'rb', false, $ctx);

    if (!$fp) {
        $response = "Failed to log error. File pointer could not be initialized.";
        $app->getLog()->warn($response);
    }

    $response = @stream_get_contents($fp);

    if ($response === false) {
        $response = "Failed to log error. stream_get_contents failed.";
        $app->getLog()->warn($response);
    }

    $result = array('response'=>$response);

    JsonView::render($result);
});

/**
 * UI COLLECTION
 */

$app->map("/$v/tables/:table/columns/:column/:ui/?", function($table, $column, $ui) use ($acl, $ZendDb, $params, $requestPayload, $app) {
    $TableGateway = new TableGateway($acl, 'directus_ui', $ZendDb);
    switch ($app->request()->getMethod()) {
      case "PUT":
      case "POST":
        $keys = array('table_name' => $table, 'column_name' => $column, 'ui_name' => $ui);
        $uis = to_name_value($requestPayload, $keys);

        $column_settings = array();
        foreach ($uis as $col) {
          $existing = $TableGateway->select(array('table_name' => $table, 'column_name' => $column, 'ui_name'=>$ui, 'name'=>$col['name']))->toArray();
          if(count($existing) > 0) {
            $col['id'] = $existing[0]['id'];
          }
          array_push($column_settings, $col);
        }
        $TableGateway->updateCollection($column_settings);
    }
    $UiOptions = new DirectusUiTableGateway($acl, $ZendDb);
    $get_new = $UiOptions->fetchOptions($table, $column, $ui);
    JsonView::render($get_new);
})->via('GET','POST','PUT');

/**
 * Run the Router
 */

if(isset($_GET['run_api_router']) && $_GET['run_api_router']) {
    // Run Slim
    $app->response()->header('Content-Type', 'application/json; charset=utf-8');
    $app->run();
}
