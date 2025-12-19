<?php
//siddharth : 2025-12-12 commented out cronjob and stop the script becasue too many requests were being made to the API
// exit(0);
set_time_limit(60*50);

ini_set("error_reporting", "true");
// error_reporting(E_ALL);

require_once(realpath(dirname(__FILE__) . "/../../../config.php"));

require_once(CLASSES_PATH . "/settings.class.php");
require_once(CLASSES_PATH . "/event.class.php");
require_once(CLASSES_PATH . "/affiliates.class.php");
require_once(LIBRARY_PATH . "/functions.class.php");
require_once(LIBRARY_PATH . "/facebook.lib.php");
require_once(CLASSES_PATH . "/db.class.php");
require_once(LIBRARY_PATH . "/sqs.lib.php");

define('NL', "\n");
define('DEBUG', 1);
define('FILE_LOG', 0);
define('MAX_SUCCESS_CALLS', 1000000);
define('MAX_LIFE', 3600); //60 minutes

$city = null;
$cursor = 0;
$cities_count = 0;
$success_calls = array();
$sleep_seconds = 0;

$psw = Configurations::get_value('skiddle.psw');
$psw = json_decode($psw, true);

$name = 'Skiddle';
$affiliate = Affiliates::get_affiliate($name);
$api_config = Affiliates::$api_config[$name];
print_debug($name, array("psw" => $psw, "affiliate" => $affiliate, "config" => $api_config), 1);
$last_city_id_from_db = get_last_city_id();
print_r(array($last_city_id_from_db[0]['id'],$psw['last_city_id']));
if ($psw['last_city_id'] >= ($last_city_id_from_db[0]['id'] - 1)) {
    $psw['last_city_id'] = 0;
}else{
    $psw['last_city_id'] = (int) $psw['last_city_id'] + 1;
}
//$psw['last_city_id'] = 0;
echo "-------> last citie id is -> {$psw['last_city_id']} \n";
$cities = get_cities($psw['last_city_id']);
print_debug("Cities", count($cities), 1);

$aesqs = new AESQS(SQS::updateEvents);
$epsqs = new AESQS(SQS::eventPublishedTrigger);

