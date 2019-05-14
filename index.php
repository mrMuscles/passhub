<?php

/**
 * index.php
 *
 * PHP version 7
 *
 * @category  Password_Manager
 * @package   PassHub
 * @author    Mikhail Vysogorets <m.vysogorets@wwpass.com>
 * @copyright 2016-2018 WWPass
 * @license   http://opensource.org/licenses/mit-license.php The MIT License
 */

require_once 'config/config.php';
require_once 'vendor/autoload.php';
require_once 'src/functions.php';
require_once 'src/db/user.php';
require_once 'src/db/safe.php';
require_once 'src/db/item.php';
require_once 'src/template.php';

require_once 'src/cookie.php';

require_once 'src/db/SessionHandler.php';

$mng = newDbConnection();

setDbSessionHandler($mng);

session_start();

if (!defined('IDLE_TIMEOUT')) {
    define('IDLE_TIMEOUT', 540);
}
if (!isset($_SERVER['HTTP_USER_AGENT'])) {
    $_SERVER['HTTP_USER_AGENT'] = "undefined";
    passhub_err("HTTP_USER_AGENT undefined (corrected)");
}

$hideInstructions = sniffCookie('hideInstructions');
if (!$hideInstructions) {
    if (stripos($_SERVER['HTTP_USER_AGENT'], "iPod")
        || stripos($_SERVER['HTTP_USER_AGENT'], "iPhone")
        || stripos($_SERVER['HTTP_USER_AGENT'], "iPad")
        || stripos($_SERVER['HTTP_USER_AGENT'], "Android")
    ) {
        $hideInstructions = true;
        setcookie('hideInstructions', true, time() + 60*60*50);
    }
}
/*
if (isset($_REQUEST['updateTicket'])) {
    try {
        $time_left = update_ticket();
        echo json_encode(['status'=> 'Ok', 'time_left' => $time_left]);
        exit();
    } catch (Exception $e) {
        $err_msg = 'Caught exception: ' . $e->getMessage();
        passhub_err(get_class($e));
        passhub_err($err_msg);
        // return 500
        echo json_encode(['status' => "fail"]);
        exit();
    }
}
*/

if (isset($_REQUEST['current_safe']) && isset($_SESSION['UserID'])) {
    _set_current_safe($mng, $_SESSION['UserID'], $_REQUEST['current_safe']);
    exit();
}

if (defined('FILE_DIR') && defined('GOOGLE_CREDS')) {
    passhub_err("Error: both local storage and Google drive are enabled");
    error_page("Site is misconfigured. Consult system administrator");
}

if (!isset($_SESSION['PUID'])) {
    if (isset($_REQUEST['ref'])) {
        $ref= $_REQUEST['ref'];
        header("Location: login.php?ref=$ref");
        exit();
    }
    header("Location: login.php");
    exit();
}

if (isset($_SESSION['next'])) {
    $next_page = $_SESSION['next'];
    unset($_SESSION['next']);
}

