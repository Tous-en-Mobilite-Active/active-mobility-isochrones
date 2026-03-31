/**
 * active-mobility-isochrones — Script JavaScript standalone
 *
 * Gère la carte Leaflet, l'autocomplétion d'adresses via l'API IGN,
 * le polling du calcul asynchrone, et l'affichage des zones de résultat.
 *
 * @license MIT
 * @see https://github.com/biz-lab/active-mobility-isochrones
 */

// Constantes de couleur des zones
const COLORS = {
	WALK: '#4CAF50',
	BIKE: '#FFEB3B',
	EBIKE: '#FF9800',
	DEBUG1: '#FF0000',
	DEBUG2: '#0000FF'
};

const OPACITIES = {
	WALK: 0.5,
	BIKE: 0.4,
	EBIKE: 0.3,
	DEBUG1: 0.2,
	DEBUG2: 0.2,
};

let map;
let walkLayer, bikeLayer, ebikeLayer, debugRedLayer, debugBlueLayer;
let markerLayer;
let autocompleteTimeout;

// Initialisation de la carte avec Leaflet
function initMap() {
	map = L.map('map', {
		zoomSnap: 0.25,
		zoomDelta: 0.25,
		wheelPxPerZoomLevel: 120
	}).setView([48.1173, -1.6778], 12);

	L.tileLayer('https://{s}.tile.openstreetmap.fr/osmfr/{z}/{x}/{y}.png', {
		attribution: '&copy; OpenStreetMap contributors',
		maxZoom: 19
	}).addTo(map);
}

// Toggle du panneau de paramètres
function toggleSettings() {
	const panel = document.getElementById('settings-panel');
	panel.classList.toggle('active');
}

// Fermeture de toutes les listes d'autocomplétion
function closeAllLists() {
	const listDiv = document.getElementById('autocomplete-list');
	listDiv.classList.remove('active');
	listDiv.innerHTML = '';
}

// Géocodage de l'adresse via API IGN Géoplateforme
async function geocodeAddress(address) {
	try {
		const response = await fetch(
			`https://data.geopf.fr/geocodage/search/?q=${encodeURIComponent(address)}&limit=1`
		);
		const data = await response.json();

		if (data && data.features && data.features.length > 0) {
			const coords = data.features[0].geometry.coordinates;
			const props = data.features[0].properties;
			return {
				lon: coords[0],
				lat: coords[1],
				label: props.label
			};
		}
		return null;
	} catch (error) {
		console.error('Erreur de géocodage:', error);
		return null;
	}
}

// Affichage du message
function showMessage(text, type = 'info') {
	const messageDiv = document.getElementById('message');
	messageDiv.innerHTML = `<div class="info ${type}">${text}</div>`;
	setTimeout(() => {
		messageDiv.innerHTML = '';
	}, 5000);
}

// Soumission du formulaire
async function submitForm() {
	const address = document.getElementById('address').value;
	if (!address) {
		showMessage('Veuillez saisir une adresse.', 'error');
		return;
	}

	const latitude = document.getElementById('latitude').value;
	const longitude = document.getElementById('longitude').value;

	if (!latitude || !longitude || latitude === '0' || longitude === '0') {
		showMessage('Recherche de l\'adresse en cours...', 'info');
		const coords = await geocodeAddress(address);

		if (!coords) {
			showMessage('Adresse introuvable. Veuillez réessayer.', 'error');
			return;
		}

		document.getElementById('address').value = coords.label;
		document.getElementById('latitude').value = coords.lat;
		document.getElementById('longitude').value = coords.lon;
	}

	document.getElementById('formObj').submit();
}

// Copier l'URL de partage
function copyShareUrl() {
	const shareUrl = document.getElementById('share-url');
	shareUrl.select();
	shareUrl.setSelectionRange(0, 99999);

	try {
		navigator.clipboard.writeText(shareUrl.value).then(() => {
			showMessage('URL copiée dans le presse-papier !', 'success');
		}).catch(() => {
			document.execCommand('copy');
			showMessage('URL copiée dans le presse-papier !', 'success');
		});
	} catch (err) {
		showMessage('Erreur lors de la copie', 'error');
	}
}