$script_start_time = time();
//continuesly reading messages from queue
while (true) {
    try {
        if(time() > $script_start_time + MAX_LIFE) throw new Exception("I am too old, killing myself :(", 1);

    $location_array = [
        ['lat' => 50.0000000000, 'long' => -5.5000000000],
        ['lat' => 50.5000000000, 'long' => -4.0000000000],
        ['lat' => 50.8000000000, 'long' => -2.0000000000],
        ['lat' => 51.1000000000, 'long' => 0.5000000000],
        ['lat' => 51.7000000000, 'long' => -4.0000000000],
        ['lat' => 52.0000000000, 'long' => -2.0000000000],
        ['lat' => 52.5000000000, 'long' => 1.0000000000],
        ['lat' => 53.0000000000, 'long' => -4.0000000000],
        ['lat' => 53.3000000000, 'long' => -2.3000000000],
        ['lat' => 53.7000000000, 'long' => -0.5000000000],
        ['lat' => 54.6000000000, 'long' => -6.5000000000],
        ['lat' => 54.8000000000, 'long' => -2.5000000000],
        ['lat' => 55.5000000000, 'long' => -4.5000000000],
        ['lat' => 56.2000000000, 'long' => -3.5000000000],
        ['lat' => 57.2000000000, 'long' => -3.0000000000],
        ['lat' => 57.5000000000, 'long' => -6.5000000000],
        ['lat' => 58.5000000000, 'long' => -4.0000000000],
        ['lat' => 60.3000000000, 'long' => -1.3000000000]
    ];

    foreach ($location_array as $index => $location) {

        if (time() > $script_start_time + MAX_LIFE) {
            throw new Exception("I am too old, killing myself :(", 1);
        }

        print_debug(
            "Processing Location",
            "Lat: {$location['lat']}, Long: {$location['long']}",
            1
        );

        $params = array(
            'latitude'  => $location['lat'],
            'longitude' => $location['long'],
            'radius'    => 100,
            'offset'    => 0,
            'description' => 1,
            'api_key'   => $api_config['token'],
            'limit'     => 100
        );

        // Resume support
        if (
            isset($psw['last_location_index']) &&
            (int)$psw['last_location_index'] === (int)$index
        ) {
            $params['offset'] = $psw['last_offset'];
        }

        $psw['last_location_index'] = $index;

        while (true) {

            $result = fetch_events_from_skiddle($params);
            $psw['last_offset'] = $params['offset'];
            Configurations::set_value("skiddle.psw", json_encode($psw));

            if (!isset($success_calls[date("H")])) {
                $success_calls[date("H")] = 0;
            }
            $success_calls[date("H")]++;

            $events = $result['events'];

            print_debug(
                "Lat {$location['lat']} / Long {$location['long']} (Offset {$params['offset']})",
                count($events),
                1
            );

            if (is_array($events) && count($events) > 0) {

                foreach ($events as $obj) {

                    $event = decode_skiddle_event($obj, $affiliate);
                    $event_id = $event['event_id'];
                    $action = detect_action($event_id, $event);

                    if ($action == DB::INSERT) {

                        if (
                            $event['params']['postponed'] == 1 ||
                            $event['params']['cancelled'] == 1
                        ) {
                            print_debug(
                                'Skipped - not inserted',
                                json_encode($event['params'], true),
                                1,
                                "red"
                            );
                            continue;
                        }

                        $insertID = Event::insert_event_cron($event);

                        if ($insertID > 0) {

                            if ($event['banner_url']) {
                                Event::update_event_banner_cron($event);
                            }

                            sleep(2);

                            $updated_event_obj = $event['params'] ?: [];
                            $updated_event_obj['last_updated_from_source'] = time();
                            update_ext_event_params($event_id, $updated_event_obj);

                            $aesqs->sendMessage(json_encode([
                                "event"  => $event,
                                "action" => "IMAGES"
                            ]));

                            $epsqs->sendMessage(json_encode([
                                "event_id"     => $event_id,
                                "published_by" => 0
                            ]));

                            Functions::insert_into_sqs(
                                json_encode(["event_id" => $event_id]),
                                SQS::eventProfilingQueue
                            );

                            sleep(1);

                            if ($event['categories']) {
                                $categories = implode(",", $event['categories']);
                                Event::update_event_ext_catags(
                                    $event_id,
                                    $categories,
                                    $categories
                                );
                            } else {
                                $extracted = Event::identifyCategories(
                                    $event['eventname'] . ";" . $event['description']
                                );
                                if ($extracted) {
                                    Event::update_event_ext_catags(
                                        $event_id,
                                        implode(",", $extracted['categories']),
                                        implode(",", $extracted['keywords'])
                                    );
                                }
                            }

                            print_debug('Inserted', $event_id, 1, "green");

                        } else {
                            print_debug(
                                'Could not Insert (' . $event['privacy'] . ')',
                                $event_id,
                                1,
                                "red"
                            );
                        }
                    }

                    if ($action == DB::UPDATE) {
                        $current_time = time();
                        $event_start_time = strtotime($event['start_time']);
                        $time_until_event = $event_start_time - $current_time;
                        
                        // Skip update if event is in the past (difference < 0)
                        if ($time_until_event < 0) {
                            $days_since_event = round(abs($time_until_event) / 86400, 1);
                            print_debug(
                                'Skipped Update - Event in past',
                                "Event {$event_id}: Event passed {$days_since_event} days ago",
                                1,
                                "yellow"
                            );
                            continue;
                        }
                        
                        // Event is in the future or happening now
                        $update_threshold = $time_until_event / 2;
                        
                        // If event is within 1 day, always update (skip threshold check)
                        if ($time_until_event <= 86400) {
                            // Event is within 1 day, always update
                        } else {
                            // Event is more than 1 day away, check threshold
                            // Get last update time from event params
                            $db = new DB();
                            $sql = "select ex_params from ext_events where event_id = :event_id limit 1";
                            $db->query($sql, array("event_id" => $event_id));
                            $ext_event = $db->fetchAssoc();
                            
                            $last_updated = 0;
                            if ($ext_event && $ext_event['ex_params']) {
                                $ext_event_params = json_decode($ext_event['ex_params'], true);
                                if (isset($ext_event_params['last_updated_from_source'])) {
                                    $last_updated = $ext_event_params['last_updated_from_source'];
                                }
                            }
                            
                            // Calculate time since last update
                            $time_since_last_update = $current_time - $last_updated;
                            if ($time_since_last_update < $update_threshold) {
                                $days_until_event = round($time_until_event / 86400, 1);
                                $days_threshold = round($update_threshold / 86400, 1);
                                $days_since_update = round($time_since_last_update / 86400, 1);
                                
                                print_debug(
                                    'Skipped Update - Too early',
                                    "Event {$event_id}: {$days_until_event} days until event, threshold: {$days_threshold} days, last update: {$days_since_update} days ago",
                                    1,
                                    "yellow"
                                );
                                continue;
                            }
                        }

                        Event::update_event_cron($event);

                        if ($event['banner_url']) {
                            Event::update_event_banner_cron($event);
                        }

                        $aesqs->sendMessage(json_encode([
                            "event"  => $event,
                            "action" => "IMAGES"
                        ]));

                        sleep(2);

                        $updated_event_obj = $event['params'] ?: [];
                        $updated_event_obj['last_updated_from_source'] = time();
                        update_ext_event_params($event_id, $updated_event_obj);

                        if (
                            $event['params']['postponed'] == 1 ||
                            $event['params']['cancelled'] == 1
                        ) {
                            Event::mark_as_spam($event_id);
                            continue;
                        }

                        Functions::insert_into_sqs(
                            json_encode(["event_id" => $event_id]),
                            SQS::eventProfilingQueue
                        );

                        sleep(1);

                        print_debug('Updated', $event_id, 1);
                    }
                }
            }

            if ($result['has_more_events'] == 1) {
                $params['offset'] += 100;
                continue;
            }

            print_debug("Breaking", "No more events", 1);
            sleep(2);
            break;
        }

        Configurations::set_value("skiddle.psw", json_encode($psw));
    }

    } catch (Exception $ex) {
        echo "Breaking with Error:" . $ex->getMessage();
        break;
    }
}

