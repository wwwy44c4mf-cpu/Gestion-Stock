# Gestion-Stock

Application française de gestion de stocks multi-magasins pour les interventions techniques : catalogue commun, entrées, consommations, retours, transferts, inventaires, alertes, statistiques et audit. Trois rôles : administrateur, gestionnaire et utilisateur. Quantités entières, unité unique « unité ».

Le MVP est intégré à main et sa recette finale est réussie ; aucune mise en production n’est déclarée. La [réinitialisation sécurisée du mot de passe](docs/reinitialisation-mot-de-passe.md) utilise un lien temporaire envoyé par courriel. [État des lots et PR](suivi.md) · [Historique](CHANGELOG.md). **Code propriétaire, tous droits réservés** : [LICENSE](LICENSE).

## Documentation

| Besoin | Guide |
| --- | --- |
| Prendre en main l’application | [Guide utilisateur](docs/guide-utilisateur.md) |
| Configurer et gérer | [Guide administrateur](docs/guide-administrateur.md) et [permissions](docs/controle-acces.md) |
| Présenter avec des données fictives | [Démonstration](docs/demonstration.md) |
| Comprendre et intégrer | [Architecture](docs/architecture.md), [modèle](docs/modele-donnees.md), [API](docs/api.md), [OpenAPI](docs/openapi.json) |
| Déployer, maintenir, restaurer | [Fiche de livraison](docs/livraison.md), [bilan de finalisation](docs/finalisation-mvp.md) et [production HTTPS](docs/deploiement-production.md) |
| Installer avec Portainer et Cloudflared | [Home Assistant OS / Portainer](docs/installation-portainer.md) |
| Scanner des articles avec un smartphone | [Codes-barres et QR codes](docs/lecture-codes.md) |
| Développer, tester, signaler un problème | [Contribution](CONTRIBUTING.md), [recette](docs/recette-qa01.md), [sécurité](SECURITY.md) |

## Prérequis

Docker Engine avec Compose >= 2.24.4, ou Docker Desktop compatible ; Internet pour les premières constructions, espace disque pour images et volumes. Aucun PHP, Composer, PostgreSQL ou serveur web à installer sur l’hôte. Les recettes pilotées par les scripts demandent aussi un shell POSIX et Python 3.

PHP 8.4, Symfony 8.1, Twig/Stimulus, DBAL/PostgreSQL 18, Nginx, Messenger et Scheduler. Les images sont verrouillées par empreinte multiarchitecture ; `composer.lock` fixe les dépendances PHP. Le code est embarqué dans les images, sans montage du code de travail à l’exécution. Les versions effectives se consultent avec `docker compose exec app php bin/console about` ; reconstruire et tester après modification.

## Premier démarrage

Depuis une **copie neuve** du dépôt, préparer la configuration locale et les secrets aléatoires, sans les afficher :

```sh
docker run --rm --user "$(id -u):$(id -g)" --mount "type=bind,source=$(pwd),target=/workspace" -w /workspace composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac php docker/setup.php
docker compose config --quiet
docker compose up -d --build --wait
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm --no-deps app php bin/console app:user:create-admin administrateur@example.test "Administrateur"
```

La dernière commande demande le mot de passe en saisie masquée ; utiliser son adresse réelle pour l’installation utile. Ouvrir [la connexion locale](http://127.0.0.1:8080/connexion). Aucun catalogue ni compte n’est créé au démarrage. Pour les cinq comptes fictifs et les vingt articles, suivre **à la place de la création du premier administrateur** [la démonstration dédiée](docs/demonstration.md). Un [administrateur de test seul](docs/administrateur-test.md) reste disponible en mode développement.

Le script de préparation ne remplace jamais les secrets existants. `.secrets/` est privé sur l’hôte et exclu de Git et des images. Les fichiers sont lisibles par les identités des conteneurs auxquelles ils sont montés ; ces secrets Compose sur fichiers ne constituent pas un coffre chiffré. Ne pas copier ceux d’un autre environnement. Modifier un fichier de mot de passe ne modifie pas un rôle déjà initialisé dans PostgreSQL.

`.env` contrôle le nom de projet et le port. Le mode local par défaut est `prod`, debug désactivé, HTTP seulement sur `127.0.0.1:8080` et courriels désactivés. **Ce mode ne remplace pas une configuration de production HTTPS.** Les migrations sont explicites ; le service `init` prépare seulement les tables techniques Messenger. [Migrations et retour arrière](docs/migrations.md).

## Utilisation

Créer magasins, comptes et affectations, puis catégories/articles et entrées initiales. Les gestionnaires proposent les changements du catalogue ; l’administrateur les valide. Les utilisateurs consomment pour eux-mêmes et rendent leurs articles ; les gestionnaires et administrateurs interviennent dans leur périmètre. Un inventaire en cours gèle les mouvements du magasin. [Guide utilisateur](docs/guide-utilisateur.md) · [Guide administrateur](docs/guide-administrateur.md).

Les courriels restent désactivés jusqu’à configuration du SMTP, de l’expéditeur et de l’URL publique. Ensuite les alertes sont immédiates et le rappel quotidien arrive à 08 h Europe/Paris, avec rattrapage du jour. [SMTP et reprises](docs/courriels.md) · [Destinataires](docs/alertes-et-destinataires.md).

## Services et persistance

| Service | Rôle |
| --- | --- |
| `web`, `app` | Nginx et Symfony/PHP-FPM ; seul le HTTP loopback est publié localement |
| `db` | PostgreSQL privé, rôle applicatif distinct du superutilisateur, volume durable |
| `init` | Préparation ponctuelle de Messenger avant les consommateurs |
| `worker`, `scheduler` | Notifications et tâches planifiées ; files durables en base |
| `test`, `contract-test` | Profils de test, PHP et OpenAPI |
| `backup` | Profil facultatif de sauvegarde chiffrée après configuration des clés |

L’architecture sépare les réseaux web/base et l’accès sortant SMTP ; le runtime applicatif et Nginx utilisent des utilisateurs sans privilèges, systèmes de fichiers en lecture seule et zones temporaires. Les sondes publiques exposent seulement un état minimal. [Architecture](docs/architecture.md).

## Tests et diagnostic

```sh
sh bin/test-qa core
sh bin/test-installation
sh bin/diagnostic
docker compose exec app php bin/console app:stock:reconcile
```

Les deux recettes créent leurs propres secrets/bases fictives, puis nettoient uniquement leurs installations temporaires. `core` couvre métier, droits, concurrence, RGPD et performances. `full` ajoute sauvegarde/restauration, HTTPS et incidents : `sh bin/test-qa full`. Les tests privilégiés RGPD n’utilisent pas le secret administrateur de la base de travail. Les rapports restent dans `var/qa/` et `var/delivery/`, ignorés par Git. [Méthode](docs/recette-qa01.md) · [Validation QA](docs/validation-qa01.md).

Pour les dépendances et la configuration :

```sh
docker compose --profile test build test contract-test
docker compose run --rm --no-deps test composer validate --strict
docker compose run --rm --no-deps test composer audit
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:yaml config
```

Les tests PHP peuvent aussi être lancés via `docker compose run --rm test` ; les tests privilégiés exigent le banc isolé pour être exécutés. Ne pas considérer une exclusion de test comme une preuve de réussite.

## Arrêt, mise à jour et dépannage

```sh
docker compose ps
docker compose logs --tail=100 web app worker scheduler
docker compose down
docker compose up -d --wait
```

`down` conserve les volumes ; ne pas ajouter `--volumes` pour un redémarrage normal. Un volume n’est pas une sauvegarde. Après modification du code local : `docker compose up -d --build --wait`, puis appliquer les migrations si nécessaire. En production utiliser la procédure dédiée avec sauvegarde préalable.

| Symptôme | Vérification |
| --- | --- |
| Page inaccessible | `docker compose ps`, disponibilité du port de `.env`, journaux web/app |
| Erreur après installation | Vérifier les migrations et les secrets ; ne pas supprimer le volume |
| Connexion refusée | Compte actif, environnement du compte de démo, limitation temporaire ; ne pas publier le mot de passe |
| Mouvement refusé | Droits, magasin/article actifs, quantité disponible et gel d’inventaire |
| Courriels absents | Activation SMTP, expéditeur, file et état de livraison ; [diagnostic](docs/supervision-diagnostic.md) |
| Accès bloqué après restauration | Retraitement de conservation requis ; [reprise](docs/sauvegardes-restauration.md) |

## Préparer la production

Serveur unique Docker Compose avec Caddy intégré et HTTPS. Sauvegardes quotidiennes chiffrées, rétention 90 jours, copie hors serveur à choisir/configurer ; risque de perte jusqu’à environ 24 h accepté, cible de remise en service un jour ouvré. Conservation nominative à un an, avec retraitement obligatoire après restauration. [Sauvegardes](docs/sauvegardes-restauration.md) · [Conservation](docs/conservation-donnees.md).

Avant ouverture : renseigner serveur/domaine, SMTP, destination extérieure, responsables et surveillance ; relire la sécurité, valider la recette du commit livré puis suivre [la fiche de livraison](docs/livraison.md). Aucune de ces actions n’est réalisée automatiquement par une PR.
