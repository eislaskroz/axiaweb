<?php
require __DIR__.'/lib/bootstrap.php';
require_admin();
$token = current_token();
$services = [
'Seguridad y Monitoreo','Redes Voz y Datos','Control de Accesos','Enlaces Inalámbricos',
'Electricidad','Paneles Solares','Plantas de Energía','Obra Civil','Aires Acondicionados'
];
$priorities = ['Baja','Media','Alta','Urgente'];
$statuses = ['Abierto','En proceso','Cerrado'];
$dateFrom = trim($_GET['date_from'] ?? date('Y-m-01'));
$dateTo = trim($_GET['date_to'] ?? date('Y-m-d'));
$serviceFilter = trim($_GET['service'] ?? '');
$priorityFilter = trim($_GET['priority'] ?? '');
$responsibleFilter = trim($_GET['responsible'] ?? '');
$adminsR = supabase_request('GET','/rest/v1/profiles',null,$token,[
'select'=>'id,full_name,email,role','role'=>'eq.admin_axia','order'=>'full_name.asc'
]);
$admins = ($adminsR['status']===200 && is_array($adminsR['data'])) ? $adminsR['data'] : [];
$adminMap=[];
foreach($admins as $a) {
    $adminMap[$a['id']]=$a['full_name'] ?: $a['email'];
}
$ticketsR = supabase_request('GET','/rest/v1/tickets',null,$token,[
'select'=>'id,folio,status,priority,service,ticket_date,name,email,phone,assigned_to,created_at,updated_at',
'order'=>'created_at.asc','limit'=>'5000'
]);
$allTickets = ($ticketsR['status']===200 && is_array($ticketsR['data'])) ? $ticketsR['data'] : [];
$loadError = $ticketsR['status']===200 ? '' : 'No fue posible consultar los tickets en Supabase.';
// Historial real de cambios de estado. Las métricas de resolución usan exclusivamente
// transiciones registradas a 'Cerrado'; no se infieren cierres desde updated_at.
$historyR = supabase_request('GET','/rest/v1/ticket_history',null,$token,[
'select'=>'ticket_id,action,new_value,created_at','action'=>'eq.Cambio de estado','order'=>'created_at.asc','limit'=>'10000'
]);
$historyReady = $historyR['status']===200 && is_array($historyR['data']);
$closedAt=[];
if($historyReady) {
    foreach($historyR['data'] as $h) {
        if(($h['new_value']??'')==='Cerrado') $closedAt[$h['ticket_id']]=$h['created_at'];
    }
}
$inDateRange = static function(array $t) use($dateFrom,$dateTo): bool {
    $d = substr((string)($t['ticket_date'] ?: $t['created_at'] ?? ''),0,10);
    if($dateFrom!=='' && $d < $dateFrom) return false;
    if($dateTo!=='' && $d > $dateTo) return false;
    return true;
};
$matches = static function(array $t) use($inDateRange,$serviceFilter,$priorityFilter,$responsibleFilter): bool {
    if(!$inDateRange($t)) return false;
    if($serviceFilter!=='' && ($t['service']??'')!==$serviceFilter) return false;
    if($priorityFilter!=='' && ($t['priority']??'')!==$priorityFilter) return false;
    if($responsibleFilter==='unassigned' && !empty($t['assigned_to'])) return false;
    if($responsibleFilter!=='' && $responsibleFilter!=='unassigned' && ($t['assigned_to']??'')!==$responsibleFilter) return false;
    return true;
};
$tickets = array_values(array_filter($allTickets,$matches));
$count = static fn(array $rows, callable $fn): int => count(array_filter($rows,$fn));
$total=count($tickets);
$open=$count($tickets,fn($t)=>($t['status']??'')==='Abierto');
$progress=$count($tickets,fn($t)=>($t['status']??'')==='En proceso');
$closed=$count($tickets,fn($t)=>($t['status']??'')==='Cerrado');
$urgent=$count($tickets,fn($t)=>($t['priority']??'')==='Urgente' && ($t['status']??'')!=='Cerrado');
$unassigned=$count($tickets,fn($t)=>empty($t['assigned_to']) && ($t['status']??'')!=='Cerrado');
$closureRate=$total ? round(($closed/$total)*100,1) : 0;
$closeSeconds=[];
foreach($tickets as $t) {
    if(($t['status']??'')!=='Cerrado') continue;
    $start=strtotime($t['created_at']??'');
    if(empty($closedAt[$t['id']])) continue;
    $end=strtotime($closedAt[$t['id']]);
    if($start && $end && $end >= $start) $closeSeconds[]=$end-$start;
}
$avgCloseSeconds=$closeSeconds ? array_sum($closeSeconds)/count($closeSeconds) : 0;
function human_duration(float $seconds): string {
    if($seconds<=0) return '—';
    $hours=$seconds/3600;
    if($hours<24) return number_format($hours,1).' h';
    return number_format($hours/24,1).' días';
}
$byService=array_fill_keys($services,0);
$byPriority=array_fill_keys($priorities,0);
$byStatus=array_fill_keys($statuses,0);
foreach($tickets as $t) {
    if(isset($byService[$t['service']??''])) $byService[$t['service']]++;
    if(isset($byPriority[$t['priority']??''])) $byPriority[$t['priority']]++;
    if(isset($byStatus[$t['status']??''])) $byStatus[$t['status']]++;
}
arsort($byService);
// Tendencia diaria. Acotamos visualmente a los últimos 31 puntos del periodo filtrado.
$daily=[];
foreach($tickets as $t) {
    $d=substr((string)($t['ticket_date'] ?: $t['created_at'] ?? ''),0,10);
    if(!$d) continue;
    if(!isset($daily[$d])) $daily[$d]=['created'=>0,'closed'=>0];
    $daily[$d]['created']++;
}
foreach($tickets as $t) {
    if(($t['status']??'')!=='Cerrado') continue;
    if(empty($closedAt[$t['id']])) continue;
    $d=substr((string)$closedAt[$t['id']],0,10);
    if(!$d) continue;
    if(!isset($daily[$d])) $daily[$d]=['created'=>0,'closed'=>0];
    $daily[$d]['closed']++;
}
ksort($daily);
if(count($daily)>31) $daily=array_slice($daily,-31,31,true);
$dailyMax=1;
foreach($daily as $d) {
    $dailyMax=max($dailyMax,$d['created'],$d['closed']);
}
$respStats=[];
foreach($admins as $a) {
    $respStats[$a['id']]=['name'=>$a['full_name']?:$a['email'],'assigned'=>0,'active'=>0,'closed'=>0,'urgent'=>0];
}
foreach($tickets as $t) {
    $id=$t['assigned_to']??'';
    if(!$id || !isset($respStats[$id])) continue;
    $respStats[$id]['assigned']++;
    if(($t['status']??'')==='Cerrado') $respStats[$id]['closed']++;
    else $respStats[$id]['active']++;
    if(($t['priority']??'')==='Urgente' && ($t['status']??'')!=='Cerrado') $respStats[$id]['urgent']++;
}
usort($respStats,fn($a,$b)=>$b['assigned']<=>$a['assigned']);
if(($_GET['export']??'')==='csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="AXIA_Tickets_Reporte_'.date('Ymd_His').'.csv"');
    $out=fopen('php://output','w');
    fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Folio','Fecha','Solicitante','Correo','Servicio','Prioridad','Estado','Responsable','Creado','Actualizado']);
    foreach($tickets as $t) {
        fputcsv($out,[$t['folio']??'', $t['ticket_date']??'', $t['name']??'', $t['email']??'', $t['service']??'', $t['priority']??'', $t['status']??'', $adminMap[$t['assigned_to']??'']??'Sin asignar', $t['created_at']??'', $t['updated_at']??'']);
    }
    fclose($out);
    exit;
}
function pct(int $value,int $max): string {
    return $max>0 ? number_format(($value/$max)*100,2,'.','') : '0';
}
$maxService=max(1,...array_values($byService));
$maxPriority=max(1,...array_values($byPriority));
$exportQuery=$_GET;
$exportQuery['export']='csv';
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            Reportes · AXIA Tickets
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
                    <a href="responsables.php">
                        👥
                        <span>
                            Responsables
                        </span>
                    </a>
                    <a class="active" href="reportes.php">
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
                            Reportes
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
                <section class="content reports-page">
                    <div class="page-heading">
                        <div>
                            <h2>
                                Analítica de Tickets
                            </h2>
                            <p>
                                Consulta el comportamiento operativo y exporta la información del periodo seleccionado.
                            </p>
                        </div>
                        <a class="ghost-btn" href="reportes.php?<?=h(http_build_query($exportQuery))?>">
                            ⇩ Exportar CSV
                        </a>
                    </div>
                    <?php if($loadError):?>
                        <div class="alert error">
                            <?=h($loadError)?>
                        </div>
                    <?php endif;?>
                    <form class="report-filters panel" method="get">
                        <div class="filter-field">
                            <label>
                                DESDE
                            </label>
                            <input type="date" name="date_from" value="<?=h($dateFrom)?>">
                        </div>
                        <div class="filter-field">
                            <label>
                                HASTA
                            </label>
                            <input type="date" name="date_to" value="<?=h($dateTo)?>">
                        </div>
                        <div class="filter-field service-filter">
                            <label>
                                SERVICIO
                            </label>
                            <select name="service">
                                <option value="">
                                    Todos
                                </option>
                                <?php foreach($services as $s):?>
                                    <option value="<?=h($s)?>" <?=$serviceFilter===$s?'selected':''?>>
                                        <?=h($s)?>
                                    </option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label>
                                PRIORIDAD
                            </label>
                            <select name="priority">
                                <option value="">
                                    Todas
                                </option>
                                <?php foreach($priorities as $p):?>
                                    <option value="<?=h($p)?>" <?=$priorityFilter===$p?'selected':''?>>
                                        <?=h($p)?>
                                    </option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="filter-field">
                            <label>
                                RESPONSABLE
                            </label>
                            <select name="responsible">
                                <option value="">
                                    Todos
                                </option>
                                <option value="unassigned" <?=$responsibleFilter==='unassigned'?'selected':''?>>
                                    Sin asignar
                                </option>
                                <?php foreach($admins as $a):?>
                                    <option value="<?=h($a['id'])?>" <?=$responsibleFilter===$a['id']?'selected':''?>>
                                        <?=h($a['full_name']?:$a['email'])?>
                                    </option>
                                <?php endforeach;?>
                            </select>
                        </div>
                        <div class="filter-actions">
                            <button class="filter-primary">
                                Aplicar
                            </button>
                            <a href="reportes.php?date_from=&date_to=">
                                Histórico
                            </a>
                        </div>
                    </form>
                    <div class="report-kpis">
                        <div class="report-kpi">
                            <span>
                                Tickets del periodo
                            </span>
                            <strong>
                                <?=$total?>
                            </strong>
                            <small>
                                <?=h(($dateFrom?:'Inicio').' → '.($dateTo?:'Hoy'))?>
                            </small>
                        </div>
                        <div class="report-kpi cyan-line">
                            <span>
                                Abiertos
                            </span>
                            <strong>
                                <?=$open?>
                            </strong>
                            <small>
                                pendientes de atención
                            </small>
                        </div>
                        <div class="report-kpi amber-line">
                            <span>
                                En proceso
                            </span>
                            <strong>
                                <?=$progress?>
                            </strong>
                            <small>
                                actualmente atendidos
                            </small>
                        </div>
                        <div class="report-kpi green-line">
                            <span>
                                Tasa de cierre
                            </span>
                            <strong>
                                <?=$closureRate?>%
                            </strong>
                            <small>
                                <?=$closed?> tickets cerrados
                            </small>
                        </div>
                        <div class="report-kpi red-line">
                            <span>
                                Urgentes activos
                            </span>
                            <strong>
                                <?=$urgent?>
                            </strong>
                            <small>
                                requieren prioridad
                            </small>
                        </div>
                        <div class="report-kpi violet-line">
                            <span>
                                Tiempo medio al cierre
                            </span>
                            <strong>
                                <?=h(human_duration($avgCloseSeconds))?>
                            </strong>
                            <small>
                                <?=$historyReady?'basado en historial':'estimado con actualización'?>
                            </small>
                        </div>
                    </div>
                    <div class="reports-grid">
                        <section class="panel report-panel trend-panel">
                            <div class="panel-head">
                                <div>
                                    <h3>
                                        Tendencia del periodo
                                    </h3>
                                    <p>
                                        Tickets creados y cerrados por día.
                                    </p>
                                </div>
                            </div>
                            <?php if(!$daily):?>
                                <div class="empty-state">
                                    <span>
                                        📈
                                    </span>
                                    <strong>
                                        Sin información para graficar
                                    </strong>
                                    <p>
                                        Los indicadores aparecerán cuando existan tickets en el periodo.
                                    </p>
                                </div>
                            <?php else:?>
                                <div class="trend-chart">
                                    <div class="trend-legend">
                                        <span>
                                            <i class="created-dot">
                                            </i>
                                            Creados
                                        </span>
                                        <span>
                                            <i class="closed-dot">
                                            </i>
                                            Cerrados
                                        </span>
                                    </div>
                                    <div class="trend-bars">
                                        <?php foreach($daily as $day=>$vals):?>
                                            <div class="trend-day" title="<?=h(date('d/m/Y',strtotime($day)))?> · Creados: <?=$vals['created']?> · Cerrados: <?=$vals['closed']?>">
                                                <div class="bar-pair">
                                                    <i class="bar-created" style="height:<?=pct($vals['created'],$dailyMax)?>%">
                                                    </i>
                                                    <i class="bar-closed" style="height:<?=pct($vals['closed'],$dailyMax)?>%">
                                                    </i>
                                                </div>
                                                <small>
                                                    <?=h(date('d/m',strtotime($day)))?>
                                                </small>
                                            </div>
                                        <?php endforeach;?>
                                    </div>
                                </div>
                            <?php endif;?>
                        </section>
                        <section class="panel report-panel">
                            <div class="panel-head">
                                <div>
                                    <h3>
                                        Tickets por servicio
                                    </h3>
                                    <p>
                                        Distribución de solicitudes recibidas.
                                    </p>
                                </div>
                            </div>
                            <div class="metric-bars">
                                <?php foreach($byService as $label=>$value):?>
                                    <div class="metric-row">
                                        <div>
                                            <span>
                                                <?=h($label)?>
                                            </span>
                                            <strong>
                                                <?=$value?>
                                            </strong>
                                        </div>
                                        <div class="metric-track">
                                            <i style="width:<?=pct($value,$maxService)?>%">
                                            </i>
                                        </div>
                                    </div>
                                <?php endforeach;?>
                            </div>
                        </section>
                        <section class="panel report-panel">
                            <div class="panel-head">
                                <div>
                                    <h3>
                                        Prioridad
                                    </h3>
                                    <p>
                                        Nivel de criticidad de los tickets.
                                    </p>
                                </div>
                            </div>
                            <div class="metric-bars compact">
                                <?php foreach($byPriority as $label=>$value):?>
                                    <div class="metric-row">
                                        <div>
                                            <span>
                                                <b class="priority-mini <?=priority_class($label)?>">
                                                </b>
                                                <?=h($label)?>
                                            </span>
                                            <strong>
                                                <?=$value?>
                                            </strong>
                                        </div>
                                        <div class="metric-track">
                                            <i style="width:<?=pct($value,$maxPriority)?>%">
                                            </i>
                                        </div>
                                    </div>
                                <?php endforeach;?>
                            </div>
                        </section>
                        <section class="panel report-panel status-donut-panel">
                            <div class="panel-head">
                                <div>
                                    <h3>
                                        Estado actual
                                    </h3>
                                    <p>
                                        Composición del periodo seleccionado.
                                    </p>
                                </div>
                            </div>
                            <div class="status-summary">
                                <div class="status-ring" style="--open:<?=pct($open,max(1,$total))?>;--progress:<?=pct($progress,max(1,$total))?>">
                                    <div>
                                        <strong>
                                            <?=$total?>
                                        </strong>
                                        <span>
                                            Total
                                        </span>
                                    </div>
                                </div>
                                <div class="status-legend">
                                    <span>
                                        <i class="s-open">
                                        </i>
                                        Abiertos
                                        <strong>
                                            <?=$open?>
                                        </strong>
                                    </span>
                                    <span>
                                        <i class="s-progress">
                                        </i>
                                        En proceso
                                        <strong>
                                            <?=$progress?>
                                        </strong>
                                    </span>
                                    <span>
                                        <i class="s-closed">
                                        </i>
                                        Cerrados
                                        <strong>
                                            <?=$closed?>
                                        </strong>
                                    </span>
                                    <span>
                                        <i class="s-unassigned">
                                        </i>
                                        Sin asignar activos
                                        <strong>
                                            <?=$unassigned?>
                                        </strong>
                                    </span>
                                </div>
                            </div>
                        </section>
                    </div>
                    <section class="panel responsible-report">
                        <div class="panel-head">
                            <div>
                                <h3>
                                    Desempeño por responsable
                                </h3>
                                <p>
                                    Carga y resolución dentro del periodo filtrado.
                                </p>
                            </div>
                        </div>
                        <div class="table-wrap">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>
                                            Responsable
                                        </th>
                                        <th>
                                            Asignados
                                        </th>
                                        <th>
                                            Activos
                                        </th>
                                        <th>
                                            Urgentes
                                        </th>
                                        <th>
                                            Cerrados
                                        </th>
                                        <th>
                                            Tasa de cierre
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!$respStats):?>
                                        <tr>
                                            <td colspan="6" class="empty">
                                                No existen administradores configurados.
                                            </td>
                                        </tr>
                                    <?php else:foreach($respStats as $r): $rate=$r['assigned']?round($r['closed']/$r['assigned']*100,1):0;?>
                                        <tr>
                                            <td>
                                                <strong>
                                                    <?=h($r['name'])?>
                                                </strong>
                                            </td>
                                            <td>
                                                <?=$r['assigned']?>
                                            </td>
                                            <td>
                                                <?=$r['active']?>
                                            </td>
                                            <td>
                                                <?=$r['urgent']?>
                                            </td>
                                            <td>
                                                <?=$r['closed']?>
                                            </td>
                                            <td>
                                                <div class="rate-cell">
                                                    <span>
                                                        <?=$rate?>%
                                                    </span>
                                                    <div>
                                                        <i style="width:<?=$rate?>%">
                                                        </i>
                                                    </div>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach;endif;?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                </section>
            </main>
        </div>
        <script src="assets/js/portal.js">
        </script>
    </body>
</html>
