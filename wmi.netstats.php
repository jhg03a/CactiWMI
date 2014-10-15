#!/usr/bin/php -q
<?php
/**
 * CactiWMI
 * Version 0.0.7-SVN
 *
 * Copyright (c) 2008-2010 Ross Fawcett
 *
 * This file is the main application which interfaces the wmic binary with the
 * input and output from Cacti. The idea of this is to move the configuration
 * into Cacti rather than creating a new script for each item that you wish to
 * monitor via WMI.
 *
 * The only configurable options are listed under general configuration and are
 * the debug level, log location and wmic location. Other than that all other
 * configuration is done via the templates.
 */

// general configuration
$wmiexe = trim(`which wmic`); // executable for the wmic command
if (empty($wmiexe)) {
	print "You must install the wmi client to use this script.\n\n";
	exit;
}
$pw_location = '/etc/cacti/'; // location of the password files, ensure the trailing slash
$log_location = '/var/log/cacti/wmi/'; // location for the log files, ensure trailing slash
$dbug = 0; // debug level 0,1 or 2

// globals
$output = null; // by default the output is null
$inc = null; // by default needs to be null
$sep = " "; // character to use between results
$dbug_levels = array(0,1,2); // valid debug levels
$version = '0.0.7-git'; // version
$namespace = 'root\CIMV2'; // default namespace
$columns = '*'; // default to select all columns
$fp;

// grab arguments
$args = getopt("h:u:w:c:k:v:n:d:y:");

$opt_count = count($args); // count number of options, saves having to recount later
$arg_count = count($argv); // count number of arguments, again saving recounts further on

function &array_split(&$in) {
	$keys = func_get_args();
	array_shift($keys);

	$out = array();
	foreach($keys as $key) {
		if(isset($in[$key]))
			$out[$key] = $in[$key];
		else
			$out[$key] = null;
		unset($in[$key]);
	}

	return $out;
}

function canonicalize_name($in_name) {
		$canonical_name = preg_replace('/[\[\]]/','',$in_name);
                $canonical_name = preg_replace('/[^\d|\w|\s]/i','_',$canonical_name);
                return $canonical_name;
}

