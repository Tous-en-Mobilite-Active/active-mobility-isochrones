<?php
/**
 * active-mobility-isochrones
 *
 * Classe de mathématiques géométriques pour les opérations sur grille hexagonale.
 * Découpe un polygone géographique en grille d'hexagones, reconstruit les contours,
 * et fusionne des ensembles d'hexagones adjacents.
 *
 * @license MIT
 * @see https://github.com/biz-lab/active-mobility-isochrones
 */

namespace ActiveMobilityIsochrones;

class GeoMath {
	
	// Rayon de la Terre en mètres
	private const EARTH_RADIUS = 6371000;
	
	// Point de référence pour la grille
	private float $refLat;
	private float $refLon;
	
	// Taille des hexagones (distance entre centres en mètres)
	private int $hexSize;
	
	// Précision des coordonnées (nombre de décimales)
	private int $precision;
	
	/**
	 * Constructeur avec point de référence et précision
	 *
	 * @param float $refLon Longitude de référence (par défaut : -2.0)
	 * @param float $refLat Latitude de référence (par défaut : 48.0)
	 * @param int $hexSize Distance entre centres d'hexagones en mètres (par défaut : 100m)
	 * @param int $precision Nombre de décimales pour les coordonnées (par défaut : 5)
	 */
	public function __construct(float $refLon = -2.0, float $refLat = 48.0, int $hexSize = 100, int $precision = 5)
	{
		$this->refLon = $refLon;
		$this->refLat = $refLat;
		$this->hexSize = $hexSize;
		$this->precision = max(1, min(10, $precision));
	}
	
	/**
	 * Convertir un polygone en tableau d'hexagones avec clés 'lon|lat'
	 *
	 * @param string $polygonJson GeoJSON du polygone
	 * @param array<string, array{row: int, col: int, coordinates: array<int, array{float, float}>, center: array{float, float}}> $previousHexagons Hexagones du polygone précédent (optionnel)
	 * @return array<string, array{row: int, col: int, coordinates: array<int, array{float, float}>, center: array{float, float}}> Tableau avec clés 'lon|lat' > coordonnées de l'hexagone
	 */
	public function polygonToGrid(string $polygonJson, array $previousHexagons = []): array
	{
		$polygon = json_decode($polygonJson, true);
		
		if (!$polygon || !isset($polygon['coordinates'][0])) {
			return [];
		}
		
		$coords = $polygon['coordinates'][0];
		
		// Calculer la boîte englobante du polygone
		$bounds = $this->getBoundingBox($coords);
		
		$hexRadius = $this->hexSize / sqrt(3);
		$hexHeight = $hexRadius * 2;
		$hexWidth = $hexRadius * sqrt(3);
		
		$vertSpacing = (3 * $hexRadius) / 2;
		$horizSpacing = $hexWidth;
		
		$latStepVert = $this->metersToLatDegrees($vertSpacing);
		$lonStepHoriz = $this->metersToLonDegrees($horizSpacing, $this->refLat);
		
		$radiusLatDeg = $this->metersToLatDegrees($hexRadius);
		$radiusLonDeg = $this->metersToLonDegrees($hexRadius, $this->refLat);
		
		$hexLatSize = $this->metersToLatDegrees($hexHeight / 2);
		$hexLonSize = $this->metersToLonDegrees($hexWidth / 2, $this->refLat);
		
		$minRow = (int) floor(($bounds['minLat'] - $this->refLat - $hexLatSize) / $latStepVert);
		$maxRow = (int) ceil(($bounds['maxLat'] - $this->refLat + $hexLatSize) / $latStepVert);
		
		$minCol = (int) floor(($bounds['minLon'] - $this->refLon - $hexLonSize) / $lonStepHoriz);
		$maxCol = (int) ceil(($bounds['maxLon'] - $this->refLon + $hexLonSize) / $lonStepHoriz);
		
		$keyFormat = "%." . $this->precision . "f|%." . $this->precision . "f";
		
		// Commencer avec les hexagones précédents (déjà validés)
		$includedHexagons = $previousHexagons;
		
		// Générer les hexagones inclus
		for ($row = $minRow; $row <= $maxRow; $row++) {
			$centerLat = $this->refLat + ($row * $latStepVert);
			$colOffset = ($row % 2 === 0) ? 0 : 0.5;
			
			for ($col = $minCol; $col <= $maxCol; $col++) {
				$centerLon = $this->refLon + (($col + $colOffset) * $lonStepHoriz);
				
				$centerLatRounded = round($centerLat, $this->precision);
				$centerLonRounded = round($centerLon, $this->precision);
				
				// Créer la clé
				$key = sprintf($keyFormat, $centerLonRounded, $centerLatRounded);
				
				// OPTIMISATION : Si l'hexagone existe déjà dans previousHexagons, on le garde tel quel
				if (isset($previousHexagons[$key])) {
					continue; // Déjà dans $includedHexagons
				}
				
				// Sinon, on effectue les tests d'inclusion
				$hexagon = $this->createHexagonPointyTop($centerLon, $centerLat, $radiusLonDeg, $radiusLatDeg);
				
				if ($this->isHexagonIncluded($hexagon, $coords)) {
					$includedHexagons[$key] = [
						'row' => $row,
						'col' => $col,
						'coordinates' => $hexagon,
						'center' => [$centerLonRounded, $centerLatRounded]
					];
				}
			}
		}
		
		return $includedHexagons;
	}
	
	
	/**
	 * Créer un hexagone Pointy-Top (pointe en haut) centré sur un point
	 * Les sommets sont numérotés de 0 à 5 en partant de la droite-haut, sens horaire
	 *
	 * @param float $centerLon Centre longitude
	 * @param float $centerLat Centre latitude
	 * @param float $radiusLonDeg Rayon longitude en degrés
	 * @param float $radiusLatDeg Rayon latitude en degrés
	 * @return array<int, array{float, float}> Coordonnées des 6 sommets + fermeture
	 */
	private function createHexagonPointyTop(float $centerLon, float $centerLat, float $radiusLonDeg, float $radiusLatDeg): array
	{
		// Pour un hexagone Pointy-Top, les sommets sont à ces angles (en degrés) : 30°, 90°, 150°, 210°, 270°, 330°
		$angles = [30, 90, 150, 210, 270, 330];
		$vertices = [];
		
		foreach ($angles as $angle) {
			$rad = deg2rad($angle);
			$vertices[] = [
				$centerLon + $radiusLonDeg * cos($rad),
				$centerLat + $radiusLatDeg * sin($rad)
			];
		}
		
		// Arrondir et fermer
		$rounded = array_map(function ($v) {
			return [round($v[0], $this->precision), round($v[1], $this->precision)];
		}, $vertices);
		
		$rounded[] = $rounded[0]; // Fermer l'hexagone
		
		return $rounded;
	}
	
