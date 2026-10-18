# Recette STK-01 et fin de ART-03

Validation du 18 octobre 2026 : **132 tests PHP / 1 908 assertions et quatre validations indépendantes OpenAPI réussis** dans Docker. Commande complète : `docker compose run --rm test`, durée PHPUnit 59,468 s, sortie 0. La recette ciblée initiale avait également réussi (7 tests / 166 assertions) ; un huitième test de contexte magasin a ensuite été inclus dans la suite finale.

## Couverture

`StockConsultationTest` utilise un schéma PostgreSQL isolé et une transaction annulée après chaque test. Les huit tests couvrent :

- ADMIN, MANAGER, USER et compte sans affectation ; magasins affectés, non affectés et désactivés.
- Changements d’affectation et désactivation immédiatement pris en compte, indépendamment du contexte choisi dans l’en-tête.
- Total autorisé avant pagination et sur page vide ; valeur exacte `36028797018963964` pour une somme supérieure à la précision entière JavaScript.
- Articles sans solde affichés à zéro, sans aucune création de ligne ; absence de modification des soldes après les lectures.
- Seuil absent distinct de zéro, égalité du seuil, rupture, filtres combinés et recherche littérale `%`.
- Erreurs des paramètres inconnus, tableaux, identifiants invalides, pagination hors limites et magasin inaccessible.
- Fiche stock et journal local : signes des entrées/transferts, pagination, refus pour USER, absence d’auteur ou d’identifiant du magasin distant.
- Catalogue global : recherche/catégorie/statut combinés, cinq tris et stabilité entre pages ; stocks associés exclusivement dans le périmètre autorisé.
- Contexte de l’en-tête utilisé par défaut à l’écran ; filtre vide explicite pour consulter tous les magasins ; API indépendante du contexte ; contexte périmé retiré.
- Rendu HTML, échappement des libellés, absence de liens/options de magasins inaccessibles et affichage ADMIN des magasins désactivés.

Les tests existants d’authentification et de navigation ont été mis à jour pour la nouvelle route Stocks et la section supplémentaire. Le reste de la suite, y compris concurrence catalogue/propositions, sessions, comptes, magasins et migrations protégées, reste vert. Les messages d’erreurs de scénarios négatifs (panne injectée, rollback refusé, route inexistante) sont attendus.

## Contrat et interface

Le contrat autonome comporte 59 opérations et 89 schémas : 31 opérations disponibles et 28 planifiées. Les quatre validations officielles indépendantes réussissent dans le conteneur contract-test ; les réponses stock et journal sont aussi validées contre leurs schémas pendant les tests PHP.

Contrôle navigateur local : connexion du compte dev, ouverture Stocks, détail avec journal vide, fiche article avec total, filtre de recherche soumis et réinitialisation. Contrôle responsive à 390 × 844 : largeur du document égale à 390, sans débordement global ; les tables restent dans leur zone de défilement. La taille normale a été rétablie. Capture : `/tmp/gestion-stock-stk01.jpg`. Aucun mouvement ni article fictif ajouté à la base locale pour cette recette.

## Livraison

[PR #62](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/62), basée sur `codex/art-02-proposals` (#61), encore ouverte au moment de la livraison. La recette est validée ; la dépendance doit être traitée avant intégration à main. Aucune nouvelle migration. Les formulaires de mouvements, la configuration des seuils et le journal général restent dans leurs lots respectifs.

Le premier lancement des tests avait été empêché par une limite temporaire du contrôle automatique d’approbation. Après confirmation de sa levée, la recette complète a été exécutée par le même contrôle habituel. Ce blocage est résolu.
