<?php
/*
 * Attempts to fixup any revision with a rev_user of 0 where there exists a user with a name of rev_user_text.
 * Created specifically for the UESP/net wiki and importing The Assimilation Lab sub-wikis.
 * 
 * Backup of the revision table is recommended before running.
 */
if (php_sapi_name() != "cli") die("Can only be run from command line!");

	/* Wiki database, user, and passwords */
/*
require("/home/uesp/secrets/wiki.secrets");
$WIKI_DB = $uespWikiDB;
$WIKI_USER = $uespWikiUser;
$WIKI_PW = $uespWikiPW;
$WIKI_HOST = $UESP_SERVER_DB1; //*/


require("/home/uesp/secrets/theassimilationlab.secrets");
$WIKI_USER = $uespWikiUser;
$WIKI_PW = $uespWikiPW;
$WIKI_HOST = $UESP_SERVER_DB1;
//$WIKI_DB = "uesp_net_lab_bettercities";
//$WIKI_DB = "uesp_net_lab_mwmodding";
//$WIKI_DB = "uesp_net_lab_obquestmod";
//$WIKI_DB = "uesp_net_lab_ooowiki";
//$WIKI_DB = "uesp_net_lab_ootdwiki";
//$WIKI_DB = "uesp_net_lab_questlist";
$WIKI_DB = "uesp_net_lab_tescosi";
//*/

	/* Globals */
$lastQuery = "";


function ReportError($msg, $db)
{
	global $lastQuery;
	
	print("$msg\n");
	
	if ($db) 
	{
		print("\tDB Error: " . $db->error . "\n");
		print("\tLast Query: $lastQuery\n");
	}
	
	return false;
}	


	/* Begin Main Program */
print("Fixing revisions with rev_user=0 in $WIKI_DB...\n");

$db = new mysqli($WIKI_HOST, $WIKI_USER, $WIKI_PW, $WIKI_DB);
if ($db == null || $db->connect_error) return ReportError("Could not connect to mysql database for '$WIKI_DB'!", $db);

$lastQuery = "SELECT rev_user_text FROM revision WHERE rev_user=0;";
$result = $db->query($lastQuery);
if ($result === false) return ReportError("Failed to load revisions from '$WIKI_DB'!", $db);

$revUserNames = array();
$revCount = 0;

while (($row = $result->fetch_array()))
{
	$name = $row['rev_user_text'];
	if ($revUserNames[$name] == null) $revUserNames[$name] = 0;
	++$revUserNames[$name];
	++$revCount;
}

$count = count($revUserNames);
print("\tFound $revCount revisions with $count unique names with rev_user=0 in $WIKI_DB!\n");
$foundUserCount = 0;

foreach ($revUserNames as $userName => $userCount)
{
	$safeName = $db->real_escape_string($userName);
	$lastQuery = "SELECT * FROM user WHERE user_name='$safeName';";
	$result = $db->query($lastQuery);
	
	if ($result === false) 
	{
		ReportError("\tError: Failed to load user name matching '$userName'!", $db);
		continue;
	}
	
	if ($result->num_rows == 0) 
	{
		//print("\tNo user matching '$userName' found!\n");
		continue;
	}
	
	if ($result->num_rows > 1) 
	{
		print("\tWarning: Found multiple matches for user name matching '$userName'!\n");
		continue;
	}
	
	$userRecord = $result->fetch_assoc();
	$userId = $userRecord['user_id'];
	
	print("\tFound user $userName with ID of $userId.\n");
	++$foundUserCount;
	
	$lastQuery = "UPDATE revision SET rev_user='$userId' WHERE rev_user=0 AND rev_user_text='$safeName';";
	$result = $db->query($lastQuery);
	
	if ($result === false) 
	{
		ReportError("\tError: Failed to fix the revision table for user name '$userName' with user ID $userId!", $db);
		continue;
	}
}

if ($foundUserCount == 0) 
{
	print("\tNo matching users found to fix!\n");
	exit();
}







