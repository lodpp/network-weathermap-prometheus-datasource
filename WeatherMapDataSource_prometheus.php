<?php

// This is a Prometheus datasource plugin Proof of Concept for network-weathermap
// On my setup, I use the prometheus SNMP scrapper on a Cisco device ( using the basic if_mib module )
// I have the ifHCInOctets/ifHCOutOctets series with the instance label and interface_name label in it.

// The plugins is using the irate()[2m] function for the series matching the instance and intf_name
// 2m is arbitrary, but I scrape every 30s my device, so looking back up to 2min to fetch 2 point
// to do the speed delta seems okay to me ( YMMV )
//
// Usually network folks don't like bytes/sec or Octets/sec and prefer bits/sec. That's the reason to
// multiply by 8 the return of the irate() function  
// I assume this should work for other series that use bytes/sec as unit ;)

// TARGET will looks like:
// prometheus:proto:remote_host:remote_port:instance:intf_name:series_in:series_out
//  or
// prometheus:proto:remote_host:remote_port:instance:intf_name:series_in

// - proto: http|https
// - remote_host: address of the prometheus database.
// - remote_port: port on which the prometheus database listen to.
// - instance: the SNMP target you looking data to.
// - intf_name: the interface on that target specifically.
// - series_in/series_out: the series to look into.

// Example:
// - TARGET prometheus:http:localhost:9090:192.168.178.32:Gi0/1:ifHCInOctets:ifHCOutOctets
// or
// - TARGET prometheus:http:localhost:9090:192.168.178.32:Gi0/1:ifHCInOctets


// Extended to allow free-text Prometheus queries. This allows polling any Prometheus metric and
// use of complex queries in targets.

// In this case TARGET will look like:
// prometheus:proto:remote_host:remote_port:free_text_query_in:free_text_query_out
// or
// prometheus:proto:remote_host:remote_port:free_text_query_in

// This exmple shows how powerful this can be. It uses the max() with regex matching to poll the busiest 
// link in a bundle on a given router, identified here by having 'ae232' in the interface description 
// (which in this case show up as ifAlias).
// - TARGET prometheus:http:localhost:9090:max(irate(ifHCOutOctets{ifAlias=~".*ae232.*",instance="192.168.178.32"}[10m]))*8:max(irate(ifHCInOctets{ifAlias=~".*ae232.*",instance="192.168.178.32"}[10m]))*8

class WeatherMapDataSource_prometheus extends WeatherMapDataSource {

    // FIXME: regexes probably too permissive.
    private $prom_regex_single_value = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+)\:([a-zA-z0-9-_.]+)\:([^:]+)\:([^:]+)$/';
    private $prom_regex_dual_value   = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+)\:([a-zA-z0-9-_.]+)\:([^:]+)\:([^:]+)\:([^:]+)$/';
    private $prom_regex_single_query = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+)\:([^:]+)$/';
    private $prom_regex_dual_query   = '/^prometheus\:(http|https)\:([a-zA-z0-9-_.]+)\:(\d+)\:([^:]+)\:([^:]+)$/';


    function Init(&$map)
    {
        // nothing the see here ( as of now )
        return TRUE;
    }

    // Parse the plugin line and recognise prom looking targetstring
    function Recognise($targetstring)
    {

        if( preg_match($this->prom_regex_single_value,$targetstring,$matches) ||
            preg_match($this->prom_regex_dual_value,$targetstring,$matches) ||
            preg_match($this->prom_regex_single_query,$targetstring,$matches) ||
            preg_match($this->prom_regex_dual_query,$targetstring,$matches) )
        {
            return TRUE;
        }
        else
        {
            return FALSE;
        }
    }

    // Get data from prometheus db via its API
    function GetPromData($url)
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

        wm_debug("PROMETHEUS ReadData: got BW value: $bw\n");
    
        return $bw;
    }

    function ReadData($targetstring, &$map, &$item)
    {
    
        $inbw = NULL;
        $outbw = NULL;
        $data_time=0;
    
        if(preg_match($this->prom_regex_dual_value,$targetstring,$matches))
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
            $inbw = $this->GetPromData($call);
    
            // OUT
            $query = urlencode($out_query);
            $call = $url . $query;
            $outbw = $this->GetPromData($call);
        }
        elseif(preg_match($this->prom_regex_single_value,$targetstring,$matches))
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
            $inbw = $this->GetPromData($call);
    
            $outbw = $inbw;
        }
        elseif(preg_match($this->prom_regex_dual_query,$targetstring,$matches))
        {
            // In and Out as free Prometheus queries
            $proto       = $matches[1];
            $remote_host = $matches[2];
            $remote_port = $matches[3];
            $in_query    = $matches[4];
            $out_query   = $matches[5];

            $url = $proto . '://' . $remote_host . ':' . $remote_port . '/api/v1/query?query=';

            // IN
            $query = urlencode($in_query);
            $call = $url . $query;
            $inbw = $this->GetPromData($call);

            // OUT
            $query = urlencode($out_query);
            $call = $url . $query;
            $outbw = $this->GetPromData($call);

        }
        elseif(preg_match($this->prom_regex_single_query,$targetstring,$matches))
        {
            // In and Out as free Prometheus queries
            $proto       = $matches[1];
            $remote_host = $matches[2];
            $remote_port = $matches[3];
            $in_query    = $matches[4];

            $url = $proto . '://' . $remote_host . ':' . $remote_port . '/api/v1/query?query=';

            $query = urlencode($in_query);
            $call = $url . $query;
            $inbw = $this->GetPromData($call);

            $outbw = $inbw;
        }
    
        $data_time = time();
        return ( array($inbw,$outbw,$data_time) );
    }
}    

?>

// vim:ts=4:sw=4:
