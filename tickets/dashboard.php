<?php
require __DIR__.'/lib/bootstrap.php';
require_admin();
$token=current_token();
$filters=['select'=>'id,folio,status,priority,service,ticket_date,name,email,phone,source,assigned_to,description,created_at,updated_at','order'=>'created_at.desc','limit'=>'250'];
if (!empty($_GET['status'])) $filters['status']='eq.'.$_GET['status'];
if (!empty($_GET['priority'])) $filters['priority']='eq.'.$_GET['priority'];
if (!empty($_GET['service'])) $filters['service']='eq.'.$_GET['service'];
$r=supabase_request('GET','/rest/v1/tickets',null,$token,$filters);
$tickets=($r['status']===200 && is_array($r['data']))?$r['data']:[];
$allR=supabase_request('GET','/rest/v1/tickets',null,$token,['select'=>'id,status,priority']);
$all=($allR['status']===200&&is_array($allR['data']))?$allR['data']:[];
$count=function($fn)use($all) {
    return count(array_filter($all,$fn));
};
$counts=['total'=>count($all),'open'=>$count(fn($t)=>($t['status']??'')==='Abierto'),'progress'=>$count(fn($t)=>($t['status']??'')==='En proceso'),'urgent'=>$count(fn($t)=>($t['priority']??'')==='Urgente'&&($t['status']??'')!=='Cerrado'),'closed'=>$count(fn($t)=>($t['status']??'')==='Cerrado')];
$services=['Seguridad y Monitoreo','Redes Voz y Datos','Control de Accesos','Enlaces Inalámbricos','Electricidad','Paneles Solares','Plantas de Energía','Obra Civil','Aires Acondicionados'];
?>
<!doctype html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>
            AXIA Tickets · Dashboard
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
                    <a class="active" href="dashboard.php">
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
                            Dashboard de Tickets
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
                    <div class="welcome">
                        <div>
                            <h2>
                                Hola, <?=h(explode(' ',trim(current_user()['full_name']?:'Administrador'))[0])?> 👋
                            </h2>
                            <p>
                                Este es el estado actual de las solicitudes recibidas desde la aplicación móvil.
                            </p>
                        </div>
                        <a href="dashboard.php" class="ghost-btn">
                            ↻ Actualizar
                        </a>
                    </div>
                    <div class="stats">
                        <div class="stat">
                            <span class="stat-icon blue">
                                🎫
                            </span>
                            <div>
                                <small>
                                    Total
                                </small>
                                <strong>
                                    <?=$counts['total']?>
                                </strong>
                                <em>
                                    registrados
                                </em>
                            </div>
                        </div>
                        <div class="stat">
                            <span class="stat-icon cyan">
                                ◷
                            </span>
                            <div>
                                <small>
                                    Abiertos
                                </small>
                                <strong>
                                    <?=$counts['open']?>
                                </strong>
                                <em>
                                    por atender
                                </em>
                            </div>
                        </div>
                        <div class="stat">
                            <span class="stat-icon amber">
                                ⚙
                            </span>
                            <div>
                                <small>
                                    En proceso
                                </small>
                                <strong>
                                    <?=$counts['progress']?>
                                </strong>
                                <em>
                                    en atención
                                </em>
                            </div>
                        </div>
                        <div class="stat">
                            <span class="stat-icon red">
                                !
                            </span>
                            <div>
                                <small>
                                    Urgentes
                                </small>
                                <strong>
                                    <?=$counts['urgent']?>
                                </strong>
                                <em>
                                    requieren atención
                                </em>
                            </div>
                        </div>
                        <div class="stat">
                            <span class="stat-icon green">
                                ✓
                            </span>
                            <div>
                                <small>
                                    Cerrados
                                </small>
                                <strong>
                                    <?=$counts['closed']?>
                                </strong>
                                <em>
                                    resueltos
                                </em>
                            </div>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="panel-head">
                            <div>
                                <h3>
                                    Tickets recientes
                                </h3>
                                <p>
                                    Consulta y administra las solicitudes registradas.
                                </p>
                            </div>
                            <div class="search">
                                <span>
                                    ⌕
                                </span>
                                <input id="tableSearch" placeholder="Buscar folio, cliente o servicio…" oninput="filterTable()">
                            </div>
                        </div>
                        <form class="filters" method="get">
                            <select name="status">
                                <option value="">
                                    Todos los estados
                                </option>
                                <option <?=($_GET['status']??'')==='Abierto'?'selected':''?>>
                                    Abierto
                                </option>
                                <option <?=($_GET['status']??'')==='En proceso'?'selected':''?>>
                                    En proceso
                                </option>
                                <option <?=($_GET['status']??'')==='Cerrado'?'selected':''?>>
                                    Cerrado
                                </option>
                            </select>
                            <select name="priority">
                                <option value="">
                                    Todas las prioridades
                                </option>
                                <?php foreach(['Baja','Media','Alta','Urgente'] as $p):?>
                                    <option <?=($_GET['priority']??'')===$p?'selected':''?>>
                                        <?=h($p)?>
                                    </option>
                                <?php endforeach;?>
                            </select>
                            <select name="service">
                                <option value="">
                                    Todos los servicios
                                </option>
                                <?php foreach($services as $s):?>
                                    <option <?=($_GET['service']??'')===$s?'selected':''?>>
                                        <?=h($s)?>
                                    </option>
                                <?php endforeach;?>
                            </select>
                            <button>
                                Aplicar filtros
                            </button>
                            <a href="dashboard.php">
                                Limpiar
                            </a>
                        </form>
                        <div class="table-wrap">
                            <table id="ticketsTable">
                                <thead>
                                    <tr>
                                        <th>
                                            Folio
                                        </th>
                                        <th>
                                            Fecha
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
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if(!$tickets):?>
                                        <tr>
                                            <td colspan="7" class="empty">
                                                No hay tickets que coincidan con los filtros.
                                            </td>
                                        </tr>
                                    <?php else: foreach($tickets as $t):?>
                                        <tr>
                                            <td>
                                                <a class="folio" href="ticket.php?id=<?=urlencode($t['id'])?>">
                                                    <?=h($t['folio']?:'Sin folio')?>
                                                </a>
                                            </td>
                                            <td>
                                                <?=h(date('d/m/Y',strtotime($t['ticket_date'])))?>
                                                <small>
                                                    <?=h(date('H:i',strtotime($t['created_at'])))?>
                                                </small>
                                            </td>
                                            <td>
                                                <strong>
                                                    <?=h($t['name'])?>
                                                </strong>
                                                <small>
                                                    <?=h($t['email'])?>
                                                </small>
                                            </td>
                                            <td>
                                                <?=h($t['service'])?>
                                            </td>
                                            <td>
                                                <span class="badge <?=priority_class($t['priority'])?>">
                                                    ● <?=h($t['priority'])?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge <?=status_class($t['status'])?>">
                                                    <?=h($t['status'])?>
                                                </span>
                                            </td>
                                            <td>
                                                <a class="row-btn" href="ticket.php?id=<?=urlencode($t['id'])?>">
                                                    Ver →
                                                </a>
                                            </td>
                                        </tr>
                                    <?php endforeach; endif;?>
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