Configurations::set_value("skiddle.psw", json_encode($psw));

exit(0);
print_debug("Status", "Process finished", 1);
die();
//main body - ends

//Functions starts
function print_debug($label,$value,$print_always, $style = "off") {
    if($print_always==1 || DEBUG==1) {
        style_text($style);
        echo "$label -> "; print_r($value);
        style_text("off");
        echo NL;
    }
}

function print_separator() {
    style_text("blue_bg");
    echo "----------------------------------------------";
    style_text("off");
    echo NL;
}

function style_text($code) {
    $codes = array(
        "off"        => 0,
        "bold"       => 1,
        "italic"     => 3,
        "underline"  => 4,
        "blink"      => 5,
        "inverse"    => 7,
        "hidden"     => 8,
        "black"      => 30,
        "red"        => 31,
        "green"      => 32,
        "yellow"     => 33,
        "blue"       => 34,
        "magenta"    => 35,
        "cyan"       => 36,
        "white"      => 37,
        "black_bg"   => 40,
        "red_bg"     => 41,
        "green_bg"   => 42,
        "yellow_bg"  => 43,
        "blue_bg"    => 44,
        "magenta_bg" => 45,
        "cyan_bg"    => 46,
        "white_bg"   => 47
    );
    echo "\033[" . $codes[$code] . "m";
}

function fetch_event_deta_from_ticket_url($params, $timeout=3) {
    try{
        $apibase = "https://us-east1-image-upload-224812.cloudfunctions.net/metafetch?url=";

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $apibase.$params['ticket_url']);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

        $response = json_decode(curl_exec($ch), true);
        $err = curl_error($ch);

        curl_close($ch);
    }catch(Exception $ex){}

    if ($err) {
        log_ticket_data($params, 'failure');
        return false;
    } else {
        return $response;
    }
}

