#!/usr/bin/php
<?php
# Icinga Plugin Script (Check Command). Check results of the ftp backup by reading and analysing ftp log files.
# https://github.com/xyhtac/check_ftp_log/
# Max.Fischer <dev@monologic.ru>
# Tested with Debian GNU/Linux 12 (bookworm) with Icinga v2.14.6
# 
# supposed to be placed in nagios plugins directory, i.e.:
# /usr/lib/nagios/plugins/check_ftp_log.php - CHMOD 755
#
# Usage example:
# ./check_ftp_log.php --ftp-host 10.0.1.1 --bak-file-pattern web_storage_ --ftp-username username --ftp-password secret_password --log-age 36
#
# ICINGA CONFIG DEFINITIONS:
# Configure Host
# object Host "www.monitored-website.com" {
#	import "generic-host"
#	address = "11.12.13.14"
#	vars.bak_file_pattern["FTP Backup Storage"] = "www.monitored-website.com_storage-"
#	vars.bak_file_pattern["FTP Backup Code"] = "www.monitored-website.com_code-"
#	vars.bak_file_pattern["FTP Backup Database"] = "www.monitored-website.com_database-"
#	vars.log_age = "32"
#}
#
#Configure service
#apply Service for (identifier => pattern in host.vars.bak_file_pattern) {
#	display_name = identifier
#	check_interval = 3h
#	retry_interval = 30m
#	check_command = "check_ftp_log"
#	vars.ftp_host = "10.0.0.1"
#	vars.ftp_port = "21"
#	vars.ftp_ssl = "0"
#	vars.ftp_username = "username"
#	vars.ftp_password = "secret_password"
#	vars.logfile_age = "14"
#	vars.log_age = host.vars.log_age
#	vars.data_source = host.vars.data_source
#	vars.ftp_path = host.vars.ftp_path
#	vars.filename_pattern_ok = host.vars.filename_pattern_ok
#	vars.filename_pattern_warn = host.vars.filename_pattern_warn
#	vars.bak_file_pattern = pattern
#}
#
#Configure Command
#object CheckCommand "check_ftp_log" {
#	import "plugin-check-command"
#	command = [ PluginDir + "/check_ftp_log.php" ]
#	arguments = {
#		"--ftp-host" = "$ftp_host$"
#		"--ftp-port" = "$ftp_port$"
#		"--ftp-ssl" = "$ftp_ssl$"
#		"--ftp-username" = "$ftp_username$"
#		"--ftp-password" = "$ftp_password$"
#		"--log-age" = "$log_age$"
#		"--logfile-age" = "$logfile_age$"
#		"--bak-file-pattern" = "$bak_file_pattern$"
#		"--data-source" = "$data_source$"
#		"--ftp-path" = "$ftp_path$"
#		"--filename-pattern-ok" = "$filename_pattern_ok$"
#		"--filename-pattern-warn" = "$filename_pattern_warn$"
#	}
#}


# default values for externally definable parameters 
$cfg['ftp-host'] = "10.0.0.1";					# storage ftp hostname.
$cfg['ftp-port'] = "21";					# storage ftp port.
$cfg['ftp-ssl'] = "0";						# storage ftp ssl mode.
$cfg['ftp-path'] = "";						# location of logfiles on the storage ftp
$cfg['ftp-username'] = "username";				# login for storage ftp.
$cfg['ftp-password'] = "secret_password";			# password for storage ftp.
$cfg['log-age'] = 336;						# log age threshold in hours.	
$cfg['logfile-age'] = 16;					# logfile age threshold in days.
$cfg['min-log-entry'] = 120;					# minimal size of logfile entry in bytes.
$cfg['data-source'] = 'log';					# script may get data either from text 'log' or from 'filename'
$cfg['filename-pattern-ok'] = "OK";				# Successfull backup flag for filename mode
$cfg['filename-pattern-warn'] = "WARN";				# Warning flag for filename mode
$cfg['cache'] = "/tmp";
$cfg['cache-lifetime'] = 700000;
$cfg['cache-expected-size'] = 100;

# initial variables
define( "STATUS_OK", 0 );
define( "STATUS_WARNING", 1 );
define( "STATUS_CRITICAL", 2 );
define( "STATUS_UNKNOWN", 3 );
$i = 0;
$n = 0;
$full_log = "";
$filename = "";
$flag = "";


# define timezone and set now_time timespamp
date_default_timezone_set('Europe/Madrid');
$now = date("d.m.Y H:i:s");
$now_time = strtotime($now);

