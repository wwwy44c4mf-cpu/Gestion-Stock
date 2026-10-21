# Supervision et diagnostic — OPS-03

Les diagnostics techniques sont réservés aux opérateurs du serveur. Ils ne sont exposés ni dans l’API publique, ni dans un écran métier. L’accès Docker/SSH donne des pouvoirs importants sur l’installation : ne pas l’accorder aux utilisateurs de l’application pour leur permettre de consulter ces indicateurs. Définir avant production le responsable, son remplaçant, la disponibilité cible, le rythme de vérification et le canal d’alerte externe.

## Contrôle courant

```sh
# Installation locale
sh bin/diagnostic

# Installation productive, mêmes fichiers privés et mode de proxy qu’au déploiement
PRODUCTION_PROXY_MODE=integrated sh bin/diagnostic production
```

Le contrôle affiche uniquement service, état et santé, puis un rapport JSON agrégé. Il renvoie **0** lorsque les services requis et les indicateurs sont sains, **1** en cas de dégradation, absence ou arrêt d’un service, santé encore en démarrage, ou base/schéma indisponible. Une erreur Docker/SSH reste une erreur de contrôle, jamais une preuve de bonne santé.

App, web, base, worker et scheduler sont requis ; le proxy est requis en mode intégré et la sauvegarde est obligatoire en production et contrôlée en local si présente. Un service arrêté, suspendu, absent, malsain ou encore en démarrage est signalé. Le contrôle ne redémarre rien, ne consomme aucun message et ne modifie pas les stocks, notifications, journaux d’audit ou transports. Les sondes de worker/scheduler exigent un battement de moins de 120 secondes ; une date future est refusée.

Le diagnostic applicatif peut aussi être invoqué seul :

```sh
docker compose exec -T app php bin/console app:ops:status --no-interaction
```

Dans ce cas, il ne voit pas les processus des autres conteneurs : pour détecter un worker arrêté même si sa file est vide, utiliser le contrôle complet. En production, employer les overlays du guide OPS-02, par exemple `sh bin/production diagnostic`.

## Lecture des indicateurs

- `database` : requêtes réussies ; `unavailable` et code générique si connexion ou schéma inutilisable. Les données sont lues dans un instantané transactionnel cohérent, en lecture seule, avec délai SQL de cinq secondes lorsque le diagnostic ouvre sa propre transaction.
- `queues` : nombres totaux, immédiatement disponibles (`due`), différés (`delayed`) et en cours (`inflight`), avec âge du plus ancien message disponible. Les noms sont limités à `notifications`, `async`, `failed` ; les autres sont regroupés en `other`, sans révéler leurs libellés. Les corps et en-têtes Messenger ne sont jamais chargés par ce diagnostic.
- Une livraison notifications est considérée en cours pendant 120 secondes ; les autres transports utilisent le délai Messenger de 3 600 secondes. Après ce délai, elle redevient disponible si son heure prévue est atteinte. Les messages différés ne sont pas présentés comme un retard de traitement.
- `notifications` : éléments en attente, disponibles pour mise en file, en file et en échec, dont ceux ayant épuisé quatre tentatives. Un échec reste visible jusqu’à sa résolution. Un retard de mise en file est signalé uniquement si l’envoi est activé ; la désactivation volontaire n’est pas traitée comme une panne.
- `delivery_last_24h` : compteurs d’échecs SMTP, de rendu et de destinataire indisponible. Un incident récent reste compté après récupération, sans maintenir à lui seul l’état dégradé lorsque la notification est résolue.
- `stock_reconciliation_differences` : écarts quantitatifs, normalement zéro. Un écart dégrade le diagnostic et nécessite une investigation, pas une correction automatique.
- `stock_events` : événements durables de stock non marqués traités et leur âge. Ils sont informatifs : le MVP calcule les alertes par déclencheurs SQL synchrones et ne possède pas de consommateur de cette table. Ne pas assimiler ces événements historiques à la file Messenger, ni modifier leur état pour rendre le diagnostic vert.

