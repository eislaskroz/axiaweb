<?php
require __DIR__.'/lib/bootstrap.php';
require_admin();
$token=current_token();
$user=current_user();
$id=$_GET['id']??'';
if(!$id) {
    header('Location: dashboard.php');
    exit;
}
$msg='';
$err='';

function ticket_has_uploads(array $files): bool {
    if (!isset($files['name'])) return false;
    if (is_array($files['name'])) {
        foreach ($files['name'] as $name) if ((string)$name !== '') return true;
        return false;
    }
    return (string)$files['name'] !== '';
}

function normalize_uploads(array $files): array {
    if (!isset($files['name'])) return [];
    $out=[];
    if (!is_array($files['name'])) {
        return [[
            'name'=>$files['name']??'', 'type'=>$files['type']??'', 'tmp_name'=>$files['tmp_name']??'',
            'error'=>$files['error']??UPLOAD_ERR_NO_FILE, 'size'=>$files['size']??0,
        ]];
    }
    foreach ($files['name'] as $i=>$name) {
        $out[]=[
            'name'=>$name, 'type'=>$files['type'][$i]??'', 'tmp_name'=>$files['tmp_name'][$i]??'',
            'error'=>$files['error'][$i]??UPLOAD_ERR_NO_FILE, 'size'=>$files['size'][$i]??0,
        ];
    }
    return $out;
}

