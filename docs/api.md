# Contrats et socle API — SOC-03

Contrat autonome : [openapi.json](openapi.json), OpenAPI 3.1. Le document décrit 60 opérations et 94 schémas de données pour le MVP. Aucun appel à un service externe n’est nécessaire pour le lire ou pour le valider.

## Ce qui fonctionne déjà

| Route | Rôle |
|---|---|
| `GET /api/v1/health` | Sonde technique minimale |
| `GET /api/v1/openapi.json` | Document OpenAPI local |
| `GET /api/v1/auth/csrf` | Jeton CSRF lié à une session cookie |
| `POST /api/v1/auth/login` | Connexion par courriel et mot de passe |
| `POST /api/v1/auth/logout` | Déconnexion de la session |
| `GET /api/v1/auth/me` | Profil de l’utilisateur connecté |
| `PATCH /api/v1/auth/me` | Modification de son fuseau horaire |
| `GET /api/v1/users` | Liste administrative des comptes |
| `POST /api/v1/users` | Création administrative idempotente |
| `GET /api/v1/users/{id}` | Consultation administrative d’un compte |
| `PATCH /api/v1/users/{id}` | Modification, désactivation ou réactivation |

| `GET /api/v1/stores` | Liste paginée des magasins autorisés |
| `GET /api/v1/stores/{id}` | Consultation d’un magasin autorisé |
| `GET /api/v1/consumption-beneficiaries` | Identifiant et nom des comptes actifs sélectionnables |

| `POST /api/v1/stores` | Création administrative idempotente |
| `PATCH /api/v1/stores/{id}` | Modification, archivage et réactivation |

| `GET /api/v1/articles` et `GET /api/v1/articles/{id}` | Catalogue global et fiche article |
| `POST /api/v1/articles` et `PATCH /api/v1/articles/{id}` | Administration des articles, version et archivage |
| `GET /api/v1/categories` | Liste des catégories |
| `POST /api/v1/categories` et `PATCH /api/v1/categories/{id}` | Administration des catégories et cascade de statut |

| `GET /api/v1/article-proposals` et `GET /api/v1/article-proposals/{id}` | Propositions autorisées et commentaire de décision |
| `POST /api/v1/article-proposals` | Soumission idempotente sans publication |
| `POST /api/v1/article-proposals/{id}/review` | Approbation ou refus administratif idempotent |

| `GET /api/v1/stocks` et `GET /api/v1/stores/{storeId}/stocks` | Soldes autorisés, filtres et pagination |
| `GET /api/v1/articles/{id}/stocks` | Total exact autorisé et détail paginé |
| `GET /api/v1/stores/{storeId}/stocks/{articleId}/movements` | Journal local sans identité distante, ADMIN/MANAGER autorisés |

| `GET /api/v1/movements` et `GET /api/v1/movements/{id}` | Journal général filtrable et détail avec masquage des informations hors périmètre |

| `POST /api/v1/movements/entries` | Entrée autorisée, atomique et idempotente |
| `POST /api/v1/movements/consumptions` | Consommation pour soi ou tiers autorisé, confirmation HTML dans Location |
| `POST /api/v1/movements/returns` | Retour borné lié à sa consommation, magasin imposé, confirmation HTML dans Location |
| `POST /api/v1/movements/transfers` | Débit/crédit atomiques, droits sur les deux magasins, confirmation HTML dans Location |
| `POST /api/v1/movements/adjustments` | Écart signé justifié |
| `POST /api/v1/movements/{id}/correct` | Bonne quantité, contre-mouvement et remplacement atomiques liés |
| `POST /api/v1/movements/{id}/reverse` | Annulation exacte unique et justifiée |

| `GET/POST /api/v1/inventories` et `GET /api/v1/inventories/{id}` | Campagnes autorisées, création de brouillon idempotente et détail |
| `GET /api/v1/inventories/{id}/lines` | Référence figée paginée, tri et filtre article |
| `POST /api/v1/inventories/{id}/start`, `/submit`, `/cancel` | Démarrage atomique, soumission complète et annulation tracée ; gel pendant comptage/validation |

Ces cinquante opérations portent `x-implementation: available`. Les 10 autres portent `planned` : leurs contrats sont définis, mais elles seront développées dans leurs lots métier. Elles ne renvoient pas de fausses données de démonstration. Les routes `/api/v1/_test/*` vérifient les outils communs exclusivement en environnement test et sont absentes en production.

