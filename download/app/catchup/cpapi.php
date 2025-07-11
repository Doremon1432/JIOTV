<?php

// * Copyright 2021-2025 SnehTV, Inc.
// * Licensed under MIT (https://github.com/mitthu786/TS-JioTV/blob/main/LICENSE)
// * Created By : TechieSneh

error_reporting(0);
include "cpfunctions.php";

// Server configuration
$protocol = ($_SERVER['HTTPS'] ?? '') === 'on' ? 'https://' : 'http://';
$local_ip = getHostByName(php_uname('n'));
$host_jio = (!in_array($_SERVER['SERVER_ADDR'], ['127.0.0.1', 'localhost']))
    ? $_SERVER['HTTP_HOST']
    : $local_ip;

$host_jio .= (strpos($host_jio, $_SERVER['SERVER_PORT']) === false)
    ? ':' . $_SERVER['SERVER_PORT']
    : '';

$jio_path = sprintf(
    '%s%s%s',
    $protocol,
    $host_jio,
    str_replace(basename($_SERVER['PHP_SELF']), '', str_replace(" ", "%20", $_SERVER['PHP_SELF']))
);

// Common headers
header("Content-Type: application/vnd.apple.mpegurl");
header("Access-Control-Allow-Origin: *");
header("Access-Control-Expose-Headers: Content-Length,Content-Range");
header("Access-Control-Allow-Headers: Range");
header("Accept-Ranges: bytes");

// Get credentials
$cred = getCRED();
$jio_cred = json_decode($cred, true);
$ssoToken = $jio_cred['ssoToken'];
$access_token = $jio_cred['authToken'];
$crm = $jio_cred['sessionAttributes']['user']['subscriberId'];
$uniqueId = $jio_cred['sessionAttributes']['user']['unique'];
$device_id = $jio_cred['deviceId'];

// Request parameters
$srno = $_REQUEST['srno'] ?? '';
$id = $_REQUEST['id'] ?? '';
$result = substr($srno, 0, 6);
$begin = $_REQUEST['begin'] ?? '';
$end = $_REQUEST['end'] ?? '';

// API request setup
$post_data = http_build_query([
    'stream_type' => 'Catchup',
    'channel_id' => $id,
    'programId' => $srno,
    'showtime' => '000000',
    'srno' => $srno,
    'begin' => $begin,
    'end' => $end
]);

$headers_api = [
    "Host: jiotvapi.media.jio.com",
    "Content-Type: application/x-www-form-urlencoded",
    "appkey: NzNiMDhlYzQyNjJm",
    "channel_id: $id",
    "userid: $crm",
    "crmid: $crm",
    "deviceId: $device_id",
    "devicetype: phone",
    "isott: true",
    "languageId: 6",
    "lbcookie: 1",
    "os: android",
    "dm: Xiaomi 22101316UP",
    "osversion: 14",
    "srno: 240303144000",
    "accesstoken: $access_token",
    "subscriberid: $crm",
    "uniqueId: $uniqueId",
    "content-length: " . strlen($post_data),
    "usergroup: tvYR7NSNn7rymo3F",
    "User-Agent: okhttp/4.9.3",
    "versionCode: 353",
];

// API call
$response = cUrlGetData("https://jiotvapi.media.jio.com/playback/apis/v1/geturl?langId=6", $headers_api, $post_data);
$haystack = json_decode($response);

if ($haystack->code !== 200) {
    refresh_token();
    header("Location: {$_SERVER['REQUEST_URI']}");
    exit;
}

// Response processing
$cookie_parts = explode('?', $haystack->result);
$chs = explode('/', $cookie_parts[0]);
$cookie = match (true) {
    str_contains($cookie_parts[1], "bpk-tv") => '_' . explode("&_", $cookie_parts[1])[1],
    str_contains($cookie_parts[1], "/HLS/") => '_' . explode("&_", $cookie_parts[1])[1],
    default => $cookie_parts[1]
};

$cook = bin2hex($cookie);
$headers = jio_headers($cook, $crm, $device_id, $ssoToken, $uniqueId);

// Content routing
if (str_contains($cookie, "bpk-tv")) {
    $hs = cUrlGetData($haystack->result, $headers);
    $replacements = [
        'URI="' => "URI='cpstream.php?ck={$cook}&cid=",
        "{$chs[4]}-video" => "cpstream.php?ck={$cook}&cid={$chs[4]}-video",
        "{$chs[4]}-audio" => "cpstream.php?ck={$cook}&cid={$chs[4]}-audio",
        '?vbegin=' => '?vb=',
        '&vend=' => '=',
        "cpstream.php?ck={$cook}&cid=keyframes/" => "cpstream.php?ck={$cook}&cid=keyframes/"
    ];
    print_r(str_replace(array_keys($replacements), $replacements, $hs));
} elseif (str_contains($cookie, "/HLS/")) {
    $link_1 = implode('/', array_slice($chs, 0, 7));
    $link = implode('/', array_slice($chs, 0, 5));
    $data = explode("_", $chs[5])[0];

    $hs = cUrlGetData($haystack->result, $headers);
    $base_url = "cpSonyAuth.php?id=$id&ck=$cook&link=";
    if (str_contains($hs, "WL/")) {
        print str_replace([$data, 'WL/'], ["{$base_url}$link&data=$data", "{$base_url}$link_1&data=WL/"], $hs);
    } else {
        print str_replace([$data, 'WL2/'], ["{$base_url}$link&data=$data", "{$base_url}$link_1&data=WL2/"], $hs);
    }
} elseif (str_contains($cookie, "acl=/{$chs[3]}/")) {
    print str_replace(
        'https://jiotvmbcod.cdn.jio.com/',
        "cpstream.php?ck=$cook&sid=",
        cUrlGetData($haystack->result, $headers)
    );
} else {
    http_response_code(404);
    die;
}
