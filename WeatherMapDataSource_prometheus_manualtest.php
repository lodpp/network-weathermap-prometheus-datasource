<?php

// Assuming the prometheus query is returning a single result, in bits/second.

// Query example I use to monitor the bw on a Cisco Switch:
// ` irate(ifHCInOctets{ifName="Gi0/1",instance="192.168.178.32"}[2m])*8 `
//
// irate()[2m] => delta between the last 2 points in the serie, I look
//                         in serie up to 2mins in the past to find them. 
// instance    => the switch I want to check the interface in ( ifName )


//$prom_query = 'prometheus:http:localhost:9090:192.168.178.32:Gi0/13:ifHCInOctets:ifHCOutOctets';
$prom_query = 'prometheus:http:localhost:9090:192.168.178.32:Gi0/13:ifHCInOctets:ifHCOutOctets';


// regexes for single / dual value prom query
$prom_regex_single_value = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+)\:([a-zA-z0-9-_.]+)\:([^:]+)\:([^:]+)$/';
$prom_regex_dual_value   = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+)\:([a-zA-z0-9-_.]+)\:([^:]+)\:([^:]+)\:([^:]+)$/';


// Parse the plugin line and recognise prom plugin
function Recognise($targetstring)
{
    global $prom_regex_single_value, $prom_regex_dual_value;

    if( preg_match($prom_regex_single_value,$targetstring,$matches) ||
        preg_match($prom_regex_dual_value,$targetstring,$matches) )
    {
        return TRUE;
    }
    else
    {
        return FALSE;
    }
}

function ReadData($targetstring)
{
	global $prom_regex_single_value, $prom_regex_dual_value;

    $inbw = NULL;
    $outbw = NULL;
    $data_time=0;

    if(preg_match($prom_regex_dual_value,$targetstring,$matches))
	{
		// In and Out query provided
	    $proto       = $matches[1];
		$remote_host = $matches[2];
		$remote_port = $matches[3];
		$instance    = $matches[4];
		$intf        = $matches[5];
        $in_series   = $matches[6];
        $out_series  = $matches[7];

        $url = $proto . '://' . $remote_host . ':' . $remote_port . '/api/v1/query?query=';

        $in_query  = 'irate(' . $in_series  . '{ifName="' . $intf . '",instance="' . $instance . '"}[2m])*8';
        $out_query = 'irate(' . $out_series . '{ifName="' . $intf . '",instance="' . $instance . '"}[2m])*8';

        // IN
		$query = urlencode($in_query);
		$call = $url . $query;
		$inbw = GetPromData($call);

        // OUT
		$query = urlencode($out_query);
		$call = $url . $query;
		$outbw = GetPromData($call);
	}
	elseif(preg_match($prom_regex_single_value,$targetstring,$matches))
	{
		// Only In query provided
        $proto       = $matches[1];
        $remote_host = $matches[2];
        $remote_port = $matches[3];
        $instance    = $matches[4];
        $intf        = $matches[5];
        $in_series   = $matches[6];

        $url = $proto . '://' . $remote_host . ':' . $remote_port . '/api/v1/query?query=';

        $in_query = 'irate(' . $in_series . '{ifName="' . $intf . '",instance="' . $instance . '"}[2m])*8';

        $query = urlencode($in_query);
        $call = $url . $query;
        $inbw = GetPromData($call);

        $outbw = $inbw;
	}

	$data_time = time();
    return ( array($inbw,$outbw,$data_time) );
}

function GetPromData($url)
{
	// this is wget like :)
	$content = file_get_contents($url);

	if ($content)
	{
	    $json_data = json_decode($content, true);

		if (isset($json_data["data"]["result"][0]["value"][1]))
		{
			$bw = round(intval($json_data["data"]["result"][0]["value"][1]));
		}
		else
		{
			$bw = NULL;
		}
	}
	else
	{
		$bw = NULL;
	}

	return $bw;
}

// Start working
$valid_request = Recognise($prom_query);

if ($valid_request)
{
    echo "The Request is valid\n";
}
else
{
    echo "The Request is invalid\n";
}

$output = ReadData($prom_query);

print_R($output);

?>

// vim:ts=4:sw=4:

