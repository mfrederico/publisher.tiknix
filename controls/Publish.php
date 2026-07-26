<?php
/**
 * Publish — where and how the selected project goes live.
 *
 * DESIGN: publishing is a PIPELINE, not a bespoke mechanism. The target's steps live in
 * the project's own `pipelines/publish.json`, so everything the pipeline runtime already
 * provides comes for free: scheduling via trigger.cron, run history, the step-trace
 * debugger, and a REST trigger. Nothing here reimplements deploy.
 *
 * That also decides the write direction. This sidecar writes back into the SELECTED
 * PROJECT — a file in its repo, run through its own trigger endpoint with its
 * trigger_secret, exactly the server-to-server path the kit contract already documents.
 * It never writes core's registry, so no new write-seam is needed.
 *
 * And it is a sidecar rather than part of the app because a finished application should
 * not ship its own deployment tooling: the pipeline stays in the repo (it is the
 * project's), while the UI for authoring and running it does not.
 */

namespace app;

use \Flight as Flight;
use app\BaseControls\Control;
use app\Sidecar\Kernel;
use app\Sidecar\Access;
use app\Sidecar\Sso;
use app\Pipeline\Loader;

class Publish extends Control {

    /** The pipeline slug this sidecar owns in each project. */
    const PIPELINE = 'publish';

    /** GET /publish — the page. */
    public function index($params = []): void {
        [$s, $inst] = $this->guard();
        if (!$inst) return;

        $dir      = $this->instanceDir($inst);
        $def      = (new Loader($dir))->get(self::PIPELINE);
        $cfg      = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
        $bindings = $this->bindings((int) $inst['id']);

        $this->render('publish/index', [
            'project'     => $inst,
            'projectsUrl' => Sso::projectPickerUrl(),
            'coreUrl'     => rtrim((string) Flight::get('sidecar.core_url'), '/'),
            'def'         => $def,
            'drivers'     => \app\Publish\PublishRegistry::all(),
            // The end URL is a property of the TARGET, not of the project — it is
            // whatever this pipeline publishes to, and it lives here because this is
            // where it is decided.
            'endUrl'      => (string) ($def['publish']['url'] ?? ''),
            // A hosting target's domain IS its end URL, but the driver needs it as a bare
            // hostname, so it is stored as one and shown in the same field.
            'domain'      => (string) ($def['publish']['domain'] ?? ''),
            'cron'        => (string) ($def['trigger']['cron'] ?? ''),
            'workingUrl'  => rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/'),
            'canTrigger'  => (string) ($cfg['pipeline']['trigger_secret'] ?? '') !== '',
            'bindings'    => $bindings,
            'selected'    => $this->selectedDriver($def, $bindings),
        ], false);
    }

    /**
     * Which target the page opens on.
     *
     * A saved pipeline always wins — it is the project's decision. With none, prefer a
     * target that is actually BOUND to something, so connecting a repo and coming here
     * lands on that repo rather than on a hosting target the project has never used.
     *
     * Deliberately NOT the same as creating the pipeline: connecting a repo says where
     * code MAY go, not that every change should be published there, and writing a file
     * into someone's repo as a side effect of connecting is not ours to do. This just
     * means one Save away instead of a dropdown hunt.
     */
    private function selectedDriver(?array $def, array $bindings): string {
        $saved = (string) ($def['publish']['driver'] ?? '');
        if ($saved !== '') return $saved;
        foreach ($bindings as $key => $b) if (!empty($b['ok'])) return $key;
        return 'tiknix-hosted';
    }

    /**
     * What each target is actually bound to, keyed by driver — "GitHub Pull Request" on
     * its own says nothing about WHICH repo, and a page that decides where a project
     * publishes has to show that or the operator is choosing blind.
     *
     * Read straight from core's directory over PDO. The driver's own status() would be
     * the obvious source, but running it here would resolve its beans against THIS
     * sidecar's database — the drivers are core's, and they only ever run on core.
     *
     * @return array<string,array{label:string,detail:string,ok:bool}>
     */
    private function bindings(int $instanceId): array {
        $out  = [];
        $core = Kernel::coreDb();
        if (!$core) return $out;

        $st = $core->prepare('SELECT metadata_json, last_used_at, last_error FROM connections
             WHERE instance_id = ? AND connector_type = ? AND enabled = 1 LIMIT 1');
        if ($st && $st->execute([$instanceId, 'github'])) {
            $row  = $st->fetch(\PDO::FETCH_ASSOC) ?: null;
            $meta = $row ? (json_decode((string) $row['metadata_json'], true) ?: []) : [];
            $repo = trim((string) ($meta['owner'] ?? '') . '/' . (string) ($meta['repo'] ?? ''), '/');
            $out['github-pr'] = $repo !== ''
                ? ['label' => $repo, 'ok' => true,
                   'detail' => 'opens a pull request into ' . (string) ($meta['defaultBranch'] ?? 'main')
                             . ((string) ($row['last_used_at'] ?? '') !== '' ? ' · last published ' . $row['last_used_at'] : '')]
                : ['label' => 'No repository connected', 'ok' => false,
                   'detail' => 'Connect one on the Connections page, then publish.'];
        }

        $st = $core->prepare('SELECT ct_vmid FROM instance WHERE id = ?');
        if ($st && $st->execute([$instanceId])) {
            $vmid = (int) ($st->fetchColumn() ?: 0);
            $out['tiknix-hosted'] = $vmid > 0
                ? ['label' => 'Container ' . $vmid, 'ok' => true, 'detail' => 'already running — publishing re-applies settings']
                : ['label' => 'Not deployed yet', 'ok' => false, 'detail' => 'publishing stands the container up'];
        }
        return $out;
    }

    /** POST /publish/save — write the publish pipeline into the project's repo. */
    public function save($params = []): void {
        [$s, $inst] = $this->guard(true);
        if (!$inst) return;

        $driver = (string) $this->getParam('driver', 'tiknix-hosted');
        $url    = trim((string) $this->getParam('url', ''));
        $cron   = trim((string) $this->getParam('cron', ''));
        $d = \app\Publish\PublishRegistry::driver($driver);
        if (!$d) { Flight::jsonError('Unknown publish driver.', 400); return; }

        // A hosting target binds a hostname: the field holds a bare domain, not a URL, and
        // the driver + capricorn + the certificate all key off it. Validate it HERE rather
        // than trusting the far end — this value ends up in a proxy file and a cert request.
        $domain = '';
        if (!empty($d::capabilities()['domain'])) {
            $domain = strtolower(trim($url));
            if ($domain !== '' && !$this->validHost($domain)) { Flight::jsonError('That is not a valid domain name.', 400); return; }
            $url = $domain !== '' ? 'https://' . $domain : '';
        }

        $dir    = $this->instanceDir($inst);
        $loader = new Loader($dir);
        $def    = $loader->get(self::PIPELINE) ?: [];

        $def['slug']    = self::PIPELINE;
        $def['name']    = 'Publish';
        $def['steps']   = $this->steps($def['steps'] ?? [], $driver, $domain);
        $def['publish'] = ['driver' => $driver, 'url' => $url];
        if ($domain !== '') $def['publish']['domain'] = $domain;
        if ($cron !== '') $def['trigger'] = ['cron' => $cron];
        else unset($def['trigger']);

        $errs = Loader::validate($def);
        if ($errs) { Flight::jsonError('Invalid pipeline: ' . implode('; ', $errs), 400); return; }
        $loader->save($def);

        Flight::jsonSuccess(['def' => $def], 'Publish target saved to the project.');
    }

    /**
     * POST /publish/run — fire the publish pipeline on the instance.
     *
     * Uses the instance's own trigger endpoint and its trigger_secret: the documented
     * server-to-server path, and the same one the Pipeline Editor uses. The run executes
     * on the instance and its history lands in the instance's DB, so this sidecar holds
     * no state that could disagree with it.
     */
    public function run($params = []): void {
        [$s, $inst] = $this->guard(true);
        if (!$inst) return;

        $dir    = $this->instanceDir($inst);
        $cfg    = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];
        $base   = rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/');
        $secret = (string) ($cfg['pipeline']['trigger_secret'] ?? '');
        if ($base === '' || $secret === '') { Flight::jsonError('This project has no pipeline trigger configured.', 400); return; }

        $ch = curl_init($base . '/pipeline/trigger/' . rawurlencode(self::PIPELINE));
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_POSTFIELDS     => json_encode(['source' => 'publisher']),
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $secret],
        ]);
        $body = (string) curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $d    = json_decode($body, true);

        if ($code !== 200 || empty($d['run_id'])) {
            Flight::jsonError('Publish did not start (HTTP ' . $code . '). ' . substr(strip_tags($body), 0, 160), 502);
            return;
        }
        Flight::jsonSuccess(['run_id' => (int) $d['run_id']], 'Publishing…');
    }

    /**
     * The pipeline's steps for a target — WITHOUT taking ownership of the recipe.
     *
     * The file is the project's. So this only writes the single `publish` step it
     * generated itself: on a first save, or when that generated step is still the whole
     * pipeline and the operator has picked a different target. The moment anyone edits
     * the recipe in the Pipeline Editor — adds a build step, a test, a notification —
     * their steps are returned untouched and only the target metadata below changes.
     *
     * The step itself carries no credential: it presents the instance's broker key to
     * core's /publish/run, and core resolves the instance from that key. See
     * lib/Pipeline/Steps/PublishStep.php.
     */
    private function steps(array $steps, string $driver, string $domain = ''): array {
        $config = ['target' => $driver, 'op' => 'deploy'];
        if ($domain !== '') $config['config'] = ['domain' => $domain];
        // Standing a container up runs a chain of hypervisor tasks and can pass a minute;
        // the step default (120s) would report a timeout while the deploy was still
        // succeeding, which is the worst possible answer.
        if ($driver === 'tiknix-hosted') $config['timeout'] = 600;

        $generated = [
            'name'       => 'publish',
            'type'       => 'publish',
            'config'     => $config,
            'on_success' => 'next',
            'on_fail'    => 'exit',
        ];
        if (!$steps) return [$generated];
        $onlyOurs = count($steps) === 1
            && ($steps[0]['type'] ?? '') === 'publish'
            && ($steps[0]['name'] ?? '') === 'publish';
        return $onlyOurs ? [$generated] : $steps;
    }

    // ---- guards ------------------------------------------------------------

    /** Session + the SELECTED project. No ?inst: core owns which project this is. */
    private function guard(bool $json = false): array {
        $s = Sso::session();
        if (!$s) {
            $core = rtrim((string) Flight::get('sidecar.core_url'), '/');
            if ($json) { Flight::jsonError('Not signed in.', 401); return [null, null]; }
            Flight::redirect($core . '/sidecar/launch/publisher');
            return [null, null];
        }
        $core = Kernel::coreDb();
        if (!$core) { Flight::jsonError('Core directory unavailable.', 503); return [$s, null]; }

        $inst = Sso::projectInstance(new Access($core), (int) $s['member_id']);
        if (!$inst) {
            if ($json) { Flight::jsonError('No project selected — choose one at ' . Sso::projectPickerUrl(), 409); return [$s, null]; }
            Flight::redirect(Sso::projectPickerUrl());
            return [$s, null];
        }
        return [$s, $inst];
    }

    /** Strict host allowlist, mirroring capricorn's valid_host: DNS chars, no traversal. */
    private function validHost(string $h): bool {
        $h = strtolower(trim($h));
        if ($h === '' || strlen($h) > 253) return false;
        if (strpos($h, '..') !== false) return false;
        if (!preg_match('/^[a-z0-9.-]+$/', $h)) return false;
        if (in_array($h[0], ['.', '-'], true) || in_array(substr($h, -1), ['.', '-'], true)) return false;
        return strpos($h, '.') !== false;
    }

    /** Instance dir built ONLY from the resolved row's slug/app, never client input. */
    private function instanceDir(array $inst): string {
        $parent = dirname(rtrim((string) Flight::get('sidecar.core_root'), '/'));
        $app    = ($inst['app'] ?? '') !== '' ? $inst['app'] : 'tiknix';
        return $parent . '/' . $inst['slug'] . '.' . $app;
    }
}
