#!/usr/bin/php
<?php


#################
# SOME SETTINGS #
#################

/**
* You must either define the PUBLIC_IPV4 constant if you have a static address
**/
// define('PUBLIC_IPV4', '82.65.162.75');

/**
* Or define the DDNS_HOSTNAME below. We'll do the lookup
**/
define('DDNS_HOSTNAME', 'home.mabox.eu');

/**
* Both values CAN'T BE SET at the same time
**/

define('TRANSMISSION_USER', 'debian-transmission');
define('TUNNEL_GROUP', 'vpntunnel');


##
# WARNING : make sure you use services that support both IPv4 and IPv6
# ipinfo.io doesn't seem to support IPv6. This may affect results
##
$ipcheckers = [
	'http://ifconfig.io/all.json'	=> 'json',
	'https://ifconfig.co/json'	=> 'json',
	'https://ipinfo.io'		=> 'json'
];

################
# END SETTINGS #
################


$local_ipv6 = null;

function output($str, $type = 'i'){
    switch ($type) {
        case 'e': //error
            fwrite(STDERR, "\033[31m ✗ ".$str." \033[0m".PHP_EOL);
        break;
        case 's': //success
		fwrite(STDOUT, "\033[32m ✓ ".$str." \033[0m".PHP_EOL);
        break;
        case 'w': //warning
		fwrite(STDERR, "\033[33m ⚠ ".$str." \033[0m".PHP_EOL);
        break;  
        case 'i': //info
		fwrite(STDOUT, "\033[36m ℹ ".$str." \033[0m".PHP_EOL);
        break;      
        default:
        	fwrite(STDOUT, $str.PHP_EOL);
        break;
    }
}

function get_ip($host, $version = 4, $format = 'raw') {
	$host_nice = @parse_url($host)['host'] ?? $host;

	output("\tFetching \033[1mIPv".$version);

        $c = shell_exec('curl --connect-timeout 5 -'.$version.' -s '.$host);
        if ($c === false || empty($c)) {
                //output("\tCould not fetch IPv".$version." from \"".$host_nice."\"", 'w');
                return false;
        }

        switch ($format) {
                case 'json':
                        if (false === $d = @json_decode($c)) {
                                output("\tUnable to decode the JSON response for \"".$host_nice."\"", 'w');
                                return false;
                        }
                        //TODO: should be dynamic
                        $ip = $d->ip ?? null;

			if (is_null($ip)) {
				output("\tThe fetched IP is null", 'w');
				return false;
			};
                break;
                case 'raw':
                        $ip = $c;
                break;
                default:
                        output("\tUnknown response format", 'w');
                        return false;
        }
	output("\tPublic IPv".$version." is ".trim($ip));
	return trim($ip);
}


function check_ip($host, $format = 'raw') {
	$host_nice = @parse_url($host)['host'] ?? $host;
	$alert = false;
	$failed = 0;

        // We check the presence of a public IPV4
        $ipv4 = get_ip($host, 4, $format);
        if ($ipv4 !== false && defined('PUBLIC_IPV4')) {
                if ($ipv4 == PUBLIC_IPV4) {
                        output("\tThis IP is publically exposed!", 'e');
                        $alert = true;
                }
                else {
                        output("\tThis IP is safe", 's');
                }

        }
        else {
		output("\tCould not fetch public IPv4", 'w');
                ++ $failed;
        }

	// If there is at least one local IPv6 found
	if (defined('HAS_IPV6')) {
		//TODO: not very clean
		global $local_ipv6;

		// We check the presence of a public IPv6
		$ipv6 = get_ip($host, 6, $format);
		if ($ipv6 !== false && count($local_ipv6) > 0) {
			if (in_array($ipv6, $local_ipv6)) {
				output("\tThis IP is publically exposed!", 'e');
				$alert = true;
			}
			else {
				output("\tThis IP is safe", 's');
			}
		}
		else {
			output("\tCould not fetch IPv6, assuming this is safe AS LONG AS this IP checker supports IPv6", 's');
			++ $failed;
		}
	}


	if (intval($failed) == 2) {
		output("\tBoth IPv4 and IPv6 tests have failed", 'w');
		return false;
	}

	if ($alert === true) {
		block_transmission();
	}

	return true;
}

