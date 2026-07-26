<?php
/**
 * Publisher — the one place a project's publish target is decided.
 *
 * Vars: $project, $projectsUrl, $coreUrl, $def, $drivers, $endUrl, $cron, $workingUrl, $canTrigger
 */
$h = fn($s) => htmlspecialchars((string) $s);
$csrf = \app\SimpleCsrf::getTokenArray();
$coreRoot = rtrim((string) (\Flight::get('sidecar.core_root') ?? ''), '/');
$dsFile   = $coreRoot . '/views/components/design-system.php';
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Publisher · tiknix</title>
<script>(function(){try{var t=localStorage.getItem('ui-theme');if(t)document.documentElement.setAttribute('data-bs-theme',t);}catch(e){}})();</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<?php if ($coreUrl): ?><link href="<?= $h($coreUrl) ?>/css/app.css" rel="stylesheet"><?php endif; ?>
<?php if (is_file($dsFile)) include $dsFile; ?>
<style> .wrap { max-width: 900px; margin: 0 auto; padding: 1.25rem 1.1rem; } </style>
</head>
<body>
<div class="wrap">

  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mb-3">
    <div>
      <h1 class="h4 fw-bold mb-0"><i class="bi bi-rocket-takeoff me-2"></i>Publisher</h1>
      <div class="text-body-secondary small">where and how <strong><?= $h($project['name']) ?></strong> goes live</div>
    </div>
    <a href="<?= $h($projectsUrl) ?>" target="_top" class="btn btn-outline-secondary btn-sm">
      <i class="bi bi-grid-3x3-gap me-1"></i>Change project
    </a>
  </div>

  <?php
  /* Working vs published, stated together and only here. The shell's chip links to the
     WORKING instance because that is what you are editing; the end URL belongs to the
     target and is decided on this page, which is why it is not shown anywhere else. */
  ?>
  <div class="row g-3 mb-3">
    <div class="col-md-6">
      <div class="card h-100 shadow-sm"><div class="card-body">
        <div class="text-uppercase text-body-secondary small fw-semibold" style="letter-spacing:.06em">Working instance</div>
        <div class="mt-1"><a href="<?= $h($workingUrl) ?>" target="_blank" rel="noopener"><?= $h(parse_url($workingUrl, PHP_URL_HOST) ?: $workingUrl) ?></a></div>
        <div class="text-body-secondary small mt-1">what you are building — always here</div>
      </div></div>
    </div>
    <div class="col-md-6">
      <div class="card h-100 shadow-sm"><div class="card-body">
        <div class="text-uppercase text-body-secondary small fw-semibold" style="letter-spacing:.06em">Published to</div>
        <div class="mt-1">
          <?php if ($endUrl !== ''): ?>
            <a href="<?= $h($endUrl) ?>" target="_blank" rel="noopener"><?= $h(parse_url($endUrl, PHP_URL_HOST) ?: $endUrl) ?></a>
          <?php else: ?>
            <span class="text-body-secondary">not published yet</span>
          <?php endif; ?>
        </div>
        <div class="text-body-secondary small mt-1">a property of the target below, not of the project</div>
      </div></div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="bi bi-sliders"></i><span class="fw-semibold">Target</span>
      <span class="text-body-secondary small ms-auto">saved as <code>pipelines/publish.json</code> in the project</span>
    </div>
    <div class="card-body">
      <form id="pub-form" class="row g-3">
        <div class="col-md-5">
          <label class="form-label small fw-semibold">How</label>
          <select id="pub-driver" class="form-select form-select-sm">
            <?php foreach ($drivers as $d): $sel = ($def['publish']['driver'] ?? 'tiknix-hosted') === $d['key']; ?>
              <option value="<?= $h($d['key']) ?>" <?= $sel ? 'selected' : '' ?> <?= empty($d['available']) ? 'disabled' : '' ?>>
                <?= $h($d['label']) ?><?= empty($d['available']) ? ' — unavailable' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <div class="form-text" id="pub-blurb"></div>
        </div>
        <div class="col-md-4">
          <label class="form-label small fw-semibold" id="pub-url-label">End URL</label>
          <input id="pub-url" class="form-control form-control-sm" placeholder="https://example.com"
                 value="<?= $h($domain !== '' ? $domain : $endUrl) ?>" autocomplete="off" spellcheck="false">
          <div class="form-text" id="pub-url-help">Where this publishes to.</div>
        </div>
        <div class="col-md-3">
          <label class="form-label small fw-semibold">When</label>
          <input id="pub-cron" class="form-control form-control-sm" placeholder="on demand"
                 value="<?= $h($cron) ?>" autocomplete="off" spellcheck="false">
          <div class="form-text">Cron, or blank for on demand.</div>
        </div>
        <div class="col-12 d-flex gap-2 align-items-center">
          <button class="btn btn-primary btn-sm" type="submit"><i class="bi bi-save me-1"></i>Save target</button>
          <button id="pub-run" class="btn btn-dark btn-sm" type="button" <?= $canTrigger ? '' : 'disabled title="This project has no pipeline trigger secret"' ?>>
            <i class="bi bi-cloud-upload me-1"></i>Publish now
          </button>
          <span id="pub-msg" class="form-text ms-1"></span>
        </div>
      </form>
    </div>
  </div>

  <?php /* Publishing IS a pipeline, so the editor and its run history are the same ones
           used for everything else — no bespoke deploy log to keep in sync. */ ?>
  <div class="text-body-secondary small">
    Publishing runs as a pipeline in this project, so it schedules, retries and debugs like any other.
    Edit its steps in the <a href="<?= $h($coreUrl) ?>/sidecar/app/pipelines" target="_top">Pipeline Editor</a>.
  </div>
