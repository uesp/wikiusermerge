<?php
/*
 * Attempts to merge users from one wiki database to another. Specifically created to merge users from The Assimilation Lab
 * wikis into the main UESP wiki. To use outside of this purpose edit the constants at the top of this file as needed. 
 * MediaWiki 1.27 was used for this merge and other versions may require a different user table definition.
 * 
 * Backup of the destination user table and the source revision tables are recommended before running.
 * 
 * Note that this script is not currently re-entrant on the destination user table. Running it multiple time on the 
 * same source wiki will result in edit count being higher than actual. 
 */

if (php_sapi_name() != "cli") die("Can only be run from command line!");

	/* Wiki database, user, and passwords */
require("/home/uesp/secrets/wiki.secrets");
$DEST_DB = $uespWikiDB;
$DEST_USER = $uespWikiUser;
$DEST_PW = $uespWikiPW;
$DEST_HOST = $UESP_SERVER_DB1;

require("/home/uesp/secrets/theassimilationlab.secrets");
$SOURCE_USER = $uespWikiUser;
$SOURCE_PW = $uespWikiPW;
$SOURCE_HOST = $UESP_SERVER_DB1;
$SOURCE_DBS = array(
		"uesp_net_lab_bettercities",
		"uesp_net_lab_mwmodding",
		"uesp_net_lab_obquestmod",
		"uesp_net_lab_ooowiki",
		"uesp_net_lab_ootdwiki",
		"uesp_net_lab_questlist",
		"uesp_net_lab_tescosi",
);

	/* Set to false to do the actual merge (backup destination user table first). */
$TEST_MERGE = true;

	/* Show users that were ignored and not merged for any reason */
$SHOW_IGNORED_USERS = false;

	/* Namespace IDs that are to be merged (copied from our $wgExtraNamespaces config variable) */
$VALID_NAMESPACES = array(
		/*
		100 => 'Tamold',        101 => 'Tamold_talk',
		102 => 'Arena',         103 => 'Arena_talk',
		104 => 'Daggerfall',    105 => 'Daggerfall_talk',
		106 => 'Battlespire',   107 => 'Battlespire_talk',
		108 => 'Redguard',      109 => 'Redguard_talk',
		110 => 'Morrowind',     111 => 'Morrowind_talk',
		112 => 'Tribunal',      113 => 'Tribunal_talk',
		114 => 'Bloodmoon',     115 => 'Bloodmoon_talk',
		116 => 'Oblivion',      117 => 'Oblivion_talk',
		118 => 'General',       119 => 'General_talk',
		120 => 'Review',        121 => 'Review_talk', */
		122 => 'Tes3Mod',       123 => 'Tes3Mod_talk',
		124 => 'Tes4Mod',       125 => 'Tes4Mod_talk',
		/*
		126 => 'Shivering',     127 => 'Shivering_talk',
		128 => 'Shadowkey',     129 => 'Shadowkey_talk',
		130 => 'Lore',          131 => 'Lore_talk',
		132 => 'Dawnstar',      133 => 'Dawnstar_talk',
		134 => 'Skyrim',        135 => 'Skyrim_talk',
		136 => 'OBMobile',      137 => 'OBMobile_talk',
		138 => 'Stormhold',     139 => 'Stormhold_talk',
		140 => 'Books',         141 => 'Books_talk', */
		142 => 'Tes5Mod',       143 => 'Tes5Mod_talk',
		/*
		144 => 'Online',        145 => 'Online_talk',
		146 => 'Dragonborn',    147 => 'Dragonborn_talk',
		148 => 'ESOMod',        149 => 'ESOMod_talk',
		150 => 'Legends',       151 => 'Legends_talk',
		152 => 'Blades',        153 => 'Blades_talk',
		154 => 'Tes1Mod',       155 => 'Tes1Mod_talk',
		156 => 'Tes2Mod',       157 => 'Tes2Mod_talk',
		158 => 'Call_to_Arms',  159 => 'Call_to_Arms_talk',
		160 => 'TesOtherMod',   161 => 'TesOtherMod_talk',
		162 => 'Merchandise',   163 => 'Merchandise_talk',
		164 => 'Pinball',       165 => 'Pinball_talk',
		166 => 'SkyrimVSE',     167 => 'SkyrimVSE_talk', */
);

$IGNORE_USER_NAMES = array(
		"WikiSysop" => 1,
		"Test" => 1,
		"Daveh" => 1,
		"MediaWiki default" => 1,
		
);


	/* Global variables */
