# Recette STK-01 et fin de ART-03

État au 18 octobre 2026 : **NON VALIDÉ**. Les images app/test/contract-test ont été construites. Le lancement des tests Docker a été refusé avant exécution par le contrôle automatique d’approbation, dont la limite d’utilisation était atteinte. Les dernières corrections n’ont pas encore été reconstruites. Aucune réussite PHP, SQL ou navigateur n’est revendiquée pour ce lot.

## Contrôles à exécuter

1. Reconstruire les images : `docker compose build app test contract-test`.
2. Exécuter le test ciblé : `docker compose run --rm --no-deps test php vendor/bin/phpunit tests/StockConsultationTest.php`.
3. Corriger tout échec, reconstruire et exécuter la suite complète : `docker compose run --rm test`.
4. Vérifier les écrans Stocks, fiche stock, fiche article et filtres sur ordinateur et mobile ; absence de débordement, formulaires sans JavaScript et échappement des libellés.
5. Déployer les images locales validées, puis vérifier la santé des services. Aucun enregistrement métier fictif ne doit être ajouté à la base locale.
6. Actualiser cette recette, suivi.md et les issues #12/#13 avec les résultats réels avant de déclarer la livraison prête.

## Couverture écrite, non encore exécutée

`StockConsultationTest` utilise un schéma PostgreSQL isolé et une transaction annulée après chaque test. Les cas couvrent les trois rôles, les magasins affectés/non affectés/désactivés, les modifications d’affectation, les totaux avant pagination et sur page vide, les sommes supérieures à la précision JavaScript, les couples sans solde, les seuils nuls ou à zéro, les filtres combinés, les recherches littérales, les paramètres invalides, les fiches et le journal local sans identités distantes. Les tris du catalogue sont comparés entre vue complète et pages successives.

Les validations officielles OpenAPI existantes restent obligatoires. La tentative de les exécuter directement sur l’hôte n’a pas abouti, car `jsonschema` n’y est pas installé ; le conteneur contract-test reste la méthode prévue.
