<?php
require "include/bittorrent.php";
dbconn();
require_once(get_langfile_path());
loggedinorreturn();
parked();

if (get_user_class() < $staffmem_class)
	permissiondenied();

$count = get_row_count("reports");
if (!$count){
	stderr($lang_reports['std_oho'], $lang_reports['std_no_report']);
}
stdhead($lang_reports['head_reports']);
$perpage = 20;
list($pagertop, $pagerbottom, $limit) = pager($perpage, $count, "reports.php?");
print("<h1 align=center>".$lang_reports['text_reports']."</h1>");
echo '<form method="post" action="takeupdate.php">';
echo ('<table cellpadding="5">');
echo "<thead><tr><th class=\"nowrap\">".$lang_reports['col_added']."</th><th>".$lang_reports['col_reporter']."</th><th>".$lang_reports['col_reporting']."</th><th class=\"nowrap\">".$lang_reports['col_type']."</th><th>".$lang_reports['col_reason']."</th><th class=\"nowrap\">".$lang_reports['col_dealt_with']."</th><th class=\"nowrap\">".$lang_reports['col_action'].'</th></thead><tfoot><tr><td class="colhead" colspan="7" align="right"><input type="submit" name="setdealt" value="', $lang_reports['submit_set_dealt'], '" /><input type="submit" name="delete" value="', $lang_reports['submit_delete'], '" /></td></tr></tfoot><tbody>';

$reportres = sql_query("SELECT * FROM reports ORDER BY dealtwith ASC, id DESC $limit");

while ($row = _mysql_fetch_array($reportres))
{
	if ($row['dealtwith'])
		$dealtwith = "<font color=green>".$lang_reports['text_yes']."</font> - " . get_username($row['dealtby']);
	else
		$dealtwith = "<font color=red>".$lang_reports['text_no']."</font>";
	switch ($row['type'])
	{
		case "torrent":
		{
			$type = $lang_reports['text_torrent'];
			$res = sql_query("SELECT id, name FROM torrents WHERE id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = $lang_reports['text_torrent_does_not_exist'];
			else
			{
				$arr = _mysql_fetch_array($res);
				$reporting = "<a href=details.php?id=".$arr['id'].">".htmlspecialchars($arr['name'])."</a>";
			}
			break;
		}
		case "user":
		{
			$type = $lang_reports['text_user'];
			$res = sql_query("SELECT id FROM users WHERE id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = $lang_reports['text_user_does_not_exist'];
			else
			{
				$arr = _mysql_fetch_array($res);
				$reporting = get_username($arr['id']);
			}
			break;
		}
		case "offer":
		{
			$type = $lang_reports['text_offer'];
			$res = sql_query("SELECT id, name FROM offers WHERE id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = $lang_reports['text_offer_does_not_exist'];
			else
			{
				$arr = _mysql_fetch_array($res);
				$reporting = "<a href=\"offers.php?id=".$arr['id']."&off_details=1\">".htmlspecialchars($arr['name'])."</a>";
			}
			break;
		}

		case "request":
		{
			$type = $lang_reports['text_request'];
			$res = sql_query("SELECT id, request FROM requests WHERE id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = "Request doesn't exist or is deleted.";
			else
			{
				$arr = _mysql_fetch_array($res);
				$reporting = "<a href=\"viewrequests.php?id=".$arr['id']."&req_details=1\">".htmlspecialchars($arr['request'])."</a>";
			}
			break;
		}

		case "post":
		{
			$type = $lang_reports['text_forum_post'];
			$res = sql_query("SELECT topics.id AS topicid, topics.subject AS subject, posts.userid AS postuserid FROM topics LEFT JOIN posts ON posts.topicid = topics.id WHERE posts.id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = $lang_reports['text_post_does_not_exist'];
			else
			{
				$arr = _mysql_fetch_array($res);
				$reporting = $lang_reports['text_post_id'].$row['reportid'].$lang_reports['text_of_topic']."<b><a href=\"forums.php?action=viewtopic&topicid=".$arr['topicid']."&page=p".htmlspecialchars($row['reportid'])."#pid".htmlspecialchars($row['reportid'])."\">".htmlspecialchars($arr['subject'])."</a></b>".$lang_reports['text_by'].get_username($arr['postuserid']);
			}
			break;
		}
		case "comment":
		{
			$type = $lang_reports['text_comment'];
			$res = sql_query("SELECT id, user, torrent, offer FROM comments WHERE id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = $lang_reports['text_comment_does_not_exist'];
			else
			{
					$arr = _mysql_fetch_array($res);
					if ($arr['torrent'])
					{
						$name = get_single_value("torrents","name","WHERE id=".sqlesc($arr['torrent']));
						$url = "details.php?id=".$arr['torrent']."#cid".$row['reportid'];
						$of = $lang_reports['text_of_torrent'];
					}
					elseif ($arr['offer'])
					{
						$name = get_single_value("offers","name","WHERE id=".sqlesc($arr['offer']));
						$url = "offers.php?id=".$arr['offer']."&off_details=1#cid".$row['reportid'];
						$of = $lang_reports['text_of_offer'];
					} else //Comment belongs to no one
						$of = "unknown";
					$reporting = $lang_reports['text_comment_id'].$row['reportid'].$of."<b><a href=\"".$url."\">".htmlspecialchars($name)."</a></b>".$lang_reports['text_by'].get_username($arr['user']);
			}
			break;
		}
		case "subtitle":
		{
			$type = $lang_reports['text_subtitle'];
			$res = sql_query("SELECT id, torrent_id, title FROM subs WHERE id=".sqlesc($row['reportid']));
			if (_mysql_num_rows($res) == 0)
				$reporting = $lang_reports['text_subtitle_does_not_exist'];
			else
			{
				$arr = _mysql_fetch_array($res);
				$reporting = "<a href=\"downloadsubs.php?torrentid=" . $arr['torrent_id'] ."&subid=" .$arr['id']."\">".htmlspecialchars($arr['title'])."</a>".$lang_reports['text_for_torrent_id']."<a href=\"details.php?id=" . $arr['torrent_id'] ."\">".$arr['torrent_id']."</a>";
			}
			break;
		}
		default:
		{
			break;
		}
	}

	print("<tr><td class=\"nowrap\">".gettime($row['added'])."</td><td>" . get_username($row['addedby']) . "</td><td>".$reporting."</td><td class=\"nowrap\">".$type."</td><td>".htmlspecialchars($row['reason'])."</td><td class=\"nowrap\">".$dealtwith."</td><td><input type=\"checkbox\" name=\"delreport[]\" value=\"" . $row['id'] . "\" /></td></tr>\n");
}
?>
</tbody>
</table> 
</form>
<?php
print($pagerbottom);
stdfoot();