	/**
	 * Test d'inclusion d'hexagone (au moins 33% des points de test inclus)
	 *
	 * @param array<int, array{float, float}> $hexagon Coordonnées de l'hexagone
	 * @param array<int, array{float, float}> $polygon Coordonnées du polygone
	 */
	private function isHexagonIncluded(array $hexagon, array $polygon): bool
	{
		// Calculer le centre
		$centerLon = 0;
		$centerLat = 0;
		for ($i = 0; $i < 6; $i++) {
			$centerLon += $hexagon[$i][0];
			$centerLat += $hexagon[$i][1];
		}
		$centerLon /= 6;
		$centerLat /= 6;
		
		// Test rapide : si le centre est dans le polygone, l'hexagone est inclus
		if ($this->isPointInPolygonFast($centerLon, $centerLat, $polygon)) {
			return true;
		}
		
		// Sinon, test détaillé pour les hexagones en bordure :
		// 6 sommets + 6 points au milieu des arêtes, seuil = 4 sur 12
		$pointsInside = 0;
		
		// Test sur les sommets
		for ($i = 0; $i < 6; $i++) {
			if ($this->isPointInPolygonFast($hexagon[$i][0], $hexagon[$i][1], $polygon)) {
				$pointsInside++;
				// Sortie positive dès que l'on 4 points inclut
				if ($pointsInside >= 4) { return true; }
			}
			// Sortie négative si dès l'on sait que l'on aura moins de 2 sommets inclut
			if ($pointsInside + (5 - $i) < 2) { return false; }
		}
		
		// Test sur les points au milieu des arêtes
		for ($i = 0; $i < 6; $i++) {
			$p1 = $hexagon[$i];
			$p2 = $hexagon[$i + 1];
			if ($this->isPointInPolygonFast(($p1[0] + $p2[0]) / 2, ($p1[1] + $p2[1]) / 2, $polygon)) {
				$pointsInside++;
				// Sortie positive dès que l'on 4 points inclut
				if ($pointsInside >= 4) { return true; }
			}
			// Sortie négative si le seuil de 4 est devenu inatteignable
			if ($pointsInside + (5 - $i) < 4) { return false; }
		}
		
		// Sortie
		return false;
	}
	