function block_transmission() {
        output('Stopping Transmission daemon immediately', 'i');
        if (false !== @shell_exec('sudo /bin/systemctl stop transmission-daemon.service')) {

                $running = @shell_exec('/bin/systemctl is-active transmission-daemon.service');
                if ($running !== false && trim($running) == 'inactive') {
                        output('Transmission daemon was stopped', 's');
                        output('Please do not forget that the Transmission daemon could be restarted in case of an unexpected reboot.', 'w');
                }
                else {
                        output('We have tried to stop Transmission but it is still running according to Systemd', 'e');
                }
        }
        else {
                output('Could not stop transmission daemon, immediate action is required', 'e');
        }
}

function check_vpn_service() {
	output('Checking whether VPN service is running', 'i');
	$active = shell_exec('systemctl  is-active  openvpn@openvpn.service');
	if (trim($active) == 'active') {
		output("VPN service is Active according to Systemd", 's');
		return true;
	}
	else {
		output("VPN service is Inactive according to Systemd", 'w');
		return false;
	}
}

function check_transmission_group() {
	output('Checking whether Transmission user belongs to the '.TUNNEL_GROUP.' group', 'i');
	$group = shell_exec('id -gn '.TRANSMISSION_USER);
	if ($group !== false && ! empty($group)) {
		if (trim($group) == TUNNEL_GROUP) {
			output('The Transmission user belongs to the '.TUNNEL_GROUP.' group', 's');
			return true;
		}
		else {
			output("The Transmission user doesn't belong to the ".TUNNEL_GROUP." group. \033[4mYour Public IP may be exposed even if the VPN tunnel is working.", 'e');
			return false;
		}
	}
	else {
		output('Unable to check Transmission daemon group', 'w');
		return null;
	}
}

function get_ipv6_addresses() {
	global $local_ipv6;

	output("Getting local IPv6 addresses");
	$cmd = shell_exec('ip -6 -o a');
	if ($cmd !== false && !empty($cmd)) {
		if (preg_match_all('~([0-9a-f]{1,4}:+){1,7}[0-9a-f]{1,4}~im', $cmd, $ips) && count($ips[0]) > 0) {
			define('HAS_IPV6', true);
			foreach ($ips[0] as $i) {
				// Dropping local IPs
				if (substr($i, 0, 4) == 'fe80') {
					continue;
				}
				$local_ipv6[] = $i;
				output("\t- ".$i, 's');
			}
		}
		else {
			output("\tNo IP found", 'i');
		}
	}
	else {
		output("Could not get local IPs", 'w');
	}
}

function get_ddns_ip() {
	output("Looking for your DDNS IP");
	$ip = gethostbyname(DDNS_HOSTNAME);
	if ($ip !== false && !empty($ip)) {
		output("Your DDNS IP is: ".$ip, 's');
		if (! defined('PUBLIC_IPV4')) {
			define('PUBLIC_IPV4', $ip);
			return true;
		}
		else {
			output("The \"PUBLIC_IPV4\" constant is already defined", 'e');
			return false;
		}
	}
	else {
		output("Could not get your DDNS IP", 'e');
		return false;
	}
}

function dienow() {
	output(' ', 'a');
	output("\033[1mThe output of the previous command prevents me from going on. Please fix and retry", 'e');
	die(1);
}

# Starting the checks here
output(' ', 'a');
output("\033[1;4;36mTHE VPN TUNNEL FOR TRANSMISSION KILL SWITCH \033[0m", 'a');
output(' ', 'a');

# Checking that Transmission daemon has the right group
if (true !== check_transmission_group()) {
	dienow();
}
output(' ', 'a');

# We check the VPN service
if (true !== check_vpn_service()) {
	dienow();
}
output(' ', 'a');

# Getting the DDNS IP
if (defined('DDNS_HOSTNAME')) {
	if (! get_ddns_ip()) {
		dienow();
	}
	output(' ', 'a');
}

# Getting local IPv6 addresses
get_ipv6_addresses();
output(' ', 'a');

# Then we check the public IP
$failed = 0;
foreach ($ipcheckers as $host => $format) {
	$host_nice = @parse_url($host)['host'] ?? $host;

	output('Checking public IPs on "'.$host_nice.'"', 'i');
	$result = check_ip($host, $format);

	if ($result === false) {
		++ $failed;
        	output('IP check failed on "'.$host_nice.'"', 'w');
		output(' ', 'a');
	}
	else {
		output(' ', 'a');
		output('All checks done', 'i');
		break;
	}
}

if ($failed == count($ipcheckers)) {
	output(' ', 'a');
	output("All tests have failed!. Quick action is required.", 'e');
	die(1);
}

exit;
