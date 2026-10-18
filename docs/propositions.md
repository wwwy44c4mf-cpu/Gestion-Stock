# Propositions d’articles — ART-02

Depuis **Articles → Propositions du catalogue**, les gestionnaires soumettent une création ou une modification sans toucher au catalogue publié. Depuis une fiche article, **Proposer une modification** reprend ses informations et sa version. Les administrateurs disposent de la file complète ; un gestionnaire voit uniquement ses propres propositions. Le rôle Utilisateur n’a pas accès à ce module.

## Soumission

Une création reprend la référence, le nom, la description, la catégorie, l’unité unique et le statut proposés. Une modification indique l’article, sa version de départ et les champs à modifier ; référence et unité restent immuables. Les mêmes validations métier que l’administration du catalogue sont utilisées. Une demande sans changement effectif ou fondée sur une version périmée est refusée.

La proposition envoyée est conservée telle quelle. Elle n’est ni modifiable ni supprimable depuis l’application ; son auteur peut en soumettre une nouvelle. Les propositions en attente ne réservent pas une référence : plusieurs personnes peuvent proposer la même référence, mais une seule publication pourra la créer. Une référence déjà publiée, y compris archivée, est refusée dès la soumission et revérifiée à la publication.

## Décision administrative

La fiche compare les champs proposés aux valeurs publiées aujourd’hui. Pour une création, les valeurs par défaut effectives sont également affichées. L’administrateur approuve la proposition telle quelle, ou la refuse en indiquant un motif. Le motif et l’identité du décideur sont visibles par l’auteur de la proposition. Le commentaire d’approbation est facultatif.

L’approbation vérifie à nouveau la référence, la catégorie et ses parents actifs, ainsi que la version de l’article. Une catégorie devenue inactive ou un article modifié depuis la proposition bloque la publication. La proposition reste en attente et peut toujours être refusée avec un motif ; elle n’est jamais appliquée en écrasant une modification plus récente. L’auteur peut soumettre une nouvelle demande sur la fiche à jour.

Une approbation, sa modification du catalogue, son résultat de rejeu et son audit sont enregistrés dans la même transaction. Un refus ne modifie aucun article. Une décision déjà enregistrée ne peut pas être remplacée ; une nouvelle décision concurrente reçoit un conflit. Les versions des articles et les règles d’archivage du catalogue continuent à s’appliquer.

## Envois répétés et droits

Les formulaires fonctionnent sans JavaScript, avec CSRF et clé de rejeu. Les soumissions et décisions identiques peuvent être renvoyées sans doublon. Réutiliser une clé pour une autre opération ou un contenu différent produit 409. Un rejeu restitue le résultat initial ; GET retourne l’état actuel de la proposition, même si elle a été examinée depuis sa soumission.

Les listes, compteurs et accès directs sont contrôlés côté serveur par les permissions et scopes d’AUTH-03. Un gestionnaire ne peut pas ouvrir une proposition d’un autre auteur ni prendre une décision. Les écrans affichent uniquement les noms utiles des intervenants, sans ouvrir leurs profils complets. L’audit contient les noms des champs modifiés, pas les valeurs du contenu proposé ni les motifs de refus ; ces derniers restent dans la proposition autorisée.

## API et migration

| Route | Droits |
|---|---|
| GET /api/v1/article-proposals | ADMIN : toutes ; MANAGER : les siennes |
| GET /api/v1/article-proposals/{id} | Même périmètre que la liste |
| POST /api/v1/article-proposals | ADMIN ou MANAGER + CSRF + Idempotency-Key |
| POST /api/v1/article-proposals/{id}/review | ADMIN + CSRF + Idempotency-Key |

Le champ `review_comment` restitue le commentaire de décision. Erreurs : 403 pour rôle/CSRF, 404 pour ressource inaccessible ou inexistante, 409 pour clé en conflit, version périmée, référence déjà publiée ou décision déjà prise, 422 pour données invalides ou motif de refus vide. Un problème d’applicabilité détecté lors de la publication ne valide pas la proposition.

Appliquer explicitement `Version20261018150000` avant utilisation. La table technique `proposal_requests` conserve les résultats des soumissions et décisions. La transaction verrouille `users`, `categories`, `articles`, puis `article_proposals`, dans un ordre compatible avec l’administration du catalogue ; elle revérifie l’identité avant écriture. La publication réutilise `CatalogAdministration` et ses invariants. Le retour arrière est refusé si des résultats de propositions existent.
