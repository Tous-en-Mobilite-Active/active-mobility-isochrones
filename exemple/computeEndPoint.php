<?php
/**
 * active-mobility-isochrones — Endpoint de calcul
 *
 * Déclenché par le JS en arrière-plan pour lancer le calcul effectif.
 * Reçoit les paramètres en POST, exécute le calcul, et retourne 'done'.
 *
 * @license MIT
 * @see https://github.com/biz-lab/active-mobility-isochrones
 */

require_once __DIR__ . '/../src/GeoMath.php';
require_once __DIR__ . '/../src/GeoCompute.php';
require_once __DIR__ . '/../src/GeoManage.php';

$app = new \ActiveMobilityIsochrones\GeoManage(
	__DIR__ . '/temp/geoData',
	__DIR__ . '/temp/geoCache'
);

$app->readParameters(!empty($_POST) ? $_POST : $_GET);
$app->run();

header('Content-Type: text/plain');
echo 'done';