	/**
	 * Calculer la boîte englobante du polygone
	 *
	 * @param array<int, array{float, float}> $coords Coordonnées du polygone
	 * @return array{minLon: float, maxLon: float, minLat: float, maxLat: float}
	 */
	private function getBoundingBox(array $coords): array
	{
		$lons = array_column($coords, 0);
		$lats = array_column($coords, 1);
		
		return [
			'minLon' => min($lons),
			'maxLon' => max($lons),
			'minLat' => min($lats),
			'maxLat' => max($lats)
		];
	}
	
	/**
	 * Test point-in-polygon optimisé (ray casting)
	 *
	 * @param float $x Longitude
	 * @param float $y Latitude
	 * @param array<int, array{float, float}> $polygon
	 */
	private function isPointInPolygonFast(float $x, float $y, array $polygon): bool
	{
		$inside = false;
		$n = count($polygon) - 1;
		
		for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
			$xi = $polygon[$i][0];
			$yi = $polygon[$i][1];
			$xj = $polygon[$j][0];
			$yj = $polygon[$j][1];
			
			if ((($yi > $y) !== ($yj > $y)) && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi)) {
				$inside = !$inside;
			}
		}
		
		return $inside;
	}
	
	/**
	 * Convertir des mètres en degrés de latitude
	 */
	private function metersToLatDegrees(float $meters): float
	{
		return $meters / self::EARTH_RADIUS * (180 / M_PI);
	}
	
	/**
	 * Convertir des mètres en degrés de longitude
	 */
	private function metersToLonDegrees(float $meters, float $latitude): float
	{
		$latRad = deg2rad($latitude);
		return $meters / (self::EARTH_RADIUS * cos($latRad)) * (180 / M_PI);
	}
	
	/**
	 * Reconstruire le polygone de contour à partir d'un tableau d'hexagones
	 * Élimine les segments intérieurs (présents 2 fois)
	 */
	public function gridToContourPolygon(array $hexagons): string
	{
		if (empty($hexagons)) {
			return json_encode(['type' => 'Polygon', 'coordinates' => [[]]]);
		}
		
		// Préparer le format de nombre pour la précision
		$fmt = "%." . $this->precision . "f";
		// Format de clé pour un segment : "lon1,lat1-lon2,lat2"
		$keyFmt = "$fmt,$fmt-$fmt,$fmt";
		
		// Collecter tous les segments et les compter
		$edgeCount = [];
		
		foreach ($hexagons as $hexagon) {
			$coords = $hexagon['coordinates'];
			
			// Les 6 côtés de l'hexagone
			for ($i = 0; $i < count($coords) - 1; $i++) {
				$p1 = $coords[$i];
				$p2 = $coords[$i + 1];
				
				// Normaliser le segment (toujours du plus petit au plus grand)
				// Comparaison lexicographique des coordonnées arrondies
				if ($p1[0] < $p2[0] || ($p1[0] == $p2[0] && $p1[1] < $p2[1])) {
					$key = sprintf($keyFmt, $p1[0], $p1[1], $p2[0], $p2[1]);
				} else {
					$key = sprintf($keyFmt, $p2[0], $p2[1], $p1[0], $p1[1]);
				}
				
				if (!isset($edgeCount[$key])) {
					$edgeCount[$key] = 0;
				}
				$edgeCount[$key]++;
			}
		}
		
		// Collecter les segments de bordure (count == 1) avec leur orientation originale
		$boundaryEdges = [];
		$addedEdges = [];
		
		foreach ($hexagons as $hexagon) {
			$coords = $hexagon['coordinates'];
			
			for ($i = 0; $i < count($coords) - 1; $i++) {
				$p1 = $coords[$i];
				$p2 = $coords[$i + 1];
				
				// Calculer la clé normalisée
				if ($p1[0] < $p2[0] || ($p1[0] == $p2[0] && $p1[1] < $p2[1])) {
					$normalizedKey = sprintf($keyFmt, $p1[0], $p1[1], $p2[0], $p2[1]);
				} else {
					$normalizedKey = sprintf($keyFmt, $p2[0], $p2[1], $p1[0], $p1[1]);
				}
				
				// Si ce segment n'apparaît qu'une fois, c'est un segment de bordure
				if ($edgeCount[$normalizedKey] === 1) {
					// Clé unique pour ce segment orienté spécifique pour éviter les doublons
					$edgeKey = sprintf($keyFmt, $p1[0], $p1[1], $p2[0], $p2[1]);
					
					if (!in_array($edgeKey, $addedEdges)) {
						$boundaryEdges[] = ['start' => $p1, 'end' => $p2];
						$addedEdges[] = $edgeKey;
					}
				}
			}
		}
		
		// Tracer le contour en suivant les segments
		$contours = $this->traceContours($boundaryEdges);
		
		if (empty($contours)) {
			return json_encode(['type' => 'Polygon', 'coordinates' => [[]]]);
		}
		
		// Retourner le plus grand contour (contour extérieur)
		$largestContour = $contours[0];
		foreach ($contours as $contour) {
			if (count($contour) > count($largestContour)) {
				$largestContour = $contour;
			}
		}
		
		return json_encode(['type' => 'Polygon', 'coordinates' => [$largestContour]]);
	}
	
	/**
	 * Tracer les contours à partir des segments
	 */
	private function traceContours(array $edges): array
	{
		$contours = [];
		$used = array_fill(0, count($edges), false);
		
		for ($i = 0; $i < count($edges); $i++) {
			if ($used[$i])
				continue;
			
			$contour = [];
			$current = $i;
			$iterations = 0;
			$maxIterations = count($edges) * 2;
			
			do {
				$used[$current] = true;
				$edge = $edges[$current];
				
				if (
					empty($contour) ||
					$contour[count($contour) - 1][0] != $edge['start'][0] ||
					$contour[count($contour) - 1][1] != $edge['start'][1]
				) {
					$contour[] = $edge['start'];
				}
				$contour[] = $edge['end'];
				
				// Chercher le prochain segment
				$found = false;
				for ($j = 0; $j < count($edges); $j++) {
					if ($used[$j])
						continue;
					
					// Connexion exacte : fin du segment actuel == début du prochain
					if (
						$edges[$j]['start'][0] == $edge['end'][0] &&
						$edges[$j]['start'][1] == $edge['end'][1]
					) {
						$current = $j;
						$found = true;
						break;
					}
				}
				
				if (!$found)
					break;
				
				$iterations++;
				if ($iterations > $maxIterations)
					break;
				
			} while (!$used[$current]);
			
			if (count($contour) > 2) {
				// Fermer le contour
				$contour[] = $contour[0];
				$contours[] = $contour;
			}
		}
		
		return $contours;
	}
	
	/**
	 * Calculer la distance entre deux points géographiques (formule de Haversine)
	 *
	 * @return float Distance en kilomètres
	 */
	static function coordDistance($lon1, $lat1, $lon2, $lat2): float
	{
		$earthRadius = self::EARTH_RADIUS / 1000; // Rayon de la Terre en kilomètres
		$dLat = deg2rad($lat2 - $lat1);
		$dLon = deg2rad($lon2 - $lon1);
		$a = sin($dLat / 2) * sin($dLat / 2) +
			cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
			sin($dLon / 2) * sin($dLon / 2);
		$c = 2 * atan2(sqrt($a), sqrt(1 - $a));
		return $earthRadius * $c;
	}
	
	/**
	 * Trouve le sommet le plus éloigné d'un polygone par rapport à un point donné
	 *
	 * @return float Distance maximale en kilomètres
	 */
	static function findFarthestVertex($lon, $lat, $polygonJson): float
	{
		$maxDistance = 0;
		
		$polygon = json_decode($polygonJson, true);
		if (!$polygon || !isset($polygon['coordinates'][0])) {
			return 0;
		}
		$vertices = $polygon['coordinates'][0];
		
		foreach ($vertices as $vertex) {
			$distance = self::coordDistance(
				$lon,
				$lat,
				$vertex[0],
				$vertex[1]
			);
			
			if ($distance > $maxDistance) {
				$maxDistance = $distance;
			}
		}
		
		return $maxDistance;
	}
	
	
	/**
	 * Fusionne deux listes d'hexagones en ajoutant uniquement les hexagones
	 * de la seconde liste qui sont mitoyens (partagent un segment) avec la première
	 *
	 * @param array<string, array{row: int, col: int, coordinates: array<int, array{float, float}>, center: array{float, float}}> $baseHexagons Liste d'hexagones de base
	 * @param array<string, array{row: int, col: int, coordinates: array<int, array{float, float}>, center: array{float, float}}> $candidateHexagons Liste d'hexagones candidats à l'ajout
	 * @param int $addedItems Nombre d'hexagones ajoutés
	 * @return array<string, array{row: int, col: int, coordinates: array<int, array{float, float}>, center: array{float, float}}> Liste fusionnée
	 */
	public function mergeAdjacentHexagons(array $baseHexagons, array $candidateHexagons, int &$addedItems=0): array
	{
		if (empty($baseHexagons)) { return []; }
		
		if (empty($candidateHexagons)) { return $baseHexagons; }
		
		// Copie de la liste de base
		$merged = $baseHexagons;
		$addedItems = 0;
		
		// Format de clé pour les segments
		$fmt = "%." . $this->precision . "f";
		$keyFmt = "$fmt,$fmt-$fmt,$fmt";
		
		// Construire l'index des segments de la liste de base
		$baseSegments = $this->buildSegmentIndex($baseHexagons, $keyFmt);
		
		
		do {
			// Liste des hexagones ajoutés lors de cette itération
			$addedThisRound = [];
			
			// Pour chaque hexagone candidat non encore ajouté
			foreach ($candidateHexagons as $key => $hexagon) {
				// Si déjà dans la liste fusionnée, on ignore
				if (isset($merged[$key])) { continue; }
				
				// Vérifier si cet hexagone partage au moins un segment avec la liste actuelle
				if ($this->hasSharedSegment($hexagon, $baseSegments, $keyFmt)) {
					// Ajouter l'hexagone
					$merged[$key] = $hexagon;
					$addedThisRound[$key] = $hexagon;
					$addedItems++;
					// Ajouter ses segments à l'index pour les prochaines itérations
					$this->addHexagonSegmentsToIndex($hexagon, $baseSegments, $keyFmt);
				}
			}
			
			// Continuer tant qu'on ajoute de nouveaux hexagones
		} while (!empty($addedThisRound));
		
		return $merged;
	}
	
	/**
	 * Construit un index des segments pour tous les hexagones
	 *
	 * @param array<string, array> $hexagons
	 * @param string $keyFmt Format de clé pour les segments
	 * @return array<string, bool> Index des segments (clé => true)
	 */
	private function buildSegmentIndex(array $hexagons, string $keyFmt): array
	{
		$segments = [];
		
		foreach ($hexagons as $hexagon) {
			$coords = $hexagon['coordinates'];
			
			// Les 6 côtés de l'hexagone
			for ($i = 0; $i < count($coords) - 1; $i++) {
				$p1 = $coords[$i];
				$p2 = $coords[$i + 1];
				
				// Normaliser le segment (ordre canonique)
				if ($p1[0] < $p2[0] || ($p1[0] == $p2[0] && $p1[1] < $p2[1])) {
					$key = sprintf($keyFmt, $p1[0], $p1[1], $p2[0], $p2[1]);
				} else {
					$key = sprintf($keyFmt, $p2[0], $p2[1], $p1[0], $p1[1]);
				}
				
				$segments[$key] = true;
			}
		}
		
		return $segments;
	}
	
	/**
	 * Vérifie si un hexagone partage au moins un segment avec l'index
	 *
	 * @param array{coordinates: array<int, array{float, float}>} $hexagon
	 * @param array<string, bool> $segmentIndex
	 * @param string $keyFmt
	 * @return bool
	 */
	private function hasSharedSegment(array $hexagon, array $segmentIndex, string $keyFmt): bool
	{
		$coords = $hexagon['coordinates'];
		
		for ($i = 0; $i < count($coords) - 1; $i++) {
			$p1 = $coords[$i];
			$p2 = $coords[$i + 1];
			
			// Normaliser le segment
			if ($p1[0] < $p2[0] || ($p1[0] == $p2[0] && $p1[1] < $p2[1])) {
				$key = sprintf($keyFmt, $p1[0], $p1[1], $p2[0], $p2[1]);
			} else {
				$key = sprintf($keyFmt, $p2[0], $p2[1], $p1[0], $p1[1]);
			}
			
			// Si ce segment existe dans l'index, c'est un segment partagé
			if (isset($segmentIndex[$key])) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * Ajoute les segments d'un hexagone à l'index
	 *
	 * @param array{coordinates: array<int, array{float, float}>} $hexagon
	 * @param array<string, bool> &$segmentIndex Référence à l'index à modifier
	 * @param string $keyFmt
	 */
	private function addHexagonSegmentsToIndex(array $hexagon, array &$segmentIndex, string $keyFmt): void
	{
		$coords = $hexagon['coordinates'];
		
		for ($i = 0; $i < count($coords) - 1; $i++) {
			$p1 = $coords[$i];
			$p2 = $coords[$i + 1];
			
			// Normaliser le segment
			if ($p1[0] < $p2[0] || ($p1[0] == $p2[0] && $p1[1] < $p2[1])) {
				$key = sprintf($keyFmt, $p1[0], $p1[1], $p2[0], $p2[1]);
			} else {
				$key = sprintf($keyFmt, $p2[0], $p2[1], $p1[0], $p1[1]);
			}
			
			$segmentIndex[$key] = true;
		}
	}
	
}