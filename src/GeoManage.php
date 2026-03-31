<?php
/**
 * active-mobility-isochrones
 *
 * Implémentation concrète de GeoCompute avec gestion du cache fichier,
 * lecture des paramètres HTTP, et relancement sur timeout.
 *
 * @license MIT
 * @see https://github.com/biz-lab/active-mobility-isochrones
 */

namespace ActiveMobilityIsochrones;

class GeoManage extends GeoCompute {

	private string $geoDataDir;
	private string $resultDir;

	/**
	 * @param string $geoDataDir Répertoire de cache des données API
	 * @param string $resultDir Répertoire de cache des résultats
	 */
	public function __construct(string $geoDataDir, string $resultDir) {
		$this->geoDataDir = $geoDataDir;
		$this->resultDir = $resultDir;
		if (!is_dir($this->geoDataDir)) { mkdir($this->geoDataDir, 0777, true); }
		if (!is_dir($this->resultDir)) { mkdir($this->resultDir, 0777, true); }
	}

	// ==========================================================================
	// Chemins de cache
	// ==========================================================================

	protected function getGeoDataCachePath(): string {
		return $this->geoDataDir;
	}

	protected function getResultCachePath(): string {
		return $this->resultDir;
	}

	// ==========================================================================
	// Lecture des paramètres
	// ==========================================================================

	public function readParameters(array $input): void {
		$this->address = substr(trim($input['address'] ?? ''), 0, 256);
		$this->latitude = (float) preg_replace('/[^0-9.\-]/', '', $input['latitude'] ?? '');
		$this->longitude = (float) preg_replace('/[^0-9.\-]/', '', $input['longitude'] ?? '');
		$this->speedWalk = (float) preg_replace('/[^0-9.]/', '', $input['speedWalk'] ?? '999');
		if (($this->speedWalk <= 0) || ($this->speedWalk > 12)) { $this->speedWalk = 4.; }
		$this->speedBike = (float) preg_replace('/[^0-9.]/', '', $input['speedBike'] ?? '999');
		if (($this->speedBike <= 0) || ($this->speedBike > 25)) { $this->speedBike = 12.; }
		$this->speedEbike = (float) preg_replace('/[^0-9.]/', '', $input['speedEbike'] ?? '999');
		if (($this->speedEbike <= 0) || ($this->speedEbike > 30)) { $this->speedEbike = 17.; }
		$this->delayBike = (float) preg_replace('/[^0-9.]/', '', $input['delayBike'] ?? '999');
		if (($this->delayBike < 0) || ($this->delayBike > 20)) { $this->delayBike = 4.; }
		$this->delayCar = (float) preg_replace('/[^0-9.]/', '', $input['delayCar'] ?? '999');
		if (($this->delayCar < 0) || ($this->delayCar > 30)) { $this->delayCar = 8.; }
	}

	// ==========================================================================
	// Cache
	// ==========================================================================

	public function cacheGetFileKey(): string {
		return strtolower(hash('xxh32', var_export([
			$this->address, $this->longitude, $this->latitude,
			$this->speedWalk, $this->speedBike, $this->speedEbike,
			$this->delayBike, $this->delayCar, $this->computeDetailLevel
		], true)));
	}

	protected function cacheGetFilePath(): string {
		return $this->resultDir . '/' . $this->cacheGetFileKey() . '.txt';
	}

	public function cacheGet(): void {
		$cacheFilePath = $this->cacheGetFilePath();
		if (!is_file($cacheFilePath)) {
			if (count($_GET) < 1) { return; }
			if (!preg_match('/^[a-f0-9]{7,9}$/', $key = array_key_first($_GET))) { return; }
			$cacheFilePath = $this->resultDir . '/' . $key . '.txt';
			if (!is_file($cacheFilePath)) { return; }
		}

		$cacheContent = file_get_contents($cacheFilePath);
		if (empty($cacheContent)) { return; }
		$cacheContent = unserialize(gzuncompress(base64_decode($cacheContent)));

		$this->address = $cacheContent['address'];
		$this->longitude = $cacheContent['longitude'];
		$this->latitude = $cacheContent['latitude'];
		$this->speedWalk = $cacheContent['speedWalk'];
		$this->speedBike = $cacheContent['speedBike'];
		$this->speedEbike = $cacheContent['speedEbike'];
		$this->delayBike = $cacheContent['delayBike'];
		$this->delayCar = $cacheContent['delayCar'];
		$this->computeDetailLevel = $cacheContent['computeDetailLevel'];
		$this->status = $cacheContent['status'];
		$this->computingLifeSignal = $cacheContent['computingLifeSignal'];
		$this->computingProgress = $cacheContent['computingProgress'];
		$this->walkCoords = $cacheContent['walkCoords'];
		$this->bikeCoords = $cacheContent['bikeCoords'];
		$this->ebikeCoords = $cacheContent['ebikeCoords'];
		$this->debugRedCoords = $cacheContent['debugRedCoords'];
		$this->debugBlueCoords = $cacheContent['debugBlueCoords'];
		$this->debugLoopCount = $cacheContent['debugLoopCount'];
		$this->debugBreak = $cacheContent['debugBreak'];
		$this->debugBreakOnMobility = $cacheContent['debugBreakOnMobility'];
		$this->computingVars = $cacheContent['computingVars'];
		$this->cacheKey = $this->cacheGetFileKey();

		@touch($cacheFilePath);
	}