$allUsers = array();
$newUsers = array();
$duplicateUsers = array();
$destUserNameIndex = array();
$userIdFixups = array();
$lastQuery = "";
$nextFreeDestUserId = 1;


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


function CreateDestUserNameIndex()
{
	global $allUsers;
	global $destUserNameIndex;
	global $nextFreeDestUserId;
	
	$destUserNameIndex = array();
	$destUsers = $allUsers["__dest"];
	$maxId = 0;
	
	print("\tCreating destination user name index...\n");
	
	foreach ($destUsers as $userId => $user)
	{
		$name = $user['user_name'];
		
		if ($destUserNameIndex[$name] != null) ReportError("\tDuplicate user name '$name' found in destination database!\n");
		
		$destUserNameIndex[$name] = $userId;
		
		if ($userId > $maxId) $maxId = $userId;
	}
	
	$nextFreeDestUserId = $maxId + 1;
	print("\tNext available destination user ID is $nextFreeDestUserId.\n");
}


function LoadUsersFromDB($dbName, $host, $user, $password)
{
	global $lastQuery;
	
	$users = array();
	
	$db = new mysqli($host, $user, $password, $dbName);
	if ($db == null || $db->connect_error) return ReportError("Could not connect to mysql database for '$dbName'!", $db);
	
	$lastQuery = "SELECT * FROM user;";
	$result = $db->query($lastQuery);
	if ($result === false) return ReportError("Failed to load user data!", $db);
	
	while (($row = $result->fetch_assoc()))
	{
		$userId = $row['user_id'];
		$users[$userId] = $row;
	}
	
	$count = count($users);
	print("\tLoaded $count users from $dbName.\n");
	
	//$db->close();
	
	return $users;
}


function LoadUsersToBeMerged()
{
	global $SOURCE_DBS, $SOURCE_USER, $SOURCE_PW, $SOURCE_HOST;
	global $DEST_DB, $DEST_USER, $DEST_PW, $DEST_HOST;
	global $allUsers;
	global $userIdFixups;
	
	foreach ($SOURCE_DBS as $dbName)
	{
		$users = LoadUsersFromDB($dbName, $SOURCE_HOST, $SOURCE_USER, $SOURCE_PW);
		
		if ($users) 
		{
			$allUsers[$dbName] = $users;
			$userIdFixups[$dbname] = array();
		}
	}
	
	$users = LoadUsersFromDB($DEST_DB, $DEST_HOST, $DEST_USER, $DEST_PW);
	
	if ($users) 
	{
		$allUsers["__dest"] = $users;
		CreateDestUserNameIndex();
	}
	
	return true;
}


function IsIgnoreUser($user)
{
	global $IGNORE_USER_NAMES;
	
	if ($user['user_name'] == null) return true;
	if ($user['user_name'] == "") return true;
	if ($IGNORE_USER_NAMES[$user['user_name']] != null) return true;
	
	return false;
}


function &FindExistingUser($user)
{
	global $allUsers;
	global $destUserNameIndex;
	
	$name = $user['user_name'];
	$destUserId = $destUserNameIndex[$name];
	
	if ($destUserId == null) return null;
	return $allUsers['__dest'][$destUserId];
}


function CountUserValidEdits($user, $dbName)
{
	global $SOURCE_DBS, $SOURCE_USER, $SOURCE_PW, $SOURCE_HOST;
	global $SHOW_IGNORED_USERS;
	global $VALID_NAMESPACES;
	global $lastQuery;
	
	if ($user['user_editcount'] <= 0) 
	{
		if ($SHOW_IGNORED_USERS) print("\t$dbName: Ignoring user '{$user['user_name']}' with no edits...\n");
		//return 0;
	}
	
	$db = new mysqli($SOURCE_HOST, $SOURCE_USER, $SOURCE_PW, $dbName);
	
	if ($db == null || $db->connect_error) 
	{
		ReportError("Could not connect to mysql database for '$dbName'!", $db);
		return 0;
	}
	
	$userId = $user['user_id'];
	$lastQuery = "SELECT * FROM revision LEFT JOIN page ON revision.rev_page=page.page_id WHERE rev_user='$userId';";
	$result = $db->query($lastQuery);
	
	if (!$result)
	{
		ReportError("Error: Failed to load revisions for user $userId in $dbName!", $db);
		return 0;
	}
	
	$revisionCount = 0;
	
	while (($row = $result->fetch_assoc())) 
	{
		if ($row['page_id'] == null) continue;	//Revision with deleted page
		
		$namespace = $row['page_namespace'];
		if ($namespace == null) continue;	// Page with unknown namespace
		if ($VALID_NAMESPACES[$namespace] == null) continue;	//Page with invalid namespace
		
		++$revisionCount;
	}
	
	//$db->close();
	
	if ($revisionCount <= 0) 
	{
		if ($SHOW_IGNORED_USERS) print("\t$dbName: Ignoring user '{$user['user_name']}' with no valid revisions...\n");
		return 0;
	}
	
	return $revisionCount;
}