# extract parameters from command line to $cfg
foreach ($argv as &$val) {
	if (preg_match("/\-\-/", $val) && $argv[$i+1] && !preg_match("/\-\-/", $argv[$i+1]) ) {
		$varvalue = $argv[$i+1];
		$varname = str_replace("--", "", $val);
		$cfg[$varname] = $varvalue;
	}
	$i++;
}

# Throw error if filename pattern was not specified in the input
if (!$cfg['bak-file-pattern']) { 
		echo "No filename pattern specified. Can't run.";
		exit(STATUS_UNKNOWN);
}

# open ftp connection
if ($cfg['ftp-ssl'] == '1') {
	$ftp_conn = ftp_ssl_connect($cfg['ftp-host'], $cfg['ftp-port']);
	ftp_set_option($ftp_conn, FTP_USEPASVADDRESS, false);
} else {
	$ftp_conn = ftp_connect($cfg['ftp-host'], $cfg['ftp-port']);
}
$login_result = ftp_login($ftp_conn, $cfg['ftp-username'], $cfg['ftp-password']);

# Throw error if there is a problem with ftp connection.
if (!$login_result) { 
		echo "FTP connection error.";
		exit(STATUS_UNKNOWN);
}

# set mode, list files
ftp_pasv($ftp_conn, true);
$loglist = ftp_nlist($ftp_conn, "./".$cfg['ftp-path']);

# Throw error if file list was empty.
if (!$loglist) { 
		echo "Logfile directory empty.";
		exit(STATUS_UNKNOWN);
}

$threshold = $cfg['log-age'];
$ptrn = $cfg['bak-file-pattern'];

