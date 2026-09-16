<?php
require_once 'config/config.php';
requiere_login();
$db = db();
$res = [];
if (!empty($_GET['q']) || !empty($_GET['pab']) || !empty($_GET['anio'])) {
    $sql = "SELECT e.id, e.nro_expediente, e.anio, e.fecha_expediente, e.area_origen, e.asunto,
                   p.codigo AS pab, e.estado,
                   (SELECT COUNT(*) FROM documentos d WHERE d.expediente_id=e.id) AS docs
            FROM expedientes e JOIN pabellones p ON p.id=e.pabellon_id
            WHERE 1=1";
    $p = [];
    if (es_consulta()) { $sql .= " AND e.estado = 'APROBADO'"; }   // consulta solo ve aprobados
    if ($q = trim($_GET['q'] ?? '')) {
        // busca en numero, asunto, area O en el texto de los PDF (OCR de capa)
        $sql .= " AND (e.nro_expediente LIKE ? OR e.asunto LIKE ? OR e.area_origen LIKE ?
                  OR e.id IN (SELECT expediente_id FROM documentos WHERE texto LIKE ?))";
        $p = array_merge($p, ["%$q%","%$q%","%$q%","%$q%"]);
    }
    if ($pb = $_GET['pab'] ?? '') { $sql .= " AND p.codigo = ?"; $p[] = $pb; }
    if ($an = $_GET['anio'] ?? '') { $sql .= " AND e.anio = ?"; $p[] = $an; }
    $sql .= " ORDER BY e.creado_en DESC LIMIT 100";
    $st = $db->prepare($sql); $st->execute($p); $res = $st->fetchAll();

    // registrar la busqueda para el ranking de "mas buscados" (solo si hubo termino y resultados)
    if ($q !== '' && !empty($res)) {
        $insB = $db->prepare("INSERT INTO busquedas (expediente_id, termino, usuario_id) VALUES (?,?,?)");
        foreach ($res as $r) {
            $insB->execute([$r['id'] ?? null, $q, $_SESSION['uid'] ?? null]);
        }
    }
}
$pabs = $db->query("SELECT codigo, nombre FROM pabellones ORDER BY codigo")->fetchAll();
$anios = $db->query("SELECT DISTINCT anio FROM expedientes ORDER BY anio DESC")->fetchAll();
?>
<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">
<link rel="icon" href="assets/logo-msm.png"><link rel="stylesheet" href="css/estilos.css">
<title>SIGAD · Buscar</title></head>
<body class="app"><aside class="side">
  <div class="marca"><img src="assets/logo-msm.png"><div><h2>SIGAD</h2>
    <div class="muni">MD SAN MARCOS<br>Archivo Central</div></div></div>
  <nav class="nav">
    <?= nav_html('Buscar') ?>
  </nav>
  <div class="me"><b><?= htmlspecialchars($_SESSION['nombre']) ?></b><br><?= $_SESSION['rol'] ?>
    <br><a href="<?= logout_href() ?>" style="color:#60a5fa">Cerrar sesión</a></div>
</aside>
<main class="main">
  <div class="top"><h1>Buscar expediente</h1></div>
  <div class="card">
    <form method="get">
    <div class="row">
      <div><label>N° de expediente / texto</label>
        <input name="q" placeholder="EXP-2024-000123 o palabra del documento" value="<?= htmlspecialchars($_GET['q']??'') ?>"></div>
      <div><label>Pabellón</label>
        <select name="pab"><option value="">Todos</option>
          <?php foreach ($pabs as $p): ?><option <?= ($_GET['pab']??'')===$p['codigo']?'selected':'' ?>><?= $p['codigo'] ?></option><?php endforeach; ?>
        </select></div>
      <div><label>Año</label>
        <select name="anio"><option value="">Todos</option>
          <?php foreach ($anios as $a): ?><option value="<?= $a['anio'] ?>" <?= ($_GET['anio']??'')==$a['anio']?'selected':'' ?>><?= $a['anio'] ?></option><?php endforeach; ?>
        </select></div>
      <div style="flex:0"><button class="btn-sm" type="submit">Buscar</button></div>
    </div></form>
  </div>
  <div class="card">
    <?php if (empty($res)): ?><p class="muted">Sin resultados. Use los filtros de arriba.</p>
    <?php else: ?>
    <table>
      <tr><th>N° Expediente</th><th>Año</th><th>Fecha</th><th>Área</th><th>Asunto</th><th>Ubicación</th><th>Docs</th><th>Estado</th><th>Acción</th></tr>
      <?php foreach ($res as $e): ?>
      <tr><td class="mono"><?= htmlspecialchars($e['nro_expediente']) ?></td>
          <td><?= $e['anio'] ?></td>
          <td class="mono"><?= $e['fecha_expediente'] ? date('d/m/Y', strtotime($e['fecha_expediente'])) : '—' ?></td>
          <td><?= htmlspecialchars($e['area_origen']) ?></td>
          <td><?= htmlspecialchars($e['asunto']) ?></td>
          <td class="mono"><?= htmlspecialchars($e['pab']) ?></td>
          <td><?= $e['docs'] ?></td>
          <td><span class="tag <?= $e['estado']==='APROBADO'?'t-ok':'t-warn' ?>"><?= $e['estado']==='APROBADO'?'Aprobado':'En proceso' ?></span></td>
          <td>
            <a class="btn-sm" href="documentos.php?exp=<?= $e['id'] ?>">Docs</a>
            <?php if (es_admin() && $e['estado']!=='APROBADO'): ?>
              <form method="post" action="aprobar.php" style="display:inline" onsubmit="return confirm('¿Aprobar este expediente?')">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= $e['id'] ?>">
                <button class="btn-sm" type="submit">Aprobar</button>
              </form>
            <?php endif; ?>
          </td></tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</main></body></html>