function extend_ticket_obj($ticket_url, $obj, $event){
    $ticket_url = strtok($ticket_url,'?');
    $ticket_domain = getDomain($ticket_url);
    $event_id = $event['event_id'];

    $ticket_obj = ($obj['offers']['offers'])? $obj['offers']['offers'] : $obj['offers'];

    if(!$ticket_obj) {
        log_ticket_data($event, 'skdl-crawled', "", $ticket_url);
        return false;
    }

    $ticket_price = array();
    $is_aggregate = false;

    // for ticket details
    $ticket_price['tickets'] = array();

    if (empty($ticket_obj[0])) {
        log_ticket_data($event, 'crawled');
        return false;
    }

    $i=0;
    foreach ($ticket_obj as $t) {
        if ($t['@type'] == "AggregateOffer") {
            $ticket_price['min_ticket_price'] = $t['lowPrice'];
            $ticket_price['max_ticket_price'] = $t['highPrice'];
            $ticket_price['ticket_currency'] = $t['priceCurrency'];
            $is_aggregate = true;
            continue;
        }
        if($t['name'] != "") $ticket_price['tickets'][$i]['name'] = $t['name'];
        if($t['availability'] != "")$ticket_price['tickets'][$i]['availability'] = (strpos($t['availability'], "schema.org")) ? $t['availability'] : ("https://schema.org/" . $t['availability']);
        if(isset($t['price']))$ticket_price['tickets'][$i]['price'] = $t['price'];

        $t['priceCurrency'] = ($t['priceCurrency'] != "") ? $t['priceCurrency'] : (($t['pricecurrency'] != "") ? $t['pricecurrency'] : "");
        if($t['priceCurrency'] != "") $ticket_price['tickets'][$i]['priceCurrency'] = $t['priceCurrency'];
        if($t['validFrom'] != ""){
            $ticket_price['tickets'][$i]['validFrom'] = $t['validFrom'];
            $ticket_price['tickets'][$i]['availabilityStarts'] = ($t['availabilityStarts'] != "") ?  $t['availabilityStarts']:$t['validFrom'];
        }
        if($t['availabilityEnds'] != "") $ticket_price['tickets'][$i]['availabilityEnds'] = $t['availabilityEnds'];
        $i++;
    }

    // Fetch performers
    if (isset($obj['performers']) || isset($obj['performer'])) {

        $performers = (isset($obj['performers'])) ? $obj['performers'] : $obj['performer'];
        foreach ($performers as $performer) {
            if (strtolower($performer['@type']) !== "organization") {
                if(array_key_exists('url', $performer)) unset($performer['url']);
                array_push($ticket_price['performers'], $performer);
            }
        }

        if (empty($ticket_price['performers']) || !$ticket_price['performers']) unset($ticket_price['performers']);
    }

    if(!$ticket_price['tickets']) unset($ticket_price['tickets']);

    // additional ticket details like platforms
    if(!$is_aggregate && $ticket_price['tickets']){
        $ticket_price_obj = array_map(function($details) {
          return $details['price'];
        }, $ticket_price['tickets']);

        $ticket_currency_obj = array_map(function($details) {
          return $details['priceCurrency'];
        }, $ticket_price['tickets']);

        $ticket_price['min_ticket_price'] = min($ticket_price_obj);
        $ticket_price['max_ticket_price'] = max($ticket_price_obj);
    }

    if ($ticket_price['ticket_currency'] == "" && $ticket_currency_obj[0]){
        $ticket_price['ticket_currency'] = $ticket_currency_obj[0] != "" ? $ticket_currency_obj[0] : "";
    }
    $ticket_price['ticket_url'] = $ticket_url;
    $ticket_price['ticket_domain'] = $ticket_domain;
    $ticket_price['ticket_url_last_fetched'] = time();
    $ticket_price['ticket_platform'] = $ticket_domain;
    $ticket_price['last_ticket_url'] = $ticket_url;

    if($ticket_price != null && (count($ticket_price) > 0)){
        update_ext_event_params($event_id, $ticket_price);
        log_ticket_data($event, 'schema_found_skiddle', json_encode($ticket_price['tickets']), $ticket_url);
        Functions::slack_push($event_id,'skiddle_event_update','skiddle_update', array("event_id" => $event_id, "ticket_url" => $ticket_url, "ae_url" => "https://allevents.in/e/" . $event_id));
    }
}

