<?php
/**
 * active-mobility-isochrones
 *
 * Classe abstraite contenant la logique de calcul géographique pour déterminer
 * les zones où la mobilité active (marche, vélo, VAE) est plus rapide que la voiture.
 *
 * Responsabilités : propriétés de calcul, appels API IGN, algorithme itératif.
 * La gestion du cache, des paramètres et du timeout est déléguée aux classes filles.
 *
 * @license MIT
 * @see https://github.com/biz-lab/active-mobility-isochrones
 */

namespace ActiveMobilityIsochrones;

abstract class GeoCompute {

	// --- Propriétés de paramétrage ---
	protected string $address = '';
	protected float $longitude = 0.;
	protected float $latitude = 0.;
	protected float $speedWalk = 4.;
	protected float $speedBike = 12.;
	protected float $speedEbike = 17.;
	protected float $delayBike = 4.;
	protected float $delayCar = 8.;
	protected string $computeDetailLevel = 'std';

	// --- Propriétés d'état et de résultat ---
	protected string $status = '';
	protected int $computingLifeSignal = 0;
	protected int $computingProgress = 0;
	protected string $cacheKey = '';
	protected string $walkCoords = '';
	protected string $bikeCoords = '';
	protected string $ebikeCoords = '';
	protected string $debugRedCoords = '';
	protected string $debugBlueCoords = '';
	protected int $debugLoopCount = 0;
	protected bool $debugBreak = false;
	protected string $debugBreakOnMobility = '';
	protected array $computingVars = [];

	// ==========================================================================
	// Méthodes abstraites — à implémenter par les classes filles
	// ==========================================================================

	/** Initialise les paramètres de calcul depuis un tableau de données */
	abstract protected function readParameters(array $input): void;

	/** Génère une clé unique de cache basée sur les paramètres de calcul */
	abstract protected function cacheGetFileKey(): string;

	/** Retourne le chemin complet du fichier cache résultat */
	abstract protected function cacheGetFilePath(): string;

	/** Récupère les données depuis le cache si elles existent */
	abstract protected function cacheGet(): void;

	/** Sauvegarde les données et résultats dans le cache */
	abstract protected function cacheSet(): void;

	/** Retourne le chemin du répertoire de cache pour les données API géographiques */
	abstract protected function getGeoDataCachePath(): string;

	/** Retourne le chemin du répertoire de cache pour les résultats de calcul */
	abstract protected function getResultCachePath(): string;

	/** Gestion du timeout de calcul (relancement asynchrone) */
	abstract protected function onComputeTimeout(): void;

	// ==========================================================================
	// Données géographiques API
	// ==========================================================================

	/**
	 * Récupère les données géographiques d'isochrone/isodistance depuis l'API IGN
	 *
	 * Documentation API : https://geoservices.ign.fr/documentation/services/services-geoplateforme/isochrone
	 *
	 * @param float $longitude Longitude du point de départ
	 * @param float $latitude Latitude du point de départ
	 * @param string $costType Type de coût : 'time' (temps) ou 'distance' (distance)
	 * @param int $costValue Valeur du coût en secondes ou mètres
	 * @param string $profile Profil de déplacement : 'pedestrian' ou 'car'
	 * @return string Données géographiques au format JSON (geometry)
	 */
	protected function geoDataGet(float $longitude, float $latitude, string $costType, int $costValue, string $profile): string {
		$url = 'https://data.geopf.fr/navigation/isochrone?gp-access-lib=3.4.2&resource=bdtopo-valhalla&crs=EPSG:4326&timeUnit=second&distanceUnit=meter'
			. '&point=' . round($longitude, 4) . ',' . round($latitude, 4)
			. '&direction=departure'
			. '&costType=' . $costType
			. '&costValue=' . $costValue
			. '&profile=' . $profile;

		$cacheFilePath = $this->getGeoDataCachePath() . '/' . md5($url) . '.txt';
		if (is_file($cacheFilePath)) {
			return gzuncompress(base64_decode(file_get_contents($cacheFilePath)));
		}

		$apiReturn = @file_get_contents($url);
		if ($apiReturn === false) { return ''; }
		$apiReturn = json_decode($apiReturn);
		if (isset($apiReturn->geometry)) { $apiReturn = json_encode($apiReturn->geometry); }
		file_put_contents($cacheFilePath, base64_encode(gzcompress($apiReturn)));
		return $apiReturn;
	}

	// ==========================================================================
	// Algorithme de calcul des zones
	// ==========================================================================