if($_SERVER['REQUEST_METHOD']==='POST') {
    $action=$_POST['action']??'manage';

    if($action==='manage') {
        $newStatus=(string)($_POST['status']??'');
        $assignedTo=array_key_exists('assigned_to',$_POST) ? ($_POST['assigned_to']?:null) : null;
        $serviceNotes=trim((string)($_POST['service_notes']??''));
        $visibleToRequester=isset($_POST['visible_to_requester']);
        $uploads=normalize_uploads($_FILES['evidence']??[]);
        $hasFiles=ticket_has_uploads($_FILES['evidence']??[]);

        $current=supabase_request('GET','/rest/v1/tickets',null,$token,['select'=>'id,status,assigned_to','id'=>'eq.'.$id,'limit'=>'1']);
        $currentTicket=$current['data'][0]??null;
        if(!$currentTicket) {
            $err='No fue posible consultar el estado actual del ticket.';
        } elseif($newStatus==='Cerrado' && ($currentTicket['status']??'')!=='Cerrado' && $serviceNotes==='') {
            $err='Para cerrar el ticket debes registrar las notas del servicio realizado.';
        } elseif($hasFiles && $serviceNotes==='') {
            $err='Agrega una nota del servicio para asociar las fotografías de evidencia.';
        } else {
            $validUploads=[];
            if($hasFiles) {
                if(count($uploads)>6) {
                    $err='Puedes adjuntar un máximo de 6 fotografías por registro.';
                } else {
                    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp'];
                    $finfo=new finfo(FILEINFO_MIME_TYPE);
                    foreach($uploads as $file) {
                        if(($file['error']??UPLOAD_ERR_NO_FILE)===UPLOAD_ERR_NO_FILE) continue;
                        if(($file['error']??UPLOAD_ERR_OK)!==UPLOAD_ERR_OK) { $err='Una de las fotografías no pudo cargarse correctamente.'; break; }
                        if((int)$file['size']>8*1024*1024) { $err='Cada fotografía debe pesar máximo 8 MB.'; break; }
                        $mime=$finfo->file($file['tmp_name']) ?: '';
                        if(!isset($allowed[$mime])) { $err='Solo se permiten fotografías JPG, PNG o WEBP.'; break; }
                        $validUploads[]=$file+['mime'=>$mime,'ext'=>$allowed[$mime]];
                    }
                }
            }

            if(!$err) {
                $body=['status'=>$newStatus,'assigned_to'=>$assignedTo];
                $u=supabase_request('PATCH','/rest/v1/tickets',$body,$token,['id'=>'eq.'.$id]);
                if($u['status']>=200&&$u['status']<300) {
                    $noteId=null;
                    if($serviceNotes!=='') {
                        $note=supabase_request('POST','/rest/v1/ticket_service_notes',[
                            'ticket_id'=>$id,
                            'author_id'=>$user['id']??null,
                            'notes'=>$serviceNotes,
                            'visible_to_requester'=>$visibleToRequester,
                        ],$token);
                        if($note['status']>=200&&$note['status']<300 && !empty($note['data'][0]['id'])) {
                            $noteId=$note['data'][0]['id'];
                        } else {
                            $err='El ticket se actualizó, pero no fue posible guardar las notas del servicio.';
                        }
                    }

                    if(!$err && $noteId && $validUploads) {
                        foreach($validUploads as $file) {
                            $safeBase=preg_replace('/[^A-Za-z0-9._-]+/','-',pathinfo((string)$file['name'],PATHINFO_FILENAME));
                            $safeBase=trim((string)$safeBase,'-.') ?: 'evidencia';
                            $storagePath=$id.'/'.$noteId.'/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(5)).'-'.$safeBase.'.'.$file['ext'];
                            $up=supabase_storage_upload('ticket-evidence',$storagePath,$file['tmp_name'],$file['mime'],$token);
                            if($up['status']<200||$up['status']>=300) {
                                $err='Las notas se guardaron, pero una fotografía no pudo subirse. Puedes volver a agregarla.';
                                break;
                            }
                            $meta=supabase_request('POST','/rest/v1/ticket_evidence',[
                                'ticket_id'=>$id,
                                'note_id'=>$noteId,
                                'uploaded_by'=>$user['id']??null,
                                'storage_path'=>$storagePath,
                                'file_name'=>(string)$file['name'],
                                'mime_type'=>$file['mime'],
                                'file_size'=>(int)$file['size'],
                                'visible_to_requester'=>$visibleToRequester,
                            ],$token);
                            if($meta['status']<200||$meta['status']>=300) {
                                supabase_storage_delete('ticket-evidence',[$storagePath],$token);
                                $err='Las notas se guardaron, pero no fue posible registrar una fotografía. Puedes volver a agregarla.';
                                break;
                            }
                        }
                    }
                    if(!$err) $msg='Ticket actualizado correctamente'.($serviceNotes!==''?' y atención documentada.':'.');
                } else {
                    $err='No fue posible actualizar el ticket.';
                }
            }
        }
    }
}
$r=supabase_request('GET','/rest/v1/tickets',null,$token,['select'=>'*','id'=>'eq.'.$id,'limit'=>'1']);
$ticket=$r['data'][0]??null;
if(!$ticket) {
    http_response_code(404);
    exit('Ticket no encontrado.');
}
$a=supabase_request('GET','/rest/v1/profiles',null,$token,['select'=>'id,full_name,email,role','role'=>'eq.admin_axia','order'=>'full_name.asc']);
$admins=is_array($a['data'])?$a['data']:[];
$resp=supabase_request('GET','/rest/v1/ticket_responsables',null,$token,['select'=>'profile_id,is_active','is_active'=>'eq.true']);
if($resp['status']===200 && is_array($resp['data'])) {
    $activeIds=array_column($resp['data'],'profile_id');
    $admins=array_values(array_filter($admins,fn($ad)=>in_array($ad['id'],$activeIds,true) || $ticket['assigned_to']===$ad['id']));
}
$history=supabase_request('GET','/rest/v1/ticket_history',null,$token,['select'=>'id,action,old_value,new_value,created_at,changed_by','ticket_id'=>'eq.'.$id,'order'=>'created_at.desc','limit'=>'100']);
$historyRows=($history['status']===200&&is_array($history['data']))?$history['data']:[];
$notes=supabase_request('GET','/rest/v1/ticket_service_notes',null,$token,[
    'select'=>'id,notes,created_at,author_id,visible_to_requester,author:profiles!ticket_service_notes_author_id_fkey(full_name,email),ticket_evidence(id,file_name,mime_type,file_size,created_at,visible_to_requester)',
    'ticket_id'=>'eq.'.$id,
    'order'=>'created_at.desc'
]);
$noteRows=($notes['status']===200&&is_array($notes['data']))?$notes['data']:[];
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?=h($ticket['folio'])?> · AXIA Tickets</title>
    <link rel="stylesheet" href="assets/css/portal.css">
</head>
<body>
<div class="app-shell">
<aside class="sidebar">
    <div class="sidebar-brand"><img src="assets/img/logo-axia.webp"><div><strong>AXIA</strong><small>Tickets</small></div></div>
    <nav>
        <a href="dashboard.php">▦ <span>Dashboard</span></a>
        <a class="active" href="tickets.php">🎫 <span>Tickets</span></a>
        <a href="responsables.php">👥 <span>Responsables</span></a>
        <a href="reportes.php">📊 <span>Reportes</span></a>
    </nav>
    <div class="sidebar-foot"><a href="logout.php" class="logout-link">↪ Cerrar sesión</a></div>
