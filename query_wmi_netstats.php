<?php
 
/* do NOT run this script through a web browser */
if (!isset($_SERVER["argv"][0]) || isset($_SERVER['REQUEST_METHOD'])  || isset($_SERVER['REMOTE_ADDR'])) {
   die("<br><strong>This script is only meant to run at the command line.</strong>");
}
 
# deactivate http headers
$no_http_headers = true;
# include some cacti files for ease of use
include(dirname(__FILE__) . "/../include/global.php");
include(dirname(__FILE__) . "/../lib/snmp.php");
 
# define all OIDs we need for further processing
$oids = array(
        "index"         => ".1.3.6.1.2.1.2.2.1.1",
        );
$xml_delimiter          =  "!";
 
# all required input parms
$hostname       	= $_SERVER["argv"][1];
$wmi_cred		= $_SERVER["argv"][2];
$cmd			= $_SERVER["argv"][3];
if (isset($_SERVER["argv"][4])) { $query_field = $_SERVER["argv"][4]; };
if (isset($_SERVER["argv"][5])) { $query_index = $_SERVER["argv"][5]; };
 
# get number of snmp retries from global settings
$snmp_retries   = read_config_option("snmp_retries");

# -------------------------------------------------------------------------
# main code starts here
# -------------------------------------------------------------------------

 
# -------------------------------------------------------------------------
# script MUST respond to index queries
#       the command for this is defined within the XML file as
#       <arg_index>index</arg_index>
#       you may replace the string "index" both in the XML and here
# -------------------------------------------------------------------------
#       php -q <script> <parms> index
# will list all indices of the target values
# e.g. in case of interfaces
#      it has to respond with the list of interface indices
# -------------------------------------------------------------------------
function get_netstats($host,$cred) {
	$phpExec = trim(`which php`);
	if(empty($phpExec)) {
		echo "Could not find php executable!\n";
		exit;	
	}
	$path = substr(__FILE__, 0, strlen(__FILE__) - strlen(basename(__FILE__)));
	$phpExecQuery = $phpExec." ".$path."wmi.netstats.php -h ".$host." -u ".$cred." 2>/dev/null";
	exec($phpExecQuery,$out_netstats,$execstatus);
	$dbug = 0;
	if ($execstatus != 0) {
                $dbug = max($dbug, 1);
                echo "\nReturn code non-zero, debug mode enabled in query!\n";
        }

        if ($dbug == 1) { // basic debug, show output in easy to read format and display the exact execution command
                echo "\n".$wmiexec."\nExec Status: ".$execstatus."\n";
                $sep = "\n";
        }
	$temp_netstat_rows = $out_netstats;
	$result = array();
	foreach($temp_netstat_rows as $row_key => $row) {
		$keypairs = explode(" ",$row);
		$result[$row_key] = array();
		foreach($keypairs as $keypair) {
			list($k,$v) = explode(":",$keypair);
			$result[$row_key] = array_merge($result[$row_key],array($k => $v));
		}
	}
	return $result;
}

if ($cmd == "index") {
        # retrieve all indices from target
#        $return_arr = reindex(cacti_snmp_walk($hostname, $snmp_community, $oids["index"], $snmp_version, $snmp_auth_username,
#        		$snmp_auth_password, $snmp_auth_protocol, $snmp_priv_passphrase, $snmp_priv_protocol, $snmp_context, $snmp_port, $snmp_timeout, $snmp_retries, $max_oids, SNMP_POLLER));

	$return_arr = reindex(get_netstats($hostname,$wmi_cred),"adapter_Index");
 
        # and print each index as a separate line
        for ($i=0;($i<sizeof($return_arr));$i++) {
                print $return_arr[$i] . "\n";
        }
 
# -------------------------------------------------------------------------
}elseif ($cmd == "query" && isset($query_field)) {
	$netstats_arr = get_netstats($hostname,$wmi_cred);
        $arr_index = reindex($netstats_arr,"adapter_Index");
        $arr = reindex($netstats_arr,$query_field);
 
        for ($i=0;($i<sizeof($arr_index));$i++) {
                print $arr_index[$i] . $xml_delimiter . $arr[$i] . "\n";
        }
# -------------------------------------------------------------------------
}elseif ($cmd == "get" && isset($query_field) && isset($query_index)) {
	$netstats_arr = get_netstats($hostname,$wmi_cred);
	foreach($netstats_arr as $row_key => $row) {
		if(array_key_exists($query_field,$row) && $row['adapter_Index'] == $query_index)
			print($row[$query_field]);
	}
} else {
        print "Invalid use of script query, required parameters:\n\n";
        print "    <hostname> <cmd>\n";
}
 
function reindex($arr,$field) {
        $return_arr = array();
 
        for ($i=0;($i<sizeof($arr));$i++) {
                $return_arr[$i] = $arr[$i][$field];
        }
 
        return $return_arr;
}
