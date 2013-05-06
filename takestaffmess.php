<?php
require "include/bittorrent.php";
if ($_SERVER["REQUEST_METHOD"] != "POST")
	stderr("Error", "Permission denied!");
dbconn();
loggedinorreturn();                                                    

if (get_user_class() < UC_ADMINISTRATOR)
	stderr("Sorry", "Permission denied.");

$sender_id = ($_POST['sender'] == 'system' ? 0 : (int)$CURUSER['id']);
$msg = trim($_POST['msg']);
if (!$msg)
	stderr("Error","Don't leave any fields blank.");
$updateset = $_POST['clases'];
if (is_array($updateset)) {
	foreach ($updateset as $class) {
		if (!is_valid_id($class) && $class != 0)
			stderr("Error","Invalid Class");
	}
}else{
	if (!is_valid_id($updateset) && $updateset != 0)
		stderr("Error","Invalid Class");
}
$subject = trim($_POST['subject']);
$query = sql_query("SELECT id FROM users WHERE class IN (".implode(",", $updateset).")");
while($dat=_mysql_fetch_assoc($query)) {
	send_pm($sender_id, $dat['id'], $subject, $msg);
}

header("Refresh: 0; url=staffmess.php?sent=1");

