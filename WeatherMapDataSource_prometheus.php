<?php

$prom_query = 'prometheus:https:localhost:9090:irate(ifHCInOctets{ifName="Gi0/1",instance="192.168.178.32"}[2m])*8:irate(ifHCOutOctets{ifName="Gi0/1",instance="192.168.178.32"}[2m])*8';

$prom_regex_single_value = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+):([^:]+)$/';
$prom_regex_dual_value   = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+):([^:]+)\:([^:]+)$/';

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
	    $proto       = $matches[1];
		$remote_host = $matches[2];
		$remote_port = $matches[3];
		$in_query    = $matches[4];
		$out_query   = $matches[5];

		$query = urlencode($in_query);
		$url = "http://$remote_host:$remote_port/api/v1/query?query=$query";
		$inbw = GetQuery($url);

		$query = urlencode($out_query);
		$url = "http://$remote_host:$remote_port/api/v1/query?query=$query";
		$outbw = GetQuery($url);
    }
	elseif(preg_match($prom_regex_single_value,$targetstring,$matches))
	{
		$proto       = $matches[1];
        $remote_host = $matches[2];
        $remote_port = $matches[3];
        $in_query    = $matches[4];

        $query = urlencode($in_query);
        $url = "http://$remote_host:$remote_port/api/v1/query?query=$query";
        $inbw = GetQuery($url);

        $outbw = $inbw
	}

	$data_time = time();
    return ( array($inbw,$outbw,$data_time) );
}

function GetQuery($url)
{
    $json = file_get_contents($url);
    $json_data = json_decode($json, true);

	$bw = round(intval($json_data["data"]["result"][0]["value"][1]));

	return $bw;
}

// Start working
$valid_request = Recognise($prom_query);

if ($valid_request)
{
    echo "the Request is valid\n";
}
else
{
    echo "The Request is invalid\n";
}

$output = ReadData($prom_query);

print_R($output);

?>

// vim:ts=4:sw=4:

