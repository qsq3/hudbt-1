<?php
require_once('include/bittorrent_announce.php');
require_once('include/benc.php');

header('X-Robots-Tag: noindex');

//detect server supports ipv6
$server_ipv6 = (strpos($_SERVER['SERVER_ADDR'], ':') !== false);

//1. BLOCK ACCESS WITH WEB BROWSERS AND CHEATS!
$agent = $_SERVER["HTTP_USER_AGENT"];
block_browser();
//2. GET ANNOUNCE VARIABLES
// get string type passkey, info_hash, peer_id, event, ip from client
foreach (array("passkey","info_hash","peer_id","event", 'ipv6') as $x) {
  if(isset($_GET[$x])) {
    $GLOBALS[$x] = $_GET[$x];
  }
}

// Find private address
if (!isset($ipv6) || strtolower($ipv6[0]) == 'f') {
  $ipv6 = null;
}
else {
  $ipv6 = inet_pton($ipv6); // convert to binary
  if (!$ipv6) {
    $ipv6 = null;
  }
}

// get integer type port, downloaded, uploaded, left from client
foreach (array("port","downloaded","uploaded","left","compact","no_peer_id") as $x) {
  if (isset($_GET[$x])) {
    $GLOBALS[$x] = 0 + $_GET[$x];
  }
}

//check info_hash, peer_id and passkey
foreach (array("passkey","info_hash","peer_id","port","downloaded","uploaded","left") as $x) {
  if (!isset($x)) {
    err("Missing key: $x");
  }
}
foreach (array("info_hash","peer_id") as $x) {
  if (strlen($GLOBALS[$x]) != 20) {
    err("Invalid $x (" . strlen($GLOBALS[$x]) . " - " . rawurlencode($GLOBALS[$x]) . ")");
  }
}

if (strlen($passkey) != 32) {
  err("Invalid passkey (" . strlen($passkey) . " - $passkey)");
}

//4. GET IP AND CHECK PORT
$ip = getip();	// avoid to get the spoof ip from some agent
if (!$port || $port > 0xffff) {
  err("invalid port");
}
if ($ipv6 || !ip2long($ip)) { //Disable compact announce with IPv6
  $compact = 0;
}

// check port and connectable
if (portblacklisted($port)) {
  err("Port $port is blacklisted.");
}

//5. GET PEER LIST
// Number of peers that the client would like to receive from the tracker.This value is permitted to be zero. If omitted, typically defaults to 50 peers.
$rsize = 50;
foreach(array("numwant", "num want", "num_want") as $k) {
  if (isset($_GET[$k])) {
    $rsize = 0 + $_GET[$k];
    break;
  }
}

// set if seeder based on left field
$seeder = ($left == 0) ? "yes" : "no";

// check passkey
$az = $Cache->get_value('user_passkey_'.$passkey.'_content');
if ($az === false) {
  $az = sql_query('SELECT id, downloadpos, enabled, uploaded, downloaded, class, parked, clientselect, showclienterror FROM users WHERE passkey= ? LIMIT 1', [$passkey])->fetch();
  $Cache->cache_value('user_passkey_'.$passkey.'_content', $az, 950);
}
if (!$az) err("Invalid passkey! Re-download the .torrent from $BASEURL");
$userid = 0+$az['id'];

//3. CHECK IF CLIENT IS ALLOWED
list($clicheck_res, $client_familyid) = check_client($peer_id,$agent);
if($clicheck_res){
  if ($az['showclienterror'] == 'no') {
      update_user($userid, "showclienterror = 'yes'");
    }
  err($clicheck_res);
}
elseif ($az['showclienterror'] == 'yes'){
  $USERUPDATESET[] = "showclienterror = 'no'";
  $Cache->delete_value('user_passkey_'. $passkey .'_content');
}

// check torrent based on info_hash
$torrent = torrent_for_infohash($info_hash);
if (!$torrent) err("torrent not registered with this tracker");
elseif ($torrent['banned'] == 'yes' && $az['class'] < $seebanned_class) err("torrent banned");
// select peers info from peers table for this torrent
$torrentid = $torrent["id"];
$numpeers = $torrent["seeders"]+$torrent["leechers"];

if ($seeder == 'yes'){ //Don't report seeds to other seeders
  $only_leech_query = " AND seeder = 'no' ";
  $newnumpeers = $torrent["leechers"];
}
else{
  $only_leech_query = "";
  $newnumpeers = $numpeers;
}
if ($newnumpeers > $rsize) {
  if ($ipv6) {
    // Perfer peers with ipv6 address
    $limit = " ORDER BY ISNULL(ipv6) ASC, RAND() LIMIT $rsize";
  }
  else {
    $limit = " ORDER BY RAND() LIMIT $rsize";
  }
}
else {
  $limit = "";
}
$announce_wait = 30;

$fields = "seeder, peer_id, ip, port, uploaded, downloaded, ipv6, (".TIMENOW." - UNIX_TIMESTAMP(last_action)) AS announcetime, UNIX_TIMESTAMP(prev_action) AS prevts";
//$peerlistsql = "SELECT ".$fields." FROM peers WHERE torrent = ".$torrentid." AND connectable = 'yes' ".$only_leech_query.$limit;
$peerlistsql = "SELECT ".$fields." FROM peers WHERE torrent = ".$torrentid . $only_leech_query . $limit;

$res = sql_query($peerlistsql);

$real_annnounce_interval = $announce_interval;
if ($anninterthreeage && ($anninterthree > $announce_wait) && (TIMENOW - $torrent['ts']) >= ($anninterthreeage * 86400))
  $real_annnounce_interval = $anninterthree;
elseif ($annintertwoage && ($annintertwo > $announce_wait) && (TIMENOW - $torrent['ts']) >= ($annintertwoage * 86400))
  $real_annnounce_interval = $annintertwo;

$resp = "d" . benc_str("interval") . "i" . $real_annnounce_interval . "e" . benc_str("min interval") . "i" . $announce_wait . "e". benc_str("complete") . "i" . $torrent["seeders"] . "e" . benc_str("incomplete") . "i" . $torrent["leechers"] . "e" . benc_str("peers");

$peer_list = "";
unset($self);
// bencoding the peers info get for this announce
while ($row = _mysql_fetch_assoc($res)) {
  $row["peer_id"] = hash_pad($row["peer_id"]);

  // $peer_id is the announcer's peer_id while $row["peer_id"] is randomly selected from the peers table
  if ($row["peer_id"] === $peer_id) {
    $self = $row;
    continue;
  }

  if ($compact == 1) {
    $longip = ip2long($row['ip']);
    if ($longip) //Ignore ipv6 address
      $peer_list .= pack("Nn", sprintf("%d",$longip), $row['port']);
  }
  else if ($no_peer_id == 1) {
    $peer_list .= "d" .
      benc_str("ip") . benc_str($row["ip"]) .
      benc_str("port") . "i" . $row["port"] . "e" .
      "e";
    if ($ipv6 && $row['ipv6']) {
      $peer_list .= "d" .
	benc_str("ip") . benc_str(inet_ntop($row["ipv6"])) .
	benc_str("port") . "i" . $row["port"] . "e" .
	"e";
    }
  }
  else {
    $peer_list .= "d" .
      benc_str("ip") . benc_str($row["ip"]) .
      benc_str("peer id") . benc_str($row["peer_id"]) .
      benc_str("port") . "i" . $row["port"] . "e" .
      "e";
    
    if ($ipv6 && $row['ipv6']) {
      $peer_list .= "d" .
	benc_str("ip") . benc_str(inet_ntop($row["ipv6"])) .
	benc_str("peer id") . benc_str($row["peer_id"]) .
	benc_str("port") . "i" . $row["port"] . "e" .
	"e";
    }
  }
}

if ($compact == 1) {
  $resp .= benc_str($peer_list);
}
else {
  $resp .= "l".$peer_list."e";
}

$resp .= "e";
$selfwhere = "torrent = ? AND peer_id = ?";
$selfwhere_args = [$torrentid, $peer_id];

//no found in the above random selection
if (!isset($self)) {
  $res = sql_query("SELECT $fields FROM peers WHERE $selfwhere LIMIT 1", $selfwhere_args);
  $row = _mysql_fetch_assoc($res);
  if ($row) {
    $self = $row;
  }
}

// min announce time
if(isset($self) && $self['prevts'] > (TIMENOW - $announce_wait)) {
  err('There is a minimum announce time of ' . $announce_wait . ' seconds');
}

// current peer_id, or you could say session with tracker not found in table peers
if (!isset($self)) {
  $valid = @_mysql_fetch_row(@sql_query("SELECT COUNT(*) FROM peers WHERE torrent=$torrentid AND userid=" . sqlesc($userid)));
  if ($valid[0] >= 1 && $seeder == 'no') err("You already are downloading the same torrent. You may only leech from one location at a time.");
  if ($valid[0] >= 3 && $seeder == 'yes') err("You cannot seed the same torrent from more than 3 locations.");

  if ($az["enabled"] == "no")
    err("Your account is disabled!");
  elseif ($az["parked"] == "yes")
    err("Your account is parked! (Read the FAQ)");
  elseif ($az["downloadpos"] == "no")
    err("Your downloading priviledges have been disabled! (Read the rules)");

  if ($az["class"] < UC_VIP) {
    $ratio = (($az["downloaded"] > 0) ? ($az["uploaded"] / $az["downloaded"]) : 1);
    $gigs = $az["downloaded"] / (1024*1024*1024);
    if ($waitsystem == "yes") {
      if($gigs > 10) {
	$elapsed = strtotime(date("Y-m-d H:i:s")) - $torrent["ts"];
	if ($ratio < 0.4) $wait = 24;
	elseif ($ratio < 0.5) $wait = 12;
	elseif ($ratio < 0.6) $wait = 6;
	elseif ($ratio < 0.8) $wait = 3;
	else $wait = 0;

	if ($elapsed < $wait)
	  err("Your ratio is too low! You need to wait " . mkprettytime($wait * 3600 - $elapsed) . " to start, please read $BASEURL/faq.php#id46 for details");
      }
    }
    if ($maxdlsystem == "yes") {
      $max = get_maxslots($az['downloaded'], $ratio);
      
      if ($max > 0) {
	$res = sql_query("SELECT COUNT(*) AS num FROM peers WHERE userid='$userid' AND seeder='no'") or err("Tracker error 5");
	$row = _mysql_fetch_assoc($res);
	if ($row['num'] >= $max) err("Your slot limit is reached! You may at most download $max torrents at the same time, please read $BASEURL/faq.php#id66 for details");
      }
    }
  }
}
else { // continue an existing session
  $upthis = $trueupthis = max(0, $uploaded - $self["uploaded"]);
  $downthis = $truedownthis = max(0, $downloaded - $self["downloaded"]);
  $announcetime = ($self["seeder"] == "yes" ? "seedtime = seedtime + $self[announcetime]" : "leechtime = leechtime + $self[announcetime]");
  $is_cheater = false;
  
  if ($cheaterdet_security) {
    if ($az['class'] < $nodetect_security && $self['announcetime'] > 10) {
      $is_cheater = check_cheater($userid, $torrent['id'], $upthis, $downthis, $self['announcetime'], $torrent['seeders'], $torrent['leechers']);
    }
  }

  if (!$is_cheater && ($trueupthis > 0 || $truedownthis > 0)) {
    list($pr_state) = get_pr_state($torrent['sp_state'], $torrent['added'], $torrent['promotion_time_type'], $torrent['promotion_until']);
    if($pr_state==3) { //2X
      $USERUPDATESET[] = "uploaded = uploaded + 2*$trueupthis";
      $USERUPDATESET[] = "downloaded = downloaded + $truedownthis";
    }
    elseif($pr_state==4) { //2X Free
      $USERUPDATESET[] = "uploaded = uploaded + 2*$trueupthis";
    }
    elseif($pr_state==6) { //2X 50%
      $USERUPDATESET[] = "uploaded = uploaded + 2*$trueupthis";
      $USERUPDATESET[] = "downloaded = downloaded + $truedownthis/2";
    }
    else {
      if ($torrent['owner'] == $userid && $uploaderdouble_torrent > 0) {
	$upthis = $trueupthis * $uploaderdouble_torrent;
      }

      if($pr_state==2) { //Free
	$USERUPDATESET[] = "uploaded = uploaded + $upthis";
      }
      elseif($pr_state==5) { //50%
	$USERUPDATESET[] = "uploaded = uploaded + $upthis";
	$USERUPDATESET[] = "downloaded = downloaded + $truedownthis/2";
      }
      elseif($pr_state==7) { //30%
	$USERUPDATESET[] = "uploaded = uploaded + $upthis";
	$USERUPDATESET[] = "downloaded = downloaded + $truedownthis*3/10";
      }
      elseif($pr_state==1) { //Normal
	$USERUPDATESET[] = "uploaded = uploaded + $upthis";
	$USERUPDATESET[] = "downloaded = downloaded + $truedownthis";
      }
    }
  }
}

$date = date("Y-m-d H:i:s");
$dt = sqlesc(date("Y-m-d H:i:s"));
$updateset = array();
// set non-type event
if (!isset($event)) {
  $event = "";
}

if ($event == "stopped") {
  if (isset($self)) {
    sql_query("DELETE FROM peers WHERE $selfwhere", $selfwhere_args) or err("D Err");
    if (_mysql_affected_rows()) {
      $updateset[] = ($self["seeder"] == "yes" ? "seeders = seeders - 1" : "leechers = leechers - 1");
      sql_query("UPDATE LOW_PRIORITY snatched SET uploaded = uploaded + $trueupthis, downloaded = downloaded + $truedownthis, to_go = $left, $announcetime, last_action = ".$dt." WHERE torrentid = $torrentid AND userid = $userid") or err("SL Err 1");
    }
  }
}
elseif(isset($self)) {
  if ($event == "completed") {
    //sql_query("UPDATE LOW_PRIORITY snatched SET  finished  = 'yes', completedat = $dt WHERE torrentid = $torrentid AND userid = $userid");
    $finished = ", finishedat = ".TIMENOW;
    $finished_snatched = ", completedat = ".$dt . ", finished  = 'yes'";
    $updateset[] = "times_completed = times_completed + 1";
  }
  else {
    $finished = '';
    $finished_snatched = '';
  }

  $args = array_merge([$ip, $port, $uploaded, $downloaded, $left, $seeder, $agent, $ipv6], $selfwhere_args);
  sql_query("UPDATE peers SET ip = ?, port = ?, uploaded = ?, downloaded = ?, to_go = ?, prev_action = last_action, last_action = $dt, seeder = ?, agent = ? $finished, ipv6=? WHERE $selfwhere", $args) or err("PL Err 1");

  if (_mysql_affected_rows()) {
    if ($seeder <> $self["seeder"])
      $updateset[] = ($seeder == "yes" ? "seeders = seeders + 1, leechers = leechers - 1" : "seeders = seeders - 1, leechers = leechers + 1");
    sql_query("UPDATE LOW_PRIORITY snatched SET uploaded = uploaded + $trueupthis, downloaded = downloaded + $truedownthis, to_go = $left, $announcetime, last_action = ".$dt." $finished_snatched WHERE torrentid = $torrentid AND userid = $userid") or err("SL Err 2");
  }
}
else {
  if(strpos($ip, '2001:') !== false) { // ipv6
    if (!$server_ipv6) {
      $check_ip = '['.$ip.']';
    }
    else {
      $check_ip = null;
    }
  } else {
    $check_ip = $ip;
  }

  if ($check_ip) {
    $connectable = $Cache->get_value('peer_connectable_' . $check_ip . '_' . $port, 1800, function() use ($check_ip, $port){
	$sockres = @pfsockopen($check_ip, $port, $errno, $errstr, 5);
	
	if (!$sockres) {
	  return "no";
	}
	else {
	  @fclose($sockres);
	  return 'yes';
	}
      });
  }
  else {
    $connectable = "yes";
  }

  function check_same_peer($sql, $args) {
    global $ipv6;
    if ($ipv6) {
      $sql .= ' AND ipv6 = ?';
      $args[] = $ipv6;
    }

    if (sql_query('SELECT COUNT(1) FROM peers ' . $sql, $args)->fetch()[0] > 0) {
      err("Please do not open multiple clients.");
    }
  }

  sql_query('DELETE FROM peers WHERE torrent = ? AND userid = ? AND ip = ? AND port = ? AND agent = ?', [$torrentid, $userid, $ip, $port, $agent]);
  check_same_peer('WHERE torrent = ? AND userid = ? AND ip = ?', [$torrentid, $userid, $ip]);
  
  sql_query("INSERT INTO peers (torrent, userid, peer_id, ip, port, connectable, uploaded, downloaded, to_go, started, last_action, seeder, agent, downloadoffset, uploadoffset, passkey, ipv6) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$torrentid, $userid, $peer_id, $ip, $port, $connectable, $uploaded, $downloaded, $left, $date, $date, $seeder, $agent, $downloaded, $uploaded, $passkey, $ipv6]) or err("PL Err 2");

  if (_mysql_affected_rows()) {
    $updateset[] = ($seeder == "yes" ? "seeders = seeders + 1" : "leechers = leechers + 1");
    
    $check = @_mysql_fetch_row(@sql_query("SELECT COUNT(1) FROM snatched WHERE torrentid = $torrentid AND userid = $userid"));
    if (!$check['0'])
      sql_query("INSERT INTO snatched (torrentid, userid, ip, port, uploaded, downloaded, to_go, startdat, last_action) VALUES ($torrentid, $userid, ".sqlesc($ip).", $port, $uploaded, $downloaded, $left, $dt, $dt)") or err("SL Err 4");
    else
      sql_query("UPDATE LOW_PRIORITY snatched SET to_go = $left, last_action = ".$dt ." WHERE torrentid = $torrentid AND userid = $userid") or err("SL Err 3.1");
  }
}

if (count($updateset)) { // Update only when there is change in peer counts
  $updateset[] = "visible = 'yes'";
  if ($seeder == 'yes') {
    $updateset[] = "last_action = $dt";
    $updateset[] = "startseed = 'yes'";
  }
  sql_query("UPDATE LOW_PRIORITY torrents SET " . join(",", $updateset) . " WHERE id = $torrentid");
}

if($client_familyid != 0 && $client_familyid != $az['clientselect']) {
  $USERUPDATESET[] = "clientselect = ".sqlesc($client_familyid);
}

if(count($USERUPDATESET) && $userid) {
  update_user($userid, join(",", $USERUPDATESET), [], false);
}
benc_resp_raw($resp);

