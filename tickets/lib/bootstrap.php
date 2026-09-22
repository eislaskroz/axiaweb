<?php
session_start();
$configFile = __DIR__ . '/../config/config.php';
if (!file_exists($configFile)) {
    http_response_code(500);
    exit('Falta tickets/config/config.php. Copia config.example.php como config.php y configura Supabase.');
}
$config = require $configFile;
date_default_timezone_set($config['timezone'] ?? 'America/Mexico_City');

function h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function base_url(string $path = ''): string { return $path; }

function supabase_request(string $method, string $path, ?array $body = null, ?string $accessToken = null, array $query = []): array {
    global $config;
    $url = rtrim($config['supabase_url'], '/') . $path;
    if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    $headers = [
        'apikey: ' . $config['supabase_publishable_key'],
        'Content-Type: application/json',
    ];
    if ($accessToken) $headers[] = 'Authorization: Bearer ' . $accessToken;
    if (in_array($method, ['POST','PATCH'], true)) $headers[] = 'Prefer: return=representation';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 15,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    $decoded = json_decode((string)$raw, true);
    return ['status'=>$status, 'data'=>$decoded, 'raw'=>$raw, 'error'=>$error];
}

function current_token(): ?string { return $_SESSION['access_token'] ?? null; }
function current_user(): ?array { return $_SESSION['user'] ?? null; }
function require_admin(): void {
    if (empty($_SESSION['access_token']) || empty($_SESSION['user']) || ($_SESSION['user']['role'] ?? '') !== 'admin_axia') {
        header('Location: index.php'); exit;
    }
}

function fetch_profile(string $token, string $userId): ?array {
    $r = supabase_request('GET', '/rest/v1/profiles', null, $token, [
        'select'=>'id,full_name,email,phone,role', 'id'=>'eq.'.$userId, 'limit'=>'1'
    ]);
    return ($r['status'] === 200 && !empty($r['data'][0])) ? $r['data'][0] : null;
}

function priority_class(string $priority): string {
    return match(mb_strtolower($priority)) {
        'urgente' => 'priority-urgent', 'alta' => 'priority-high', 'media' => 'priority-medium', default => 'priority-low'
    };
}
function status_class(string $status): string {
    return match(mb_strtolower($status)) {
        'cerrado' => 'status-closed', 'en proceso' => 'status-progress', default => 'status-open'
    };
}

function supabase_storage_upload(string $bucket, string $path, string $localFile, string $mimeType, string $accessToken): array {
    global $config;
    $encodedPath=implode('/',array_map('rawurlencode',explode('/',$path)));
    $url=rtrim($config['supabase_url'],'/').'/storage/v1/object/'.rawurlencode($bucket).'/'.$encodedPath;
    $headers=[
        'apikey: '.$config['supabase_publishable_key'],
        'Authorization: Bearer '.$accessToken,
        'Content-Type: '.$mimeType,
        'x-upsert: false',
    ];
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_POST=>true,
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>file_get_contents($localFile),
        CURLOPT_TIMEOUT=>30,
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    return ['status'=>$status,'data'=>json_decode((string)$raw,true),'raw'=>$raw,'error'=>$error];
}

function supabase_storage_download(string $bucket, string $path, string $accessToken): array {
    global $config;
    $encodedPath=implode('/',array_map('rawurlencode',explode('/',$path)));
    $url=rtrim($config['supabase_url'],'/').'/storage/v1/object/authenticated/'.rawurlencode($bucket).'/'.$encodedPath;
    $headers=['apikey: '.$config['supabase_publishable_key'],'Authorization: Bearer '.$accessToken];
    $ch=curl_init($url);
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HTTPHEADER=>$headers,CURLOPT_TIMEOUT=>30]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    return ['status'=>$status,'raw'=>$raw,'error'=>$error];
}

function supabase_storage_delete(string $bucket, array $paths, string $accessToken): array {
    global $config;
    $url=rtrim($config['supabase_url'],'/').'/storage/v1/object/'.rawurlencode($bucket);
    $headers=[
        'apikey: '.$config['supabase_publishable_key'],
        'Authorization: Bearer '.$accessToken,
        'Content-Type: application/json',
    ];
    $ch=curl_init($url);
    curl_setopt_array($ch,[
        CURLOPT_RETURNTRANSFER=>true,
        CURLOPT_CUSTOMREQUEST=>'DELETE',
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_POSTFIELDS=>json_encode(['prefixes'=>array_values($paths)]),
        CURLOPT_TIMEOUT=>30,
    ]);
    $raw=curl_exec($ch);
    $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
    $error=curl_error($ch);
    curl_close($ch);
    return ['status'=>$status,'data'=>json_decode((string)$raw,true),'raw'=>$raw,'error'=>$error];
}
