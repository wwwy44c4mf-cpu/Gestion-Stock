# Consultation des stocks — STK-01 / ART-03

État : implémenté et validé pour revue dans la [PR #62](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/62). [Recette](validation-stk01.md).

## Parcours

Le menu **Stocks** affiche tous les couples article/magasin autorisés. Un article qui n’a pas encore de solde apparaît à zéro, sans création d’une ligne en base. Ce choix a été confirmé par l’utilisateur le 18 octobre 2026. Les articles désactivés restent consultables pour conserver leur historique.

Le magasin sélectionné dans l’en-tête est appliqué par défaut à l’écran Stocks ; un filtre explicite peut choisir tous les magasins autorisés. L’API reste indépendante de ce contexte.

Les filtres portent sur le magasin, l’article, la catégorie exacte, la référence ou le nom et la disponibilité. La recherche est littérale, sans distinction de casse ; `%` et `_` ne sont pas des jokers. La rupture correspond à zéro ; le filtre de seuil bas nécessite un seuil configuré et inclut l’égalité. Un seuil absent est distinct de zéro. La configuration des seuils reste dans ALT-01.

Depuis **Magasins**, le nom ouvre les stocks du magasin. Depuis la fiche article, le total et le détail paginé des magasins sont affichés ; le lien de filtrage ouvre les stocks de cet article. Le total exact est calculé sur l’ensemble du périmètre et du filtre avant pagination, et reste identique sur une page vide. Les sommes sont des chaînes d’entiers décimaux dans l’API ; les quantités individuelles sont des entiers JSON.

La fiche de stock indique le solde, l’unité, les seuils et les éventuels statuts désactivés. ADMIN et MANAGER disposent d’un journal local paginé : type, variation signée et date. Aucun auteur, bénéficiaire ou identifiant de magasin distant n’est renvoyé par ce journal. USER n’y a pas accès ; ses consommations personnelles relèvent du lot dédié. Le [journal général et ses filtres métier](historique-mouvements.md) sont disponibles dans STK-08 ; les corrections et annulations relèvent de STK-05.

## Droits

ADMIN lit tous les magasins, même désactivés. MANAGER et USER lisent uniquement les magasins actifs auxquels ils sont affectés. Le sélecteur global ne constitue pas une autorisation : les requêtes utilisent les affectations à jour avant comptage et calcul du total. Une demande explicite pour un magasin inaccessible renvoie 404 ; sans affectation, la liste est vide et le total vaut zéro. Le catalogue descriptif reste global.

Les lectures ne modifient ni les soldes, ni les mouvements, ni les seuils. Aucun formulaire de mouvement n’est livré dans ce lot.

## API

- `GET /api/v1/stocks` : stocks filtrés et paginés.
- `GET /api/v1/stores/{storeId}/stocks` : même lecture limitée au magasin demandé.
- `GET /api/v1/articles/{id}/stocks` : total autorisé exact et détail paginé.
- `GET /api/v1/stores/{storeId}/stocks/{articleId}/movements` : journal local limité à ADMIN/MANAGER autorisés.

La lecture du catalogue ajoute les tris `category` et `status`, en plus de référence, nom et date ; le tri est complété par l’identifiant. Aucune nouvelle migration n’est nécessaire ; les migrations précédentes doivent être appliquées.
