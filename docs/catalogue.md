# Catalogue global — ART-01

Le menu **Articles** donne accès à la liste, à la fiche d’un article et aux catégories. Tous les comptes actifs autorisés peuvent lire le catalogue global ; les réponses API descriptives restent globales et sans stock. La fiche HTML affiche aussi les [stocks autorisés](stocks.md) et leur total, limités aux droits par magasin. La création et la modification directe sont réservées aux administrateurs. Les gestionnaires passent par [les propositions du catalogue](propositions.md), soumises à validation administrative (ART-02).

## Articles

- Référence obligatoire et immuable, 80 caractères maximum ; unique sans distinction de casse ni d’espaces périphériques, y compris après archivage.
- Nom obligatoire, 160 caractères maximum ; description facultative de 5 000 caractères maximum ; catégorie facultative.
- Une seule unité : **unité**, quantités entières. Le code technique `unit` n’est pas modifiable.
- Statut actif par défaut, choisissable dès la création. Archivage et réactivation par modification du statut. Les données et les mouvements restent conservés ; aucune suppression physique n’est exposée.
- Chaque modification effective incrémente la version. L’API PATCH exige la version lue ; un formulaire périmé est refusé avec 409. L’utilisateur conserve sa saisie et doit recharger la fiche avant de réessayer. Une modification sans changement effectif n’ajoute ni version ni audit.

Une nouvelle consommation d’un article désactivé est refusée par PostgreSQL, même en cas d’écriture directe. Le contrôle prend un verrou partagé sur l’article pour être cohérent avec son archivage concurrent. Les anciennes consommations sont conservées et les retours restent possibles. Les endpoints de mouvements et leurs autres règles restent dans les lots STK.

## Catégories et branches

Une catégorie possède un nom, un parent facultatif et un statut actif par défaut. Les cycles sont interdits, y compris entre plusieurs niveaux. Une nouvelle affectation exige une catégorie et des parents actifs ; une référence existante à une catégorie archivée est conservée. Une catégorie racine n’a pas de parent. Le nom d’une catégorie n’est pas une référence unique ; son identité est son identifiant.

Décisions validées :

- Désactiver une catégorie désactive **toute sa branche**, sous-catégories et articles compris.
- Réactiver une catégorie réactive **toute sa branche et tous ses articles**, y compris les articles déjà désactivés avant l’archivage de cette branche.
- Pour réactiver une sous-branche, ses parents extérieurs à la branche doivent être actifs. Un article ne peut pas être réactivé dans une catégorie restée inactive ; il peut être rattaché à une catégorie active ou devenir sans catégorie.

Les articles dont le statut change reçoivent une nouvelle version. Les éléments déjà dans l’état demandé ne reçoivent pas de modification superflue. Chaque élément modifié est journalisé ; les événements des descendants identifient la catégorie à l’origine de la cascade. Le changement complet est transactionnel : un échec annule aussi le changement de la catégorie de départ.

## Écrans et API

Les formulaires fonctionnent sans JavaScript, avec jeton CSRF. Les créations ont une clé de rejeu conservée dans le formulaire : un double envoi restitue le résultat initial sans créer de doublon. La recherche de base, les filtres par catégorie/état, les tris et la pagination suivent les paramètres du contrat API ; un caractère `%` est recherché littéralement, sans ouvrir une recherche globale.

| Route | Accès |
|---|---|
| GET /api/v1/articles, GET /api/v1/articles/{id} | Lecture du catalogue global |
| GET /api/v1/categories | Lecture des catégories |
| POST /api/v1/articles, POST /api/v1/categories | ADMIN + CSRF + Idempotency-Key |
| PATCH /api/v1/articles/{id}, PATCH /api/v1/categories/{id} | ADMIN + CSRF ; version requise pour un article |

Validation 422, rôle/CSRF 403, ressource inexistante 404, référence utilisée/clé en conflit/version périmée 409. Les réponses de création et de modification respectent les schémas OpenAPI. Les créations retournent 201 avec Location. Les noms et descriptions sont échappés ; l’audit contient les champs modifiés et les identifiants de cascade, sans copier les valeurs descriptives.

## Intégration

Appliquer explicitement `Version20261018140000` avant utilisation. `CatalogAdministration` sérialise ses mutations dans l’ordre `users`, `categories`, `articles`, revérifie le rôle dans la transaction et y inscrit le résultat de rejeu et l’audit. Cette sérialisation évite les cycles concurrents et les doublons. Les futurs services de propositions doivent respecter le contrôle de version et ces invariants. Les services STK restent responsables du stock disponible, des permissions par magasin, du gel d’inventaire et de l’idempotence métier ; le trigger de statut actif ne les remplace pas.
