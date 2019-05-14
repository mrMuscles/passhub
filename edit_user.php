<?php

/**
 * edit_user.php
 *
 * PHP version 7
 *
 * @category  Password_Manager
 * @package   PassHub
 * @author    Mikhail Vysogorets <m.vysogorets@wwpass.com>
 * @copyright 2016-2019 WWPass
 * @license   http://opensource.org/licenses/mit-license.php The MIT License
 */

require_once 'config/config.php';
require_once 'src/functions.php';
require_once 'src/db/user.php';
require_once 'src/db/safe.php';
require_once 'src/db/item.php';
require_once 'src/db/iam_ops.php';

require_once 'src/db/SessionHandler.php';


$mng = newDbConnection();

setDbSessionHandler($mng);

session_start();

function edit_user_proxy($mng) {

    if (!isset($_SESSION['UserID'])) {
        return "login";
    }
    try {
        update_ticket();
    } catch (Exception $e) {
        $_SESSION['expired'] = true;
        passhub_err('Caught exception: ' . $e->getMessage());
        return "login";
    }
    if (!isset($_POST['verifier']) || !User::is_valid_csrf($_POST['verifier'])) {
        passhub_err("bad csrf");
        return "Bad Request (44)";
    }
    if (!isSiteAdmin($mng, $_SESSION['UserID'])) {
        passhub_err("usr ed user 63");
        return "usr ed user 63";
    }
    if (!array_key_exists('id', $_POST)) {
        passhub_err("usr ed 49");
        return "internal error ed 49";
    }

    if (!ctype_xdigit($_POST['id'])) {
        passhub_err("usr ed 51 " . $_POST['id']);
        return "internal error ed 51";
    }

    if ($_POST['id'] == $_SESSION['UserID']) {
        passhub_err("usr ed 59");
        return "internal error ed 59";
    }
    $id = $_POST['id'];

    return edit_user($mng, $id);
}

$result = edit_user_proxy($mng);


header('Content-type: application/json');
header('Cache-Control: no-cache, must-revalidate');
header('Expires: Mon, 01 Jan 1996 00:00:00 GMT');

if (gettype($result) == "string") {
    $result = array("status" => $result);
}


// Send the data.
echo json_encode($result);