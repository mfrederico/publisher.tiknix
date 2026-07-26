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

        $dir  = $this->instanceDir($inst);
        $def  = (new Loader($dir))->get(self::PIPELINE);
        $cfg  = @parse_ini_file($dir . '/conf/config.ini', true) ?: [];

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
            'cron'        => (string) ($def['trigger']['cron'] ?? ''),
            'workingUrl'  => rtrim((string) ($cfg['app']['baseurl'] ?? ''), '/'),
            'canTrigger'  => (string) ($cfg['pipeline']['trigger_secret'] ?? '') !== '',
        ], false);
    }

    /** POST /publish/save — write the publish pipeline into the project's repo. */
    public function save($params = []): void {
        [$s, $inst] = $this->guard(true);
        if (!$inst) return;

        $driver = (string) $this->getParam('driver', 'tiknix-hosted');
        $url    = trim((string) $this->getParam('url', ''));
        $cron   = trim((string) $this->getParam('cron', ''));
        if (!\app\Publish\PublishRegistry::driver($driver)) { Flight::jsonError('Unknown publish driver.', 400); return; }

        $dir    = $this->instanceDir($inst);
        $loader = new Loader($dir);
        $def    = $loader->get(self::PIPELINE) ?: [];

        // Keep whatever steps the project has authored — this owns the target, not the
        // recipe. A first save seeds a minimal, honest one-step pipeline the operator
        // can then edit in the Pipeline Editor.
        $def['slug']    = self::PIPELINE;
        $def['name']    = 'Publish';
        // A minimal, HONEST first step: it announces the target rather than pretending to
        // deploy. The operator edits the real recipe in the Pipeline Editor — this file is
        // the project's, and overwriting authored steps would be the sidecar taking
        // ownership of something it does not own.
        $def['steps']   = $def['steps'] ?? [[
            'name'       => 'announce',
            'type'       => 'shell',
            'config'     => ['command' => 'echo ' . escapeshellarg('publishing via ' . $driver)],
            'on_success' => 'next',
            'on_fail'    => 'exit',
        ]];
        $def['publish'] = ['driver' => $driver, 'url' => $url];
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

    /** Instance dir built ONLY from the resolved row's slug/app, never client input. */
    private function instanceDir(array $inst): string {
        $parent = dirname(rtrim((string) Flight::get('sidecar.core_root'), '/'));
        $app    = ($inst['app'] ?? '') !== '' ? $inst['app'] : 'tiknix';
        return $parent . '/' . $inst['slug'] . '.' . $app;
    }
}
