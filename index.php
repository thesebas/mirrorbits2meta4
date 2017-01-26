<?php

require 'vendor/autoload.php';

define("MLNS", "urn:ietf:params:xml:ns:metalink");

function parseLinkHeader($str){
	$matched = preg_match('/<(.*?)>;\s*(.*)/', $str, $matches);
	
	if($matched){
		$url = $matches[1];
		$attrs = explode(";", $matches[2]);
		$attrs = array_map(function($attr){
			$attr = trim($attr);
			return explode("=", $attr);
			
		}
		, $attrs);
		
		$attrs = array_reduce($attrs, function($attrs, $attr){
			list($name,$val) = $attr;
			$attrs[$name] = $val;
			return $attrs;
		}
		, []);
		
		return ['url' => $url, 'attr'=>$attrs];
	}
}


$source = $_GET['url'];

if(empty($source)){
	throw new \Exception("url missing");
}

$clientOpts = [
	'allow_redirects' => false,
	'verify' => false,
	'debug' => false
];

$client = new \GuzzleHttp\Client($clientOpts);

$res = $client->request('HEAD', $source);

$linkHeaders = explode(", ", $res->getHeaderLine('link'));
$locationHeader = $res->getHeaderLine('location');

$res = $client->request('HEAD', $locationHeader);

if($res->getStatusCode() == 302){
	$locationHeader = $res->getHeaderLine('location');
	$res = $client->request('HEAD', $locationHeader);
}

$contentLengthHeader = $res->getHeaderLine('content-length');
$lastModifiedHeader = $res->getHeaderLine('last-modified');


$checksumtypes = ['md5', 'sha1', 'sha256'];

$checksums = array_reduce($checksumtypes, function($checksums, $checksumtype) use($client, $source){
	$res = $client->request('GET', $source, ['query'=>$checksumtype, 'http_errors' => false]);
	if($res->getStatusCode() == 200){
		$checksums[$checksumtype] = explode("  ", $res->getBody()->getContents());
	}
	return $checksums;
}
, []);

$dom = new \DOMDocument("1.0", "UTF-8");
$dom->formatOutput = true;

$root = $dom->appendChild($dom->createElementNS(MLNS, 'metalink'));

$pub = $root->appendChild($dom->createElementNS(MLNS, 'published'));
$pub->nodeValue = date('c', strtotime($lastModifiedHeader));

$filename = basename(parse_url($locationHeader, PHP_URL_PATH));
$file = $root->appendChild($dom->createElementNS(MLNS, 'file'));
$file->setAttribute("name", $filename);

$size = $file->appendChild($dom->createElementNS(MLNS, 'size'));
$size->nodeValue = $contentLengthHeader;

$url = $file->appendChild($dom->createElementNS(MLNS, 'url'));
$url->nodeValue = $locationHeader;

foreach(array_map('parseLinkHeader', $linkHeaders ) as $linkInfo){
	$url = $file->appendChild($dom->createElementNS(MLNS, 'url'));
	
	$url->setAttribute('location', $linkInfo['attr']['geo']);
	$url->setAttribute('priority', $linkInfo['attr']['pri']);
	
	$url->nodeValue = $linkInfo['url'];
}

foreach($checksums as $chtype => $checksuminfo){
	$hash = $file->appendChild($dom->createElementNS(MLNS, 'hash'));
	$hash->setAttribute("type", $chtype);
	$hash->nodeValue = $checksuminfo[0];
}
header("Content-Disposition: attachment; filename=\"{$filename}.meta4\"");
echo $dom->saveXML();