</aside>
<main class="main">
<header class="topbar">
    <button class="menu-toggle" onclick="toggleSidebar()">☰</button>
    <div><div class="eyebrow dark">DETALLE DEL TICKET</div><h1><?=h($ticket['folio'])?></h1></div>
    <a class="ghost-btn" href="tickets.php">← Regresar a Tickets</a>
</header>
<section class="content">
    <?php if($msg):?><div class="alert success"><?=h($msg)?></div><?php endif;?>
    <?php if($err):?><div class="alert error"><?=h($err)?></div><?php endif;?>

    <div class="ticket-grid">
        <div class="panel detail">
            <div class="ticket-title">
                <div>
                    <span class="badge <?=priority_class($ticket['priority'])?>">● <?=h($ticket['priority'])?></span>
                    <span class="badge <?=status_class($ticket['status'])?>"><?=h($ticket['status'])?></span>
                    <h2><?=h($ticket['service'])?></h2>
                </div>
                <div class="ticket-date">Creado<br><strong><?=h(date('d/m/Y H:i',strtotime($ticket['created_at'])))?></strong></div>
            </div>
            <div class="description"><small>DESCRIPCIÓN DEL PROBLEMA</small><p><?=nl2br(h($ticket['description']))?></p></div>
            <div class="info-grid">
                <div><small>SOLICITANTE</small><strong><?=h($ticket['name'])?></strong></div>
                <div><small>CORREO</small><strong><?=h($ticket['email'])?></strong></div>
                <div><small>TELÉFONO</small><strong><?=h($ticket['phone'])?></strong></div>
                <div><small>FUENTE</small><strong><?=h($ticket['source'])?></strong></div>
            </div>
        </div>

        <div class="panel manage">
            <h3>Gestión y atención</h3>
            <p>Actualiza el estado, responsable y documenta el servicio realizado.</p>
            <form method="post" enctype="multipart/form-data" id="ticketManageForm">
                <input type="hidden" name="action" value="manage">
                <div class="manage-controls">
                    <div class="manage-field">
                        <label>Estado</label>
                        <select name="status" id="ticketStatus">
                            <option <?=$ticket['status']==='Abierto'?'selected':''?>>Abierto</option>
                            <option <?=$ticket['status']==='En proceso'?'selected':''?>>En proceso</option>
                            <option <?=$ticket['status']==='Cerrado'?'selected':''?>>Cerrado</option>
                        </select>
                    </div>
                    <div class="manage-field">
                        <label>Asignado a</label>
                        <select name="assigned_to">
                            <option value="">Sin asignar</option>
                            <?php foreach($admins as $ad):?>
                                <option value="<?=h($ad['id'])?>" <?=$ticket['assigned_to']===$ad['id']?'selected':''?>><?=h($ad['full_name']?:$ad['email'])?></option>
                            <?php endforeach;?>
                        </select>
                    </div>
                </div>

                <div class="service-documentation">
                    <label for="serviceNotes">Notas del servicio <span id="notesRequired" class="required-note" hidden>· obligatorias al cerrar</span></label>
                    <textarea name="service_notes" id="serviceNotes" rows="5" maxlength="5000" placeholder="Describe diagnóstico, trabajos realizados, solución aplicada, recomendaciones o pendientes..."></textarea>
                    <div class="field-help">La nota quedará registrada con fecha y usuario. Máximo 5,000 caracteres.</div>

                    <label class="requester-visibility">
                        <input type="checkbox" name="visible_to_requester" value="1" checked>
                        <span><strong>Visible para el solicitante</strong><small>La nota y las fotografías de este registro podrán consultarse desde AXIA TICKETS.</small></span>
                    </label>

                    <label for="evidence">Evidencia fotográfica <span class="optional-label">Opcional</span></label>
                    <label class="upload-zone" for="evidence">
                        <span class="upload-icon" aria-hidden="true">📷</span>
                        <strong>Agregar fotografías</strong>
                        <small>JPG, PNG o WEBP · máximo 6 archivos · 8 MB por fotografía</small>
                    </label>
                    <input class="file-input" type="file" name="evidence[]" id="evidence" accept="image/jpeg,image/png,image/webp" multiple>
                    <div id="filePreview" class="file-preview"></div>
                </div>

                <button class="primary-btn">Guardar cambios</button>
            </form>
        </div>
    </div>

    <div class="panel service-history-panel">
        <div class="panel-head">
            <div><h3>Atención del servicio</h3><p>Notas y evidencia registrada durante la atención del ticket.</p></div>
            <span class="service-count"><?=count($noteRows)?> registro<?=count($noteRows)===1?'':'s'?></span>
        </div>
        <?php if(!$noteRows):?>
            <div class="empty-history">Todavía no se han registrado notas de servicio ni fotografías.</div>
        <?php else:?>
            <div class="service-notes-list">
            <?php foreach($noteRows as $note):
                $profile=$note['author']??[];
                $author=$profile['full_name']??($profile['email']??'Administrador AXIA');
                $evidences=is_array($note['ticket_evidence']??null)?$note['ticket_evidence']:[];
            ?>
                <article class="service-note-card">
                    <div class="service-note-head">
                        <div><strong><?=h($author)?></strong><small><?=h(date('d/m/Y H:i',strtotime($note['created_at'])))?></small></div>
                        <div class="service-note-badges">
                            <span class="service-note-tag">ATENCIÓN</span>
                            <?php if(!empty($note['visible_to_requester'])):?><span class="service-note-visible">VISIBLE EN APP</span><?php else:?><span class="service-note-internal">INTERNA</span><?php endif;?>
                        </div>
                    </div>
                    <p><?=nl2br(h($note['notes']))?></p>
                    <?php if($evidences):?>
                        <div class="evidence-grid">
                        <?php foreach($evidences as $photo):?>
                            <a class="evidence-card" href="evidence.php?id=<?=urlencode($photo['id'])?>" target="_blank" rel="noopener">
                                <img src="evidence.php?id=<?=urlencode($photo['id'])?>" alt="<?=h($photo['file_name'])?>" loading="lazy">
                                <span><?=h($photo['file_name'])?></span>
                            </a>
                        <?php endforeach;?>
                        </div>
                    <?php endif;?>
                </article>
            <?php endforeach;?>
            </div>
        <?php endif;?>
    </div>

    <div class="panel">
        <div class="panel-head"><div><h3>Historial</h3><p>Trazabilidad de cambios realizados al ticket.</p></div></div>
        <?php if(!$historyRows):?>
            <div class="empty-history">Todavía no hay eventos registrados. Ejecuta <strong>sql/02_portal_admin.sql</strong> para habilitar el historial automático.</div>
        <?php else:?>
            <div class="timeline">
            <?php foreach($historyRows as $row):?>
                <div class="timeline-item"><span></span><div><strong><?=h($row['action'])?></strong><p><?=h($row['old_value']??'')?> → <?=h($row['new_value']??'')?></p><small><?=h(date('d/m/Y H:i',strtotime($row['created_at'])))?></small></div></div>
            <?php endforeach;?>
            </div>
        <?php endif;?>
    </div>