function update_ext_event_params($event_id, $obj) {
    $db = new DB();
    $sql = "select ex_params from ext_events where event_id =:event_id limit 1";
    $db->query($sql, array("event_id" => $event_id));
    $event = $db->fetchAssoc();
    if($event) {
        $params = $event['ex_params'];
        if($params == ''){
            $params = $obj;
        }else {
            $params = json_decode($params, true);
            foreach ($obj as $key => $value) {
                $params[$key] = $value;
            }
        }
        $params = json_encode($params);
        $sql = "update ext_events set ex_params = :ex_params, updated = CURRENT_TIMESTAMP() where event_id = :event_id limit 1";
        $db -> query($sql, array('ex_params' => $params, 'event_id' => $event_id));
        if ($db -> affectedRows() > 0) {
            $func_key = Functions::get_unique_key_of_function("Event::get_event", array($event_id));
            $oCache = new CacheMemcache();
            $oCache->delData($func_key);
            return true;
        }
        else
            return false;
    } else {
        $event = array('html' => '', 'video_url' => '', 'hashtag' => '', 'banner_url' => '', 'event_id' => $event_id, 'categories' => '', 'tags' => '');
        $event['ex_params'] = array($p);
        Event::insert_event_ext($event);
        return true;
    }
}

function log_facebook_errors($process_name, $end_point, $request_data, $response) {
    //print_debug($end_point, $response, 1);
    $log = LOG_PATH . '/facebook-errors.txt';
    $fp = fopen($log, "a");
    fwrite($fp, date('Y-m-d H:i:s', time()) . "\t" . $process_name . "\t" . $end_point . "\t" . $request_data . "\t" . $response . "\n");
    fclose($fp);
}

function log_facebook_calls($mode, $query)
{
    $log=LOG_PATH.'/facebook-call.txt';
    $fp = fopen($log,"a");
    fwrite($fp,date('Y-m-d H:i:s',time()). "\t".$mode . "\t". $query."\n");
    fclose($fp);
}

function fetch_events_by_friends($access_token) {
    $friends = fetch_friends($access_token);
    print_debug("Friends", count($friends), 1);
    if($friends === -1) return -1;
    $events = array();
    if($friends) {
        $events = fetch_events_by_ids($friends, $access_token);
        if($events) return $events;
    }
    return $events;
}

function fetch_friends($access_token) {
    $graph_url=API::sfacebook."/me/friends?limit=5000&fields=id&access_token={$access_token}";
    $json = Functions::get_data($graph_url);
    $friends = json_decode($json, true);
    if(isset($events['error']['code'])){
        if($events['error']['code'] == 190) return -1;
        if($events['error']['code'] == 17) sleep(5);
        return array();
    }
    else if($friends){
        if($friends['data']) {
            $friends = array_map(function ($ar) {return $ar['id'];}, $friends['data']);
            return $friends;
        }
    }
    return array();
}

function fetch_events_by_ids($facebook_ids, $access_token) {
    if(trim($access_token) == '') return false;

    $batched_request = array();
    $chunk_of_friends = array_chunk($facebook_ids, 50);
    foreach ($chunk_of_friends as $frnd_chunk) {
        $ids_str = implode(",", $frnd_chunk);
        $batched_request[] = array("method" => "GET", "relative_url" => "?ids={$ids_str}&fields=events.since(today).limit(20).fields(id,start_time)");
    }

    $graph_url = API::sfacebook."/?access_token=" . $access_token;

    $fields_string = 'batch='.urlencode(json_encode($batched_request));

    $ch = curl_init();
    curl_setopt($ch,CURLOPT_URL,$graph_url);
    curl_setopt($ch,CURLOPT_POST,1);
    curl_setopt($ch,CURLOPT_POSTFIELDS,$fields_string);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 0);
    $response = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($response,true); unset($response);

    if (isset($data['error'])) {
        if($data['error']['code'] == 190) return false;
        log_facebook_errors("facebook-to-db", $graph_url, $fields_string, json_encode($data));
        return false;
    }

    $events = array();
    if($data) {
        foreach($data as $d) {
            if($d['code']==200){
                $body = json_decode($d['body'], true);
                foreach ($body as $key => $value) {
                    if(isset($value['events'])) {
                        foreach ($value['events']['data'] as $e) {
                            $events[$e['id']] = $e;
                        }
                    }
                }
            }
        }
    }
    return $events;
}

function getDomain($url){
    $pieces = parse_url($url);
    $domain = isset($pieces['host']) ? $pieces['host'] : '';
    if(preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $domain, $regs)){
        return $regs['domain'];
    }
    return FALSE;
}

