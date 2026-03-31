# Active Mobility Isochrones

Outil de calcul des zones géographiques accessibles plus rapidement en **mobilité active** (marche, vélo, vélo électrique) qu'en **voiture**.

Ce projet est issu de [tous-en-mobilite-active.fr](https://tous-en-mobilite-active.fr/), une initiative visant à promouvoir la mobilité active au quotidien. Vous pouvez tester l'outil directement en ligne : [tous-en-mobilite-active.fr/rapidite](https://tous-en-mobilite-active.fr/rapidite)

## Présentation

Cet outil permet de visualiser sur une carte les zones autour d'une adresse donnée où il est plus rapide de se déplacer à pied, à vélo ou à vélo électrique plutôt qu'en voiture. Il prend en compte :

- Les **vitesses réelles** de déplacement (personnalisables)
- Les **pénalités temporelles** de chaque mode : temps de stationnement, temps d'accès au véhicule, temps pour détacher/rattacher un vélo
- Les **données routières et piétonnes** de l'IGN (Institut Géographique National) via l'API Géoplateforme

## Aperçu rapide

Les zones colorées sur la carte représentent :

- **Vert** : plus rapide à pied qu'en voiture
- **Jaune** : plus rapide à vélo qu'en voiture
- **Orange** : plus rapide à vélo électrique qu'en voiture

## Installation et utilisation

### Prérequis

- PHP >= 8.1 avec les extensions `json` et `zlib`
- Un navigateur web moderne

### Lancement rapide

```bash
git clone https://github.com/biz-lab/active-mobility-isochrones.git
cd active-mobility-isochrones
php -S localhost:8000 -t exemple/
```

Ouvrez ensuite [http://localhost:8000](http://localhost:8000) dans votre navigateur.

### Intégration dans un projet existant

```bash
composer require biz-lab/active-mobility-isochrones
```

```php
use ActiveMobilityIsochrones\GeoManage;

// Créer l'instance avec les répertoires de cache
$app = new GeoManage('/chemin/vers/cache/geoData', '/chemin/vers/cache/geoCache');

// Lire les paramètres et lancer le calcul
$app->readParameters($_POST);
$app->run();

// Accéder aux résultats
$app->getStatus();     // 'computed', 'computing' ou ''
$app->getWalkCoords(); // GeoJSON du polygone marche
$app->getBikeCoords(); // GeoJSON du polygone vélo
$app->getEbikeCoords();// GeoJSON du polygone VAE
```

## Structure du projet

```
active-mobility-isochrones/
├── src/
│   ├── GeoMath.php         # Grille hexagonale, contours, géométrie
│   ├── GeoCompute.php      # Classe abstraite : algorithme de calcul des zones
│   └── GeoManage.php       # Implémentation : cache fichier, paramètres, run, timeout
├── exemple/
│   ├── index.php           # Page HTML (appelle GeoManage + affichage inline)
│   ├── ajaxEndPoint.php    # Endpoint AJAX pour le polling de progression
│   ├── computeEndPoint.php # Endpoint de calcul (déclenché par le JS)
│   ├── script.js           # JavaScript (Leaflet, autocomplétion, polling)
│   └── style.css           # Styles CSS
├── CONTRIBUTING.md
├── composer.json
├── LICENSE                 # MIT
└── README.md
```

## Notes de conception

### 1. Problématique et approche générale

Estimer les zones accessibles plus rapidement en mobilité active (marche, vélo, vélo électrique) qu'en voiture revient à chercher les frontières au-delà desquelles le temps d'accès en voiture devient inférieur à celui en mobilité active.

Ce calcul est nécessairement dichotomique : les données disponibles sont éparses, les temps de trajet par type de mobilité pour des points donnés, et non des fonctions continues pour lesquelles on pourrait résoudre mathématiquement la frontière en cherchant `TempsDeTrajetVoiture(x, y) = TempsDeTrajetMobilitéActive(x, y)`.

#### Choix entre requêtes isochrones et point-à-point

Les données de temps de trajet peuvent être recueillies sous deux formes :

- **Point-à-point** : temps de trajet entre deux coordonnées pour une mobilité donnée
- **Isochrone** : polygone des zones accessibles pour une mobilité et une durée déterminée

Pour une même résolution, le volume de requêtes en mode point-à-point évolue **au carré** du rayon de la surface d'investigation, alors que le volume de requêtes en mode isochrone reste **linéaire** par rapport au rayon.

Pour fixer les ordres de grandeur, le rayon typique de la zone plus rapidement accessible en mobilité active qu'en voiture est de :

- 0,5 à 1 km à pied
- 2 à 5 km à vélo
- 3 à 10 km à vélo électrique

La zone de recherche des frontières s'étend ainsi sur une surface de 30 à 300 km².

Concrètement, pour résoudre les 3 frontières avec une résolution standard, l'ordre de grandeur est de **~50 requêtes isochrones** contre **~3 000 requêtes point-à-point**.

Le choix final dépend du temps de réponse respectif de chaque type de requête, mais a priori appeler 3 000 requêtes à un système externe ne semble pas la meilleure approche — d'autant que ces données dépendent du point de départ et ne sont pas réutilisables d'un utilisateur à un autre.

### 2. Choix de la source de données

Deux sources de données libres ont été identifiées pour la France : Google Maps et le service cartes.gouv.fr (anciennement Géoportail, API IGN Géoplateforme).

#### Google Maps

**Avantages** :

- Calcul de temps de trajet disponible pour voiture, vélo et piéton.
  
- Fiabilité a priori des données, au minimum pour la voiture.
  
- Accessibilité et richesse de l'API.
  

**Inconvénients** :

- Pas de calcul d'isochrone, uniquement des calculs point-à-point.
  
- Données piétonnes de fiabilité moyenne dans nos tests : rues faussement non accessibles aux piétons et itinéraires à rallonge.
  

**Temps de réponse** : ~0,3 s par requête

**Limites** : 10 000 requêtes gratuites / mois

#### cartes.gouv.fr (IGN Géoplateforme)

**Avantages** :

- Service français légitime.
  
- Disponibilité de calculs d'isochrone (voiture, piéton) et d'isodistance.
  

**Inconvénients** :

- Le calcul de temps de trajet piéton est minimaliste : il se limite à un calcul de distance avec une vitesse fixe de 4 km/h, indépendamment du relief et de l'environnement (ex : nombre de rues à traverser). Le polygone obtenu par l'API isochrone piéton pour 1 h de marche est identique à celui obtenu par l'API isodistance piéton pour 4 km.
  
- Pas de profil vélo ou vélo électrique, mais contournable en utilisant le profil piéton avec une distance ajustée.
  

**Temps de réponse** : ~0,4 s par requête

**Limites** : 5 requêtes / seconde

Compte tenu de ces caractéristiques, le plus simple pour obtenir les isochrones à pied, à vélo et à vélo électrique est de demander le polygone d'**isodistance piéton** en calculant en amont la distance équivalente au temps et à la vitesse souhaités.

#### Décision

Le service cartes.gouv.fr a été privilégié pour :

- sa **rapidité globale** (nombre de requêtes × temps de réponse, grâce au mode isochrone)
- sa **gratuité**
- sa **légitimité nationale**

### 3. Stratégie de calcul : la grille hexagonale

Les données d'entrée étant des isochrones sous forme de polygones (coordonnées des sommets), il serait tentant d'utiliser directement des opérations géométriques entre polygones (union, soustraction). Cependant, nos tentatives avec les algorithmes classiques d'opérations sur polygones ont été un échec : les moindres erreurs d'arrondi sur des surfaces tangentes aboutissent à des résultats non attendus, et le temps de calcul croît trop rapidement.

Pour s'affranchir de ces problèmes, la stratégie adoptée est la suivante :

1. Définir une **grille** centrée sur l'adresse de départ
2. **Convertir** les polygones d'isochrone en zonage (liste de cellules de la grille)
3. **Effectuer les opérations** (union, soustraction) sur le zonage, ce qui est instantané

Pour le zonage, une **grille d'hexagones** a été choisie plutôt que des carrés. Les carrés ont l'inconvénient de créer des ambiguïtés lorsqu'ils ne sont joints que par un angle (connexité diagonale). Dans une grille d'hexagones, deux cellules adjacentes partagent toujours une arête commune, ce qui simplifie les calculs — notamment pour retransformer le zonage final en polygone d'affichage : il suffit de collecter toutes les arêtes des hexagones et de supprimer celles qui apparaissent en double pour obtenir le contour extérieur.

### 4. Algorithme itératif

#### Paramètres d'entrée

- Coordonnées du point de départ
- Vitesse moyenne de déplacement : à pied, à vélo, à vélo électrique
- Délai de pénalité vélo : temps supplémentaire pour détacher/rattacher son vélo par rapport à un déplacement à pied
- Délai de pénalité voiture : temps supplémentaire pour rejoindre son véhicule et se garer par rapport à un déplacement à pied

#### Déroulement pour chaque mobilité active

**1. Initialisation** — L'algorithme commence par calculer le temps **T0** pendant lequel la mobilité active prend de l'avance sur la voiture. Ce temps correspond au délai de pénalité voiture, diminué du délai de pénalité éventuel du vélo, augmenté du temps nécessaire à la voiture pour rattraper en ligne droite la personne en mobilité active à sa vitesse moyenne. L'isochrone de mobilité active correspondant à T0 donne une première zone où la mobilité est plus rapide qu'une voiture sans aucune contrainte de déplacement.

**2. Itération** — On incrémente le temps de déplacement d'un « pas temporel ». À chaque itération *n*, avec T*n* = T0 + *n* × pas temporel :

- On demande l'**isodistance piéton** pour la mobilité étudiée pour le temps T*n*
- On demande l'**isochrone voiture** pour le temps T*n*, auquel on retranche le retard T0 ainsi que le temps nécessaire à la voiture pour parcourir un hexagone à sa vitesse estimée (ceci permet la bonne prise en compte des hexagones obtenusà égalité en mobilité active et en voiture)
- On soustrait la zone voiture de la zone mobilité pour obtenir les hexagones atteints plus vite en mobilité active
- On ajoute ces hexagones au cumul des itérations précédentes

**3. Arrêt** — Le calcul s'arrête lorsque la voiture a rattrapé la personne en mobilité active quelle que soit la direction (voir section 5 pour les conditions d'arrêt détaillées).

#### Pas temporel

La valeur du pas temporel est un compromis entre la **précision** de la carte finale et le **temps de calcul** engendré.

La taille des hexagones de la grille correspond à la distance parcourue en ligne droite en un pas temporel dans la mobilité active concernée. Grossièrement, à chaque itération on évalue si l'on peut ajouter un hexagone de plus à la frontière déjà établie.

Le temps de calcul est doublement impacté par le pas temporel. Lorsqu'on le réduit :

- Le nombre d'itérations croît **linéairement** (la somme des pas devant dépasser le temps nécessaire pour atteindre le point le plus éloigné accessible en mobilité active)
- Le nombre d'hexagones de la grille croît **au carré**, impactant directement le temps de conversion entre polygones et zonage

La valeur du pas est ajustée en fonction de la **vitesse estimée de la voiture** dans la zone. Moins la voiture est rapide, plus la zone accessible en mobilité active est grande, et plus un pas large est justifié. Concrètement, on appelle l'isochrone voiture pour 10 minutes, on mesure la distance au point le plus éloigné atteint, et on en déduit une vitesse à vol d'oiseau. En fonction de cette vitesse, on fixe le pas temporel et donc la taille des hexagones :

| Mobilité | Voiture < 20 km/h | 20–30 km/h | > 30 km/h |
| --- | --- | --- | --- |
| **Marche** | 90 s (~100 m) | 75 s (~83 m) | 60 s (~67 m) |
| **Vélo** | 120 s (~400 m) | 105 s (~350 m) | 90 s (~300 m) |
| **VAE** | 180 s (~850 m) | 150 s (~700 m) | 120 s (~570 m) |

### 5. Cas particuliers et contournements

#### Conditions d'arrêt

L'algorithme utilise quatre conditions d'arrêt, évaluées dans l'ordre à chaque itération :

**Condition 1 — La voiture a rattrapé la mobilité.**
Si toutes les zones précédemment gagnées par la mobilité sont désormais couvertes par l'isochrone voiture, le calcul s'arrête. C'est la condition principale : la voiture a doublé dans toutes les directions.

**Condition 2 — La voiture ne gagne plus de terrain.**
Si la voiture a déjà commencé à rattraper des zones de mobilité, mais qu'à l'itération courante elle ne gagne aucune nouvelle zone par rapport au tour précédent, le calcul s'arrête. Cela couvre le cas des zones exclusivement piétonnes (parcs, sentiers) que la voiture ne pourra jamais atteindre.

**Condition 3 — Stagnation de la mobilité.**
Si la mobilité active ne gagne aucune nouvelle zone pendant 2 tours consécutifs, le calcul s'arrête. Cela évite de continuer inutilement quand la mobilité n'a plus de territoire à gagner (front de mer, montagne).

**Condition 4 — Garde-fou temporel.**
Chaque mobilité a un temps maximum au-delà duquel le calcul s'arrête même si aucune condition précédente n'est remplie :

- Marche : 20 minutes
- Vélo : 40 minutes
- Vélo électrique : 60 minutes

#### Zones infranchissables en voiture (îles, etc.)

Les paramètres d'appel des isodistances piétonnes ne permettent pas d'interdire l'accès à une île par bateau, alors que ce trajet n'est pas possible en voiture. Pour éviter ce biais dans les données reçues, une nouvelle zone n'est ajoutée au résultat cumulé que si elle est **mitoyenne** (partage au moins une arête) avec une zone déjà acquise. Les zones disjointes — comme une île accessible uniquement par ferry — sont ainsi automatiquement exclues.

#### Gestion du zonage aux bordures

Un hexagone dont le centre est en dehors du polygone d'isochrone peut quand même être inclus s'il le chevauche suffisamment le polygone à zoner. La méthode teste 13 points (centre + 6 sommets + 6 milieux d'arêtes) et inclut l'hexagone si au moins 5 points sur 13 (> 33 %) sont dans le polygone. Cela évite de perdre les zones en bordure.

#### Optimisation du calcul de zonage

À chaque itération, la fonction de conversion polygone → hexagones reçoit en entrée les hexagones de l'itération précédente. Chaque nouvelle isochrone reçue étant par construction de l'algorithme plus grande que la précédente, les hexagones déjà validés ne sont pas retestés : seuls les nouveaux hexagones situés dans la zone élargie sont évalués. Cela réduit considérablement le coût de calcul.

#### Cache à deux niveaux

- **Cache API** (geoData) : les réponses de l'API IGN sont mises en cache individuellement pendant 3 heures. Deux calculs proches dans le temps et l'espace peuvent réutiliser les mêmes données API.
- **Cache résultat** (geoCache) : le résultat complet du calcul est mis en cache pendant 15 jours pour permettre le partage via URL.

#### Mécanisme anti-timeout et calcul asynchrone

Si le calcul dure plus de 30 secondes, l'état courant est sauvegardé en cache et une nouvelle requête asynchrone est lancée pour poursuivre le calcul. L'état complet (zonage cumulé, position dans la boucle, variables intermédiaires) est sérialisé, permettant une reprise exacte. Côté client, un polling AJAX vérifie la progression toutes les 2 secondes.

## Feuille de route

Points à améliorer :

### Améliorer les temps de calcul

- **Affiner le pas temporel adaptatif** — Le pas temporel est aujourd'hui réglé par paliers en fonction de la vitesse estimée de la voiture. Un ajustement plus fin (continu ou par zone) permettrait de réduire le nombre d'itérations sans perte de précision.
- **Optimiser l'algorithme principal de zonage** — Actuellement, polygonToGrid teste tous les hexagones du rectangle englobant le polygone, dont une partie significative est en dehors. Une approche par expansion de proche en proche serait plus efficace : partir d'un hexagone connu comme inclus (par exemple celui contenant le centre du polygone), puis à chaque hexagone validé, ajouter ses 6 voisins dans une file de candidats à tester. Cela limiterait les tests d'inclusion aux hexagones réellement proches du polygone, en évitant de parcourir les zones vides du rectangle englobant.
- **Optimiser les algorithmes de zonage** — Certaines opérations (conversion polygone → grille, reconstruction du contour) ont une complexité qui croît avec le nombre d'hexagones. Identifier et optimiser les goulots d'étranglement permettrait de traiter des grilles plus fines.
- **Paralléliser les appels API** — Les requêtes isochrone mobilité et isochrone voiture de chaque itération sont aujourd'hui séquentielles. Les exécuter en parallèle réduirait le temps d'attente réseau de moitié à chaque itération.

### Améliorer la précision des résultats

- **Prendre en compte le relief** — Adapter la vitesse de déplacement en mobilité active en fonction du dénivelé (données altimétriques IGN). Un cycliste monte plus lentement et descend plus vite, ce qui déforme la zone accessible par rapport au modèle actuel à vitesse constante.
- **Adapter la vitesse à la densité urbaine** — En zone urbaine dense, la vitesse effective en mobilité active est réduite (feux, passages piétons, encombrements). Moduler la vitesse en fonction de la densité urbaine affinerait les résultats.
- **Variabiliser les pénalités selon le contexte urbain** — Le temps de stationnement voiture varie fortement entre centre-ville dense et zone périurbaine. Ajuster automatiquement les pénalités en fonction de la densité urbaine de l'adresse de départ rendrait les valeurs par défaut plus réalistes.
    

## Sources de données

- **Géocodage** : [IGN Géoplateforme](https://geoservices.ign.fr/documentation/services/services-geoplateforme/geocodage) pour l'autocomplétion des adresses
- **Isochrones/Isodistances** : [IGN Géoplateforme](https://geoservices.ign.fr/services-geoplateforme-itineraire) pour le calcul des zones accessibles
- **Cartographie** : [OpenStreetMap](https://openstreetmap.org) pour le fond de carte

## Contribuer

Les contributions sont les bienvenues ! Consultez le [guide de contribution](CONTRIBUTING.md) pour les détails, ou en résumé :

1. Nous contacter à contact@tous-en-mobilite-active.fr
2. Ouvrir une **issue** pour signaler un bug ou proposer une amélioration
3. Soumettre une **pull request** avec vos modifications
4. Participer aux **discussions** sur les choix de conception

## Licence

Ce projet est sous licence [MIT](LICENSE).