</section>
</main>
</div>
<script src="assets/js/portal.js"></script>
<script>
(function(){
    const status=document.getElementById('ticketStatus');
    const notes=document.getElementById('serviceNotes');
    const required=document.getElementById('notesRequired');
    const input=document.getElementById('evidence');
    const preview=document.getElementById('filePreview');
    function syncRequired(){
        const closing=status.value==='Cerrado' && <?=json_encode($ticket['status']!=='Cerrado')?>;
        notes.required=closing;
        required.hidden=!closing;
    }
    let previewUrls=[];
    function clearPreviewUrls(){
        previewUrls.forEach(url=>URL.revokeObjectURL(url));
        previewUrls=[];
    }
    function previewFiles(){
        clearPreviewUrls();
        preview.innerHTML='';
        const files=Array.from(input.files||[]);
        if(files.length>6){
            alert('Puedes seleccionar un máximo de 6 fotografías.');
            input.value='';
            return;
        }
        files.forEach(file=>{
            const card=document.createElement('div');
            card.className='selected-photo';
            const img=document.createElement('img');
            const url=URL.createObjectURL(file);
            previewUrls.push(url);
            img.src=url;
            img.alt='Vista previa de '+file.name;
            const meta=document.createElement('div');
            meta.className='selected-photo-meta';
            const name=document.createElement('strong');
            name.textContent=file.name;
            const size=document.createElement('small');
            size.textContent=(file.size/1024/1024).toFixed(2)+' MB';
            meta.appendChild(name);
            meta.appendChild(size);
            card.appendChild(img);
            card.appendChild(meta);
            preview.appendChild(card);
        });
    }
    status.addEventListener('change',syncRequired);
    input.addEventListener('change',previewFiles);
    syncRequired();
})();
</script>
</body>
</html>