Les routes `/health/live` et `/health/ready` de SOC-01 restent disponibles pour Docker ; la nouvelle sonde API ne remplace pas le contrôle PostgreSQL de readiness.

## Couverture métier du contrat

| Famille | Ressources, entrées et règles importantes |
|---|---|
| Authentification | Connexion/déconnexion, profil, fuseau, demande et confirmation de réinitialisation ; erreurs génériques sans révéler l’existence d’un compte |
| Comptes | Liste, création, lecture et modification réservées à l’administrateur ; rôle/affectations et désactivation invalidant les sessions |
| Magasins | Lecture selon affectation, création/modification/archivage par administrateur |
| Catalogue | Articles et catégories globaux ; référence et unité immuables ; administration directe réservée à l’administrateur |
| Propositions | Création/modification proposées par gestionnaire ; version d’origine ; validation administrative avant publication |
| Stocks | Vue par magasin, vue filtrée et total autorisé par article avant pagination du détail ; seuils personnalisés par article/magasin |
| Mouvements | Entrées, consommations, ajustements signés, transferts, retours et contre-mouvements ; aucune date rétroactive ni modification silencieuse |
| Bénéficiaires | Projection minimale id/nom des techniciens actifs pour administrateurs/gestionnaires ; pas d’accès au profil complet d’autrui |
| Consommations | Utilisateur : ses consommations, même saisies pour lui ; gestionnaire : ses magasins ; administrateur : global ; retours distincts |
| Inventaires | Brouillon, démarrage, comptages, soumission, validation et annulation ; gel des magasins jusqu’à validation/annulation, aucun comptage par utilisateur simple |
| Alertes et courriels | Alertes actives/historiques, configuration des destinataires, mise en file d’information ciblée par administrateur ; récapitulatif planifié par MAIL-03 |
| Reporting et audit | Statistiques personnelles ou de périmètre, indicateurs de gestion, journal d’audit réservé aux rôles autorisés |

Un utilisateur peut consulter sa consommation issue d’un magasin auquel il n’est pas affecté, sans obtenir l’accès au stock ou aux autres consommations de ce magasin. Les noms d’auteur/bénéficiaire sont des projections contextuelles autorisées ; après anonymisation, ils peuvent être nuls. Les totaux, les comptages et les listes ne doivent jamais révéler un autre périmètre.

## Requêtes et réponses

- Préfixe `/api/v1`, UTF-8, corps `application/json`. Le lecteur commun exige un objet, limite sa taille à 64 Kio et sa profondeur à 32. Les schémas d’écriture refusent les champs supplémentaires ; les paramètres de recherche sur une écriture sont refusés.
- Ressource métier : `{ "data": ... }`. Liste : `{ "data": [...], "meta": { "page", "limit", "total", "total_pages" } }`. Les trois opérations techniques utilisent directement leur objet décrit dans OpenAPI.
- Création : 201 avec `Location` ; lecture/modification : 200 ; absence de contenu : 204 ; file asynchrone/réinitialisation demandée : 202. La réponse à un rejeu idempotent reprend le résultat de l’opération d’origine.
- Aucun DELETE métier. L’archivage utilise PATCH ; les journaux sont conservés.
- `X-Request-Id` : 32 caractères hexadécimaux générés par le serveur à chaque requête API. Un identifiant client n’est pas repris. Les réponses sont `no-store, private` et `nosniff`.

### Quantités et précision

Les écritures acceptent des nombres JSON entiers en notation décimale sans fraction ni exposant. La borne d’une quantité élémentaire est 9 007 199 254 740 991, cohérente avec SOC-02. Les contrôles lexicaux précèdent la validation pour ne pas arrondir silencieusement une fraction infime ; les nombres dans un commentaire texte ne sont pas concernés. Les booléens et les nombres entre guillemets ne sont pas des quantités valides en entrée.

Les quantités élémentaires en réponse sont des entiers. Les **sommes** potentiellement supérieures à cette borne (`AggregateQuantity`) sont des chaînes décimales exactes : par exemple `"18014398509481982"`. Le client doit préserver cette précision, sans conversion silencieuse en Number JavaScript. Le code unité unique est `unit`, affiché « unité ». Les retours sont agrégés séparément des sorties ; entrées, ajustements et transferts ne sont pas des consommations.

### Pagination, filtres et tri

