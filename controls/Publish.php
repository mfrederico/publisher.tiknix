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
            'cron'        => (string) ($def['trigger']['cron'] ?? ''),
            'workingUrl'  => rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/'),
            'canTrigger'  => (string) ($cfg['pipeline']['trigger_secret'] ?? '') !== '',
            'bindings'    => $bindings,
            'chosen'      => $this->savedTargets($def),
            'settings'    => $this->savedSettings($def),
        ], false);
    }

    /**
     * Per-target settings already saved in the pipeline, as [driver => [field => value]].
     * Read from the STEPS for the same reason savedTargets() is: the steps are what runs.
     */
    private function savedSettings(?array $def): array {
        $out = [];
        foreach ((array) ($def['steps'] ?? []) as $step) {
            if (($step['type'] ?? '') !== 'publish') continue;
            $t = (string) ($step['config']['target'] ?? '');
            if ($t !== '') $out[$t] = (array) ($step['config']['config'] ?? []);
        }
        // The legacy single-target shape kept the domain in the metadata block.
        if (!$out && ($def['publish']['domain'] ?? '') !== '') {
            $out[(string) ($def['publish']['driver'] ?? 'tiknix-hosted')] = ['domain' => (string) $def['publish']['domain']];
        }
        return $out;
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

        // ssh/rsync: the keypair is generated on the control plane on first publish, so
        // what matters here is whether the customer has authorised it yet.
        $st = $core->prepare('SELECT connector_type, metadata_json, last_used_at, last_error FROM connections
             WHERE instance_id = ? AND connector_type IN (?, ?)');
        if ($st && $st->execute([$instanceId, 'rsync', 'ssh'])) {
            foreach ($st->fetchAll(\PDO::FETCH_ASSOC) ?: [] as $r) {
                $meta  = json_decode((string) $r['metadata_json'], true) ?: [];
                $fp    = (string) ($meta['fingerprint'] ?? '');
                // last_used_at records the ATTEMPT, so it is set after a rejected key too —
                // reporting "last published" on the strength of it would be a plain lie.
                $err   = trim((string) ($r['last_error'] ?? ''));
                $used  = trim((string) ($r['last_used_at'] ?? ''));
                $out[(string) $r['connector_type']] = [
                    // ssh-keygen -l prints "256 SHA256:… comment (ED25519)"; the hash alone
                    // is what anyone compares against, and it has to fit on a badge.
                    'label'  => $fp !== '' ? (explode(' ', $fp)[1] ?? 'Key ready') : 'Key ready',
                    'ok'     => $used !== '' && $err === '',
                    'detail' => $err !== '' ? $err
                        : ($used !== '' ? 'last published ' . $used
                                        : 'not yet accepted by the server — add the public key below to authorized_keys'),
                    'publicKey' => (string) ($meta['public_key'] ?? ''),
                ];
            }
        }
        foreach (['rsync', 'ssh'] as $k) {
            $out[$k] = $out[$k] ?? ['label' => 'No key yet', 'ok' => false,
                'detail' => 'A keypair is generated on the first publish; you then add its public half to the server.'];
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

        // Deliver the code, THEN bring the runtime in line with it — the order a person
        // would do it by hand, and the order the on_fail:exit below assumes. Explicitly
        // repository-then-hosting rather than registry order, which lists hosting first
        // and would restart the container against code that had not shipped yet.
        //
        // Any number of targets, including one: publishing to a repo without hosting, or
        // hosting without a repo, are both perfectly ordinary.
        $wanted = array_filter(array_map('strval', (array) $this->getParam('targets', [])));
        $cfgIn  = (array) $this->getParam('cfg', []);
        $targets = [];
        $settings = [];
        $ordered = array_merge(\app\Publish\PublishRegistry::repository(),
                               \app\Publish\PublishRegistry::hosting());
        foreach ($ordered as $d) {
            if (!in_array($d['key'], $wanted, true)) continue;
            if (empty($d['available'])) { Flight::jsonError($d['label'] . ' is not available: ' . $d['reason'], 400); return; }
            $vals = $this->validateFields($d, (array) ($cfgIn[$d['key']] ?? []));
            if (isset($vals['error'])) { Flight::jsonError($d['label'] . ': ' . $vals['error'], 400); return; }
            $targets[]              = $d['key'];
            $settings[$d['key']]    = $vals['values'];
        }
        if (!$targets) { Flight::jsonError('Choose at least one target.', 400); return; }

        $cron   = trim((string) $this->getParam('cron', ''));
        $dir    = $this->instanceDir($inst);
        $loader = new Loader($dir);
        $def    = $loader->get(self::PIPELINE) ?: [];

        $def['slug']    = self::PIPELINE;
        $def['name']    = 'Publish';
        $def['steps']   = $this->steps($def['steps'] ?? [], $targets, $settings);
        $def['publish'] = ['targets' => $targets];
        // The end URL, when a target binds one. Kept in the metadata block because it is
        // the one thing about a publish that other pages want to show.
        foreach ($settings as $vals) {
            if (!empty($vals['domain'])) {
                $def['publish']['domain'] = $vals['domain'];
                $def['publish']['url']    = 'https://' . $vals['domain'];
            }
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
     * @param string[]                    $targets
     * @param array<string,array<string,mixed>> $settings per-target field values
     */
    private function steps(array $steps, array $targets, array $settings = []): array {
        $generated = [];
        foreach ($targets as $t) {
            $config = ['target' => $t, 'op' => 'deploy'];
            if (!empty($settings[$t])) $config['config'] = $settings[$t];
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

    /**
     * Check a target's posted settings against the fields IT declares.
     *
     * Driven by the driver's own fields() so adding a target never means touching this
     * method. Validated here as well as in the driver, because these values are written
     * into the project's repo — a bad host should be refused at the point someone typed
     * it, not discovered on a failed publish.
     *
     * @return array{values:array}|array{error:string}
     */
    private function validateFields(array $driver, array $posted): array {
        $out = [];
        foreach ((array) ($driver['fields'] ?? []) as $f) {
            $name = (string) ($f['name'] ?? '');
            if ($name === '') continue;
            $v = trim((string) ($posted[$name] ?? ''));

            if ($v === '') {
                if (!empty($f['required'])) return ['error' => ($f['label'] ?? $name) . ' is required.'];
                continue;                                    // omit rather than store empties
            }
            if (($f['type'] ?? '') === 'host') {
                $v = strtolower($v);
                if (!$this->validHost($v)) return ['error' => ($f['label'] ?? $name) . ' is not a valid hostname.'];
            }
            if (($f['type'] ?? '') === 'number') {
                if (!ctype_digit($v)) return ['error' => ($f['label'] ?? $name) . ' must be a number.'];
                $v = (int) $v;
            }
            $out[$name] = $v;
        }
        return ['values' => $out];
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