function wmic_call($in_host, $in_cred, $in_class, $in_namespace, $in_columns, $in_condkey, $in_condval) {
	global $wmiexe,$log_location,$dbug,$sep;
	#$in_host = escapeshellarg($in_host);
	$in_cred = escapeshellarg($in_cred);
	$in_namespace = escapeshellarg($in_namespace);
	$in_condval = escapeshellarg($in_condval);
	$wmiquery = 'SELECT '.$in_columns.' FROM '.$in_class; // basic query built
	if ($in_condkey == '' && $in_condval != "''") {
		$wmiquery = $wmiquery.' WHERE '.$in_condkey.'='.$in_condval; // if the query has a filter argument add it in
	}
	$wmiquery = '"'.$wmiquery.'"'; // encapsulate the query in " "

	$wmiexec = $wmiexe.' --namespace='.$in_namespace.' --authentication-file='.$in_cred.' //'.$in_host.' '.$wmiquery. ' 2>/dev/null'; // setup the query to be run and hide error messages

		exec($wmiexec,$wmiout,$execstatus); // execute the query and store output in $wmiout and return code in $execstatus

	if ($execstatus != 0) {
		$dbug = max($dbug, 1);
		echo "\nReturn code non-zero, debug mode enabled!\n";
	}

	if ($dbug == 1) { // basic debug, show output in easy to read format and display the exact execution command
		echo "\n".$wmiexec."\nExec Status: ".$execstatus."\n";
		$sep = "\n";
	}
	if ($dbug == 2) { // advanced debug, logs everything to file for full debug
		$dbug_log = $log_location.'dbug_'.$in_host.'.log';
		$fp = fopen($dbug_log,'a+');
		if ($fp) {
			$dbug_time = date('l jS \of F Y h:i:s A');
			fwrite($fp,"Time: $dbug_time\nWMI Class: $in_class\nCredential: $in_cred\nColumns: $in_columns\nCondition Key: $in_condkey\nCondition Val: $in_condval\nQuery: $wmiquery\nExec: $wmiexec\nOutput:\n".$wmiout[0]."\n".$wmiout[1]."\n");
		} else {
			echo "\nUnable to open log file. Either file does not exist or user does not have access to write to it.\n";
			// Skip future writes.
			$dbug = 1;
		}
		fclose($fp);
	}

	// If client failed parsing code is going to fail so drop out without verbose errors.
	if ($execstatus) {
		echo "WMI Client Output: " . implode("\n", $wmiout) . "\n";
		exit($execstatus);
	}
	// Chomp any errors that wmic might have thrown, but still worked
	$classindex = -1;
	for($i=0;$i<count($wmiout);$i++)
	{
		if(0 === strpos($wmiout[$i], 'CLASS: '))
		{
			$classindex = $i;
			break;
		}
	}
	// Abort is the wmi output isn't normally structured
	if($classindex == -1)
	{
		echo "WMI Class Chomp Failed!\nWMI Client Output: ".implode("\n", $wmiout)."\n";
		exit(1);
	}
	for($i=0;$i<$classindex;$i++)
	{
		unset($wmiout[$i]);
	}
	// reindex the array output
	$wmiout = array_values($wmiout);

	$wmi_count = count($wmiout); // count the number of lines returned from wmic, saves recouting later
	$names = explode('|',$wmiout[1]); // build the names list to dymanically output it
	for($i=2;$i<$wmi_count;$i++) { // dynamically output the key:value pairs to suit cacti
		$data_init = explode('|',$wmiout[$i]);
		$data_init = array_combine($names,$data_init);
		foreach($data_init as $key => $record) { // Strip off extra parenthesis from around values for prettier display
			$record = preg_replace('/\((.*)\)/','${1}',$record);
			$data_stripped[$key] = $record;
		}
		if ($dbug == 2) {
			$dbug_log = $log_location.'dbug_'.$in_host.'.log';
			$fp = fopen($dbug_log,'a+');
			if (!$fp) {
				echo "\nUnable to open log file. Either file does not exist or user does not have access to write to it.\n";
				// Skip future writes.
				$dbug = 1;
			}
			fwrite($fp,$wmiout[$i]."\n");
			fclose($fp);
		}
		$out[$i] = $data_stripped;
	}	

	return $out;
}

function display_help() {
	echo "wmi.php version $GLOBALS[version]\n",
	     "\n",
	     "Usage:\n",
	     "       -h <hostname>         Hostname of the server to query. (required)\n",
	     "       -u <credential path>  Path to the credential file. See format below. (required)\n",
	     "       -d <debug level>      Debug level. (optional, default is none, levels are 1 & 2)\n",
	     "\n",
	     "                             All special characters and spaces must be escaped or enclosed in single quotes!\n",
	     "\n",
	     "Example: wmi.netstats.php -h 10.0.0.1 -u /etc/wmi.pw \n",
	     "\n",
	     "Password file format: Plain text file with the following 3 lines replaced with your details.\n",
	     "\n",
	     "                      username=<your username>\n",
	     "                      password=<your password>\n",
	     "                      domain=<your domain> (can be WORKGROUP if not using a domain)\n",
	     "\n";
	exit;
}

if ($opt_count > 0) { // test to see if using new style arguments and if so default to use them
	if (empty($args['h'])) {
		display_help();
	} else {
		$host = $args['h']; // hostname in form xxx.xxx.xxx.xxx
	}
	if (empty($args['u'])) {
		display_help();
	} else {
		$credential = $args['u']; // credential from wmi-logins to use for the query
	}
	// enables debug mode when the argument is passed (and is valid)
	if (isset($args['d']) && in_array($args['d'],$dbug_levels)) {
		$dbug = $args['d'];
	}
} elseif ($opt_count == 0 && $arg_count > 1) { // if using old style arguments, process them accordingly
	$host = $argv[1]; // hostname in form xxx.xxx.xxx.xxx
	$credential = $argv[2]; // credential from wmi-logins to use for the query
} else {
	display_help();
}

// Go back and change this to a function call and check