	protected function cacheSet(): void {
		$cacheContent = [
			'address' => $this->address,
			'longitude' => $this->longitude,
			'latitude' => $this->latitude,
			'speedWalk' => $this->speedWalk,
			'speedBike' => $this->speedBike,
			'speedEbike' => $this->speedEbike,
			'delayBike' => $this->delayBike,
			'delayCar' => $this->delayCar,
			'computeDetailLevel' => $this->computeDetailLevel,
			'status' => $this->status,
			'computingLifeSignal' => $this->computingLifeSignal,
			'computingProgress' => $this->computingProgress,
			'cacheKey' => $this->cacheKey,
			'walkCoords' => $this->walkCoords,
			'bikeCoords' => $this->bikeCoords,
			'ebikeCoords' => $this->ebikeCoords,
			'debugRedCoords' => $this->debugRedCoords,
			'debugBlueCoords' => $this->debugBlueCoords,
			'debugLoopCount' => $this->debugLoopCount,
			'debugBreak' => $this->debugBreak,
			'debugBreakOnMobility' => $this->debugBreakOnMobility,
			'computingVars' => $this->computingVars,
		];
		file_put_contents($this->cacheGetFilePath(), base64_encode(gzcompress(serialize($cacheContent))));
	}

	// ==========================================================================
	// Timeout
	// ==========================================================================

	protected function onComputeTimeout(): void {
		$this->cacheSet();
		$params = http_build_query([
			'address' => $this->address,
			'longitude' => $this->longitude,
			'latitude' => $this->latitude,
			'speedWalk' => $this->speedWalk,
			'speedBike' => $this->speedBike,
			'speedEbike' => $this->speedEbike,
			'delayBike' => $this->delayBike,
			'delayCar' => $this->delayCar,
			'computeDetailLevel' => $this->computeDetailLevel,
			'continueCompute' => '1',
		]);
		header('Location: computeEndPoint.php?' . $params);
		exit;
	}

	// ==========================================================================
	// Exécution
	// ==========================================================================

	/**
	 * Initialise le statut 'computing' et sauvegarde en cache (avant lancement asynchrone)
	 */
	public function initComputing(): void {
		$this->cacheKey = $this->cacheGetFileKey();
		$this->status = 'computing';
		$this->debugBreak = false;
		$this->computingVars = [];
		$this->cacheSet();
	}

	/**
	 * Lance le calcul si des coordonnées sont disponibles
	 */
	public function run(): void {
		if ($this->latitude !== 0. || $this->longitude !== 0.) {
			$this->cacheKey = $this->cacheGetFileKey();
			$this->zoneCompute();
		}
	}

	// ==========================================================================
	// Accesseurs pour le rendu HTML
	// ==========================================================================

	public function getStatus(): string { return $this->status; }
	public function getAddress(): string { return $this->address; }
	public function setAddress(string $address): void { $this->address = $address; }
	public function getLatitude(): float { return $this->latitude; }
	public function getLongitude(): float { return $this->longitude; }
	public function getSpeedWalk(): float { return $this->speedWalk; }
	public function getSpeedBike(): float { return $this->speedBike; }
	public function getSpeedEbike(): float { return $this->speedEbike; }
	public function getDelayBike(): float { return $this->delayBike; }
	public function getDelayCar(): float { return $this->delayCar; }
	public function getComputingProgress(): int { return $this->computingProgress; }
	public function getComputingLifeSignal(): int { return $this->computingLifeSignal; }
	public function getCacheKey(): string { return $this->cacheKey; }
	public function getWalkCoords(): string { return $this->walkCoords; }
	public function getBikeCoords(): string { return $this->bikeCoords; }
	public function getEbikeCoords(): string { return $this->ebikeCoords; }
	public function getDebugRedCoords(): string { return $this->debugRedCoords; }
	public function getDebugBlueCoords(): string { return $this->debugBlueCoords; }

}