function AddNewUser($user, $dbName)
{
	global $allUsers;
	global $userIdFixups;
	global $nextFreeDestUserId;
	global $destUserNameIndex;
	global $newUsers;
	
	$destUsers = &$allUsers["__dest"];
	
	$oldUserId = $user['user_id'];
	$newUserId = $nextFreeDestUserId;
	++$nextFreeDestUserId;
	
	$newUser = $user;
	$newUser['user_id'] = $newUserId;
	$newUser['__isnew'] = true;
	
	$destUsers[$newUserId] = $newUser;
	
	$userName = $newUser['user_name'];
	$destUserNameIndex[$userName] = $newUserId;
	
	$userIdFixups[$dbName][$oldUserId] = $newUserId;
	
	$newUsers[$newUserId] = $dbName;
}


function MergeUser($user, &$destUser, $dbName)
{
	global $userIdFixups;
	global $duplicateUsers;
	
	$oldUserId = $user['user_id'];
	$newUserId = $destUser['user_id'];
	
	if ($destUser['__isnew'] == null) 
	{
		$duplicateUsers[$newUserId] = $dbName;
		$destUser['__ismerged'] = true;
	}
	
	print("\t\tEditCount: {$destUser['user_editcount']}, {$user['user_editcount']}\n");
	
	$destUser['user_editcount'] += $user['user_editcount'];
	
	$userIdFixups[$dbName][$oldUserId] = $newUserId;
}


function MergeUsersFromDB($dbName)
{
	global $allUsers;
	
	$srcUsers = $allUsers[$dbName];
	$destUsers = &$allUsers["__dest"];
	$duplicateCount = 0;
	$mergedCount = 0;
	$ignoreCount = 0;
	
	$count = count($srcUsers);
	print("Merging $count users from the $dbName database...\n");
	
	foreach ($srcUsers as $userId => $user)
	{
		if (IsIgnoreUser($user)) 
		{
			print("\t$dbName: Ignoring user '{$user['user_name']}'...\n");
			++$ignoreCount;
			continue;
		}
		
		$validEditCount = CountUserValidEdits($user, $dbName);
		
		if ($validEditCount <= 0) 
		{
			++$ignoreCount;
			continue;
		}
		
		++$mergedCount;
		
		$destUser = &FindExistingUser($user);
		
		if ($destUser)
		{
			print("\t$dbName: Found existing user '{$user['user_name']}' in destination database (has $validEditCount valid edits)!\n");
			MergeUser($user, $destUser, $dbName);
			++$duplicateCount;
		}
		else 
		{
			print("\t$dbName: Adding new user '{$user['user_name']}' to destination database (has $validEditCount valid edits)!\n");
			AddNewUser($user, $dbName);
		}
	}
	
	if ($ignoreCount > 0) print("\tIgnored $ignoreCount users!\n");
	if ($mergedCount > 0) print("\tFound $mergedCount users to be merged!\n");
	if ($duplicateCount > 0) print("\tFound $duplicateCount duplicate users!\n");
	return true;
}


function MergeAllUsers()
{
	global $SOURCE_DBS;
	global $allUsers;
	global $newUsers;
	global $duplicateUsers;
	
	foreach ($SOURCE_DBS as $dbName)
	{
		MergeUsersFromDB($dbName);
	}
	
	$allMergedUsers = $newUsers + $duplicateUsers;
	$userNames = array();
	$destUsers = $allUsers["__dest"];
	
	//print("New Users:\n");
	//print_r($newUsers);
	//print("Duplicate Users:\n");
	//print_r($duplicateUsers);
	
	foreach ($allMergedUsers as $userId => $dbName)
	{
		$user = $destUsers[$userId];
		
		if ($user == null) 
		{
			print("\tError: Missing destination user $userId!\n");
			continue;
		}
		
		$userName = $user['user_name'];
		$userNames[$userName] = $user['__isnew'] ? true : false;
	}
	
	ksort($userNames);
	
	print("All new and updated users:\n");
	$i = 1;
	
	foreach ($userNames as $userName => $isNew)
	{
		if ($isNew)
			print("\t$i: $userName\n");
		else
			print("\t$i: $userName (existing)\n");
		
		++$i;
	}
	
	return true;
}


