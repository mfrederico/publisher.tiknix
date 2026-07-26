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
            // Two INDEPENDENT questions, so two lists. A project can open a pull request
            // on its repo AND run in a container; those are not alternatives.
            'repoDrivers' => \app\Publish\PublishRegistry::repository(),
            'hostDrivers' => \app\Publish\PublishRegistry::hosting(),
            'drivers'     => \app\Publish\PublishRegistry::all(),
            // A hosting target's domain IS its end URL, but the driver needs it as a bare
            // hostname, so it is stored as one and shown as one.
            'domain'      => (string) ($def['publish']['domain'] ?? ''),
            'cron'        => (string) ($def['trigger']['cron'] ?? ''),
            'workingUrl'  => rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/'),
            'canTrigger'  => (string) ($cfg['pipeline']['trigger_secret'] ?? '') !== '',
            'bindings'    => $bindings,
            'chosen'      => $this->chosen($def, $bindings),
        ], false);
    }

    /**
     * The target selected in each group, as [group => driver key|''].
     *
     * A saved pipeline always wins — it is the project's decision. With none, preselect a
     * target that is actually BOUND to something, so connecting a repo and coming here
     * lands on that repo instead of on a hosting target the project has never used.
     *
     * Deliberately NOT the same as creating the pipeline: connecting a repo says where
     * code MAY go, not that every change should be published there, and writing a file
     * into someone's repo as a side effect of connecting is not ours to do. This only
     * means one Save away instead of a dropdown hunt.
     *
     * @return array{repo:string,host:string}
     */
    private function chosen(?array $def, array $bindings): array {
        $saved = $this->savedTargets($def);
        $pick = function (array $group) use ($saved, $bindings): string {
            $keys = array_column($group, 'key');
            foreach ($saved as $k) if (in_array($k, $keys, true)) return $k;   // the project's choice
            if ($saved) return '';                                             // it chose NOT to use this group
            foreach ($keys as $k) if (!empty($bindings[$k]['ok'])) return $k;  // else: whatever is bound
            return '';
        };
        return [
            'repo' => $pick(\app\Publish\PublishRegistry::repository()),
            'host' => $pick(\app\Publish\PublishRegistry::hosting()),
        ];
    }

    /**
     * Targets the saved pipeline publishes to, in order.
     *
     * Reads the STEPS, not the metadata: the steps are what actually runs, and someone
     * editing the pipeline directly must not find this page disagreeing with their file.
     * Falls back to the legacy single `publish.driver` written before targets could be
     * combined.
     *
     * @return string[]
     */
    private function savedTargets(?array $def): array {
        $out = [];
        foreach ((array) ($def['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'publish') continue;
            $t = (string) ($step['config']['target'] ?? '');
            if ($t !== '' && !in_array($t, $out, true)) $out[] = $t;
        }
        if (!$out && ($def['publish']['driver'] ?? '') !== '') $out[] = (string) $def['publish']['driver'];
        return $out;
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

        // Ship the code first, then bring the runtime in line with it — the order a person
        // would do it by hand. Either may be blank: publishing to a repo without hosting,
        // or hosting without a repo, are both perfectly ordinary.
        $targets = [];
        foreach (['repo', 'host'] as $group) {
            $key = trim((string) $this->getParam($group, ''));
            if ($key === '') continue;
            if (!\app\Publish\PublishRegistry::driver($key)) { Flight::jsonError('Unknown publish target: ' . $key, 400); return; }
            $targets[] = $key;
        }
        if (!$targets) { Flight::jsonError('Choose at least one target — a repository, a place to run, or both.', 400); return; }

        // A hosting target binds a hostname: the field holds a bare domain, not a URL, and
        // the driver + capricorn + the certificate all key off it. Validate it HERE rather
        // than trusting the far end — this value ends up in a proxy file and a cert request.
        $domain = strtolower(trim((string) $this->getParam('domain', '')));
        if ($domain !== '' && !$this->validHost($domain)) { Flight::jsonError('That is not a valid domain name.', 400); return; }

        $cron   = trim((string) $this->getParam('cron', ''));
        $dir    = $this->instanceDir($inst);
        $loader = new Loader($dir);
        $def    = $loader->get(self::PIPELINE) ?: [];

        $def['slug']    = self::PIPELINE;
        $def['name']    = 'Publish';
        $def['steps']   = $this->steps($def['steps'] ?? [], $targets, $domain);
        $def['publish'] = ['targets' => $targets];
        if ($domain !== '') {
            $def['publish']['domain'] = $domain;
            $def['publish']['url']    = 'https://' . $domain;
        }
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
     * The pipeline's steps for the chosen targets — WITHOUT taking ownership of the recipe.
     *
     * One `publish` step per target, in order, because a publish genuinely can be several
     * things: open the pull request, then bring the container in line. The pipeline
     * runtime has always been a sequence; only this page used to force a choice.
     *
     * The file is the project's, so this rewrites ONLY steps it generated itself — a
     * pipeline consisting entirely of `publish` steps. The moment anyone edits the recipe
     * in the Pipeline Editor (a build, a test, a notification), their steps come back
     * untouched and just the target metadata changes.
     *
     * The steps carry no credential: each presents the instance's broker key to core's
     * /publish/run, and core resolves the instance from that key alone. See
     * lib/Pipeline/Steps/PublishStep.php.
     *
     * @param string[] $targets
     */
    private function steps(array $steps, array $targets, string $domain = ''): array {
        $generated = [];
        foreach ($targets as $t) {
            $driver = \app\Publish\PublishRegistry::driver($t);
            $config = ['target' => $t, 'op' => 'deploy'];
            // The domain belongs to whichever target actually binds one.
            if ($domain !== '' && $driver && !empty($driver::capabilities()['domain'])) {
                $config['config'] = ['domain' => $domain];
            }
            // Standing a container up runs a chain of hypervisor tasks and can pass a
            // minute; the step default (120s) would report a timeout while the deploy was
            // still succeeding, which is the worst possible answer.
            if ($t === 'tiknix-hosted') $config['timeout'] = 600;

            $generated[] = [
                // Step names are variable references ({step.output}), so the loader holds
                // them to [a-z0-9_] — a driver key's hyphens are not allowed here.
                'name'       => 'publish_' . str_replace('-', '_', $t),
                'type'       => 'publish',
                'config'     => $config,
                // Stop on the first failure: if the code did not ship, restarting the
                // container against the old code is not a partial success.
                'on_success' => 'next',
                'on_fail'    => 'exit',
            ];
        }
        if (!$steps) return $generated;
        foreach ($steps as $s) if (($s['type'] ?? '') !== 'publish') return $steps;  // authored — leave it
        return $generated;
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
