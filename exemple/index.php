<?php
/**
 * active-mobility-isochrones — Exemple standalone
 *
 * Serveur intégré PHP : php -S localhost:8000 -t exemple/
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

$isComputed = ($app->getStatus() === 'computed');
$isComputing = ($app->getStatus() === 'computing');
$cacheKey = $app->getCacheKey();
$shareUrl = 'index.php?' . $cacheKey;
$statusUrl = $isComputing ? 'ajaxEndPoint.php?' . $cacheKey . '=1' : '';

?><!DOCTYPE html>
<html lang="fr">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<title>Mobilité Active — Zones plus rapides qu'en voiture</title>
	<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
	<link rel="stylesheet" href="style.css">
</head>
<body>
	<h1><?= $isComputed
		? 'Voici les zones auxquelles vous accéderez plus rapidement en mobilité active qu\'en voiture'
		: 'Estimez les zones auxquelles vous accéderez plus rapidement en mobilité active (marche, vélo, vélo électrique) qu\'en voiture'
	?></h1>

	<div class="controls">
		<form id="formObj" method="post" action="index.php">
			<input type="hidden" id="latitude" name="latitude" value="<?= htmlspecialchars($app->getLatitude()) ?>">
			<input type="hidden" id="longitude" name="longitude" value="<?= htmlspecialchars($app->getLongitude()) ?>">
			<?php if ($isComputing): ?>
			<input type="hidden" id="waitForTheCalculationToFinish" value="<?= htmlspecialchars($statusUrl) ?>">
			<?php endif; ?>
			<input type="hidden" id="walkCoords" name="walkCoords" value="<?= htmlspecialchars($app->getWalkCoords()) ?>">
			<input type="hidden" id="bikeCoords" name="bikeCoords" value="<?= htmlspecialchars($app->getBikeCoords()) ?>">
			<input type="hidden" id="ebikeCoords" name="ebikeCoords" value="<?= htmlspecialchars($app->getEbikeCoords()) ?>">
			<input type="hidden" id="debugRedCoords" name="debugRedCoords" value="<?= htmlspecialchars($app->getDebugRedCoords()) ?>">
			<input type="hidden" id="debugBlueCoords" name="debugBlueCoords" value="<?= htmlspecialchars($app->getDebugBlueCoords()) ?>">
			<input type="hidden" id="pageMethod" value="<?= empty($_POST) && !isset($_GET['continueCompute']) ? 'get' : 'post' ?>">

			<div class="input-group">
				<?php if ($isComputed): ?>
				<label id="addressLabel" for="address">Adresse de départ :</label>
				<?php else: ?>
				<label id="addressLabel" for="address">Adresse à rechercher :</label>
				<?php endif; ?>
				<div class="input-field">
					<input type="text" id="address" name="address" placeholder="Ex: 1 rue de la Paix, Rennes" value="<?= htmlspecialchars($app->getAddress()) ?>" autocomplete="off">
					<div id="autocomplete-list" class="autocomplete-items"></div>
				</div>
				<button class="primary" id="mapUpdateBtn" type="button">Calculer</button>
				<button class="secondary-btn" id="settingsBtn" type="button">&#x2699;&#xFE0F;</button>
			</div>

			<div id="settings-panel" class="settings-panel">
				<h3 class="settings-title">Paramètres de calcul</h3>
				<div class="settings-grid">
					<div class="setting-item">
						<label for="speedWalk">Vitesse moyenne à pied (km/h) :</label>
						<input type="number" id="speedWalk" name="speedWalk" value="<?= $app->getSpeedWalk() ?>" step="0.1" min="1" max="12">
					</div>
					<div class="setting-item">
						<label for="speedBike">Vitesse moyenne à vélo (km/h) :</label>
						<input type="number" id="speedBike" name="speedBike" value="<?= $app->getSpeedBike() ?>" step="0.1" min="1" max="25">
					</div>
					<div class="setting-item">
						<label for="speedEbike">Vitesse moyenne à vélo électrique (km/h) :</label>
						<input type="number" id="speedEbike" name="speedEbike" value="<?= $app->getSpeedEbike() ?>" step="0.1" min="1" max="30">
					</div>
					<div class="setting-item">
						<label for="delayBike">Délai vélo (départ + arrivée) en minutes :</label>
						<input type="number" id="delayBike" name="delayBike" value="<?= $app->getDelayBike() ?>" step="1" min="0" max="20">
					</div>
					<div class="setting-item">
						<label for="delayCar">Délai voiture (départ + arrivée) en minutes :</label>
						<input type="number" id="delayCar" name="delayCar" value="<?= $app->getDelayCar() ?>" step="1" min="0" max="30">
					</div>
				</div>
			</div>

			<div id="share-section" class="share-section">
				<span class="share-label">Partager cette carte :</span>
				<div class="share-url-container">
					<input type="text" id="share-url" class="share-url" readonly value="<?= htmlspecialchars($shareUrl) ?>">
					<button class="copy-btn secondary-btn" id="copyShareBtn" title="Copier l'URL" type="button">&#x1F4CB;</button>
					<button class="print-btn secondary-btn" id="printBtn" title="Imprimer la page" type="button">&#x1F5A8;&#xFE0F;</button>
				</div>
			</div>
		</form>
	</div>

	<div id="message"></div>

	<div class="map-container">
		<div id="map"></div>

		<?php if ($isComputing): ?>
		<div id="loading-overlay">
			<div class="loading-content">
				<div class="spinner"></div>
				<p>Calcul en cours : <span id="calculationProgress">0%</span></p>
			</div>
		</div>
		<?php else: ?>
		<div class="legend">
			<h4>Légende :</h4>
			<div class="legend-item">
				<div class="legend-color legend-color-walk"></div>
				<div class="legend-text">Plus rapide à pied qu'en voiture</div>
			</div>
			<div class="legend-item">
				<div class="legend-color legend-color-bike"></div>
				<div class="legend-text">Plus rapide à vélo qu'en voiture</div>
			</div>
			<div class="legend-item">
				<div class="legend-color legend-color-ebike"></div>
				<div class="legend-text">Plus rapide à vélo élec. qu'en voiture</div>
			</div>
		</div>
		<?php endif; ?>
	</div>

	<div class="methodology">
		<h2>Comment sont calculées les zones plus rapides ?</h2>
		<div class="methodology-content">
			<h3>Fonctionnement de l'algorithme</h3>
			<p>Cet algorithme calcule les zones géographiques où la mobilité active (marche, vélo, vélo électrique) est plus rapide que la voiture pour rejoindre une destination. Il s'agit d'une comparaison des isochrones à pied, à vélo et à vélo électrique, versus en voiture. Il intègre les pénalités temporelles liées à chaque mode de transport : temps pour détacher et rattacher son vélo, temps pour rejoindre son véhicule et se garer.</p>
			<p>Le calcul débute par la détermination d'une zone accessible en mobilité active durant le temps équivalent aux délais incompressibles de la voiture. L'algorithme procède ensuite de manière itérative : à chaque étape, il calcule simultanément la zone atteignable en mobilité active et celle accessible en voiture pour la même durée de trajet.</p>
			<p>Pour optimiser les calculs, le territoire est divisé en hexagones de taille adaptée à la vitesse de déplacement. À chaque itération, seules les nouvelles zones où la mobilité active reste plus rapide sont conservées et ajoutées au résultat final. Le processus s'arrête lorsque la voiture devient plus rapide, quelle que soit la direction.</p>
			<h3>Sources des données</h3>
			<ul>
				<li><strong>Géocodage</strong> : <a href="https://geoservices.ign.fr/documentation/services/services-geoplateforme/geocodage" target="_blank" rel="noopener">geoservices.ign.fr</a> pour l'auto-complétion des adresses</li>
				<li><strong>Calcul d'itinéraires</strong> : <a href="https://geoservices.ign.fr/services-geoplateforme-itineraire" target="_blank" rel="noopener">geoservices.ign.fr</a> pour les isochrones et isodistances (piéton, vélo, vélo électrique, voiture)</li>
				<li><strong>Cartographie</strong> : <a href="https://openstreetmap.org" target="_blank" rel="noopener">OpenStreetMap</a> pour l'affichage de la carte</li>
			</ul>
		</div>
	</div>

	<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
	<script src="script.js"></script>
</body>
</html>