`page` vaut 1 par défaut, entre 1 et 10000 ; `limit` vaut 25, entre 1 et 100. Les champs de filtre et les clés de tri viennent de chaque opération OpenAPI. Un paramètre inconnu, une collection à la place d’un scalaire ou une borne invalide donne 422, sans correction silencieuse. L’ordre est `asc` ou `desc`, complété par l’identifiant pour garantir un tri stable. Les services métier devront mapper les noms autorisés vers leurs colonnes, jamais concaténer un identifiant SQL venu du client.

`QueryParameters` lit les paramètres à partir de l’operationId ; `Pagination` fournit l’offset et le format commun, sans calcul flottant. Le total correspond à la recherche autorisée avant pagination. Le total de stock d’un article est calculé sur tous les magasins autorisés correspondant aux filtres, avant pagination de son détail.

Les dates `date_from` et `date_to` sont inclusives dans le fuseau utilisateur. `DateRange` et `MovementDates` produisent un début UTC inclus et le début du jour suivant UTC exclu. Cela couvre correctement les journées de 23/25 heures aux changements d’heure ; éviter les bornes artificielles « 23:59:59 ». Une période inversée ou une date impossible donne 422.

## Erreurs JSON

Toutes les erreurs traitées par Symfony sous `/api/v1` sont des `application/problem+json`, y compris une route inconnue ou une méthode refusée :

```json
{
  "type": "about:blank",
  "title": "Données invalides",
  "status": 422,
  "detail": "Le contenu ne respecte pas le schéma attendu.",
  "code": "validation_failed",
  "request_id": "0123456789abcdef0123456789abcdef",
  "violations": [{"field": "body", "message": "Vérifier les champs, les types et les bornes."}]
}
```

400 : JSON invalide ; 401 : session absente/expirée ; 403 : rôle/périmètre/CSRF refusé ; 404 : ressource absente ou hors périmètre ; 405 : méthode refusée avec `Allow` ; 409 : version, stock, état ou idempotence en conflit ; 413 : taille ; 415 : type de contenu ; 422 : données/règles invalides ; 429 : débit avec `Retry-After` ; 500 : erreur interne masquée.

`ApiProblem` fournit le code et un message constant. Ne pas y interpoler de corps client, SQL, mot de passe ou jeton. Les erreurs inattendues ne renvoient aucun détail d’exception ; le journal technique écrit seulement un message générique, l’identifiant de requête et la classe d’erreur. Les contrôles de visibilité métier devront refuser une requête ou renvoyer 404 avant de révéler l’existence d’un objet hors périmètre.

## Sessions, CSRF et débit

Le cookie de production est `GESTION_STOCK_SESSION`, HttpOnly et SameSite=Lax. Secure s’applique sur une requête HTTPS reconnue par Symfony ; le proxy et HTTPS de production seront configurés dans OPS-02. Le stockage natif se trouve dans la zone temporaire de l’application ; un redémarrage invalide les sessions. AUTH-01 fournit la connexion, la déconnexion, le profil et le renouvellement de session, avec expiration à 30 minutes d’inactivité et 12 heures absolues. La relecture du compte rejette une identité désactivée ou une version d’authentification modifiée. Les écrans de gestion des comptes restent dans AUTH-02.

Après une connexion, le cookie est renouvelé et les anciens jetons CSRF sont supprimés. Redemander un jeton avec le nouveau cookie. Avant une écriture, appeler `/auth/csrf`, conserver le cookie puis transmettre le champ `token` dans `X-CSRF-Token`. Le jeton est lié à cette session, y compris pour connexion et réinitialisation. Un jeton absent ou appartenant à une autre session donne 403. Il ne prouve pas l’identité d’un utilisateur et ne remplace pas les contrôles de rôle.

La réinitialisation et l’information ciblée sont limitées par adresse IP et route à cinq requêtes par minute ; un contrôleur sensible supplémentaire peut déclarer `_api_sensitive: true`. Symfony RateLimiter utilise un cache local partagé entre processus du conteneur et un verrou `flock`. Un dépassement donne 429 et `Retry-After`. Les proxys ne sont pas implicitement reconnus : configurer explicitement ceux de confiance dans OPS-02. La connexion utilise une politique partagée interface/API : cinq tentatives par courriel/IP sur quinze minutes, trente par IP, audit pseudonymisé et réponse générique. Voir [AUTH-01](authentification.md). Un déploiement à plusieurs réplicas demandera un stockage partagé des limites/sessions ; la configuration actuelle cible un seul conteneur applicatif.

## Idempotence et contrôles métier à raccorder

`Idempotency-Key` : 8 à 160 caractères ASCII parmi lettres, chiffres, `.`, `_`, `:`, `-`. Réutiliser la clé lors d’une perte de réponse ; une nouvelle opération reçoit une nouvelle clé. `IdempotencyKey` valide l’en-tête et calcule l’empreinte du corps validé, de l’acteur serveur, de la méthode et du chemin. L’ordre des propriétés d’objet n’affecte pas l’empreinte ; celui des tableaux est conservé.

La persistance du résultat, la sérialisation des accès concurrents et le retour 409 pour clé réemployée avec un contenu différent appartiennent aux services métier, en particulier STK-02. L’outil partagé ne simule pas une protection de rejeu en mémoire. Les autres créations marquées idempotentes devront aussi persister leur résultat dans leur lot ; le contrat ne les active pas.

Pour chaque futur contrôleur métier : vérifier la session active, les rôles et les magasins depuis les données serveur ; lire les entrées via les outils communs et les schémas nommés ; appliquer les règles croisées dans une transaction ; filtrer aussi les totaux et les projections de noms ; utiliser les problèmes normalisés et respecter le contrat de réponse. Les schémas ne remplacent ni les autorisations ni le verrouillage des stocks.

## Validation et provenance

```sh
docker compose --profile test build app test contract-test
docker compose run --rm test
```

La commande de test dépend du conteneur `contract-test`, sans réseau, qui vérifie la structure contre le schéma officiel OpenAPI, les schémas JSON et toutes les références locales. Les versions de ce validateur et de ses dépendances sont verrouillées dans `docker/contract-requirements.txt` ; Python n’est pas installé dans l’image applicative. Les tests PHP vérifient les réponses réelles et les outils communs ; une session native de production est testée dans un processus séparé pour contrôler aussi le cookie Secure/HttpOnly/SameSite.

Provenance du schéma tiers et licence : [schemas/NOTICE.md](schemas/NOTICE.md). Références : [OpenAPI 3.1](https://spec.openapis.org/oas/v3.1.0), [Symfony CSRF](https://symfony.com/doc/current/security/csrf.html), [Symfony RateLimiter](https://symfony.com/doc/current/rate_limiter.html). Rapport de recette : [validation-soc03.md](validation-soc03.md).

AUTH-02 active les opérations de comptes ; voir [leur guide](administration-comptes.md) pour les protections, le rejeu persistant et l’invalidation des sessions. Les contrôles de périmètre des ressources métier restent dans AUTH-03.

## Consultation des stocks

Les lectures `/stocks`, `/stores/{storeId}/stocks` et `/articles/{id}/stocks` appliquent les magasins autorisés avant pagination et total. Les articles sans solde apparaissent à zéro sans écriture. Le journal local `/stores/{storeId}/stocks/{articleId}/movements` est réservé à ADMIN/MANAGER autorisés et ne retourne ni identités ni magasin distant. [Guide](stocks.md).

## Entrées de stock

Le formulaire et `POST /api/v1/movements/entries` utilisent le moteur transactionnel de STK-02. Motif obligatoire, référence facultative, auteur et date serveur ; événement durable de recalcul en attente des futurs traitements d’alertes. [Guide](entrees-stock.md) · [Recette](validation-stk03.md).

STK-05 : [corrections et ajustements](corrections-stock.md). Les réponses d’écriture de ces trois nouvelles opérations n’annoncent pas de Location vers un détail API ; sa lecture est disponible depuis STK-08.

INV-01 : [campagnes et gel des mouvements](inventaires.md). La saisie des comptages est disponible dans INV-02 ; la validation avec ajustements est disponible dans INV-03.

INV-02 : `POST /api/v1/inventories/{id}/lines` enregistre un lot atomique et idempotent. `expected_version` est obligatoire ; une saisie obsolète renvoie 409. La lecture expose `version` et `delta_percent`, nul si la référence est zéro ou la quantité non saisie. Le comptage ne modifie aucun stock.

INV-03 : `POST /api/v1/inventories/{id}/validate` clôture une campagne complète et soumise. Réponse : inventaire et identifiants de tous les ajustements. Sans corps ni recherche ; CSRF, clé idempotente et droits requis. Clôture, soldes, journal, événements et audits atomiques. Les écarts nuls ne produisent aucun mouvement.

ALT-01 : `PATCH /api/v1/stores/{storeId}/stocks/{articleId}/thresholds` configure les seuils du couple article/magasin sans mouvement. [Guide](seuils-stock.md).
