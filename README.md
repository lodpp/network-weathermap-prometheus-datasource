# network-weathermap-prometheus-datasource

Here is a work-in-progress to add Prometheus as a network-weathermap-datasource.

## Why ?
I've already moved from MRTG to the hyper hyper tools combo SNMP scraper + Prometheus + Grafana for BW graphing.
That said, I still find Weathermap very usefull for every day ops stuff, and I couldn't moved fully away from MRTG and the RRD backend as my wmap setup still needed RRD to work.

I didn't that much of alternative to network-weathermap on the market ( which is opensource, free/libre, still maintained and so on )

My main need was to get the BW, other kind of metrics would be nice but the BW is the MVP to get.

## Prometheus SNMP setup
I use the official snmp_exporter from Prometheus on  Cisco devices, using the IF_MIB module.

The most basic BW check is to graph the content of ifHCInOctets and ifHCOutOctets on the interfaces.
By default the timeseries for ifHCInOctets/ifHCOutOctets is based on the Ifalias only, I've patched it to get also the Ifname and Ifalias via label lookups.

```
if_mib:                                                                         
  auth:                                                                         
    community: 'OverKillCommunityRoName'                                                      
  walk:                                                                         
  - 1.3.6.1.2.1.2                                                               
  - 1.3.6.1.2.1.31.1.1                                                          
  get:                                                                          
  - 1.3.6.1.2.1.1.3.0                                                           
  metrics:
<SNIP>
 - name: ifHCInOctets                                                          
    oid: 1.3.6.1.2.1.31.1.1.1.6                                                 
    type: counter                                                               
    help: The total number of octets received on the interface, including framing
      characters - 1.3.6.1.2.1.31.1.1.1.6                                       
    indexes:                                                                    
    - labelname: ifIndex                                                        
      type: gauge                                                               
    lookups:                                                                    
    - labels: [ifIndex]                                                         
      labelname: ifName                                                         
      oid: 1.3.6.1.2.1.31.1.1.1.1                                               
      type: DisplayString                                                       
    - labels: [ifIndex]                                                         
      labelname: ifAlias                                                        
      oid: 1.3.6.1.2.1.31.1.1.1.18                                              
      type: DisplayString
<SNIP>
  - name: ifHCOutOctets                                                         
    oid: 1.3.6.1.2.1.31.1.1.1.10                                                
    type: counter                                                               
    help: The total number of octets transmitted out of the interface, including framing
      characters - 1.3.6.1.2.1.31.1.1.1.10                                      
    indexes:                                                                    
    - labelname: ifIndex                                                        
      type: gauge                                                               
    lookups:                                                                    
    - labels: [ifIndex]                                                         
      labelname: ifName                                                         
      oid: 1.3.6.1.2.1.31.1.1.1.1                                               
      type: DisplayString                                                       
    - labels: [ifIndex]                                                         
      labelname: ifAlias                                                        
      oid: 1.3.6.1.2.1.31.1.1.1.18                                              
      type: DisplayString
<SNIP>
```

## How does the Datasource works ? 

The datasource is using the prometheus HTTP(s) API to get the data.

### Query Basics:
I use the same prometheus query as for graphing in grafana, aka irate(series{attr})[2m] x 8.
irate(series{attr})[2m] will calculate the delta t between the last 2 points of the series.
Those 2 points are searched in the last 2m. You can tune the time in the code, 2m was fine to me as I scrappe the device every 30sec.
I'm pretty sure that I can find 2 points withing a 2mins timeframe

As the name suggests, ifHCInOctets/ifHCOutOctets are BYTES, irate() is then returning bytes/sec. Network people prefer bits/sec, that's why
you have a x8 multiplier at the end.

Target needs:
- **datasource:** prometheus
- **proto:** http|https
- **remote_host:** address of the prometheus database. ( only tested with IP )
- **remote_port:** port on which the prometheus database listen to.
- **instance:** the SNMP target you looking data to. ( only tested with IP )
- **intf_name:** the interface on that target specifically. ( only tested with ciscoishh intf name )
- **series_in/series_out:** the series to look into. ( only tested with ifHCInOctets/ifHCOutOctets )


## Adding the datasource to weathermap

Copy the `WeatherMapDataSource_prometheus.php` to the wmap datasource directory:
```
$ cd x/y/network-weathermap-prometheus-datasource/
$ cp WeatherMapDataSource_prometheus.php /path/to/weathermap/lib/datasources/ 
```
And that's it, you can use it in your wmap config

## Weathermap config

One series for IN, another for OUT
```
LINK NODE1-NODE2
    NODES NODE1 NODE2
    TARGET prometheus:http:localhost:9090:192.168.178.32:Gi0/1:ifHCInOctets:ifHCOutOctets
```

or one single series:
```
LINK NODE1-NODE2
    NODES NODE1 NODE2
    TARGET prometheus:http:localhost:9090:192.168.178.32:Gi0/1:ifHCInOctets
```
## Generate your graph
Use wmap like you would normally do, graphs should generate just fine.
Here the only errors shown are normal given my setup, i don't need `snmp` or `rrd` modules from PHP.
```
$ ./weathermap --config configs/loddp.conf --output lodpp.png
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/IP-MIB.my)
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/EVENT-MIB.my)
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/DISMAN-SCHEDULE-MIB.txt)
PHP Deprecated:  The each() function is deprecated. This message will be suppressed on further calls in /usr/lib64/php/Console/Getopt.php on line 135

// vim:ts=4:sw=4:

WARNING: configs/loddp.conf: RRD DS: Can't find RRDTOOL. Check line 29 of the 'weathermap' script.
RRD-based TARGETs will fail. [WMRRD02]
```

In case of trouble, you could check by adding the `--debug` flag the the cli


## Testing:

I've added a slightly modified version of the php class `WeatherMapDataSource_prometheus_manualtest.php` that can be used to test manually the request to the prometheus DB.
I found it easier to shoot this way

As of now, edit the script and the `$prom_query` variable ( which is actually the target as you would put it in the wmap config )

Then exec it:
- single series
```
//// $prom_query = 'prometheus:http:localhost:9090:192.168.178.32:Gi0/13:ifHCInOctets';


$ php WeatherMapDataSource_prometheus_manualtest.php
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/IP-MIB.my)
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/EVENT-MIB.my)
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/DISMAN-SCHEDULE-MIB.txt)
The Request is valid
Array
(
    [0] => 1208605
    [1] => 1208605
    [2] => 1551651075
)
```

- same with 2 series for in/out
```
//// $prom_query = 'prometheus:http:localhost:9090:192.168.178.32:Gi0/13:ifHCInOctets:ifHCOutOctets';


$ php WeatherMapDataSource_prometheus_manualtest.php
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/IP-MIB.my)
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/EVENT-MIB.my)
Did not find 'zeroDotZero' in module SNMPv2-SMI (/usr/share/snmp/mibs/DISMAN-SCHEDULE-MIB.txt)
The Request is valid
Array
(
    [0] => 1599766
    [1] => 123539
    [2] => 1551651140
)
```

## Trouble ?

This is a POC / WIP, I got not trouble putting that in production at home, no cats will be harmed if it fails.
That being said, it's not a rock-solid code ( my first PHP code actually so..... ) so putting that in real life production might be a bit radioactive :p

## Sources:
I didn't make it on my very own, found the inspiration with those existing docs :) :  
- Wmap manual itself https://www.network-weathermap.com/manual/0.98/pages/advanced.html#plugins
- influxDB datasource found on github: https://github.com/guequierre/php-weathermap-influxdb

## Last words ?

Fell free to test, report, patch, enjoy or hate it.

Cheers,
Lodpp