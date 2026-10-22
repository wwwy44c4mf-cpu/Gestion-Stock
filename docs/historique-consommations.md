# Historique des consommations — CON-01

L’entrée **Consommations** ouvre maintenant l’historique. Les actions **Nouvelle consommation** et **Rendre des articles** restent accessibles. Une consommation enregistrée pour vous par un administrateur ou un gestionnaire figure dans votre historique, avec le bénéficiaire et l’opérateur identifiés séparément.

## Périmètres de lecture

- Utilisateur : uniquement ses propres consommations, même après retrait d’une affectation. Le magasin d’origine doit être actif. Cette consultation ne donne aucun accès au stock du magasin ni aux consommations des autres personnes.
- Gestionnaire : consommations de ses magasins actifs actuellement affectés, pour tous leurs bénéficiaires.
- Administrateur : tous les magasins, y compris ceux désactivés.

Les listes, totaux, personnes proposées et détails respectent le même périmètre. Aucun courriel ni répertoire complet d’utilisateurs n’est exposé dans les filtres. Les personnes anonymisées apparaissent sous « Compte anonymisé ». Les liens vers un stock n’apparaissent dans le détail que si vous avez les droits de consultation de ce stock.

## Filtres et quantités

Les dates de début et de fin sont inclusives, selon le fuseau enregistré dans votre compte. Elles peuvent être utilisées séparément. Les dates, l’article, la catégorie actuelle exacte de l’article, le magasin et le bénéficiaire se combinent. Une catégorie n’inclut pas implicitement ses sous-catégories. Les articles et catégories désactivés restent utilisables pour lire l’historique. Le contexte de magasin choisi dans l’en-tête présélectionne le filtre ; « Réinitialiser » revient à tout votre périmètre.

Le tri se fait par date ou quantité, avec l’identifiant comme second critère pour stabiliser la pagination. La référence facultative est affichée ; le détail contient le commentaire et la liste des retours liés, avec date, opérateur, quantité et état.

Les totaux portent sur **tous les résultats filtrés**, même avec une page d’un seul résultat. Sorties et retours sont affichés séparément, en unités entières. Les totaux ne soustraient pas les retours des sorties. Les retours valides liés aux consommations sélectionnées sont comptés **quelle que soit la date du retour** ; les filtres de date s’appliquent aux dates des consommations. Ce suivi ne prétend donc pas donner le volume des retours effectués dans une période indépendante.

Les lignes annulées et corrigées sont conservées, avec leur statut, mais ne contribuent pas aux totaux valides. Un remplacement valide est une ligne distincte qui contribue au total. Les retours annulés restent visibles dans le détail et ne contribuent pas à la quantité rendue. Les protections existantes interdisent de changer magasin, bénéficiaire ou consommation d’origine lors d’une correction.

## API

`GET /api/v1/consumptions` accepte `date_from`, `date_to`, `article_id`, `category_id`, `store_id`, `consumer_id`, `sort`, `order`, `page` et `limit`. Authentification obligatoire. Un utilisateur demandant un autre bénéficiaire reçoit un refus ; un magasin hors périmètre ou inexistant n’est pas révélé.

La réponse contient `data`, `meta` et `totals`. Chaque ligne expose notamment `returned_quantity`, `status` (`valid`, `cancelled`, `corrected`), les deux identités et les libellés du catalogue. Les totaux `consumed_quantity` et `returned_quantity` sont des chaînes décimales exactes afin de préserver les sommes supérieures à la précision entière JavaScript ; `unit` vaut `unit`.

Aucune migration et aucune écriture métier : cet écran lit l’historique existant. Les statistiques et graphiques de consommation appartiennent à STAT-01.

Décision du 21 octobre 2026 : conserver les lignes annulées ou remplacées pour la traçabilité, avec un statut explicite.

## Conservation des données — RGPD-01

Les consommations anonymisées après un an quittent les vues personnelles, tout comme leurs corrections liées même récentes. Elles restent dans les vues globales autorisées sous une identité commune. Voir les [règles de conservation](conservation-donnees.md).
