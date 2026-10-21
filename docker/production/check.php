<?php

declare(strict_types=1);
// Configuration checks only: never print environment values or secrets.
$errors=[];
if (getenv('APP_ENV')!=='prod' || getenv('APP_DEBUG')!=='0' || getenv('APP_REQUIRE_HTTPS')!=='1') {$errors[]='production_flags';}
$url=parse_url((string)getenv('APP_PUBLIC_URL'));
$domain=$url['host']??'';
if (($url['scheme']??'')!=='https' || isset($url['user']) || isset($url['pass']) || isset($url['query']) || isset($url['fragment']) || isset($url['port']) || ($url['path']??'')!=='' || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/D',$domain)) {$errors[]='public_https_domain';}
$hosts=getenv('APP_TRUSTED_HOST_PATTERN');
if ($hosts!=='^'.str_replace('.', '[.]', $domain).'\\z') {$errors[]='exact_trusted_host';}
$proxies=array_filter(array_map('trim',explode(',',getenv('APP_TRUSTED_PROXIES')?:'')));
if (!$proxies) {$errors[]='trusted_proxy_missing';}
foreach ($proxies as $proxy) {
    $parts=explode('/',$proxy);$ip=$parts[0];$mask=$parts[1]??null;
    if (!filter_var($ip,FILTER_VALIDATE_IP) || count($parts)>2 || ($mask!==null && (!ctype_digit($mask) || (int)$mask< (str_contains($ip,':')?64:24) || (int)$mask>(str_contains($ip,':')?128:32)))) {$errors[]='trusted_proxy_too_broad_or_invalid';}
}
foreach (['APP_SECRET_FILE','DATABASE_PASSWORD_FILE','MAILER_DSN_FILE'] as $name) {
    $path=getenv($name);if (!$path || !is_readable($path) || trim((string)file_get_contents($path))==='') {$errors[]='required_secret_unavailable';}
}
if ($errors) {fwrite(STDERR,'Configuration de production refusée : '.implode(', ',array_unique($errors))."\n");exit(1);}
echo "Configuration de production valide.\n";
