# Contribuer à Active Mobility Isochrones

Merci de votre intérêt pour ce projet ! Toutes les contributions sont les bienvenues, que vous soyez développeur, urbaniste, cycliste quotidien ou passionné de données géographiques.

## Comment contribuer

### Signaler un bug ou proposer une idée

Ouvrez une [issue](https://github.com/biz-lab/active-mobility-isochrones/issues) en décrivant :

- **Bug** : ce que vous avez fait, ce que vous attendiez, ce qui s'est passé. Si possible, indiquez l'adresse testée et les paramètres utilisés.
- **Idée / amélioration** : décrivez le besoin et, si vous avez une piste, l'approche envisagée.

### Soumettre du code

1. Forkez le dépôt
2. Créez une branche descriptive (`amelioration-pas-temporel`, `fix-contour-ile`, etc.)
3. Faites vos modifications
4. Testez avec l'exemple standalone : `php -S localhost:8000 -t exemple/`
5. Soumettez une pull request avec une description claire de vos changements

### Améliorer la documentation

Les corrections de typos, clarifications et traductions sont appréciées. Le projet étant basé sur des données françaises, la documentation reste en français.

## Prérequis techniques

- PHP >= 8.1 avec les extensions `json` et `zlib`
- Aucune dépendance externe

## Conventions

- **Langue** : code et commentaires en français
- **Style** : cohérent avec le code existant (indentation par tabulations, accolades sur la même ligne)
- **Commits** : messages concis en français, décrivant le « pourquoi » plutôt que le « quoi »
- **Une PR = un sujet** : évitez de mélanger corrections de bugs et nouvelles fonctionnalités

## Structure des fichiers

Les fichiers `src/GeoMath.php` et `src/GeoCompute.php` sont conçus pour être synchronisés avec une base de code existante. Merci de ne pas modifier leur interface publique sans discussion préalable.

## Licence

En contribuant, vous acceptez que vos contributions soient publiées sous la [licence MIT](LICENSE) du projet.