	/**
	 * Calcule les zones où la mobilité active est plus rapide que la voiture
	 * Algorithme itératif comparant les zones accessibles en mobilité active vs voiture
	 */
	protected function zoneCompute(): void {
	// Recherche éventuelle du résultat en cache
		$this->cacheGet();

	// Sortie si les résultats sont déjà connus ou si les coordonnées sont absentes
		if ($this->status === 'computed') { return; }
		if (($this->latitude === 0.) && ($this->longitude === 0.)) { return; }

	// Ratios de conversion d'unités
		$ratio_minutes_to_second = 60;
		$ratio_second_to_hour = 1 / 3600;
		$ratio_hour_to_second = 3600;
		$ratio_km_to_meter = 1000;
		$ratio_meter_to_km = 1 / 1000;

	// Estimation de la vitesse moyenne de la voiture dans la zone
	// On demande l'isochrone voiture pour 10 min et on mesure la distance au point le plus éloigné
		$testDurationSecond = 600;
		$carZone = $this->geoDataGet($this->longitude, $this->latitude, 'time', $testDurationSecond, 'car');
		$maxDistance = GeoMath::findFarthestVertex($this->longitude, $this->latitude, $carZone);
		$speedCar = round($maxDistance / ($testDurationSecond * $ratio_second_to_hour), 1);

		$computeStartTime = microtime(true);

	// Calcul pour chaque mode de mobilité active (marche, vélo, vélo électrique)
		foreach (['Walk', 'Bike', 'Ebike'] as $mobility) {

		// --- Configuration des paramètres spécifiques à chaque mode ---
		// speedMobility : vitesse de la mobilité active
		// carTimePenalty_second : retard de la voiture (délai véhicule - délai éventuel vélo)
		// timeStep_second : pas temporel adapté à la vitesse voiture (plus la voiture est lente, plus le pas est large)
		// maxTime : garde-fou temporel (condition d'arrêt 4)
		// progressDone/progressWeight : pour le calcul de la barre de progression
			if ($mobility === 'Walk') {
				$speedMobility = $this->speedWalk;
				$carTimePenalty_second = $this->delayCar * $ratio_minutes_to_second;
				$mobilityCoords = &$this->walkCoords;
				$timeStep_second = match (true) {
					$speedCar < 20 => 90,
					$speedCar < 30 => 75,
					default => 60,
				};
				$maxTime = 20 * 60;
				$maxLoop = ceil($maxTime / $timeStep_second);
				$progressDone = 0;
				$progressWeight = 10;
			} elseif ($mobility === 'Bike') {
				$speedMobility = $this->speedBike;
				$carTimePenalty_second = ($this->delayCar - $this->delayBike) * $ratio_minutes_to_second;
				$mobilityCoords = &$this->bikeCoords;
				$timeStep_second = match (true) {
					$speedCar < 20 => 120,
					$speedCar < 30 => 105,
					default => 90,
				};
				$maxTime = 40 * 60;
				$maxLoop = ceil($maxTime / $timeStep_second);
				$progressDone = 10;
				$progressWeight = 30;
			} else {
				$speedMobility = $this->speedEbike;
				$carTimePenalty_second = ($this->delayCar - $this->delayBike) * $ratio_minutes_to_second;
				$mobilityCoords = &$this->ebikeCoords;
				$timeStep_second = match (true) {
					$speedCar < 20 => 180,
					$speedCar < 30 => 150,
					default => 120,
				};
				$maxTime = 60 * 60;
				$maxLoop = ceil($maxTime / $timeStep_second);
				$progressDone = 30;
				$progressWeight = 70;
			}

		// --- Initialisation de la grille hexagonale ---
		// Taille de l'hexagone = distance parcourue par la mobilité active en 1 pas temporel
		// Pénalité supplémentaire voiture = temps pour couvrir un hexagone complet à sa vitesse
			$hexagonSize_meter = (int)round($speedMobility * $ratio_km_to_meter * $timeStep_second * $ratio_second_to_hour);
			$timeRequiredByCarToCoverAHexagon_second = (int)round($hexagonSize_meter * $ratio_meter_to_km / $speedCar * $ratio_hour_to_second);
			$splitter = new GeoMath($this->longitude, $this->latitude, $hexagonSize_meter, 5);

		// Reprise éventuelle de l'état intermédiaire (après un timeout)
			if (isset($this->computingVars[$mobility]['done'])) { continue; }
			$cumulativeMobilityHexagonZone = $this->computingVars[$mobility]['cumulativeMobilityHexagonZone'] ?? [];
			$carHexagonZone = $this->computingVars[$mobility]['carHexagonZone'] ?? [];
			$mobilityHexagonZone = [];
			$isCarCatchingUpWithActiveMobility = $this->computingVars[$mobility]['isCarCatchingUpWithActiveMobility'] ?? false;

			$wonMobilityHexagon = 0;
			$previousWonMobilityHexagon = 0;
			$breakLoop = false;

		// === BOUCLE ITÉRATIVE ===
		// À chaque pas, on compare les zones accessibles en mobilité active vs voiture
			$loopsPerMobility = 0;
			for ($travelDuration_second = 60; $travelDuration_second <= $maxTime; $travelDuration_second += $timeStep_second) {
				$loopsPerMobility++;

			// Saut des itérations déjà calculées (reprise après timeout)
				if (isset($this->computingVars['step-' . $mobility . '-' . $loopsPerMobility])) { continue; }

			// --- Zone accessible en voiture ---
			// Temps voiture = temps mobilité - pénalité T0 - pénalité couverture hexagone
				$carTravelDuration_second = $travelDuration_second - $carTimePenalty_second - $timeRequiredByCarToCoverAHexagon_second;
				if ($carTravelDuration_second < 0) { continue; }
				if ($carTravelDuration_second >= 60) {
					$carZone = $this->geoDataGet($this->longitude, $this->latitude, 'time', $carTravelDuration_second, 'car');
					$previousCarHexagonZone = $carHexagonZone;
					$carHexagonZone = $splitter->polygonToGrid($carZone, $carHexagonZone);
				};

			// --- Zone accessible en mobilité active ---
			// On utilise l'isodistance piéton avec la distance = vitesse × temps
				$mobilityDistanceTravelled_meter = round($travelDuration_second * $ratio_second_to_hour * $speedMobility * $ratio_km_to_meter);
				$mobilityZone = $this->geoDataGet($this->longitude, $this->latitude, 'distance', $mobilityDistanceTravelled_meter, 'pedestrian');
				$mobilityHexagonZone = $splitter->polygonToGrid($mobilityZone, $mobilityHexagonZone);

			// --- Calcul différentiel ---
			// Nouvelles zones = zones mobilité SAUF zones voiture SAUF zones déjà cumulées
			// On ne garde que les zones adjacentes au cumul existant (exclut les îles)
				$newAreasMoreQuicklyAccessibleByActiveMobility = array_diff_key(array_diff_key($mobilityHexagonZone, $carHexagonZone), $cumulativeMobilityHexagonZone);
				$previousCumulativeMobilityHexagonZone = $cumulativeMobilityHexagonZone;
				$previousWonMobilityHexagon = $wonMobilityHexagon;
				if (count($cumulativeMobilityHexagonZone) === 0) {
					$cumulativeMobilityHexagonZone = $newAreasMoreQuicklyAccessibleByActiveMobility;
					$wonMobilityHexagon = count($newAreasMoreQuicklyAccessibleByActiveMobility);
				} else {
					$cumulativeMobilityHexagonZone = $splitter->mergeAdjacentHexagons($cumulativeMobilityHexagonZone, $newAreasMoreQuicklyAccessibleByActiveMobility, $wonMobilityHexagon);
				}

			// --- Conditions d'arrêt ---

			// Condition 1 : la voiture couvre toutes les zones de mobilité → elle a doublé partout
				if (count($carHexagonZone) > 0) {
					if (count(array_diff_key($cumulativeMobilityHexagonZone, $carHexagonZone)) === 0) {
						$breakLoop = true;
				};};

			// Condition 2 : la voiture a commencé à rattraper mais ne gagne plus aucune zone
			// → les zones restantes sont inaccessibles en voiture (parcs, sentiers)
				if (!$breakLoop && (count($carHexagonZone) > 0)) {
					$previousCarCoverage = array_intersect_key($previousCarHexagonZone ?? [], $previousCumulativeMobilityHexagonZone);
					$newCarCoverage = array_intersect_key($carHexagonZone, $cumulativeMobilityHexagonZone);
					$latestCarWonZones = array_diff_key($newCarCoverage, $previousCarCoverage);
					if (($isCarCatchingUpWithActiveMobility) && (count($latestCarWonZones) === 0)) {
						$breakLoop = true;
					} elseif (count($newCarCoverage) > 0) {
						$isCarCatchingUpWithActiveMobility = true;
				};};

			// Condition 3 : la mobilité ne gagne aucune zone depuis 2 tours → front de mer, montagne
				if (!$breakLoop && (count($carHexagonZone) > 0)) {
					if (($wonMobilityHexagon === 0) && ($previousWonMobilityHexagon === 0)) {
						$breakLoop = true;
				};};

			// --- Sauvegarde de l'état intermédiaire pour reprise après timeout ---
				$this->computingVars['step-' . $mobility . '-' . $loopsPerMobility] = 1;
				$this->computingVars[$mobility] = [
					'cumulativeMobilityHexagonZone' => $cumulativeMobilityHexagonZone,
					'carHexagonZone' => $carHexagonZone,
					'isCarCatchingUpWithActiveMobility' => $isCarCatchingUpWithActiveMobility,
				];
				$this->status = 'computing';
				$this->computingLifeSignal = time();
				$this->computingProgress = round($progressDone + $progressWeight * $loopsPerMobility / $maxLoop);
				$this->cacheSet();
				if ($breakLoop) { break; }

			// Anti-timeout : si le calcul dure plus de 30s, sauvegarder et relancer
				if (microtime(true) - $computeStartTime > 30) {
					$this->onComputeTimeout();
					return;
				}
			}

		// Conversion du zonage hexagonal en polygone GeoJSON pour l'affichage
			$mobilityCoords = $splitter->gridToContourPolygon($cumulativeMobilityHexagonZone);
			$this->computingVars[$mobility] = ['done' => true];
		};

	// === FIN DU CALCUL — toutes les mobilités traitées ===
		$this->status = 'computed';
		$this->computingLifeSignal = time();
		$this->computingProgress = 100;
		$this->computingVars = [];
		$this->cacheSet();
	}

}