// Mise à jour de la carte avec les zones calculées
async function updateMap() {
	let pageMethod = document.getElementById('pageMethod').value;
	let latitude = parseFloat(document.getElementById('latitude').value);
	let longitude = parseFloat(document.getElementById('longitude').value);
	if (!latitude || !longitude || (latitude === 0 && longitude === 0)) {
		if (pageMethod === 'post') { showMessage('Adresse introuvable. Veuillez réessayer.', 'error'); }
		return;
	}

	map.setView([latitude, longitude], 13);

	if (markerLayer) { map.removeLayer(markerLayer); }

	markerLayer = L.marker([latitude, longitude], {
		icon: L.divIcon({
			className: 'custom-marker',
			html: '<div style="background-color: #dc3545; width: 20px; height: 20px; border-radius: 50%; border: 3px solid white; box-shadow: 0 2px 5px rgba(0,0,0,0.3);"></div>',
			iconSize: [20, 20],
			iconAnchor: [10, 10]
		})
	}).addTo(map);

	if (ebikeLayer) map.removeLayer(ebikeLayer);
	if (bikeLayer) map.removeLayer(bikeLayer);
	if (walkLayer) map.removeLayer(walkLayer);
	if (debugRedLayer) map.removeLayer(debugRedLayer);
	if (debugBlueLayer) map.removeLayer(debugBlueLayer);
	const boundsArray = [];

	// Zone vélo électrique (orange, plus grande — en dessous)
	let ebikeCoords = document.getElementById('ebikeCoords').value;
	if (ebikeCoords !== '') {
		ebikeCoords = JSON.parse(ebikeCoords);
		ebikeCoords = ebikeCoords.coordinates[0].map(coord => [coord[1], coord[0]]);
		ebikeLayer = L.polygon(ebikeCoords, {
			color: COLORS.EBIKE, fillColor: COLORS.EBIKE, fillOpacity: OPACITIES.EBIKE, weight: 2
		}).addTo(map);
		boundsArray.push(ebikeLayer.getBounds());
	}

	// Zone vélo (jaune, moyenne — au milieu)
	let bikeCoords = document.getElementById('bikeCoords').value;
	if (bikeCoords !== '') {
		bikeCoords = JSON.parse(bikeCoords);
		bikeCoords = bikeCoords.coordinates[0].map(coord => [coord[1], coord[0]]);
		bikeLayer = L.polygon(bikeCoords, {
			color: COLORS.BIKE, fillColor: COLORS.BIKE, fillOpacity: OPACITIES.BIKE, weight: 2
		}).addTo(map);
		boundsArray.push(bikeLayer.getBounds());
	}

	// Zone à pied (vert, plus petite — au-dessus)
	let walkCoords = document.getElementById('walkCoords').value;
	if (walkCoords !== '') {
		walkCoords = JSON.parse(walkCoords);
		walkCoords = walkCoords.coordinates[0].map(coord => [coord[1], coord[0]]);
		walkLayer = L.polygon(walkCoords, {
			color: COLORS.WALK, fillColor: COLORS.WALK, fillOpacity: OPACITIES.WALK, weight: 2
		}).addTo(map);
		boundsArray.push(walkLayer.getBounds());
	}

	// Zones de debug
	let debugRedCoords = document.getElementById('debugRedCoords').value;
	if (debugRedCoords !== '') {
		debugRedCoords = JSON.parse(debugRedCoords);
		debugRedCoords = debugRedCoords.coordinates[0].map(coord => [coord[1], coord[0]]);
		debugRedLayer = L.polygon(debugRedCoords, {
			color: COLORS.DEBUG1, fillColor: COLORS.DEBUG1, fillOpacity: OPACITIES.DEBUG1, weight: 2
		}).addTo(map);
		boundsArray.push(debugRedLayer.getBounds());
	}
	let debugBlueCoords = document.getElementById('debugBlueCoords').value;
	if (debugBlueCoords !== '') {
		debugBlueCoords = JSON.parse(debugBlueCoords);
		debugBlueCoords = debugBlueCoords.coordinates[0].map(coord => [coord[1], coord[0]]);
		debugBlueLayer = L.polygon(debugBlueCoords, {
			color: COLORS.DEBUG2, fillColor: COLORS.DEBUG2, fillOpacity: OPACITIES.DEBUG2, weight: 2
		}).addTo(map);
		boundsArray.push(debugBlueLayer.getBounds());
	}

	if (boundsArray.length > 0) {
		const allBounds = L.latLngBounds(boundsArray);
		map.fitBounds(allBounds, { padding: [75, 75] });
	}
}