try {
    update_ticket();
    if (!isset($_SESSION['UserID'])) {
        $result = getUserByPuid($mng, $_SESSION['PUID']);
        if ($result['status'] == "not found") {
            if (!isset($_SESSION['TermsAccepted']) && defined('PUBLIC_SERVICE') && (PUBLIC_SERVICE == true)) {
                header("Location: accept_terms.php");
                exit();
            }
            $top_template = Template::factory('src/templates/top.html');
            $top_template->add('narrow', true)
                ->render();

            if (defined('MAIL_DOMAIN')) {
                if (!isPuidValidated($mng, $_SESSION['PUID'])) {
                    if (!isset($_SESSION['reg_code'])) {
                        $request_mail_template  = Template::factory('src/templates/request_mail.html');
                        $request_mail_template->render();
                        exit();
                    }
                    $status = process_reg_code($mng, $_SESSION['reg_code'], $_SESSION['PUID']);
                    if ($status !== "Ok") {
                        passhub_err("reg_code: " . $status);
                        error_page($status);
                    }
                }
            }
            // $create_user_template = Template::factory('src/templates/create_user_cryptoapi.html');
            passhub_log("Create User CSE begin " . $_SERVER['REMOTE_ADDR'] . " " . $_SERVER['HTTP_USER_AGENT']);
            $create_user_template = Template::factory('src/templates/upsert_user.html');
            $template_safes = file_get_contents('config/template.xml');

            if (strlen($template_safes) == 0) {
                passhub_err("template.xml absent or empty");
                error_page("Internal error. Please come back later.");
            }

            $create_user_template->add('ticket', $_SESSION['wwpass_ticket'])
                ->add('upgrade', false)
                ->add('template_safes', $template_safes)
                ->render();
            exit();
        } else if ($result['status'] == "Ok") {
            $UserID = $result['UserID'];
            $_SESSION["UserID"] = $UserID;
            passhub_log("user " . $UserID . " login " . $_SERVER['REMOTE_ADDR'] . " " .  $_SERVER['HTTP_USER_AGENT']);
        } else {
            exit($result['status']);//multiple PUID records;
        }
    }
    // ???
    if (isset($next_page) && (($next_page == 'iam.php') || ($next_page == 'account.php'))) {
        header("Location: " . $next_page);
        exit();
    }

    $UserID = $_SESSION['UserID'];
    $user = new User($mng, $UserID);

    if (defined('MAIL_DOMAIN') && !$user->email) { //  remove when done
    
        if (!isPuidValidated($mng, $_SESSION['PUID'])) {
            if (!isset($_SESSION['reg_code'])) {
                $top_template = Template::factory('src/templates/top.html');
                $top_template->add('narrow', true)
                    ->render();
                $request_mail_template  = Template::factory('src/templates/request_mail.html');
                $request_mail_template->render();
                exit();
            }
            $status = process_reg_code($mng, $_SESSION['reg_code'], $_SESSION['PUID']);
            if ($status !== "Ok") {
                passhub_err("reg_code: " . $status);
                error_page($status);
            }
            $user = new User($mng, $UserID);
        }
    }

    if (isset($_REQUEST['vault'])) {
        $user->setCurrentSafe($_REQUEST['vault']);
    }

    // after get_current_safe we know if user is cse-type
    // TODO do we need jquery ui from https://ajax.googleapis.com? - see progress
    // header("Content-Security-Policy: default-src 'unsafe-inline' 'self' https://maxcdn.bootstrapcdn.com https://cdnjs.cloudflare.com  https://cdn.wwpass.com wss://spfews.wwpass.com https://ajax.googleapis.com https://fonts.gstatic.com ; style-src 'unsafe-inline' 'self' https://maxcdn.bootstrapcdn.com https://fonts.googleapis.com");

    if (!$user->isCSE) {

        $top_template = Template::factory('src/templates/top.html');
        $top_template->add('narrow', true)
            ->render();

        passhub_log("Upgrade User CSE begin " . $_SERVER['REMOTE_ADDR'] . " " . $_SERVER['HTTP_USER_AGENT']);

        $upgrade_user_template = Template::factory('src/templates/upsert_user.html');
        $upgrade_user_template->add('ticket', $_SESSION['wwpass_ticket'])
            ->add('upgrade', true)
            ->render();
        exit();
    }
    $safe_array = $user->safe_array;

} catch ( MongoDB\Driver\Exception\Exception $e) {
    $err_msg = 'Caught exception: ' . $e->getMessage();
    passhub_err(get_class($e));
    passhub_err($err_msg);
    error_page("Internal server error idx 147");// return 500

} catch (WWPass\Exception $e) {
    $err_msg = 'Caught exception: ' . $e->getMessage();
    passhub_err(get_class($e));
    passhub_err($err_msg);
    $_SESSION['expired'] = true;
} catch (Exception $e) {
    $err_msg = 'Caught exception: ' . $e->getMessage();
    passhub_err(get_class($e));
    passhub_err($err_msg);
    // return 500
    error_page("Internal server error idx 159");
}

if (isset($_SESSION['expired'])) {
    header("Location: expired.php");
    exit();
}

$top_template = Template::factory('src/templates/top.html');

$top_template->add('index_page', true)
    ->add('isSiteAdmin', $user->site_admin)
    ->render();


$password_font = getPwdFont();

$index_template = Template::factory('src/templates/index.html')
    ->add('csrf', User::get_csrf())
    ->add('password_font', getPwdFont())
    ->render();
?>

   </div>
</div>

<?php   if (file_exists('config/server_name.php')) {
        include 'config/server_name.php';
} ?>

