# Migrations et retour arrière

Les migrations sont explicites et transactionnelles sous PostgreSQL. Le service `init` de SOC-01 continue de préparer uniquement Messenger ; aucun démarrage web ni worker ne modifie implicitement le schéma métier.

## Installation et mise à jour

Après préparation des secrets et construction décrites dans le README :

```sh
docker compose run --rm --no-deps app php bin/console doctrine:migrations:status
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm --no-deps app php bin/console app:stock:reconcile
```

La base doit être démarrée et saine. Exécuter une seule opération de déploiement/migration à la fois. Avant une mise à jour avec données réelles : sauvegarde vérifiée, arrêt des écritures web et workers selon la procédure d’exploitation, examen du SQL et des dépendances de version. Ne pas utiliser de synchronisation de schéma automatique à la place des migrations.

Une première migration crée 17 tables métier, les rôles, le domaine de quantités, les index/triggers et les vues. La table de versions Doctrine s’ajoute à la table technique Messenger existante. Aucun compte réel ni stock initial n’est créé ; les exemples de test restent dans leurs transactions isolées. Rejouer `migrate` ne recrée pas les objets et ne duplique pas les rôles.

## Tests isolés

```sh
docker compose --profile test build test
docker compose run --rm test
```

Chaque test de schéma crée un nom aléatoire dans la base locale, exécute la vraie commande Doctrine sur cette connexion et termine par un rollback de sa transaction d’isolation. Les données applicatives, la table de versions publique et la queue métier sont préservées. Le compte utilisé doit pouvoir créer un schéma dans la base de développement ; ne pas lancer cette suite sur une base de production.

La suite teste la migration vierge, sa réexécution, le retour à zéro et la réapplication, les erreurs de contraintes, l’historique immuable, les quantités fractionnaires, les références de retours, la déduplication, les comptages zéro et la réconciliation.

## Retour arrière

Sur un environnement de test sans données métier :

```sh
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate 0 --no-interaction
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
```

Le retour à zéro refuse toute table métier contenant des données, à l’exception des rôles de référence fournis à l’installation. Le refus est transactionnel : aucun objet ni enregistrement n’est supprimé. Messenger et ses messages restent hors de cette migration. Un retour arrière avec données réelles nécessite une migration corrective préservant les données, ou une restauration testée selon OPS-01 ; ne pas forcer la suppression des tables/triggers.

La restauration complète et les sauvegardes automatiques ne sont pas remplacées par ce mécanisme et restent dans OPS-01. Après un retour de version applicative, vérifier également sa compatibilité avec le schéma restant et la file de messages.

Référence technique : [configuration transactionnelle de Doctrine Migrations](https://www.doctrine-project.org/projects/doctrine-migrations/en/3.9/reference/configuration.html).

## Comptes de développement

La migration `Version20261018110000` ajoute `users.development_only`, faux pour les comptes existants. Elle ne crée aucun compte. Le provider refuse les comptes marqués hors `APP_ENV=dev`, y compris au rafraîchissement des sessions. Son retour arrière est refusé si un compte marqué existe, pour éviter de supprimer cette protection. Voir [l’administrateur de test](administrateur-test.md).

## Créations de comptes idempotentes

`Version20261018120000` ajoute la table technique `user_creation_requests` : clé par acteur, empreinte HMAC, compte créé et résultat JSON initial. Aucun mot de passe ni corps de requête initial n’y est stocké. Son retour arrière est refusé si des résultats existent. La rétention de l’identité contenue dans le résultat doit être intégrée à RGPD-01.

## Créations de magasins idempotentes

`Version20261018130000` ajoute `store_creation_requests` : clé par acteur, empreinte HMAC, magasin créé et résultat initial. Le rejeu restitue ce résultat sans créer un second magasin ni un second événement d’audit, même après modification ou archivage. Le retour arrière est refusé si des résultats existent. La migration ne crée aucun magasin et ne modifie aucun stock.

## Catalogue et consommation d’articles actifs

`Version20261018140000` ajoute les résultats idempotents des créations d’articles et de catégories (`catalog_creation_requests`) et un contrôle PostgreSQL des nouvelles consommations. Le trigger verrouille l’article en lecture partagée puis exige son statut actif ; il ne modifie aucun mouvement historique. Les retours d’articles archivés restent possibles. Les références d’article demeurent protégées par le trigger d’immutabilité existant. Le retour arrière est refusé si des résultats de création ou des articles inactifs existent.

## Soumissions et décisions des propositions

`Version20261018150000` ajoute `proposal_requests` : clé par acteur et action, empreinte HMAC, proposition concernée et réponse initiale. Aucun catalogue n’est modifié par cette migration. Les résultats rendent les soumissions et décisions répétables sans publication en double. Le retour arrière est refusé lorsque ces résultats existent.

## Événements transactionnels de stock

`Version20261018200000` ajoute `stock_change_events`, un événement durable unique par mouvement, avec article et magasins concernés. Le moteur l’écrit dans la même transaction que solde, journal et audit. Les consommateurs des futurs lots d’alertes renseigneront son état de traitement ; aucun appel SMTP n’est effectué dans le moteur. Les données existantes ne sont pas modifiées et aucun historique n’est rejoué par cette migration. Son retour arrière refuse toute suppression si des événements existent. La table d’historique conserve sa contrainte d’unicité opérateur/clé et son empreinte de requête : aucune copie supplémentaire des textes libres n’est ajoutée.

STK-05 ajoute `Version20261018223000` (huitième migration) pour `movement_corrections`, ses clés étrangères, l’unicité, le contrôle de cohérence et l’immutabilité. Déployer cette migration avant le code des nouveaux écrans ; retour arrière protégé dès qu’une correction existe.