// Vérification de l'état du calcul (polling)
async function checkCalculationStatus(url) {
	try {
		const response = await fetch(url);
		const status = await response.text();
		return status.trim();
	} catch (error) {
		console.error('Erreur lors de la vérification du statut:', error);
		return null;
	}
}

// Attente de la fin du calcul avec polling
async function waitForCalculation(url, interval = 2000, maxAttempts = 150) {
	for (let attempt = 0; attempt < maxAttempts; attempt++) {
		const status = await checkCalculationStatus(url);

		if (status === 'computed') {
			await new Promise(resolve => setTimeout(resolve, 500));
			document.getElementById('formObj').submit();
			return true;
		} else {
			const progressEl = document.getElementById('calculationProgress');
			if (progressEl) { progressEl.innerText = status + '%'; }
		}
		await new Promise(resolve => setTimeout(resolve, interval));
	}

	showMessage('Le calcul prend plus de temps que prévu. La page va se recharger.', 'warning');
	await new Promise(resolve => setTimeout(resolve, 1000));
	document.getElementById('formObj').submit();
	return false;
}


// ===== INITIALISATION =====
document.addEventListener('DOMContentLoaded', function () {
	document.getElementById('mapUpdateBtn').addEventListener('click', submitForm);
	document.getElementById('settingsBtn').addEventListener('click', toggleSettings);

	const copyBtn = document.getElementById('copyShareBtn');
	if (copyBtn) copyBtn.addEventListener('click', copyShareUrl);

	const printBtn = document.getElementById('printBtn');
	if (printBtn) printBtn.addEventListener('click', function () { window.print(); });

	// Autocomplétion d'adresse
	document.getElementById('address').addEventListener('input', function () {
		clearTimeout(autocompleteTimeout);
		const val = this.value;
		document.getElementById('latitude').value = '';
		document.getElementById('longitude').value = '';
		closeAllLists();

		if (!val || val.length < 3) return;

		autocompleteTimeout = setTimeout(async () => {
			try {
				const response = await fetch(
					`https://data.geopf.fr/geocodage/search/?q=${encodeURIComponent(val)}&limit=5`
				);
				const data = await response.json();

				if (data && data.features && data.features.length > 0) {
					const listDiv = document.getElementById('autocomplete-list');
					listDiv.classList.add('active');

					data.features.forEach(item => {
						const itemDiv = document.createElement('div');
						const props = item.properties;
						const coords = item.geometry.coordinates;

						itemDiv.textContent = props.label;
						itemDiv.dataset.lat = coords[1];
						itemDiv.dataset.lon = coords[0];

						itemDiv.addEventListener('click', function () {
							document.getElementById('address').value = this.textContent;
							document.getElementById('latitude').value = this.dataset.lat;
							document.getElementById('longitude').value = this.dataset.lon;
							closeAllLists();
						});
						listDiv.appendChild(itemDiv);
					});
				}
			} catch (error) {
				console.error('Erreur autocomplétion:', error);
			}
		}, 300);
	});

	document.addEventListener('click', function (e) {
		if (e.target.id !== 'address') { closeAllLists(); }
	});

	// Initialisation de la carte
	setTimeout(async function () {
		if (typeof L !== 'undefined') {
			initMap();
			updateMap();

			const waitInput = document.getElementById('waitForTheCalculationToFinish');
			if (waitInput && waitInput.value) {
				// Déclencher le calcul en arrière-plan si l'URL est fournie
				const triggerInput = document.getElementById('triggerComputeUrl');
				if (triggerInput && triggerInput.value) {
					const formData = new FormData(document.getElementById('formObj'));
					fetch(triggerInput.value, { method: 'POST', body: formData });
				}
				// Polling du statut en parallèle
				await waitForCalculation(waitInput.value);
			} else {
				const latitude = document.getElementById('latitude').value;
				const longitude = document.getElementById('longitude').value;
				if (latitude && longitude && latitude !== '0' && longitude !== '0') {
					const pageMethod = document.getElementById('pageMethod').value;
					if (pageMethod === 'post') { showMessage('Carte mise à jour avec succès !', 'success'); }
				}
			}
		} else {
			console.error('Leaflet n\'est pas chargé');
		}
	}, 100);

	window.addEventListener('beforeprint', function () {
		document.getElementById('map').classList.add('print');
		map.invalidateSize();
	});
	window.addEventListener('afterprint', function () {
		document.getElementById('map').classList.remove('print');
		map.invalidateSize();
	});
});