function fetch_events_by_account($facebook_id, $access_token) {
    $graph_url = API::sfacebook."/{$facebook_id}/events?limit=1000&since=today&fields=id,start_time&access_token={$access_token}";

    $list = array();
    for ($i=0; $i < 10; $i++) {
        $json = Functions::get_data($graph_url);
        $events = json_decode($json, true);
        if(isset($events['error']['code'])){
            if($events['error']['code'] == 190) return -1;
            if($events['error']['code'] == 17) sleep(5);
            break;
        }
        else if($events){
            $list = array_merge($list, $events['data']);
            if(isset($events['paging']['next'])) {
                $graph_url = $events['paging']['next'];
            } else break;
        } else break;
    }

    return $list;
}

function fetch_pages_events($facebook_id, $access_token) {
    $graph_url=API::sfacebook."/{$facebook_id}?fields=accounts.fields(events.limit(1000).fields(id,start_time))&access_token={$access_token}";
    $json = @file_get_contents($graph_url,0,null,null);

    $data = json_decode($json, true);
    $events=array();
    if(isset($data['accounts'])){
        foreach ($data['accounts']['data'] as $acc) {
            if(isset($acc['events']['data'])){
                foreach ($acc['events']['data'] as $event) {
                    $events[] = $event;
                }
            }
        }
    }
    return $events;
}

function next_facebook_user($user_id)
{
    $db=new DB();
    $sql="select * from app_facebook where
                user_id = :user_id limit 1";
    $db->query($sql, array('user_id' => $user_id));
    $temp = $db->fetchAssoc();
    if($temp) return $temp;
    else return false;
}

function flag_facebook_user($id)
{
    $db=new DB('main');
    $sql="update app_facebook set
                            expired='1',
                            updated_on=CURRENT_TIMESTAMP()
                            where
                            id=:id limit 1";
    $db->query($sql, array('id'=>$id));
    return true;
}

function get_permissions($access_token)
{
    $graph_url = API::sfacebook."/me/permissions?access_token=" . $access_token;
    $response = @file_get_contents($graph_url);
    $permissions = json_decode($response, true);
    if(isset($permissions['error']))
        return false;
    else {
        if($permissions['data']) {
            $permissions = array_map(function ($ar) { if($ar['status'] == "granted") return $ar['permission']; },  $permissions['data']);
            return $permissions;
        }
    }
    return false;
}

function insert_event_db($document)
{
    $db=new DB();
    $sql="replace into scheduler_eid_store set ".DB::queryBuilder($document);
    //echo $sql;
    $db->query($sql,$document);
}

function array_merge_unique($destination, $source) {
    foreach ($source as $value) {
        $destination[$value['id']] = $value;
    }
    return $destination;
}

function get_cities($last_city_id) {
    $db = new DB("replica");
    $sql = "select * from app_cities where id >= :last_city_id and country='United Kingdom' order by id asc";
    $db->query($sql, array("last_city_id" => $last_city_id));
    $result = $db->fetchList();
    if($result)
        return $result;
    else return false;
}

function get_last_city_id() {
    $db = new DB("replica");
    $sql = "select id from app_cities where country='United Kingdom' order by id desc limit 1";
    $db->query($sql);
    $result = $db->fetchList();
    if($result)
        return $result;
    else return false;
}

function fetch_events_from_skiddle($params) {
    $result = array('events' => array(), 'has_more_events' => 0);

    $apibase = "https://www.skiddle.com/api/v1/events/search/";

    print_r(array($apibase.'?'.http_build_query($params)));
    //exit(0);
    // echo http_build_query($params);exit;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apibase.'?'.http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if($response["error"] > 0){
        return $result;
    } else {
        $result['events'] = $response["results"];
        print_r(array('count' => count($result['events'])));
        if($response["totalcount"] > $params['offset'] + $params['limit']){
            $result['has_more_events'] = 1;
        }
    }
    return $result;
}

function calculate_timezone($local, $utc) {
    $time_diff = strtotime($local) - strtotime($utc);
    $time_diff = $time_diff / 3600;

    $sign = "+"; if($time_diff < 0) $sign = '-';
    $time_diff = abs($time_diff);

    $hour = (int) $time_diff;
    $hour = str_pad($hour, 2, '0', STR_PAD_LEFT);

    $min = (int) (($time_diff - $hour) * 60);
    $min = str_pad($min, 2, '0', STR_PAD_LEFT);

    $timezone = $sign . $hour . ':' . $min;
    return $timezone;
}

