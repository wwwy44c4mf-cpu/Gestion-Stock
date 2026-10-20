# Validation INV-02 — Saisie des comptages et écarts

Recette Docker du 20 octobre 2026 : **240 tests PHP / 3 520 assertions réussis**, sortie 0, durée PHPUnit 94,729 s. Les **quatre validations indépendantes OpenAPI** réussissent. Recette ciblée : **8 tests / 126 assertions**, sortie 0, durée 4,687 s (`InventoryCountTest` et `InventoryCountConcurrencyTest`).

## Saisie, sécurité et calcul

- Zéro explicitement enregistré avec auteur, date et version ; article omis conservé non compté. Quantité vide, négative, décimale, booléenne, textuelle ou supérieure à la borne refusée. Lots vides ou de plus de 100 lignes refusés ; version obligatoire.
- Lots atomiques : article extérieur à la référence, doublon après normalisation de casse ou version obsolète refusent tout le lot. Aucun solde ni mouvement créé par la saisie.
- Rejeu idempotent sans réécriture de quantité, auteur, heure ou version, y compris après saisie plus récente ou soumission ; clé employée pour un autre contenu refusée. Autorisations revérifiées même au rejeu.
- Rôles et magasin : USER refusé, gestionnaire hors périmètre refusé, affectation retirée immédiatement prise en compte. CSRF et clé d’idempotence contrôlés.
- Modification uniquement en cours ; brouillon, campagne soumise et annulée refusés. La protection existante des campagnes clôturées reste active.
- Écart exact `compté − théorique` ; pourcentage nul si référence zéro ou ligne non saisie. Exemple 3 théoriques / 2 comptés : écart −1 et −33,33 %. Borne entière 9 007 199 254 740 991 testée sans perte d’unité ; calcul décimal suffisamment précis même avec de grands résultats et deux décimales.
- L’audit conserve les quantités et versions avant/après. Panne d’audit injectée : quantités, auteur, date, version et résultat idempotent intégralement annulés ; nouvelle tentative avec la même clé possible.

## Concurrence réelle

Deux processus et connexions PostgreSQL distincts vérifient :

1. Deux comptages fondés sur la même version : un succès, un conflit 409 ; aucune saisie perdue.
2. Deux requêtes identiques avec la même clé : deux réponses identiques, un seul incrément.
3. Une soumission attend la validation d’un comptage zéro, puis reconnaît sa complétude.
4. Un comptage attend une soumission engagée, puis est refusé après son passage à valider ; la quantité et la version restent inchangées.

L’attente des verrous est observée dans `pg_stat_activity`. Stock et réconciliation restent inchangés.

## Interface

Tests de formulaires sur schémas isolés : champ vide distingué de zéro ; enregistrement puis retour à la référence avec pagination ; progression globale ; proposition de soumission uniquement quand tout est compté ; conflit conservant la valeur saisie avec un lien de rechargement ; quantité vide refusée ; pourcentage « Non défini » pour référence zéro.

Dans le navigateur local, la session de développement et le menu Inventaires fonctionnent après mise à jour. La liste locale ne contient aucune campagne : aucun inventaire de démonstration n’a été créé pour la recette. Les écrans de comptage sont vérifiés par les tests de formulaires isolés ; aucune saisie réelle n’a été modifiée. Aperçu de l’espace local : `/tmp/gestion-stock-inv02.jpg`.

## Migration et livraison

La dixième migration `Version20261020230000` ajoute la version des lignes et son incrément automatique. Application locale réussie : une migration, trois requêtes SQL. Elle ne modifie aucune quantité. Retour arrière refusé si des versions positives existent.

Contrat : **60 opérations / 94 schémas, 48 disponibles / 12 planifiées**. `writeInventoryLines` disponible ; `CountInput.expected_version` obligatoire ; `InventoryLine.version` et `delta_percent` exposés. Validation/clôture avec ajustements dans INV-03.

INV-01 intégré dans main (PR #70). Branche de livraison : `codex/inv-02-inventory-counts`. [Guide](inventaires.md).
