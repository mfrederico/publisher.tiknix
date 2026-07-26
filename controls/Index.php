<?php
/** Index — sidecar root → the publish page (requires the SSO session). */
namespace app;

use \Flight as Flight;
use app\BaseControls\Control;

class Index extends Control {
    public function index($params = []): void {
        Flight::redirect('/publish');
    }
}