function decode_skiddle_event($obj, $affiliate) {
    $sign = substr($obj['startdate'], -6, 1);
    $timezone = ($sign == '-' || $sign == '+') ?
            substr($obj['startdate'], -6, 3) . ":" . substr($obj['startdate'], -2, 2) : 0;

    $probable_cities = GEO::nearby_locations($obj['venue']['latitude'],$obj['venue']['longitude'],20);

    $city_match_score = array();

    foreach ($probable_cities as $key => $value) {
        $city_match_score[$key] = levenshtein($obj['venue']['town'], $value["city"]);
    }

    array_multisort($city_match_score, SORT_ASC, $probable_cities);

    $prefix = $affiliate['id'] * Affiliates::mul_factor;
    $event_id = $prefix . $obj['id'];

    if (!empty($linked_event_id)) {
        print_debug('Linked Source ID' , "Event id is linked - inserted id is -> {$linked_event_id}", 1, "green");
    }else{
        print_debug('Link Source ID' , "Event id is already linked", 1, "red");
    }
    $event = array('event_id' => $event_id,
        'eventname' => html_entity_decode($obj['eventname']),
        'thumb_url' => (isset($obj['largeimageurl']) ? $obj['largeimageurl'] : (isset($obj['imageurl']) ? $obj['imageurl'] : "https://cdn.allevents.in/old/default_thumb.png")),
        'start_time' => substr($obj['startdate'], 0, 19),
        'timezone' => isset($probable_cities[0]["timezone"]) ? $probable_cities[0]["timezone"] : $timezone,
        'end_time' => substr($obj['enddate'], 0, 19),
        'location' => $obj['venue']['name'],
        'venue' => array(
            'street' => $obj['venue']['address'],
            'city' => $obj['venue']['town'],
            'state' => $probable_cities[0]["region_code"],
            'country' => $obj['venue']['country'],
            'latitude' => $obj['venue']['latitude'],
            'longitude' => $obj['venue']['longitude']
        ),
        'description' => $obj['description'],
        'is_date_only' => false,
        'banner_url' => "",
        'privacy' => "open",
        'ticket_url' => $obj['link'],
        'affiliate_id' => $affiliate['id'],
        'updated_time' => date(DATE_ATOM)
    );

    if ($obj['imageurl'] && $obj['imageurl'] != '') {
        $event['thumb_url'] = str_replace("_th.jpg", "_400.jpg", $obj['imageurl']);
        $event['banner_url'] = str_replace("_th.jpg", "_1024.jpg", $obj['imageurl']);
    }

    /*print_r(array($obj['startdate'], substr($obj['startdate'], 0, 19), $event['start_time']));
    exit(0);*/
    $event['ticket_url'] = $event['ticket_url'] . "?sktag=13086"; //affiliate ID

    if(strlen($event['venue']['country']) == 2) {
        $country = get_country($event['venue']['country']);
        if($country) $event['venue']['country'] = $country;
    }
    $event['venue'] = Geo::resolve_venue($event['location'], $event['venue']);

    if(isset($obj['organizer']['facebook']) && $obj['organizer']['facebook']) {
        $db_org = find_organizer_page($obj['organizer']['facebook']);
        if($db_org) {
            $event['owner'] = array('name'=> $db_org['name'], 'id'=> $db_org['facebook_id']);
        } else {
            print_debug("Error", "Could not find organizer page for {$obj['organizer']['facebook']}", 1, "red_bg");
        }
    }

    if ($obj['artists']) {
        echo "event_id -> {$event['event_id']} \n";
        $event['performers'] = $obj['artists'];
        $event['description'] = $event['description'] ."<br><br><b>Artists</b><br><ul>";
        foreach ($obj['artists'] as $artist) {
            $event['description'] = $event['description'] . "<li>" . htmlspecialchars($artist['name']) . "</li>" ;
        }
        $event['description'] = $event['description'] ."</ul>";
    }

    if ($obj['genres'] && count($obj['genres']) > 0) {
        $event['description'] = $event['description'] ."<br><b>Music Genres :</b> ";
        $temp_gen = array();
        foreach ($obj['genres'] as $genres) {
            $temp_gen[] = $genres['name'];
        }
        if ($temp_gen) {
            $temp_gen = implode(', ', $temp_gen);
            $event['description'] = $event['description'] . " ". $temp_gen . "\n";
        }
    }

    if ($obj['venue']['name'] && $obj['venue']['town'] && strtolower($obj['venue']['name']) == 'virtual event' && strtolower($obj['venue']['town']) == 'online') {
        print_debug('Online Event', "Virtual Event found", 1, "yellow");
        $event['categories'] = array('virtual');
    }

    /*
    cancelled = 1
    cancellationType    rescheduled,cancelled
    */
    if ($obj['cancelled'] && $obj['cancelled'] == "1") {
        if ($obj['cancellationType'] && $obj['cancellationType'] === "rescheduled") {
            $event['params']['postponed'] = 1;
        }else{
            $event['params']['cancelled'] = 1;
        }
    }
    print_r(array($obj['startdate'], substr($obj['startdate'], 0, 19), $event['start_time']));
    //print_r($event);
    //exit(0);
    return $event;
}

