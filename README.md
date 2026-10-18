# Gestion-Stock

Application web de gestion de stocks multi-magasins. SOC-01 fournit le socle Docker et une page d’accueil provisoire ; SOC-02 ajoute le modèle de données et les migrations explicites ; SOC-03 ajoute les contrats API et leurs outils communs ; SOC-04 fournit l’interface française ; AUTH-01 active la connexion, les sessions et le profil. Les fonctionnalités métier sont détaillées dans [suivi.md](suivi.md). Aucun catalogue ni compte utilisateur n’est créé automatiquement.

## Prérequis

Docker Engine avec Docker Compose v2 récent, ou Docker Desktop. Aucun PHP, Composer, PostgreSQL ou serveur web à installer sur l’hôte. Les premières constructions nécessitent Internet. Les images officielles sont verrouillées par empreinte multiarchitecture (amd64/arm64), et les dépendances PHP par `composer.lock`.

Choix : PHP 8.4, Symfony 8.1, PostgreSQL 18, Nginx, Messenger avec transport PostgreSQL, Scheduler. Les images de runtime utilisent Alpine ; leurs paquets système reçoivent les correctifs disponibles à la construction. Symfony 8.1 est retenu dans la famille 8.x du CDC ; ses exigences PHP sont satisfaites par PHP 8.4. Les versions réellement installées peuvent être consultées avec les commandes ci-dessous. Les mises à jour doivent modifier les verrous et repasser les tests et l’audit des images.

## Premier démarrage

Depuis le répertoire du dépôt, préparer la configuration locale et des secrets aléatoires, sans les afficher :

```sh
docker run --rm --mount "type=bind,source=$(pwd),target=/workspace" -w /workspace composer:2@sha256:af98f42dfff7c68ba8d53c2164fd9fde1087b7d449514baa38c418b1f6bc4bac php docker/setup.php
docker compose config --quiet
docker compose up -d --build --wait
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
```

Ouvrir [l’application locale](http://127.0.0.1:8080). L’accès demande une connexion. Créer le premier compte avec la commande documentée dans le [guide d’authentification](docs/authentification.md), qui demande le mot de passe en saisie masquée. Le fichier `.env` peut modifier le port si nécessaire. Le script de préparation ne remplace jamais les secrets existants. Le répertoire `.secrets` est privé sur l’hôte ; ses fichiers sont lisibles par les différents UID des conteneurs auxquels ils sont explicitement montés. Ils ne sont pas intégrés aux images ni versionnés. Les secrets Compose locaux ne constituent pas un coffre chiffré ; protéger leur stockage et utiliser le mécanisme adapté lors du déploiement.

Le service ponctuel `init` prépare seulement les tables techniques Messenger. La commande de migration ci-dessus installe le schéma métier de SOC-02 explicitement. PostgreSQL initialise un compte applicatif distinct, sans privilèges superutilisateur, et conserve ses données dans un volume dédié. La première initialisation fixe ses identifiants : changer un fichier secret ne change pas automatiquement un mot de passe dans une base existante.

Modèle et diagramme : [docs/modele-donnees.md](docs/modele-donnees.md). Installation, tests isolés et retour arrière protégé : [docs/migrations.md](docs/migrations.md). Contrôler les soldes sans modifier les données :

```sh
docker compose exec app php bin/console app:stock:reconcile
```

Contrats et protections API : [docs/api.md](docs/api.md). Le [document OpenAPI local](http://127.0.0.1:8080/api/v1/openapi.json) distingue les trois routes techniques disponibles des opérations métier encore à développer.

## Services et réseau

| Service | Rôle | Persistance / santé |
|---|---|---|
| `web` | HTTP local Nginx, seul port publié sur 127.0.0.1:8080 | Sonde HTTP `/health/live` |
| `app` | Symfony via PHP-FPM | Cache et logs temporaires ; sonde FastCGI ping |
| `db` | PostgreSQL privé | Volume `postgres_data`, sonde `pg_isready` |
| `init` | Préparation explicite du transport durable | Se termine avec succès avant worker/scheduler |
| `worker` | Consommation asynchrone Messenger | File dans PostgreSQL ; battement de vie |
| `scheduler` | Exécution du calendrier Symfony | Sonde technique chaque minute ; battement de vie |
| `test` | PHPUnit et outils, profil optionnel | Exécution ponctuelle en conteneur |

Les réseaux `frontend` et `backend` sont internes. Le serveur web rejoint aussi le réseau d’accès `edge` pour publier son port local ; les services applicatifs rejoignent `outbound` pour le futur SMTP externe. Aucun port de base, de PHP-FPM ou de worker n’est publié. L’application (UID 10001) et le serveur web tournent sans root, sans capacités Linux, avec système de fichiers en lecture seule et zones temporaires dédiées. PostgreSQL conserve son entrypoint officiel, utilise `su-exec` pour abandonner les privilèges vers son utilisateur interne et ne garde que les capacités nécessaires à l’initialisation. Son encodage initial est UTF-8, locale C.

Les workers reçoivent les signaux via un processus init, terminent leur travail avant arrêt et sont relancés automatiquement. Ils se recyclent après une heure. Leur sonde mesure la boucle de travail, pas la livraison d’un courriel. Les sondes HTTP exposent uniquement un statut : `/health/live` contrôle la vie de Symfony ; `/health/ready` retourne 503 si PostgreSQL ne répond pas. Aucun secret ni détail d’exception n’est renvoyé.

La sonde planifiée est purement technique ; le récapitulatif quotidien à 08 h sera implémenté dans MAIL-03. L’envoi SMTP et les modèles métier appartiennent à MAIL-01.

## Tests et vérifications

```sh
docker compose --profile test build test contract-test
docker compose run --rm test
docker compose run --rm test composer validate
docker compose run --rm test composer audit
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:yaml config
docker compose exec app php bin/console about
docker compose ps
```

Les tests vérifient le rendu, PostgreSQL, les contraintes/migrations, les outils API, les protections CSRF/cookies/débit et le parcours de connexion, profil, expiration et déconnexion. Le test de file utilise une queue dédiée `soc01_test`, distincte de la queue métier `async`. Le test de schéma utilise des transactions isolées. Le service de test dépend de `contract-test`, qui valide OpenAPI et JSON Schema dans un conteneur sans réseau et sans accès à la base. Python et ses dépendances verrouillées restent dans cette cible de test, sans installation sur l’hôte ni dans l’image applicative.

Composer signale l’absence de licence : ce choix reste volontairement ouvert dans DOC-01 ; aucune licence n’est attribuée sans décision du propriétaire. La validation du schéma reste possible avec cet avertissement. Rapports : [SOC-01](docs/validation-soc01.md), [SOC-02](docs/validation-soc02.md), [SOC-03](docs/validation-soc03.md).

## Arrêt, diagnostic et persistance

```sh
docker compose logs --tail=100 web app worker scheduler
docker compose exec app php bin/console messenger:stats
docker compose exec app php bin/console debug:scheduler
docker compose down
docker compose up -d --wait
```

`down` conserve les volumes ; ne pas ajouter `--volumes` pour un redémarrage normal. Les caches se reconstruisent, la base et la file sont conservées. Un volume n’est pas une sauvegarde : procédures complètes et restauration relèvent d’OPS-01. Ne pas supprimer les secrets en conservant la base.

Vérifier la persistance sans données métier avec une sonde dans une queue dédiée :

```sh
docker compose exec app php docker/verify-storage.php write
docker compose down
docker compose up -d --wait
docker compose exec app php docker/verify-storage.php check
```

Après modification du code : `docker compose up -d --build --wait`. Le code est embarqué dans les images ; aucun bind mount de code n’est nécessaire au fonctionnement. Les dépendances de compilation sont absentes du runtime final, Composer et PHPUnit restent dans la cible de test.

## Avant production

La configuration fournie est locale, en HTTP sur loopback. Le domaine et le reverse proxy restent à préciser ; OPS-02 ajoutera la configuration HTTPS de production et les contrôles correspondants. Ne pas publier cet environnement directement sur Internet. Les paramètres SMTP, sauvegardes, supervision, anonymisation et permissions métier restent couverts par leurs lots dédiés.

Références : [exigences Symfony](https://symfony.com/doc/8.1/setup.html), [version Symfony 8.1](https://symfony.com/releases/8.1), [persistance PostgreSQL 18](https://docs.docker.com/guides/postgresql/immediate-setup-and-data-persistence/), [cahier des charges](cdc.md).

## Interface utilisateur (SOC-04)

Le tableau de bord en français est disponible sur [l’application locale](http://127.0.0.1:8080). Interface adaptée aux mobiles, menu Stimulus et composants Twig partagés. La connexion est requise ; le rôle authentifié alimente les menus. Le sélecteur de magasin et les modules métier attendent leurs lots respectifs. Voir le [guide de l’interface](docs/interface.md) et le [rapport de validation](docs/validation-soc04.md).

## Connexion, sessions et profil (AUTH-01)

Formulaire `/connexion`, consultation et fuseau sur `/profil`, déconnexion POST. Sessions limitées à 30 minutes d’inactivité et 12 heures absolues, mots de passe Argon2id, limitation des tentatives commune interface/API et audit sans secrets. Les quatre opérations API de connexion, déconnexion et profil sont disponibles. [Guide et premier administrateur](docs/authentification.md) · [Recette](docs/validation-auth01.md).

Pour essayer l’interface localement : [administrateur de test réservé au mode développement](docs/administrateur-test.md).

## Administration des comptes (AUTH-02)

Le menu **Administration** permet aux administrateurs de créer et modifier les comptes, leurs rôles et leurs affectations, puis de désactiver/réactiver les accès. Les anciennes sessions sont invalidées. [Guide](docs/administration-comptes.md) · [Recette](docs/validation-auth02.md). Appliquer la migration `Version20261018120000` avant la mise en service.

## Rôles et accès aux magasins (AUTH-03)

Le sélecteur **Magasin** propose les magasins autorisés et conserve le contexte choisi. Les permissions sont vérifiées côté serveur indépendamment de ce choix. Les magasins désactivés restent consultables uniquement par les administrateurs ; les utilisateurs conservent leurs lectures et retours personnels hors affectation pour une origine active. [Règles et intégration](docs/controle-acces.md) · [Recette](docs/validation-auth03.md).

## Gestion des magasins (MAG-01)

Le menu **Magasins** permet aux administrateurs de créer, modifier, désactiver et réactiver les magasins. Les autres rôles disposent d’une liste filtrée. Un inventaire en cours doit être terminé ou annulé avant désactivation ; les données et historiques sont conservés. Appliquer `Version20261018130000` avant utilisation. [Guide](docs/gestion-magasins.md) · [Recette](docs/validation-mag01.md).

## Catalogue des articles (ART-01)

Le menu **Articles** permet de consulter le catalogue et les catégories. Les administrateurs peuvent créer et modifier les articles, conserver leur référence et les archiver. Le statut d’une catégorie s’applique à toute sa branche et à tous ses articles, y compris les articles auparavant désactivés lors d’une réactivation. Appliquer `Version20261018140000` avant utilisation. [Guide](docs/catalogue.md) · [Recette](docs/validation-art01.md).

## Propositions du catalogue (ART-02)

Depuis **Articles → Propositions du catalogue**, les gestionnaires proposent des créations et modifications. L’administrateur compare les valeurs puis approuve ou refuse avec un motif. Le catalogue change uniquement après approbation ; doublons et versions périmées sont contrôlés. Appliquer `Version20261018150000` avant utilisation. [Guide](docs/propositions.md) · [Recette](docs/validation-art02.md).

## Consultation des stocks (STK-01 / ART-03)

Développement en cours de validation : le menu Stocks et la fiche article proposent les soldes et totaux sur les seuls magasins autorisés, y compris les articles sans solde à zéro. La recette Docker reste à exécuter avant livraison. [Guide](docs/stocks.md) · [État de validation](docs/validation-stk01.md).
