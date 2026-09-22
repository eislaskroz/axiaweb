<?php
require __DIR__.'/lib/bootstrap.php';
require_admin();
$token = current_token();
$services = [
'Seguridad y Monitoreo','Redes Voz y Datos','Control de Accesos','Enlaces Inalámbricos',
'Electricidad','Paneles Solares','Plantas de Energía','Obra Civil','Aires Acondicionados'
];
$msg='';
$err='';
// Catálogo de usuarios administrativos: son los únicos candidatos a responsable.
$profilesR = supabase_request('GET','/rest/v1/profiles',null,$token,[
'select'=>'id,full_name,email,phone,role','role'=>'eq.admin_axia','order'=>'full_name.asc'
]);
$profiles = ($profilesR['status']===200 && is_array($profilesR['data'])) ? $profilesR['data'] : [];
$profileMap=[];
foreach($profiles as $p) {
    $profileMap[$p['id']]=$p;
}
// Detectamos si ya se ejecutó sql/03_responsables.sql.
$configR = supabase_request('GET','/rest/v1/ticket_responsables',null,$token,[
'select'=>'profile_id,is_active,department,specialties,notes,created_at,updated_at','order'=>'created_at.asc'
]);
$tableReady = $configR['status']===200;
$configRows = ($tableReady && is_array($configR['data'])) ? $configR['data'] : [];
$configMap=[];
foreach($configRows as $row) {
    $configMap[$row['profile_id']]=$row;
}
if($_SERVER['REQUEST_METHOD']==='POST') {
    if(!$tableReady) {
        $err='Primero ejecuta tickets/sql/03_responsables.sql en Supabase.';
    } else {
        $profileId = trim($_POST['profile_id'] ?? '');
        if(!$profileId || !isset($profileMap[$profileId])) {
            $err='El responsable seleccionado no es válido.';
        } else {
            $department = trim($_POST['department'] ?? 'Soporte');
            if($department==='') $department='Soporte';
            $specialties = array_values(array_intersect($services, $_POST['specialties'] ?? []));
            $payload = [
            'profile_id'=>$profileId,
            'is_active'=>isset($_POST['is_active']),
            'department'=>$department,
            'specialties'=>$specialties,
            'notes'=>trim($_POST['notes'] ?? ''),
            'updated_at'=>date(DATE_ATOM)
            ];
            if(isset($configMap[$profileId])) {
                unset($payload['profile_id']);
                $save = supabase_request('PATCH','/rest/v1/ticket_responsables',$payload,$token,['profile_id'=>'eq.'.$profileId]);
            } else {
                $save = supabase_request('POST','/rest/v1/ticket_responsables',$payload,$token);
            }
            if($save['status']>=200 && $save['status']<300) {
                $msg='Responsable actualizado correctamente.';
                $configR = supabase_request('GET','/rest/v1/ticket_responsables',null,$token,[
                'select'=>'profile_id,is_active,department,specialties,notes,created_at,updated_at','order'=>'created_at.asc'
                ]);
                $configRows = ($configR['status']===200 && is_array($configR['data'])) ? $configR['data'] : [];
                $configMap=[];
                foreach($configRows as $row) {
                    $configMap[$row['profile_id']]=$row;
                }
            } else {
                $err='No fue posible guardar la configuración del responsable.';
            }
        }
    }
}
// Carga operacional para cada responsable: tickets abiertos/en proceso asignados.
$ticketsR = supabase_request('GET','/rest/v1/tickets',null,$token,[
'select'=>'id,status,priority,assigned_to','order'=>'created_at.desc','limit'=>'1000'
]);
$tickets = ($ticketsR['status']===200 && is_array($ticketsR['data'])) ? $ticketsR['data'] : [];
$load=[];
foreach($profiles as $p) {
    $load[$p['id']]=['active'=>0,'urgent'=>0,'closed'=>0];
}
foreach($tickets as $t) {
    $aid=$t['assigned_to']??null;
    if(!$aid || !isset($load[$aid])) continue;
    if(($t['status']??'')==='Cerrado') $load[$aid]['closed']++;
    else {
        $load[$aid]['active']++;
        if(($t['priority']??'')==='Urgente') $load[$aid]['urgent']++;
    }
}
$activeCount=0;
$configuredCount=0;
foreach($profiles as $p) {
    $c=$configMap[$p['id']]??null;
    if($c) {
        $configuredCount++;
        if(!empty($c['is_active']))$activeCount++;
    }
}
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            Responsables · AXIA Tickets
        </title>
        <link rel="stylesheet" href="assets/css/portal.css">
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar">
                <div class="sidebar-brand">
                    <img src="assets/img/logo-axia.webp">
                    <div>
                        <strong>
                            AXIA
                        </strong>
                        <small>
                            Tickets
                        </small>
                    </div>
                </div>
                <nav>
                    <a href="dashboard.php">
                        ▦
                        <span>
                            Dashboard
                        </span>
                    </a>
                    <a href="tickets.php">
                        🎫
                        <span>
                            Tickets
                        </span>
                    </a>
                    <a class="active" href="responsables.php">
                        👥
                        <span>
                            Responsables
                        </span>
                    </a>
                    <a href="reportes.php">
                        📊
                        <span>
                            Reportes
                        </span>
                    </a>
                </nav>
                <div class="sidebar-foot">
                    <div class="user-mini">
                        <div class="avatar">
                            <?=h(mb_substr(current_user()['full_name']?:'A',0,1))?>
                        </div>
                        <div>
                            <strong>
                                <?=h(current_user()['full_name']?:current_user()['email'])?>
                            </strong>
                            <small>
                                Administrador AXIA
                            </small>
                        </div>
                    </div>
                    <a href="logout.php" class="logout-link">
                        ↪ Cerrar sesión
                    </a>
                </div>
            </aside>
            <main class="main">
                <header class="topbar">
                    <button class="menu-toggle" onclick="toggleSidebar()">
                        ☰
                    </button>
                    <div>
                        <div class="eyebrow dark">
                            CENTRO DE ADMINISTRACIÓN
                        </div>
                        <h1>
                            Responsables
                        </h1>
                    </div>
                    <div class="top-actions">
                        <span class="live-dot">
                        </span>
                        <span>
                            Conectado
                        </span>
                        <div class="avatar small">
                            <?=h(mb_substr(current_user()['full_name']?:'A',0,1))?>
                        </div>
                    </div>
                </header>
                <section class="content">
                    <div class="page-heading">
                        <div>
                            <h2>
                                Equipo de atención
                            </h2>
                            <p>
                                Configura quién puede recibir tickets y en qué servicios participa.
                            </p>
                        </div>
                        <a class="ghost-btn" href="responsables.php">
                            ↻ Actualizar
                        </a>
                    </div>
                    <?php if($msg):?>
                        <div class="alert success">
                            <?=h($msg)?>
                        </div>
                    <?php endif;?>
                    <?php if($err):?>
                        <div class="alert error">
                            <?=h($err)?>
                        </div>
                    <?php endif;?>
                    <?php if(!$tableReady):?>
                        <div class="setup-banner">
                            <strong>
                                Falta activar este módulo en Supabase.
                            </strong>
                            <span>
                                Ejecuta
                                <code>
                                    tickets/sql/03_responsables.sql
                                </code>
                                . El portal seguirá mostrando tus administradores actuales, pero no podrás guardar su configuración hasta hacerlo.
                            </span>
                        </div>
                    <?php endif;?>
                    <div class="responsible-kpis">
                        <div class="ticket-kpi cyan-line">
                            <span>
                                Administradores AXIA
                            </span>
                            <strong>
                                <?=count($profiles)?>
                            </strong>
                            <small>
                                candidatos a atención
                            </small>
                        </div>
                        <div class="ticket-kpi green-line">
                            <span>
                                Responsables activos
                            </span>
                            <strong>
                                <?=$activeCount?>
                            </strong>
                            <small>
                                habilitados para asignación
                            </small>
                        </div>
                        <div class="ticket-kpi violet-line">
                            <span>
                                Configurados
                            </span>
                            <strong>
                                <?=$configuredCount?>
                            </strong>
                            <small>
                                con perfil operativo
                            </small>
                        </div>
                        <div class="ticket-kpi amber-line">
                            <span>
                                Tickets activos asignados
                            </span>
                            <strong>
                                <?=array_sum(array_column($load,'active'))?>
                            </strong>
                            <small>
                                abiertos o en proceso
                            </small>
                        </div>
                    </div>
                    <?php if(!$profiles):?>
                        <div class="panel">
                            <div class="empty-state">
                                <span>
                                    👥
                                </span>
                                <strong>
                                    No hay usuarios admin_axia
                                </strong>
                                <p>
                                    Crea o cambia un perfil administrativo en Supabase para poder configurarlo como responsable.
                                </p>
                            </div>
                        </div>
                    <?php else:?>
                        <div class="responsibles-grid">
                            <?php foreach($profiles as $p): $c=$configMap[$p['id']]??null; $active=$c ? !empty($c['is_active']) : false; $specs=$c['specialties']??[]; if(!is_array($specs))$specs=[]; $stats=$load[$p['id']]??['active'=>0,'urgent'=>0,'closed'=>0]; ?>
                            <article class="responsible-card <?=$active?'is-active':'is-inactive'?>">
                                <div class="responsible-head">
                                    <div class="responsible-avatar">
                                        <?=h(mb_strtoupper(mb_substr($p['full_name']?:$p['email'],0,1)))?>
                                    </div>
                                    <div class="responsible-ident">
                                        <h3>
                                            <?=h($p['full_name']?:'Sin nombre')?>
                                        </h3>
                                        <p>
                                            <?=h($p['email'])?>
                                        </p>
                                        <span>
                                            <?=h($p['phone']?:'Sin teléfono')?>
                                        </span>
                                    </div>
                                    <span class="availability <?=$active?'on':'off'?>">
                                        <?=$active?'ACTIVO':'INACTIVO'?>
                                    </span>
                                </div>
                                <div class="workload">
                                    <div>
                                        <strong>
                                            <?=$stats['active']?>
                                        </strong>
                                        <span>
                                            Activos
                                        </span>
                                    </div>
                                    <div>
                                        <strong>
                                            <?=$stats['urgent']?>
                                        </strong>
                                        <span>
                                            Urgentes
                                        </span>
                                    </div>
                                    <div>
                                        <strong>
                                            <?=$stats['closed']?>
                                        </strong>
                                        <span>
                                            Cerrados
                                        </span>
                                    </div>
                                </div>
                                <form method="post" class="responsible-form">
                                    <input type="hidden" name="profile_id" value="<?=h($p['id'])?>">
                                    <div class="switch-row">
                                        <div>
                                            <strong>
                                                Disponible para asignación
                                            </strong>
                                            <small>
                                                Si está inactivo, no aparecerá como opción al asignar tickets.
                                            </small>
                                        </div>
                                        <label class="switch">
                                            <input type="checkbox" name="is_active" <?=$active?'checked':''?> <?=$tableReady?'':'disabled'?>>
                                            <span>
                                            </span>
                                        </label>
                                    </div>
                                    <label>
                                        Área / departamento
                                    </label>
                                    <input type="text" name="department" value="<?=h($c['department']??'Soporte')?>" placeholder="Ej. Soporte Técnico" <?=$tableReady?'':'disabled'?>>
                                    <label>
                                        Servicios que puede atender
                                    </label>
                                    <div class="specialties">
                                        <?php foreach($services as $s):?>
                                            <label>
                                                <input type="checkbox" name="specialties[]" value="<?=h($s)?>" <?=in_array($s,$specs,true)?'checked':''?> <?=$tableReady?'':'disabled'?>>
                                                <span>
                                                    <?=h($s)?>
                                                </span>
                                            </label>
                                        <?php endforeach;?>
                                    </div>
                                    <label>
                                        Notas internas
                                    </label>
                                    <textarea name="notes" rows="3" placeholder="Ej. Horario, nivel, restricciones o información interna…" <?=$tableReady?'':'disabled'?>>
                                        <?=h($c['notes']??'')?>
                                    </textarea>
                                    <button class="primary-btn" <?=$tableReady?'':'disabled'?>>
                                        Guardar responsable
                                    </button>
                                </form>
                            </article>
                        <?php endforeach;?>
                    </div>
                <?php endif;?>
            </section>
        </main>
    </div>
    <script src="assets/js/portal.js">
    </script>
</body>
</html>
