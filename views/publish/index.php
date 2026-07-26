<?php
/**
 * Publisher — the one place a project's publish target is decided.
 *
 * Vars: $project, $projectsUrl, $coreUrl, $def, $drivers, $repoDrivers, $hostDrivers,
 *       $cron, $workingUrl, $canTrigger, $bindings, $chosen (keys), $settings
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
        <?php
        /* Every chosen target, not one — a repository target has no end URL (its result is
           a branch and a pull request, not a site), so showing only a URL here said "not
           published yet" directly above a target's own "last published". */
        $__lines = [];
        foreach ($chosen as $__k) {
            $__d = (string) ($settings[$__k]['domain'] ?? '');
            if ($__d !== '') { $__lines[] = '<a href="https://' . $h($__d) . '" target="_blank" rel="noopener">' . $h($__d) . '</a>'; continue; }
            $__host = (string) ($settings[$__k]['host'] ?? '');
            if ($__host !== '') {
                $__p = (string) ($settings[$__k]['path'] ?? '');
                $__lines[] = '<span class="fw-semibold">' . $h($__host . ($__p !== '' ? ':' . $__p : '')) . '</span>';
                continue;
            }
            if (!empty($bindings[$__k]['ok'])) $__lines[] = '<span class="fw-semibold">' . $h($bindings[$__k]['label']) . '</span>';
        }
        ?>
        <div class="mt-1">
          <?= $__lines ? implode('<br>', $__lines) : '<span class="text-body-secondary">not published yet</span>' ?>
        </div>
        <div class="text-body-secondary small mt-1">a property of the targets below, not of the project</div>
      </div></div>
    </div>
  </div>

  <div class="card shadow-sm mb-3">
    <div class="card-header d-flex align-items-center gap-2">
      <i class="bi bi-sliders"></i><span class="fw-semibold">Targets</span>
      <span class="text-body-secondary small ms-auto">saved as <code>pipelines/publish.json</code> in the project</span>
    </div>
    <div class="card-body">
      <?php
      /* TWO questions, not one choice: where the code goes and where it runs are
         independent. Both allow SEVERAL — a project can perfectly well open a pull request
         for review AND rsync to a production box — so these are checkboxes, and each
         target renders whatever settings IT declares. Nothing here knows what an rsync
         target needs; the driver says. */
      $target = function (array $d) use ($h, $chosen, $settings, $bindings) {
          $on   = in_array($d['key'], $chosen, true);
          $vals = (array) ($settings[$d['key']] ?? []);
          $id   = 'tgt-' . preg_replace('/[^a-z0-9]/', '', $d['key']);
          ?>
        <div class="col-12 pub-target-row">
          <div class="form-check">
            <input class="form-check-input pub-target" type="checkbox" id="<?= $h($id) ?>"
                   value="<?= $h($d['key']) ?>" <?= $on ? 'checked' : '' ?> <?= empty($d['available']) ? 'disabled' : '' ?>>
            <label class="form-check-label fw-semibold" for="<?= $h($id) ?>"><?= $h($d['label']) ?></label>
            <?php if (empty($d['available'])): ?>
              <span class="badge text-bg-warning ms-1">unavailable</span>
              <div class="form-text"><?= $h($d['reason']) ?></div>
            <?php else: ?>
              <div class="form-text"><?= $h($d['blurb']) ?></div>
              <?php $b = $bindings[$d['key']] ?? null; if ($b): ?>
                <div class="small mt-1">
                  <span class="badge <?= !empty($b['ok']) ? 'text-bg-success' : 'text-bg-secondary' ?>">
                    <i class="bi bi-<?= !empty($b['ok']) ? 'check-circle' : 'dash-circle' ?> me-1"></i><?= $h($b['label']) ?>
                  </span>
                  <span class="text-body-secondary ms-2"><?= $h($b['detail']) ?></span>
                </div>
                <?php if (!empty($b['publicKey'])): ?>
                  <?php /* The public half, right where the operator needs it. Withholding
                           it would mean the first publish fails and they have to go
                           looking for what to authorise. */ ?>
                  <details class="mt-1">
                    <summary class="small text-body-secondary" style="cursor:pointer">Public key to add to <code>authorized_keys</code></summary>
                    <textarea class="form-control form-control-sm mt-1" rows="2" readonly onclick="this.select()"><?= $h($b['publicKey']) ?></textarea>
                  </details>
                <?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <?php if (!empty($d['fields'])): ?>
            <div class="row g-2 mt-1 ms-1 ps-3 border-start pub-fields" data-for="<?= $h($d['key']) ?>" <?= $on ? '' : 'hidden' ?>>
              <?php foreach ($d['fields'] as $f):
                $fname = (string) $f['name'];
                $val   = (string) ($vals[$fname] ?? '');
                $wide  = ($f['type'] ?? '') === 'textarea'; ?>
                <div class="<?= $wide ? 'col-12' : 'col-sm-4' ?>">
                  <label class="form-label small fw-semibold mb-1"><?= $h($f['label'] ?? $fname) ?><?php if (!empty($f['required'])): ?><span class="text-danger">*</span><?php endif; ?></label>
                  <?php if ($wide): ?>
                    <textarea rows="2" class="form-control form-control-sm pub-field" spellcheck="false"
                              data-target="<?= $h($d['key']) ?>" data-field="<?= $h($fname) ?>"
                              placeholder="<?= $h($f['placeholder'] ?? '') ?>"><?= $h($val) ?></textarea>
                  <?php else: ?>
                    <input class="form-control form-control-sm pub-field" autocomplete="off" spellcheck="false"
                           data-target="<?= $h($d['key']) ?>" data-field="<?= $h($fname) ?>"
                           placeholder="<?= $h($f['placeholder'] ?? '') ?>" value="<?= $h($val) ?>">
                  <?php endif; ?>
                  <?php if (!empty($f['help'])): ?><div class="form-text"><?= $h($f['help']) ?></div><?php endif; ?>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php }; ?>

      <form id="pub-form" class="row g-3">
        <div class="col-12">
          <div class="text-uppercase text-body-secondary small fw-semibold" style="letter-spacing:.06em">Code goes to</div>
        </div>
        <?php foreach ($repoDrivers as $d) $target($d); ?>

        <div class="col-12 mt-3">
          <div class="text-uppercase text-body-secondary small fw-semibold" style="letter-spacing:.06em">Runs on</div>
        </div>
        <?php foreach ($hostDrivers as $d) $target($d); ?>

        <div class="col-md-4 mt-3">
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

  const boxes = Array.from(document.querySelectorAll('.pub-target'));

  // A target's settings are only a question if the target is chosen.
  function refresh() {
    boxes.forEach(b => {
      const fields = document.querySelector('.pub-fields[data-for="' + b.value + '"]');
      if (fields) fields.hidden = !b.checked;
    });
  }
  boxes.forEach(b => b.addEventListener('change', refresh));
  refresh();

  const say = (t, c) => { msg.className = 'form-text ms-1 ' + (c || 'text-body-secondary'); msg.textContent = t; };
  const post = (url, params) => fetch(url, {
    method: 'POST',
    headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
    body: params.toString()
  }).then(r => r.json());
  const withCsrf = () => { const p = new URLSearchParams(); for (const k in csrf) p.append(k, csrf[k]); return p; };

  document.getElementById('pub-form').addEventListener('submit', function (e) {
    e.preventDefault();
    say('Saving…');
    const p = withCsrf();
    boxes.filter(b => b.checked).forEach(b => p.append('targets[]', b.value));
    // Only the chosen targets' settings — a field left behind by an unchecked target is
    // not part of what this pipeline does.
    document.querySelectorAll('.pub-field').forEach(f => {
      const box = boxes.find(b => b.value === f.dataset.target);
      if (box && box.checked) p.append('cfg[' + f.dataset.target + '][' + f.dataset.field + ']', f.value.trim());
    });
    p.append('cron', document.getElementById('pub-cron').value.trim());
    post('/publish/save', p)
      .then(j => say(j.success ? (j.message || 'Saved.') : (j.message || 'Failed.'), j.success ? 'text-success' : 'text-danger'))
      .catch(() => say('Network error.', 'text-danger'));
  });

  document.getElementById('pub-run').addEventListener('click', function () {
    say('Starting…');
    post('/publish/run', withCsrf()).then(j => {
      say(j.success ? (j.message || 'Publishing…') + ' run #' + (j.data && j.data.run_id) : (j.message || 'Failed.'),
          j.success ? 'text-success' : 'text-danger');
    }).catch(() => say('Network error.', 'text-danger'));
  });
})();
</script>
</body>
</html>
