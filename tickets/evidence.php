<?php
require __DIR__.'/lib/bootstrap.php';
require_admin();
$token=current_token();
$id=(string)($_GET['id']??'');
if($id===''){ http_response_code(400); exit('Evidencia no especificada.'); }
$r=supabase_request('GET','/rest/v1/ticket_evidence',null,$token,[
    'select'=>'id,storage_path,file_name,mime_type,file_size',
    'id'=>'eq.'.$id,
    'limit'=>'1'
]);
$e=$r['data'][0]??null;
if(!$e){ http_response_code(404); exit('Evidencia no encontrada.'); }
$d=supabase_storage_download('ticket-evidence',$e['storage_path'],$token);
if($d['status']!==200){ http_response_code(404); exit('No fue posible cargar la evidencia.'); }
header('Content-Type: '.($e['mime_type']?:'application/octet-stream'));
header('Content-Length: '.strlen($d['raw']));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
echo $d['raw'];