</div>

<script>
(function () {
  const csrf = <?= json_encode($csrf) ?>;
  const msg = document.getElementById('pub-msg');

  // Targets differ enough that the wrong label is worse than none: "we handle the proxy
  // and the certificate" is actively misleading next to a pull request, and a hosting
  // target needs a bare hostname where a generic one takes a URL. One field, told what
  // it means by the selected target.
  const drivers = <?= json_encode(array_column($drivers, null, 'key')) ?>;
  const driverSel = document.getElementById('pub-driver');
  const urlIn = document.getElementById('pub-url');
  function describe() {
    const d = drivers[driverSel.value] || {}, cap = d.capabilities || {};
    document.getElementById('pub-blurb').textContent = d.blurb || '';
    // A repository target's URL is the pull request's — discovered, never typed.
    urlIn.closest('.col-md-4').hidden = !!cap.code;
    if (cap.domain) {
      document.getElementById('pub-url-label').textContent = 'Domain';
      urlIn.placeholder = 'app.example.com';
      document.getElementById('pub-url-help').textContent =
        'Point a CNAME at this control plane first — the certificate is issued for this domain.';
    } else {
      document.getElementById('pub-url-label').textContent = 'End URL';
      urlIn.placeholder = 'https://example.com';
      document.getElementById('pub-url-help').textContent = 'Where this publishes to.';
    }
  }
  driverSel.addEventListener('change', describe);
  describe();

  const say = (t, c) => { msg.className = 'form-text ms-1 ' + (c || 'text-body-secondary'); msg.textContent = t; };
  const post = (url, data) => fetch(url, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: new URLSearchParams(Object.assign({}, csrf, data)).toString()
  }).then(r => r.json());

  document.getElementById('pub-form').addEventListener('submit', function (e) {
    e.preventDefault();
    say('Saving…');
    post('/publish/save', {
      driver: document.getElementById('pub-driver').value,
      url:    document.getElementById('pub-url').value.trim(),
      cron:   document.getElementById('pub-cron').value.trim()
    }).then(j => say(j.success ? (j.message || 'Saved.') : (j.message || 'Failed.'), j.success ? 'text-success' : 'text-danger'))
      .catch(() => say('Network error.', 'text-danger'));
  });

  document.getElementById('pub-run').addEventListener('click', function () {
    say('Starting…');
    post('/publish/run', {}).then(j => {
      say(j.success ? (j.message || 'Publishing…') + ' run #' + (j.data && j.data.run_id) : (j.message || 'Failed.'),
          j.success ? 'text-success' : 'text-danger');
    }).catch(() => say('Network error.', 'text-danger'));
  });
})();
</script>
</body>
</html>