function get_country($country_code) {
    $db = new DB('replica');
    $sql = "select distinct(country) as country from app_cities where country_code = :country_code limit 1";
    $db->query($sql, array('country_code' => $country_code));
    $result = $db->fetchAssoc();
    if($result) return $result['country'];
    else return '';
}

function detect_action($event_id, $event) {
    $db = new DB();
    $sql = "(select id, updated_time, autoUpdate from events where event_id = :event_id)
            union
            (select id, updated_time, 0 as autoUpdate from eventsold where event_id = :event_id)";
    $db->query($sql, array("event_id" => $event_id));
    $result = $db->fetchAssoc();
    if($result) {
        if ($result['id'] > 0) {
            if ($result['autoUpdate'] == 0)
                return false; //do nothing
            if (strtotime($result['updated_time']) >= strtotime($event['updated_time']))
                return false; //do nothing
            else
                return DB::UPDATE;
        }
        else
            return DB::INSERT;
    }
    return DB::INSERT;
}

function find_organizer_page($facebook_username) {
    $facebook_username = trim($facebook_username, "/");
    if(!preg_match('/^[a-z\d.]{5,}$/i', $facebook_username)) return '';
    $db = new DB();
    $sql = "select name, facebook_id from app_organizers where facebook_username = :facebook_username limit 1";
    $db->query($sql, array('facebook_username' => $facebook_username));
    $result = $db->fetchAssoc();
    if($result) return $result;
    else {
        import_organizer_page($facebook_username);
        $db->query($sql, array('facebook_username' => $facebook_username));
        $result = $db->fetchAssoc();
        if($result) return $result;
    }
    return '';
}

function import_organizer_page($facebook_username) {
    $api = "https://allevents.in/api/index.php/organizer/web/import_profile";
    $data = '{"data":"http://facebook.com/'.$facebook_username.'"}';
    $result = Functions::get_data_post($api, $data);
    return true;
}

function log_ticket_data($event, $status, $params="", $end_url=""){
    // disabled log data due to no usage of this. - 1 oct 2020


    /*$ticket_url = strtok($event['ticket_url'],'?');
    $ticket_domain = getDomain($ticket_url);

    $db = new DB();
    $param = array('event_id' => $event['event_id'], 'ticket_url' => $ticket_url, 'domain' => $ticket_domain, 'status' => $status, 'params' => $params, 'end_url' => $end_url);

    $sql = "insert into external_ticket_logs set " . DB::queryBuilder($param) . ", created_on = CURRENT_TIMESTAMP, updated_on = CURRENT_TIMESTAMP";

    $db -> query($sql, $param);
    if($db->affectedRows() > 0) {
        return $db -> insertID();
    }
    else return 0;*/
}

function link_event_id_source_id($affiliate_id, $source_event_id, $event_id) {
    $db = new DB();
    $param = array(
        'affiliate_id' => $affiliate_id,
        'source_event_id' => $source_event_id,
        'event_id' => $event_id
        );

    $sql = "INSERT IGNORE INTO app_source_to_event_links set " . DB::queryBuilder($param);

    $db -> query($sql, $param);
    if($db->affectedRows() > 0) {
        return $db -> insertID();
    }
    else return 0;
}
?>