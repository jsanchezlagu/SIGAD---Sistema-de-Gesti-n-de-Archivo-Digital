<?php
require_once 'config/config.php';
requiere_login();
$db = db();

// KPIs (consultas simples, seguras con PDO)
$kpi = $db->query("SELECT
    (SELECT COUNT(*) FROM expedientes)            AS exp,
    (SELECT COUNT(*) FROM documentos)             AS doc,
    (SELECT COALESCE(SUM(tamano_mb),0) FROM documentos) AS gb,
    (SELECT COUNT(*) FROM pabellones)             AS pab")->fetch();

$ult = $db->prepare("SELECT e.id, e.nro_expediente, e.cui, e.nombre_proyecto, e.asunto, p.codigo AS pab, e.estado,
                            (SELECT COUNT(*) FROM documentos d WHERE d.expediente_id=e.id) AS docs
                     FROM expedientes e JOIN pabellones p ON p.id=e.pabellon_id
                     " . (es_consulta() ? "WHERE e.estado='APROBADO' " : "") . "
                     ORDER BY e.creado_en DESC LIMIT 8");
$ult->execute();
$ultimos = $ult->fetchAll();

// DASHBOARD REAL
// 1) Documentos mas buscados (top 5 por conteo en tabla busquedas)
$masBuscados = $db->query("
    SELECT e.nro_expediente, e.asunto, COUNT(b.id) AS veces
    FROM busquedas b JOIN expedientes e ON e.id=b.expediente_id
    GROUP BY e.id ORDER BY veces DESC LIMIT 5")->fetchAll();

// 2) Areas que mas suben documentos (conteo documentos por area_origen)
$areasTop = $db->query("
    SELECT COALESCE(e.area_origen,'Sin área') AS area, COUNT(d.id) AS docs
    FROM documentos d JOIN expedientes e ON e.id=d.expediente_id
    GROUP BY e.area_origen ORDER BY docs DESC LIMIT 5")->fetchAll();

// 3) Estado de documentos (aprobados vs en proceso)
$estado = $db->query("
    SELECT estado, COUNT(*) AS total FROM expedientes GROUP BY estado")->fetchAll();
$enProc = 0; $aprob = 0;
foreach ($estado as $s) { if ($s['estado']==='APROBADO') $aprob=$s['total']; else $enProc+=$s['total']; }
// CONSULTA solo ve aprobados en su perspectiva
if (es_consulta()) { $aprob = $db->query("SELECT COUNT(*) FROM expedientes WHERE estado='APROBADO'")->fetchColumn(); $enProc = 0; }

?><!DOCTYPE html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>SIGAD · Panel</title>
<link rel="icon" href="assets/logo-msm.png">
<link rel="stylesheet" href="css/estilos.css">
</head>
<body class="app">
<aside class="side">
  <div class="marca">
    <img src="assets/logo-msm.png" alt="MDSM">
    <div><h2>SIGAD</h2><div class="muni">MD SAN MARCOS<br>Archivo Central</div></div>
  </div>
  <nav class="nav">
    <?= nav_html('Panel') ?>
  </nav>
  <div class="me">
    <b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br>
    <?= $_SESSION['rol'] ?><br>
    <a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a>
  </div>
</aside>
<main class="main">
  <div class="top"><h1>Panel general</h1>
    <span class="muted">Municipalidad Distrital de San Marcos · Archivo Central</span></div>
  <div class="grid4">
    <div class="kpi"><b><?= number_format($kpi['exp']) ?></b><span>Expedientes registrados</span></div>
    <div class="kpi"><b><?= number_format($kpi['doc']) ?></b><span>Documentos PDF</span></div>
    <div class="kpi"><b><?= number_format($kpi['gb'],1) ?> MB</b><span>Almacenamiento usado</span></div>
    <div class="kpi"><b><?= $kpi['pab'] ?></b><span>Pabellones activos</span></div>
  </div>
  <div class="card" style="margin-top:18px"><h3>Dashboard</h3>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px">
      <!-- Documentos mas buscados -->
      <div>
        <div class="muted" style="font-weight:600;margin-bottom:8px">📈 Documentos más buscados</div>
        <?php if (empty($masBuscados)): ?><p class="muted">Aún no hay búsquedas registradas.</p>
        <?php else: ?><table>
          <tr><th>Expediente</th><th>Búsquedas</th></tr>
          <?php foreach ($masBuscados as $m): ?><tr>
            <td class="mono"><?= htmlspecialchars($m['nro_expediente']) ?></td>
            <td><span class="tag t-info"><?= $m['veces'] ?></span></td></tr>
          <?php endforeach; ?></table><?php endif; ?>
      </div>
      <!-- Areas que mas suben -->
      <div>
        <div class="muted" style="font-weight:600;margin-bottom:8px">🏢 Áreas que más suben documentos</div>
        <?php if (empty($areasTop)): ?><p class="muted">Aún no hay documentos.</p>
        <?php else: ?><table>
          <tr><th>Área</th><th>Docs</th></tr>
          <?php foreach ($areasTop as $a): ?><tr>
            <td><?= htmlspecialchars($a['area']) ?></td>
            <td><span class="tag t-ok"><?= $a['docs'] ?></span></td></tr>
          <?php endforeach; ?></table><?php endif; ?>
      </div>
      <!-- Estado de documentos -->
      <div>
        <div class="muted" style="font-weight:600;margin-bottom:8px">📊 Estado de documentos</div>
        <div style="display:flex;gap:10px;flex-wrap:wrap">
          <div class="kpi" style="flex:1;min-width:120px"><b><?= number_format($aprob) ?></b><span>Aprobados</span></div>
          <div class="kpi" style="flex:1;min-width:120px"><b><?= number_format($enProc) ?></b><span>En proceso</span></div>
        </div>
      </div>
    </div>
  </div>

  <div class="card" style="margin-top:18px">
    <h3>Últimos expedientes</h3>
    <table>
      <tr><th>N° Expediente</th><th>CUI</th><th>Proyecto / asunto</th><th>Ubicación</th><th>Docs</th><th>Estado</th><th>Acción</th></tr>
      <?php foreach ($ultimos as $e): ?>
      <tr>
        <td class="mono"><?= htmlspecialchars($e['nro_expediente']) ?></td>
        <td class="mono"><?= htmlspecialchars($e['cui'] ?: '—') ?></td>
        <td><?= htmlspecialchars($e['nombre_proyecto'] ?: $e['asunto']) ?></td>
        <td class="mono"><?= htmlspecialchars($e['pab']) ?></td>
        <td><?= $e['docs'] ?></td>
        <td><span class="tag <?= $e['estado']==='APROBADO'?'t-ok':'t-warn' ?>"><?= $e['estado']==='APROBADO'?'Aprobado':'En proceso' ?></span></td>
        <td>
          <?php if (es_admin() && $e['estado']!=='APROBADO'): ?>
            <form method="post" action="aprobar.php" style="display:inline" onsubmit="return confirm('¿Aprobar este expediente?')">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $e['id'] ?>">
              <button class="btn-sm" type="submit">Aprobar</button>
            </form>
          <?php else: ?><span class="muted">—</span><?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </div>
</main>
</body>
</html>