# set newest as future timestamp.
$newest = $now_time + 14400;
if ( $cfg['data-source'] == 'log'  ) {
	
	# Log record extraction pattern. Using Regex syntax.
	# Here we are trying to find the specific entry that fits the standard log record for successful STOR operation. 
	# Example:
	# (000051) 01.09.2022 2:22:55 - ftp_user (10.0.1.2)> 226 Successfully transferred "/path/to/file/filename_pattern_2022_09_01_010000_6539791.bak"
	# Note that line endings (\r and \n) should always be added for the lazy search to work properly. 

        $pattern = '\(\d{5,7}\) (.+?) - (.+?) \((.+?)\)\> 226 Successfully transferred \"(.+?)'.$cfg['bak-file-pattern'].'(.+?)\"';
        $regex_filter ="/".$pattern."/";
        $regex_matcher ="/".$pattern."\r\n/";

        foreach ($loglist as &$fname) {
               # echo "trying $fname\n";
		$loglines = array( "" );
		
		# skip non-log filenames
		$nameparts = explode(".", $fname);
		$extension = $nameparts[ count($nameparts) - 1 ];
		if ($extension != 'log') {
			continue;
		} 
		
		# Filename date extraction pattern.
		# here we define log filename pattern, e.g. ./fzs-2022-09-01.log
		# date in the log filename is used to list and filter logfile by its age 
		# Note that file attributes are ignored. Using Regex syntax.
		
		$date_pattern = '/(\d{4}-\d{2}-\d{2})/';		# Example: ./fzs-2022-09-01.log
		preg_match_all($date_pattern, $fname, $res, PREG_PATTERN_ORDER);
		if ($res[1][0]) {
			$file_time = strtotime($res[1][0]);
			$days_diff = round(($now_time - $file_time) / 86400);
		} else {
			$days_diff = 0;
		}

		# compose cache path
		$pathparts = explode("/", $fname);
		$pathfilename = $pathparts[ count($pathparts) - 1 ];
		$cachedLogFilename = $cfg['cache']."/".$pathfilename;
		
		# use cache if we like it
		if ( $days_diff > 1 && file_exists($cachedLogFilename) && time() - filemtime($cachedLogFilename) < $cfg['cache-lifetime'] && filesize($cachedLogFilename) > $cfg['cache-expected-size']) {
			$loglines = explode("\r\n", file_get_contents($cachedLogFilename));
			# echo "Using cache for $fname\n";
			
		} else {
			# echo "No-cache for $fname\n";
			if (count(ftp_nlist($ftp_conn, $fname)) == 1) {
				if ($days_diff < $cfg['logfile-age'] ) { 
					$logid = fopen('php://temp', 'r+');
					# echo "Trying to open $fname\n";
					ftp_fget($ftp_conn, $logid, $fname, FTP_BINARY, 0);
					$fstats = fstat($logid);
					fseek($logid, 0);
					if ($fstats['size'] > 0) {
						$logtext = fread($logid, $fstats['size']);
					}
					fclose($logid);

					$loglines = explode("\r\n", $logtext);				
					
					# write cache if there's no such file or today
					if ( ( !file_exists($cachedLogFilename) || $days_diff <= 1 ) && $logtext ) {
						# echo "Saving cache for $cachedLogFilename\n";
						file_put_contents( $cachedLogFilename, $logtext );
					}
					
					# $full_log = $full_log."/n".$logtext;
				}
			}
		}
		
		foreach ($loglines as &$logentry) {
			if (preg_match($regex_filter, $logentry)) {
				# echo "adding line to log: \n $logentry \n";
				$full_log = $logentry."\r\n".$full_log;
			}
		}
        }

	
	ftp_close($ftp_conn);
	
	# Throw error if log contents is shorter than min-log-entry.
	if (strlen($full_log) < $cfg['min-log-entry']) { 
		echo "Log file is too short.";
		exit(STATUS_UNKNOWN);
	}

	# $pattern = '/\(\d{5,7}\) (.+?) - (.+?) \((.+?)\)\> 226 Successfully transferred \"(.+?)'.$cfg['bak-file-pattern'].'(.+?)\"\r\n/';
	
	preg_match_all($regex_matcher, $full_log, $lines, PREG_PATTERN_ORDER);
	$logdates = $lines[1];

	foreach ($logdates as &$datetime) {
		
			$backup_time = strtotime($datetime);
			$backup_age = round ( ($now_time - $backup_time) / 3600 );
						
			if ($backup_age < $newest) {
				$newest = $backup_age;
				$ftpuser = $lines[2][$n];
				$filename = $cfg['bak-file-pattern'].$lines[5][$n];
				$newesttime = $datetime;
			} 

			$n++;
	}

	if ($newest <= $threshold && $filename) {
		echo "Last backup: $newest hours ago ($newesttime)\nSuccessfull STOR: $filename by user $ftpuser\n";
		exit(STATUS_OK);
	} elseif ($newest > $threshold && $filename) {
		echo "Backup expired: newest $newest hours ago ($newesttime). Expected $threshold hours. \nSuccessfull STOR: $filename by user $ftpuser.\n";
		exit(STATUS_WARNING);
	} elseif (!$filename) {
		echo "No relevant backup found for pattern $ptrn\n";
		exit(STATUS_CRITICAL);
	}
} elseif ( $cfg['data-source'] == 'filename' ) {
	foreach ($loglist as &$fname) {
		# Filename date extraction pattern for filename mode.
		# here we define file or folder name pattern, e.g. ./FLAG_16.09.2022_22-13-05_OK
		# backup date and status are extracted from the flag folder or filename. Using Regex syntax.
		# 
		$backup_age = $now_time;
		$date_pattern = '/(\d{2})\.(\d{2})\.(\d{4})_(\d{2})-(\d{2})-(\d{2})_(\w+)/';
		preg_match_all($date_pattern, $fname, $res, PREG_PATTERN_ORDER);

		if ( isset( $res[6][0] ) ) {
			$datetime = $res[3][0]."-". $res[2][0]."-". $res[1][0]." ". $res[4][0].":". $res[5][0].":". $res[6][0];
			$file_time = strtotime($datetime);
			$backup_age = $now_time - $file_time;
		}
				
		if ( preg_match("/".$cfg['bak-file-pattern']."/",$fname) && $backup_age < $newest && isset( $res[7][0] ) ) { 
			$newest = $backup_age;
			$filename = $fname;
			$newesttime = $datetime;
			$flag = $res[7][0];
		}
	}
	
	$newest = round($newest / 3600);
	
	if ($newest <= $threshold && $flag == $cfg['filename-pattern-ok']) {
		echo "Last backup: $newest hours ago ($newesttime)\n Completed successfully $filename. \n";
		exit(STATUS_OK);
	} elseif ($newest > $threshold && $flag == $cfg['filename-pattern-ok']) {
		echo "Backup expired: newest $newest hours ago ($newesttime). Expected $threshold hours. \nCompleted successfully $filename. \n";
		exit(STATUS_WARNING);
	} elseif ($newest <= $threshold && $flag == $cfg['filename-pattern-warn']) {
		echo "Last backup: $newest hours ago ($newesttime)\nLast backup completed with Warning $filename. \n";
		exit(STATUS_WARNING);
	} elseif ($newest > $threshold && $flag == $cfg['filename-pattern-warn']) {
		echo "Last backup: $newest hours ago ($newesttime). Expected $threshold hours. \nExpired. Last backup completed with Warning $filename. \n";
		exit(STATUS_WARNING);
	} elseif (!$flag) {
		echo "No relevant backup found for pattern $ptrn\n";
		exit(STATUS_CRITICAL);
	}
}

?>
