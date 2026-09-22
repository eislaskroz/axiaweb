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
// Catálogo de responsables administrativos para mostrar el nombre asignado.
$adminsResponse = supabase_request('GET','/rest/v1/profiles',null,$token,[
'select'=>'id,full_name,email,role',
'role'=>'eq.admin_axia',
'order'=>'full_name.asc'
]);
$admins = ($adminsResponse['status'] === 200 && is_array($adminsResponse['data'])) ? $adminsResponse['data'] : [];
$adminMap = [];
foreach ($admins as $admin) {
    $adminMap[$admin['id']] = $admin['full_name'] ?: $admin['email'];
}
// Recuperamos el listado administrativo. Los filtros finos se aplican en PHP para
// mantener compatibilidad con todos los hostings y evitar expresiones PostgREST complejas.
$response = supabase_request('GET','/rest/v1/tickets',null,$token,[
'select'=>'id,folio,status,priority,service,ticket_date,name,email,phone,source,assigned_to,description,created_at,updated_at',
'order'=>'created_at.desc',
'limit'=>'1000'
]);
$allTickets = ($response['status'] === 200 && is_array($response['data'])) ? $response['data'] : [];
$loadError = $response['status'] === 200 ? '' : 'No fue posible consultar los tickets en Supabase.';
$filters = [
'q' => trim($_GET['q'] ?? ''),
'status' => trim($_GET['status'] ?? ''),
'priority' => trim($_GET['priority'] ?? ''),
'service' => trim($_GET['service'] ?? ''),
'assignment' => trim($_GET['assignment'] ?? ''),
'date_from' => trim($_GET['date_from'] ?? ''),
'date_to' => trim($_GET['date_to'] ?? ''),
];
$matches = static function(array $ticket) use ($filters): bool {
    if ($filters['status'] !== '' && ($ticket['status'] ?? '') !== $filters['status']) return false;
    if ($filters['priority'] !== '' && ($ticket['priority'] ?? '') !== $filters['priority']) return false;
    if ($filters['service'] !== '' && ($ticket['service'] ?? '') !== $filters['service']) return false;
    if ($filters['assignment'] === 'assigned' && empty($ticket['assigned_to'])) return false;
    if ($filters['assignment'] === 'unassigned' && !empty($ticket['assigned_to'])) return false;
    $ticketDate = (string)($ticket['ticket_date'] ?? '');
    if ($filters['date_from'] !== '' && $ticketDate < $filters['date_from']) return false;
    if ($filters['date_to'] !== '' && $ticketDate > $filters['date_to']) return false;
    if ($filters['q'] !== '') {
        $haystack = mb_strtolower(implode(' ', [
        $ticket['folio'] ?? '', $ticket['name'] ?? '', $ticket['email'] ?? '',
        $ticket['phone'] ?? '', $ticket['service'] ?? '', $ticket['description'] ?? ''
        ]));
        if (!str_contains($haystack, mb_strtolower($filters['q']))) return false;
    }
    return true;
};
$tickets = array_values(array_filter($allTickets, $matches));
$count = static fn(callable $fn): int => count(array_filter($allTickets, $fn));
$counts = [
'total' => count($allTickets),
'open' => $count(fn($t) => ($t['status'] ?? '') === 'Abierto'),
'progress' => $count(fn($t) => ($t['status'] ?? '') === 'En proceso'),
'urgent' => $count(fn($t) => ($t['priority'] ?? '') === 'Urgente' && ($t['status'] ?? '') !== 'Cerrado'),
'unassigned' => $count(fn($t) => empty($t['assigned_to']) && ($t['status'] ?? '') !== 'Cerrado'),
'closed' => $count(fn($t) => ($t['status'] ?? '') === 'Cerrado'),
];
function current_query(array $overrides = []): string {
    $query = array_merge($_GET, $overrides);
    foreach ($query as $key => $value) if ($value === '' || $value === null) unset($query[$key]);
    return http_build_query($query);
}
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            Tickets · AXIA
        </title>
        <link rel="stylesheet" href="assets/css/portal.css">
    </head>
    <body>
        <div class="app-shell">
            <aside class="sidebar">
                <div class="sidebar-brand">
                    <img src="assets/img/logo-axia.webp" alt="AXIA">
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
                    <a class="active" href="tickets.php">
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
                            <?=h(mb_substr(current_user()['full_name'] ?: 'A',0,1))?>
                        </div>
                        <div>
                            <strong>
                                <?=h(current_user()['full_name'] ?: current_user()['email'])?>
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
                            Administración de Tickets
                        </h1>
                    </div>
                    <div class="top-actions">
                        <span class="live-dot">
                        </span>
                        <span>
                            Conectado
                        </span>
                        <div class="avatar small">
                            <?=h(mb_substr(current_user()['full_name'] ?: 'A',0,1))?>
                        </div>
                    </div>
                </header>
                <section class="content tickets-page">
                    <div class="page-heading">
                        <div>
                            <h2>
                                Tickets
                            </h2>
                            <p>
                                Consulta, filtra y abre cualquier solicitud registrada desde AXIA Tickets.
                            </p>
                        </div>
                        <a href="tickets.php" class="ghost-btn">
                            ↻ Actualizar
                        </a>
                    </div>
                    <?php if ($loadError): ?>
                        <div class="alert error">
                            <?=h($loadError)?>
                        </div>
                    <?php endif; ?>
                    <div class="ticket-kpis">
                        <a class="ticket-kpi" href="tickets.php">
                            <span>
                                Total
                            </span>
                            <strong>
                                <?=$counts['total']?>
                            </strong>
                            <small>
                                registrados
                            </small>
                        </a>
                        <a class="ticket-kpi cyan-line" href="tickets.php?status=Abierto">
                            <span>
                                Abiertos
                            </span>
                            <strong>
                                <?=$counts['open']?>
                            </strong>
                            <small>
                                por atender
                            </small>
                        </a>
                        <a class="ticket-kpi amber-line" href="tickets.php?status=En+proceso">
                            <span>
                                En proceso
                            </span>
                            <strong>
                                <?=$counts['progress']?>
                            </strong>
                            <small>
                                en atención
                            </small>
                        </a>
                        <a class="ticket-kpi red-line" href="tickets.php?priority=Urgente">
                            <span>
                                Urgentes
                            </span>
                            <strong>
                                <?=$counts['urgent']?>
                            </strong>
                            <small>
                                activos
                            </small>
                        </a>
                        <a class="ticket-kpi violet-line" href="tickets.php?assignment=unassigned">
                            <span>
                                Sin asignar
                            </span>
                            <strong>
                                <?=$counts['unassigned']?>
                            </strong>
                            <small>
                                pendientes
                            </small>
                        </a>
                        <a class="ticket-kpi green-line" href="tickets.php?status=Cerrado">
                            <span>
                                Cerrados
                            </span>
                            <strong>
                                <?=$counts['closed']?>
                            </strong>
                            <small>
                                resueltos
                            </small>
                        </a>
                    </div>
                    <div class="panel ticket-list-panel">
                        <div class="panel-head ticket-list-head">
                            <div>
                                <h3>
                                    Listado de tickets
                                </h3>
                                <p>
                                    <?=count($tickets)?> resultado<?=count($tickets)===1?'':'s'?> con los filtros actuales.
                                </p>
                            </div>
                            <form class="tickets-search" method="get">
                                <?php foreach (['status','priority','service','assignment','date_from','date_to'] as $key): if ($filters[$key] !== ''): ?>
                                    <input type="hidden" name="<?=$key?>" value="<?=h($filters[$key])?>">
                                <?php endif; endforeach; ?>
                                <span>
                                    ⌕
                                </span>
                                <input name="q" value="<?=h($filters['q'])?>" placeholder="Buscar folio, solicitante, correo…">
                                <button>
                                    Buscar
                                </button>
                            </form>
                        </div>
                        <form class="advanced-filters" method="get">
                            <div class="filter-field wide">
                                <label>
                                    Estado
                                </label>
                                <select name="status">
                                    <option value="">
                                        Todos
                                    </option>
                                    <?php foreach ($statuses as $value): ?>
                                        <option value="<?=h($value)?>" <?=$filters['status']===$value?'selected':''?>>
                                            <?=h($value)?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label>
                                    Prioridad
                                </label>
                                <select name="priority">
                                    <option value="">
                                        Todas
                                    </option>
                                    <?php foreach ($priorities as $value): ?>
                                        <option value="<?=h($value)?>" <?=$filters['priority']===$value?'selected':''?>>
                                            <?=h($value)?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field service-filter">
                                <label>
                                    Servicio
                                </label>
                                <select name="service">
                                    <option value="">
                                        Todos los servicios
                                    </option>
                                    <?php foreach ($services as $value): ?>
                                        <option value="<?=h($value)?>" <?=$filters['service']===$value?'selected':''?>>
                                            <?=h($value)?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label>
                                    Asignación
                                </label>
                                <select name="assignment">
                                    <option value="">
                                        Todos
                                    </option>
                                    <option value="unassigned" <?=$filters['assignment']==='unassigned'?'selected':''?>>
                                        Sin asignar
                                    </option>
                                    <option value="assigned" <?=$filters['assignment']==='assigned'?'selected':''?>>
                                        Asignados
                                    </option>
                                </select>
                            </div>
                            <div class="filter-field">
                                <label>
                                    Desde
                                </label>
                                <input type="date" name="date_from" value="<?=h($filters['date_from'])?>">
                            </div>
                            <div class="filter-field">
                                <label>
                                    Hasta
                                </label>
                                <input type="date" name="date_to" value="<?=h($filters['date_to'])?>">
                            </div>
                            <?php if ($filters['q'] !== ''): ?>
                                <input type="hidden" name="q" value="<?=h($filters['q'])?>">
                            <?php endif; ?>
                            <div class="filter-actions">
                                <button class="filter-primary">
                                    Aplicar filtros
                                </button>
                                <a href="tickets.php">
                                    Limpiar
                                </a>
                            </div>
                        </form>
                        <?php if ($filters['q'] || $filters['status'] || $filters['priority'] || $filters['service'] || $filters['assignment'] || $filters['date_from'] || $filters['date_to']): ?>
                            <div class="active-filters">
                                <strong>
                                    Filtros activos:
                                </strong>
                                <?php foreach ($filters as $key => $value): if ($value === '') continue; ?>
                                <span>
                                    <?=h(['q'=>'Búsqueda','status'=>'Estado','priority'=>'Prioridad','service'=>'Servicio','assignment'=>'Asignación','date_from'=>'Desde','date_to'=>'Hasta'][$key] ?? $key)?>:
                                    <b>
                                        <?=h($value)?>
                                    </b>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                    <div class="table-wrap">
                        <table class="tickets-admin-table">
                            <thead>
                                <tr>
                                    <th>
                                        Folio
                                    </th>
                                    <th>
                                        Registro
                                    </th>
                                    <th>
                                        Solicitante
                                    </th>
                                    <th>
                                        Servicio
                                    </th>
                                    <th>
                                        Prioridad
                                    </th>
                                    <th>
                                        Estado
                                    </th>
                                    <th>
                                        Responsable
                                    </th>
                                    <th>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!$tickets): ?>
                                    <tr>
                                        <td colspan="8" class="empty">
                                            <div class="empty-state">
                                                <span>
                                                    🎫
                                                </span>
                                                <strong>
                                                    No encontramos tickets
                                                </strong>
                                                <p>
                                                    Prueba modificando o eliminando alguno de los filtros.
                                                </p>
                                            </div>
                                        </td>
                                    </tr>
                                <?php else: foreach ($tickets as $ticket): ?>
                                    <?php $assignedName = $ticket['assigned_to'] ? ($adminMap[$ticket['assigned_to']] ?? 'Responsable') : ''; ?>
                                    <tr class="ticket-row" onclick="window.location='ticket.php?id=<?=urlencode($ticket['id'])?>'">
                                        <td>
                                            <a class="folio" href="ticket.php?id=<?=urlencode($ticket['id'])?>">
                                                <?=h($ticket['folio'] ?: 'Sin folio')?>
                                            </a>
                                            <small>
                                                <?=h($ticket['source'] ?? '')?>
                                            </small>
                                        </td>
                                        <td>
                                            <?=h(date('d/m/Y', strtotime($ticket['ticket_date'] ?: $ticket['created_at'])))?>
                                            <small>
                                                <?=h(date('H:i', strtotime($ticket['created_at'])))?>
                                            </small>
                                        </td>
                                        <td>
                                            <strong>
                                                <?=h($ticket['name'])?>
                                            </strong>
                                            <small>
                                                <?=h($ticket['email'])?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="service-name">
                                                <?=h($ticket['service'])?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?=priority_class($ticket['priority'])?>">
                                                ● <?=h($ticket['priority'])?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge <?=status_class($ticket['status'])?>">
                                                <?=h($ticket['status'])?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if ($assignedName): ?>
                                                <div class="assignee">
                                                    <span>
                                                        <?=h(mb_substr($assignedName,0,1))?>
                                                    </span>
                                                    <div>
                                                        <?=h($assignedName)?>
                                                    </div>
                                                </div>
                                            <?php else: ?>
                                                <span class="unassigned">
                                                    Sin asignar
                                                </span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <a class="row-btn" href="ticket.php?id=<?=urlencode($ticket['id'])?>" onclick="event.stopPropagation()">
                                                Gestionar →
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        </main>
    </div>
    <script src="assets/js/portal.js">
    </script>
</body>
</html>