$out_adapter_data = wmic_call($host,$credential,'Win32_NetworkAdapter',$namespace,'Name,Description,DeviceID,Index,InterfaceIndex,MACAddress,Speed,AdapterType,NetConnectionID,NetConnectionStatus','','');
$out_stats_data = wmic_call($host,$credential,'Win32_PerfRawData_Tcpip_NetworkInterface',$namespace,'BytesReceivedPerSec,BytesSentPerSec,BytesTotalPerSec,Caption,CurrentBandwidth,Description,Name,OutputQueueLength','','');
$out_config_data = wmic_call($host,$credential,'Win32_NetworkAdapterConfiguration',$namespace,'Index,Description,IPAddress,MACAddress','','');
$out_setting_data_raw = wmic_call($host,$credential,'Win32_NetworkAdapterSetting',$namespace,'Element,Setting','','');
$out_setting_data = array();
foreach($out_setting_data_raw as $record_key => $record) {
	foreach($record as $raw_val) {
		preg_match('/.*\.([^.]+)=([^.]+)$/',$raw_val,$matches);
		$temp_record[$matches[1]] = trim($matches[2],'"');
	}
	$out_setting_data[$record_key] = $temp_record;
}
$out_setting_data_copy = $out_setting_data;
foreach($out_setting_data as $setting_key => $setting_val) {
	foreach($setting_val as $bind_rec_key => $bind_rec_val) {
		foreach($out_config_data as $rec_key => $rec_val) {
			if(array_key_exists($bind_rec_key,$rec_val) && $rec_val[$bind_rec_key] == $bind_rec_val) {
				$prefixedArr = array_combine(array_map(function($k){ return 'config_'.$k; }, array_keys($rec_val)),$rec_val);
				$mergedArr = array_merge($setting_val,$prefixedArr);
			}
		}
	}
	$out_setting_data[$setting_key] = $mergedArr;
}
foreach($out_setting_data as $setting_key => $setting_val) {
	foreach($setting_val as $bind_rec_key => $bind_rec_val) {
		foreach($out_adapter_data as $rec_key => $rec_val) {
			if(array_key_exists($bind_rec_key,$rec_val) && $rec_val[$bind_rec_key] == $bind_rec_val) {
				$prefixedArr = array_combine(array_map(function($k){ return 'adapter_'.$k; }, array_keys($rec_val)),$rec_val);
				$mergedArr = array_merge($setting_val,$prefixedArr);
			}
		}
	}
	$out_setting_data[$setting_key] = $mergedArr;
}
foreach($out_setting_data as $setting_key => &$setting_val) {
	if(array_key_exists('adapter_NetConnectionStatus',$setting_val)) {
		switch ($setting_val['adapter_NetConnectionStatus']){
			case "0":	
				$setting_val['adapter_NetConnectionStatus']="Disconnected";
			break;
			case "1":
				$setting_val['adapter_NetConnectionStatus']="Connecting";
			break;
			case "2":
				$setting_val['adapter_NetConnectionStatus']="Connected";
			break;
			case "3":
				$setting_val['adapter_NetConnectionStatus']="Disconnecting";
			break;
			case "4":
				$setting_val['adapter_NetConnectionStatus']="Hardware not present";
			break;
			case "5":
				$setting_val['adapter_NetConnectionStatus']="Hardware disabled";
			break;
			case "6":
				$setting_val['adapter_NetConnectionStatus']="Hardware malfunction";
			break;
			case "7":
				$setting_val['adapter_NetConnectionStatus']="Media disconnected";
			break;
			case "8":
				$setting_val['adapter_NetConnectionStatus']="Authenticating";
			break;
			case "9":
				$setting_val['adapter_NetConnectionStatus']="Authentication succeeded";
			break;
			case "10":
				$setting_val['adapter_NetConnectionStatus']="Authentication failed";
			break;
			case "11":
				$setting_val['adapter_NetConnectionStatus']="Invalid address";
			break;
			case "12":
				$setting_val['adapter_NetConnectionStatus']="Credentials required";
			break;
			default:
				$setting_val['adapter_NetConnectionStatus']="Unknown";
		}
	}
	if(array_key_exists('adapter_Name',$setting_val)) {
		$setting_val['Modified_Name'] = canonicalize_name($setting_val['adapter_Name']);
	}
	unset($setting_val);
}
foreach($out_setting_data as $setting_key => $setting_val) {
	foreach($setting_val as $bind_rec_key => $bind_rec_val) {
		foreach($out_stats_data as $rec_key => &$rec_val) {
			if(array_key_exists('Name',$rec_val)) {
				$rec_val['Name'] = canonicalize_name($rec_val['Name']);
			}
			$matchPrefixArray = array_combine(array_map(function($k){ return 'Modified_'.$k; }, array_keys($rec_val)),$rec_val);
			if(array_key_exists($bind_rec_key,$matchPrefixArray) && $matchPrefixArray[$bind_rec_key] == $bind_rec_val) {
				$prefixedArr = array_combine(array_map(function($k){ return 'stats_'.$k; }, array_keys($rec_val)),$rec_val);
				$out_setting_data[$setting_key] = array_merge($setting_val,$prefixedArr);
			}
		}
		unset($rec_val);
	}
}
$bad_adapter_count = 0;
foreach($out_setting_data as $setting_key => $setting_val) {
	if (!array_key_exists("stats_Name",$setting_val) || (array_key_exists("config_MACAddress",$setting_val) && $setting_val['config_MACAddress'] == 'null')) {
		$bad_adapter_count += 1;
		if($dbug == 2){

			$dbug_log = $log_location.'dbug_'.$host.'.log';
			$fp = fopen($dbug_log,'a+');
			if (!$fp) {
				echo "\nUnable to open log file. Either file does not exist or user does not have access to write to it.\n";
				// Skip future writes.
				$dbug = 1;
			}
			fwrite($fp,"setting_index: ".$setting_key."\tadapter_Name: '".$setting_val['adapter_Name']."'\tModified_Name: '".$setting_val['Modified_Name']."'\n");
			fclose($fp);
		}
		unset($out_setting_data[$setting_key]);
	}
}	
if ($dbug == 2) {
	$dbug_log = $log_location.'dbug_'.$host.'.log';
	$fp = fopen($dbug_log,'a+');
	if (!$fp) {
		echo "\nUnable to open log file. Either file does not exist or user does not have access to write to it.\n";
		// Skip future writes.
		$dbug = 1;
	}
	fwrite($fp,"Found ".$bad_adapter_count." unrecognizable adapters.\n");
	if($bad_adapter_count > 0) {
		fwrite($fp,"Settings Bind keys:\n");
		foreach($out_setting_data_copy as $record) fwrite($fp,"\tsetting_deviceID: ".$record['DeviceID']."\tsetting_index: ".$record['Index']."\n");
		fwrite($fp,"Config Bind keys:\n");
		foreach($out_config_data as $record) fwrite($fp,"\tconfig_Index: ".$record['Index']."\n");
		fwrite($fp,"Adapter Bind keys:\n");
		foreach($out_adapter_data as $record) fwrite($fp,"\tadapter_deviceID: ".$record['DeviceID']."\tadapter_Name: ".$record['Name']."\t\t\t\tModified_Name: ".canonicalize_name($record['Name'])."\n");
		fwrite($fp,"Stats Bind keys:\n");
		foreach($out_stats_data as $record) fwrite($fp,"\tstats_Name: ".$record['Name']."\n");
	
	}
	fclose($fp);
}

foreach($out_setting_data as $adapter) {
	foreach($adapter as $key => $val) {
		$output .= $key . ':' . str_replace(array(':',' '), array('','_'), $val) . $sep;
	}
	$output .= "\n";
}
#var_dump($out_setting_data);

if ($dbug == 2) {
		$dbug_log = $log_location.'dbug_'.$host.'.log';
		$fp = fopen($dbug_log,'a+');
		if (!$fp) {
			echo "\nUnable to open log file. Either file does not exist or user does not have access to write to it.\n";
			// Skip future writes.
			$dbug = 1;
		}
		fwrite($fp,"Output to Cacti: $output\n\n\n");
		fclose($fp);
}

echo substr($output,0,-1); // strip of the trailing space just in case cacti doesn't like it
