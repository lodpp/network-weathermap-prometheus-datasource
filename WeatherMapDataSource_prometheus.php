<?php

// Assuming the prometheus query is returning a single result, in bits/second.

// Query example I use to monitor the bw on a Cisco Switch:
// ` irate(ifHCInOctets{ifName="Gi0/1",instance="192.168.178.32"}[2m])*8 `
//
// irate()[2m] => delta between the last 2 points in the serie, I look
//                         in serie up to 2mins in the past to find them. 
// instance    => the switch I want to check the interface in ( ifName )

// single value test
//$prom_query = 'prometheus:https:localhost:9090:irate(ifHCInOctets{ifName="Gi0/13",instance="192.168.178.32"}[2m])*8';

// dual value test
$prom_query = 'prometheus:https:localhost:9090:irate(ifHCInOctets{ifName="Gi0/13",instance="192.168.178.32"}[2m])*8:irate(ifHCOutOctets{ifName="Gi0/13",instance="192.168.178.32"}[2m])*8';

$prom_regex_single_value = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+):([^:]+)$/';
$prom_regex_dual_value   = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+):([^:]+)\:([^:]+)$/';


class WeatherMapDataSource_prometheus extends WeatherMapDataSource {

    function Init(&$map)
    {
        // nothing the see here ( as of now )
        return TRUE
    }

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

    // Get data from prometheus db via its API
    function GetQuery($url)
    {
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
    		// Only In query provided
    		$proto       = $matches[1];
            $remote_host = $matches[2];
            $remote_port = $matches[3];
            $in_query    = $matches[4];
    
            $query = urlencode($in_query);
            $url = "http://$remote_host:$remote_port/api/v1/query?query=$query";
            $inbw = GetQuery($url);
    
            $outbw = $inbw;
    	}
    
    	$data_time = time();
        return ( array($inbw,$outbw,$data_time) );
    }
}    

?>

// vim:ts=4:sw=4:

