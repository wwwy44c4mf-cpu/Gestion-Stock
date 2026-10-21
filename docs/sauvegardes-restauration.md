# Sauvegardes et restauration — OPS-01

Choix validés le 21 octobre 2026 : sauvegarde quotidienne, conservation de 90 jours, serveur unique, reprise souhaitée sous un jour ouvré. La destination de secours hors serveur reste à choisir. La perte possible correspond au temps depuis le début de la dernière sauvegarde réussie : environ 24 heures en fonctionnement normal, davantage en cas d’échec ou de copie extérieure en retard. Aucun RPO nul garanti.

## Contenu et protection

Le profil Compose `backup` réalise un export logique PostgreSQL 18 cohérent avec un instantané transactionnel partagé entre `pg_dump` et les empreintes de toutes les tables. Il inclut stocks, mouvements, inventaires, audit et file durable. Le même paquet contient les sources autorisées, `.env` et les quatre secrets nécessaires à la reprise : application, mot de passe applicatif et administrateur de base, SMTP. Il exclut `.git`, dépendances, caches, journaux et clé privée de sauvegarde. La base est actuellement le seul stockage persistant métier ; `backup_data` contient les archives, jamais une copie brute de PGDATA.

Chaque paquet est chiffré avec age avant publication atomique dans `backup_data`, avec somme SHA-256 et manifeste des tailles, modes et empreintes. Le clair temporaire reste dans un tmpfs limité à 512 Mio, configurable selon le volume réel. Le service écrit comme utilisateur dédié, sans socket Docker, sans clé privée, sur réseau interne et système de fichiers en lecture seule. Répertoire d’archives 0700, fichiers 0600. Les erreurs ne publient ni identifiants ni diagnostics contenant des secrets.

Les archives contiennent des secrets : conserver leur accès restreint même chiffrées. Utiliser uniquement les archives issues de sa propre chaîne de sauvegarde ; le chiffrement ne constitue pas une signature de l’émetteur. Protéger séparément la clé privée, hors serveur, avec accès nominatif. Une archive sans cette clé est irrécupérable. La rétention des copies extérieures doit aussi être de 90 jours. Après restauration, appliquer les règles d’anonymisation avant réouverture selon RGPD-01.

## Activation et exploitation

Depuis le dépôt configuré avec ses quatre secrets existants :

```sh
docker compose build backup
docker compose run --rm --no-deps -e BACKUP_KEY_UID="$(id -u)" -e BACKUP_KEY_GID="$(id -g)" backup-keygen
```

Cette commande refuse d’écraser une clé existante. Mettre `.secrets/backup_identity` en lieu sûr hors serveur ; garder `.secrets/backup_recipient` pour le chiffrement. Ne pas afficher ou publier la clé privée. Les sauvegardes fonctionnent ensuite sans monter cette clé dans le service permanent.

```sh
docker compose --profile backup up -d backup
docker compose exec backup python3 /opt/backup.py health
docker compose logs --tail=20 backup
```

Valeurs configurables : `BACKUP_INTERVAL_SECONDS=86400`, `BACKUP_RETENTION_DAYS=90`, `BACKUP_COMMAND_TIMEOUT=600`, `BACKUP_TMPFS_SIZE=512m`. Les créneaux quotidiens sont alignés sur minuit UTC, donc 01 h ou 02 h à Paris. Au premier lancement ou après interruption, une sauvegarde est rattrapée immédiatement si le créneau du jour manque ; les jours précédents ne sont pas rejoués. Une erreur est retentée ; la dernière réussite est conservée. La purge intervient seulement après réussite et ne supprime que les anciennes paires reconnues et vérifiées. Les fichiers inconnus ou incohérents sont conservés pour inspection.

Une sauvegarde manuelle utilise le même verrou, sans concurrence :

```sh
docker compose run --rm --no-deps backup once
```

Le contrôle de santé vérifie l’âge et l’empreinte de la dernière archive. Un conteneur malsain ne déclenche pas à lui seul un courriel : sa surveillance externe relève d’OPS-03. Contrôler aussi capacité disque, espace tmpfs, sauvegarde extérieure et restauration périodique. Suspendre le profil pendant une mise à jour des sources/configurations pour éviter un paquet contenant plusieurs versions de fichiers.

## Copie hors serveur

Aucune destination extérieure n’est configurée ou contactée. Le volume local protège contre certaines erreurs applicatives, mais disparaît avec le serveur. Avant production : choisir destination, compte restreint, transport chiffré, fréquence quotidienne, purge 90 jours et supervision ; tester la récupération depuis cette destination et conserver la clé séparément.

Pour préparer un export des archives chiffrées dans un répertoire privé existant :

```sh
umask 077
docker compose run -T --rm --no-deps --entrypoint tar backup -C /backups -cf - . > /chemin/prive/backups.tar
```

Ce fichier contient les archives chiffrées, sommes et état ; aucune clé privée ni donnée déchiffrée. Vérifier le succès de la commande avant transfert avec l’outil de la destination retenue. Conserver le manifeste SHA-256 avec chaque archive. Ne pas stocker uniquement la clé et les archives sur le même support.

## Reprise sur installation vierge

1. Préparer Docker Compose, les sources compatibles et l’image `backup` sur une nouvelle machine. Ne jamais supprimer les volumes du serveur d’origine pour cet essai. Arrêter application, web, worker, scheduler et backup si l’installation cible était déjà lancée. La base cible doit être neuve et ne contenir aucun objet métier.
2. Récupérer les archives chiffrées et leur somme depuis la destination de confiance, dans un répertoire privé. Installer la clé privée préservée dans `.secrets/backup_identity` (répertoire 0700, fichier 0600). Ne pas régénérer les secrets applicatifs : ils sont dans le paquet.
3. Construire `docker compose build backup`. Créer un répertoire de sortie vide `backup-recovery`. Importer uniquement la paire sélectionnée dans le volume, en remplaçant le nom d’exemple ci-dessous :

```sh
docker compose run --rm --no-deps --entrypoint sh -v "$PWD/archives-recuperees:/import:ro" backup-files -c 'cp /import/stock-AAAAMMJJTHHMMSSZ-xxxxxxxxxxxx.backup.age /import/stock-AAAAMMJJTHHMMSSZ-xxxxxxxxxxxx.backup.age.sha256 /backups/'
docker compose run --rm --no-deps backup-files inspect /backups/stock-AAAAMMJJTHHMMSSZ-xxxxxxxxxxxx.backup.age
RESTORE_OUTPUT_UID="$(id -u)" RESTORE_OUTPUT_GID="$(id -g)" docker compose run --rm --no-deps backup-files restore-files /backups/stock-AAAAMMJJTHHMMSSZ-xxxxxxxxxxxx.backup.age --output /recovery
```

Le déchiffrement authentifié et les empreintes sont vérifiés avant extraction. Traversées de chemins, liens, doublons et fichiers non autorisés sont refusés. La sortie doit être vide. Elle contient `source/`, `database.dump` et `manifest.json` : maintenir son accès privé, car elle contient désormais du clair. Copier `source/` vers le répertoire de déploiement vide de la nouvelle installation, y compris `.env` et `.secrets`. Préserver les modes et propriétaires ; ne pas lancer le générateur de secrets. Garder la paire chiffrée disponible dans le volume du projet final (réimporter si son nom Compose a changé).

4. Vérifier `.env`, les chemins et droits ; désactiver explicitement `MAIL_DELIVERY_ENABLED=0` pendant la reprise. Recréer PostgreSQL **18**, avec le même nom de base et rôle applicatif, à partir des secrets restaurés. Le rôle applicatif standard est créé par l’initialisation Docker ; les rôles PostgreSQL personnalisés ne font pas partie du paquet. Aucune migration préalable : le dump contient déjà le schéma.

```sh
docker compose up -d --wait db
RESTORE_CONFIRM_DATABASE=gestion_stock docker compose run --rm --no-deps backup-restore restore-db /backups/stock-AAAAMMJJTHHMMSSZ-xxxxxxxxxxxx.backup.age
docker compose run --rm --no-deps backup-restore verify-db /backups/stock-AAAAMMJJTHHMMSSZ-xxxxxxxxxxxx.backup.age
```

Adapter `RESTORE_CONFIRM_DATABASE` au nom exact configuré. Le programme refuse une base non vide et n’utilise pas `--clean`. Le chargement SQL se fait en transaction unique : un échec SQL annule le chargement. Après réussite, les empreintes de toutes les tables et la réconciliation des stocks sont comparées avant toute ouverture. En cas d’écart, ne pas démarrer l’application ; investiguer sur cette installation de reprise. Les contrôles postérieurs ne constituent pas eux-mêmes une annulation du chargement validé.

5. Construire les images applicatives depuis les sources récupérées. Le verrou de restauration bloque l’accès web et les travailleurs. Comparer les empreintes avant tout retraitement ; si nécessaire, migrer vers le schéma RGPD-01, puis exécuter `sh bin/production privacy` en production ou `sh bin/privacy apply` en développement, envois désactivés. Seule la réussite du retraitement lève le verrou. Démarrer ensuite app et web avec envois désactivés. Vérifier connexion, stocks, journal, inventaires, file durable et santé. Les connexions ajoutent naturellement des événements d’audit : la comparaison exacte des empreintes doit donc précéder cette étape. Valider le schéma restauré avant une éventuelle migration nécessaire au retraitement. Voir le [guide de conservation](conservation-donnees.md).
6. Après validation et règles d’anonymisation applicables, réactiver worker/scheduler, envois autorisés et sauvegarde. Une file restaurée peut contenir des courriels en attente : vérifier avant réactivation. Documenter heure de l’instantané restauré, perte effective, durée de reprise et actions correctives. Conserver l’installation d’origine jusqu’à acceptation.

`docker compose down` sans `--volumes` conserve les données et la file ; ce n’est pas une sauvegarde. Ne jamais ajouter `--volumes` à une procédure de reprise de l’installation réelle.

## Recette reproductible

```sh
sh bin/test-backup-recovery
```

Cette commande utilise un projet unique, des volumes séparés, de faux secrets et des données jetables. Elle teste corruption, clé erronée, rétention, export cohérent sous écriture concurrente, restauration transactionnelle refusée et restauration complète, puis accès web et recréation des conteneurs. Elle nettoie uniquement son projet de recette. Le résultat local ne garantit pas le délai sur un serveur réel : mesurer régulièrement la reprise complète, transfert compris, pour vérifier l’objectif d’un jour ouvré.

Références : [pg_dump PostgreSQL 18](https://www.postgresql.org/docs/18/app-pgdump.html), [pg_restore](https://www.postgresql.org/docs/18/app-pgrestore.html), [age](https://github.com/FiloSottile/age/blob/main/README.md).

Pour une reprise de production OPS-02, recopier la configuration récupérée `source/.env` vers `.env.production` et utiliser les overlays du [guide HTTPS](deploiement-production.md) avant toute ouverture publique. Le fichier privé réellement choisi par `PRODUCTION_ENV_FILE` est monté en lecture seule et sauvegardé, même s’il se trouve hors du dépôt.