`OPS_QUEUE_MAX_AGE_SECONDS=300` est le seuil technique initial configurable, entre 1 et 604 800 secondes. Il porte sur le temps depuis la disponibilité, pas depuis la création d’un message prévu pour plus tard. Les signaux sont : `queue_overdue`, `failed_transport_not_empty`, `notification_failures`, `notification_dispatch_overdue`, `stock_reconciliation_mismatch`. Ce sont des codes techniques, sans donnée nominative. Une configuration invalide est refusée.

`mail_delivery_enabled=false` indique que les courriels sont volontairement suspendus. Zéro échec ne prouve pas la disponibilité actuelle du serveur SMTP : aucun test de connexion ni envoi actif n’est réalisé par le diagnostic.

## Journaux structurés et corrélation

L’application émet une ligne JSON sur stderr : date UTC terminée par `Z`, niveau, code d’événement et `request_id`. Chaque requête principale HTML/API reçoit un identifiant aléatoire de 32 caractères hexadécimaux ; l’en-tête client n’est jamais utilisé comme identifiant de confiance. Le même identifiant figure dans `X-Request-Id`, les journaux de la requête et la réponse d’erreur API. Il vaut `null` hors requête HTTP, notamment dans les workers et commandes.

Événements utiles : `http_request`, `api_error`, `runtime_exception`, `notification_delivery`, `message_failed`, `message_handled`, `worker_started`, `worker_stopped`, `console_failed`, `database_unavailable`. Les messages libres des bibliothèques sont remplacés par des codes génériques. Classes d’exception conservées, messages et traces expurgés. Les erreurs non interceptées par les commandes réelles `bin/console` ont un rendu JSON générique même en mode verbeux, sans réafficher leurs arguments ou leur diagnostic d’origine.

Les champs sont projetés positivement : route technique, méthode HTTP connue, statut, durée, tentative, type de message et code de résultat autorisé. Pas de destinataire, nom d’utilisateur, IP, URL de requête, paramètres, mot de passe, DSN, cookie, jeton, corps de courriel, payload ou sérialisation arbitraire d’objet dans ces journaux applicatifs. Le journal d’audit métier reste distinct, avec ses droits et son guide ADM-01.

Nginx ne collecte plus ses erreurs HTTP natives susceptibles de recopier une URL : elles restent visibles dans les accès JSON, classées `warning` en 4xx et `error` en 5xx. Il écrit ses accès en JSON : date ISO UTC, identifiant repris de l’application ou généré pour une ressource statique, statut et durée. L’identifiant est transmis une seule fois au navigateur. Le journal d’accès brut de PHP-FPM est désactivé pour éviter de recopier les URL. Caddy conserve des accès structurés par statut/durée, sans objet requête, en-têtes de réponse ou identité ; ses erreurs HTTP natives non expurgées sont exclues du journal par défaut, les erreurs 502 restant dans les accès. PostgreSQL utilise `log_error_verbosity=terse`, ne journalise pas les requêtes SQL en erreur ordinaire et ne copie pas les paramètres liés ; les détails de lignes échouées sont omis. Les journaux natifs de démarrage, du moteur Docker et PostgreSQL peuvent avoir leur propre format ; ils restent réservés aux opérateurs et ne doivent pas être partagés bruts.

`APP_LOG_LEVEL=info` conserve requêtes, succès et incidents. `warning` réduit le volume mais supprime les succès ; garder `info` pour l’exploitation courante. Pour `http_request`, les 4xx sont des avertissements, les 5xx des erreurs ; les tentatives SMTP échouées sont des avertissements ou erreurs selon leur caractère terminal. Aucun changement d’état métier ne dépend du succès de l’écriture d’un journal technique.

```sh
# Journaux applicatifs récents, sortie restreinte aux opérateurs
docker compose logs --no-color --since 1h --tail=200 app worker scheduler

# Même sélection, avec les overlays productifs
sh bin/production logs
```