function WriteNewUsers()
{
	global $DEST_DB, $DEST_USER, $DEST_PW, $DEST_HOST;
	global $newUsers;
	global $allUsers;
	global $lastQuery;
	global $TEST_MERGE;
	
	$destUsers = $allUsers["__dest"];
	$count = count($newUsers);
	
	print("Creating $count new users in destination wiki...\n");
	
	$db = new mysqli($DEST_HOST, $DEST_USER, $DEST_PW, $DEST_DB);
	if ($db == null || $db->connect_error) return ReportError("Could not connect to mysql database for '$DEST_DB'!", $db);
	
	foreach ($newUsers as $newUserId => $dbName)
	{
		$newUser = $destUsers[$newUserId];
		
		if ($newUser == null) 
		{
			print("\tError: Cannot find new user $newUserId!\n");
			continue;
		}
		$cols = "user_id, user_name, user_real_name, user_password, user_newpassword, user_newpass_time, user_email, user_touched, user_token, user_email_authenticated, user_email_token, user_email_token_expires, user_registration, user_editcount, user_password_expires";
		
		$values = "";
		$values .= "'" . $db->real_escape_string($newUser['user_id']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_name']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_real_name']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_password']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_newpassword']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_newpass_time']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_email']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_touched']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_token']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_email_authenticated']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_email_token']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_email_token_expires']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_registration']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_editcount']) . "', ";
		$values .= "'" . $db->real_escape_string($newUser['user_password_expires']) . "'";
		
		$lastQuery = "INSERT INTO user($cols) VALUES($values);";
		
		if ($TEST_MERGE) 
		{
			print("\t$lastQuery\n");
		}
		else
		{
			$result = $db->query($lastQuery);
			if (!$result) ReportError("Error: Failed to save user $newUserId to database!", $db);
		}
	}
	
	return true;
}


function WriteDuplicateUsers()
{
	global $DEST_DB, $DEST_USER, $DEST_PW, $DEST_HOST;
	global $duplicateUsers;
	global $allUsers;
	global $lastQuery;
	global $TEST_MERGE;
	
	$destUsers = $allUsers["__dest"];
	
	$count = count($duplicateUsers);
	
	print("Updating $count duplicate users in destination wiki...\n");
	
	$db = new mysqli($DEST_HOST, $DEST_USER, $DEST_PW, $DEST_DB);
	if ($db == null || $db->connect_error) return ReportError("Could not connect to mysql database for '$DEST_DB'!", $db);
	
	foreach ($duplicateUsers as $userId => $dbName)
	{
		$user = $destUsers[$userId];
		
		if ($user == null) 
		{
			print("\tError: Cannot find existing user $userId!\n");
			continue;
		}
		
		$value = $db->real_escape_string($user['user_editcount']);
		$lastQuery = "UPDATE user SET user_editcount='$value' WHERE user_id='$userId' LIMIT 1;";
		
		if ($TEST_MERGE)
		{
			print("\t$lastQuery\n");
		}
		else
		{
			$result = $db->query($lastQuery);
			if (!$result) ReportError("Error: Failed to update user $userId in database!", $db);
		}
	}
	
	return true;
}


function WriteUserIDFixups()
{
	global $SOURCE_DBS, $SOURCE_USER, $SOURCE_PW, $SOURCE_HOST;
	global $userIdFixups;
	global $SOURCE_DBS;
	global $lastQuery;
	global $TEST_MERGE;
	
	$db = new mysqli($SOURCE_HOST, $SOURCE_USER, $SOURCE_PW, $SOURCE_DBS[0]);
	if ($db == null || $db->connect_error) return ReportError("Could not connect to mysql database for '{$SOURCE_DBS[0]}'!", $db);
	
	foreach ($userIdFixups as $dbName => $fixupIds)
	{
		foreach ($fixupIds as $oldUserId => $newUserId)
		{
			$lastQuery = "UPDATE $dbName.revision SET rev_user=$newUserId WHERE rev_user=$oldUserId;";
			
			if ($TEST_MERGE)
			{
				print("\t$lastQuery\n");
			}
			else
			{
				$result = $db->query($lastQuery);
				if (!$result) ReportError("Error: Failed to fixup user ID $oldUserId in revision table for $dbName!", $db);
			}
		}
	}
	
	return true;
}


	/* Begin main program */
LoadUsersToBeMerged();
MergeAllUsers();
WriteNewUsers();
WriteDuplicateUsers();
WriteUserIDFixups();