<?php if (defined('PUBLIC_SERVICE') && (PUBLIC_SERVICE == true)) { ?>
<div class="info_footer">
    <span>
        <a href="//wwpass.com" target="_blank" style="color:#000; opacity:0.8; margin:10px">Powered by WWPass</a>
        <a href="terms.php" style="color:#000; opacity:0.8; margin:10px">Terms of use</a>
    </span>
</div>

<?php } 

$backup_template = Template::factory('src/templates/modals/impex.html');
$backup_template->render();
    
$show_creds_template = Template::factory('src/templates/modals/show_creds.html');
$show_creds_template->add('password_font', $password_font)
    ->render();

$create_vault_template = Template::factory('src/templates/modals/create_vault.html');
$create_vault_template->render();

$folder_ops_template = Template::factory('src/templates/modals/folder_ops.html');
$folder_ops_template->render();

$delete_item_template = Template::factory('src/templates/modals/delete_item.html');
$delete_item_template/* ->add('vault_id', htmlspecialchars($current_vault))
                        */->render();

$delete_safe_template = Template::factory('src/templates/modals/delete_safe.html');
$delete_safe_template->render();

$rename_vault_template = Template::factory('src/templates/modals/rename_vault.html');
$rename_vault_template ->render();

$rename_file_template = Template::factory('src/templates/modals/rename_file.html');
$rename_file_template ->render();

if (defined('SHARE_BY_MAIL') && SHARE_BY_MAIL == true ) {
    $share_safe_template = Template::factory('src/templates/modals/share_by_mail.html');
} else {
    $share_safe_template = Template::factory('src/templates/modals/share_safe.html');
}

$share_safe_template->add('password_font', $password_font)
    ->render();

$safe_users_template = Template::factory('src/templates/modals/safe_users.html');
$safe_users_template->render();

$accept_sharing_template = Template::factory('src/templates/modals/accept_sharing.html');
$accept_sharing_template->render();

$progress_template = Template::factory('src/templates/progress.html');
$progress_template->render();


/*
$script_params = [
  'csrf' => User::get_csrf(),
  'publicKeyPem' => $user->publicKey_CSE,
  'invitation_accept_pending' => $user->invitation_accept_pending,
  'current_safe' => $user->current_safe,
  'user_mail' => $user->email,
  'ePrivateKey' => $user->privateKey_CSE,
  'ticket' => $_SESSION['wwpass_ticket']
];

if (array_key_exists('folder', $_GET)) {
  $script_params['active_folder'] = $_GET['folder'];
} else {
  $script_params['active_folder'] = 0;
}
if (defined('SHARE_BY_MAIL') && SHARE_BY_MAIL == true ) {
  $script_params['shareModal'] = "#shareByMailModal";
} else {
  $script_params['shareModal'] = "#safeShareModal";
}

if (isset($_REQUEST['show_table'])) {
  $script_params['show_table'] = true;
} else {
  $script_params['show_table'] = false;
}
*/

?>

<!--
<script src=js/forge.min.js></script>
<script src="js/FileSaver.min.js"></script>
-->

<script src="js/jquery.csv.js"></script>


<script>

function isSafariPrivateMode() {
  const isSafari = navigator.userAgent.match(/Version\/([0-9\._]+).*Safari/);

  if(!isSafari || !navigator.userAgent.match(/iPhone|iPod|iPad/i)) {
    return false;
  }
  const version = parseInt(isSafari[1], 10);
  if (version >= 11) {
      try {
        window.openDatabase(null, null, null, null);
        return false;
      } catch (_) {
        return true;
      };
  } else if (version === 10) {
    const x = localStorage.length;
    if(localStorage.length) {
      return false;
    } else {
      try {
        localStorage.test = 1;
        localStorage.removeItem('test');
        return false;
      } catch (_) {
        return true;
      }
    }
  }
  return false;
}

if(isSafariPrivateMode()) {
  window.location.href = "error_page.php?js=SafariPrivateMode";
}

</script>

<script src="js/dist/index.js?v=190417"></script>

<?php

$idle_and_removal_template = Template::factory('src/templates/modals/idle_and_removal.html');
$idle_and_removal_template->add('csrf', User::get_csrf())
    ->render();
?>

</body>
</html>