<?php
/**
 * active-mobility-isochrones — Endpoint AJAX
 *
 * Retourne le statut du calcul en cours (progression ou "computed").
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

$app->readParameters($_GET);
$app->cacheGet();

header('Content-Type: text/plain');
echo ($app->getStatus() === 'computing') ? $app->getComputingProgress() : $app->getStatus();