Pour un ticket : conserver heure UTC, code, niveau, identifiant de requête et quelques lignes expurgées utiles. Ne pas joindre `docker inspect`, l’environnement complet, les secrets, les dumps de file, les destinataires ou le contenu d’un courriel. Les diagnostics ne doivent pas être envoyés automatiquement à un service externe.

## Rotation et limites

Les services Compose utilisent le pilote Docker `local`, rotation à 10 Mio par fichier et trois fichiers par conteneur, compression des anciens fichiers. C’est une borne de stockage, pas une durée de conservation garantie. La suppression d’un conteneur peut supprimer ses journaux techniques ; les stocks, audits et files restent dans PostgreSQL. Préserver un extrait expurgé de l’incident avant recréation si nécessaire.

Cette rotation s’applique aux nouveaux conteneurs ; recréer les services selon OPS-02 pour l’appliquer à une installation existante. Vérifier aussi l’espace disque du serveur et ses propres journaux système. Aucun agent distant, port de métriques public ou socket Docker n’est ajouté dans les conteneurs.

## Réagir à un signal

| Signal | Vérification et action |
|---|---|
| Worker/scheduler absent ou malsain | Vérifier état, redémarrages et journaux filtrés ; corriger la cause, puis redémarrer le service concerné et confirmer la reprise de la file. Ne pas effacer la file. |
| Base indisponible | Vérifier service, disque et connectivité interne ; ne pas réinitialiser le volume ni régénérer les secrets. Reprise documentée dans OPS-01 si nécessaire. |
| File en retard | Distinguer messages différés, en cours et disponibles ; contrôler worker, verrous, horloge et durée des traitements. |
| Échecs SMTP | Distinguer envoi suspendu, tentatives temporaires et échecs définitifs ; vérifier configuration SMTP/expéditeur sans publier le DSN. Les tentatives automatiques sont bornées. |
| Transport `failed` non vide | Identifier la cause avant toute reprise ; ne pas lancer une purge ou une relance globale à l’aveugle. Des courriels peuvent déjà avoir été acceptés par le serveur distant. |
| Écart de stock | Préserver journaux et état, investiguer avec les responsables ; aucune réécriture automatique des quantités. |
| Sauvegarde malsaine | Contrôler dernière réussite, espace disponible, clé publique et copie extérieure ; ne pas supprimer la dernière archive vérifiée. |

Avant production, brancher le code de sortie du contrôle et les états Docker à la supervision choisie, avec une notification vers le responsable défini et un test de réception. Cette livraison prépare les commandes et signaux ; elle n’envoie pas de notifications externes et ne garantit pas une surveillance lorsque le serveur entier est éteint. La supervision extérieure doit couvrir ce cas, la destination des sauvegardes et le renouvellement réel des certificats.

## Recette reproductible

```sh
sh bin/test-operations
```

Projet unique, faux secrets, aucun port publié, SMTP refusé sur le port 1 du conteneur jetable : aucune connexion à un SMTP externe. La recette attend la santé initiale, arrête le worker avant de mettre un message en file, vérifie l’échec du contrôle complet, exécute un véritable consommateur avec un refus SMTP local, contrôle les compteurs et les journaux, puis arrête la base et vérifie le code d’indisponibilité générique. Des marqueurs sont aussi placés dans une URL, des en-têtes, une ligne SQL refusée et un argument de commande : ils doivent rester absents des journaux collectés app/worker/scheduler/web/proxy/base. Un refus Nginx 403 et une indisponibilité d’amont Caddy 502 restent observables après expurgation. Tous les profils et volumes du projet de recette sont nettoyés.

Références : [journalisation Symfony](https://symfony.com/doc/current/logging.html), [erreurs des commandes Symfony](https://symfony.com/doc/current/console.html#logging-command-errors), [rotation Docker local](https://docs.docker.com/engine/logging/drivers/local/), [journaux PostgreSQL 18](https://www.postgresql.org/docs/18/runtime-config-logging.html), [filtres de journaux Caddy](https://caddyserver.com/docs/caddyfile/directives/log).
