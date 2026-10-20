# Validation INV-01 — Campagnes et gel des mouvements

Recette Docker du 20 octobre 2026 : **232 tests PHP / 3 394 assertions réussis**, sortie 0, durée PHPUnit 97,485 s. Les **quatre validations indépendantes OpenAPI** réussissent. Neuf tests dédiés aux inventaires sont inclus dans la suite (`InventoryWorkflowTest` et `InventoryConcurrencyTest`).

## Fonctionnement et droits

- Création d’un brouillon sans mouvement ni solde ; résultat et annulation idempotents ; audit unique ; réponse d’origine stable même après changement d’état. Clé employée sur une autre opération : 409.
- Administrateur/gestionnaire autorisés selon le magasin ; USER refusé ; gestionnaire sans affectation sans accès ; retrait d’affectation immédiatement pris en compte. Magasin désactivé : écriture interdite, lecture administrative conservée.
- Formulaire de préparation et annulation, redirection, navigation active, erreurs de filtres et CSRF contrôlées. Réponses de campagne et de lignes conformes au contrat.
- Démarrage : référence au solde courant et non au solde lors de la préparation. Articles actifs avec ou sans solde et désactivés avec stock positif inclus ; désactivés sans stock exclus. Aucun solde à zéro créé. Références théoriques conservées après annulation ; valeurs non saisies distinctes de zéro.
- Tous les moteurs bloqués en cours : entrée, consommation, ajustement, transfert dans les deux sens, retour et annulation/correction. Un autre magasin reste utilisable. Désactivation du magasin refusée pendant la campagne.
- Soumission refusée tant qu’une quantité reste non saisie ; zéro est accepté ; l’état à valider maintient le gel. Annulation libère le magasin sans ajustement.
- Référence, lignes et requêtes protégées contre mutation/suppression ; transitions interdites et campagnes annulées/clôturées immuables. Les clôtures utilisées dans les tests sont des fixtures SQL isolées ; la clôture opérationnelle relève d’INV-03.
- Une panne injectée dans l’audit du démarrage annule l’état, les lignes et le résultat idempotent. La même clé peut ensuite aboutir normalement.

## Concurrence réelle

Des processus PHP et connexions PostgreSQL distincts vérifient :

1. Deux brouillons du même magasin démarrés simultanément donnent un succès et un refus 409 ; un seul inventaire actif et une seule référence.
2. Deux démarrages avec la même clé donnent la même réponse et un seul audit.
3. Un mouvement non validé garde le verrou partagé ; le démarrage attend. Après validation du mouvement, la référence inclut son nouveau solde (5 + 2 = 7).
4. Un démarrage non validé garde le verrou incompatible ; un transfert attend. Après validation du démarrage, le transfert est refusé pour inventaire en cours ; aucun solde destinataire n’est créé.

L’attente du verrou est observée dans `pg_stat_activity`. La réconciliation finale ne présente aucun écart. Les schémas de test sont isolés et supprimés après recette.

## Interface et environnement local

Navigateur avec le compte de développement : menu Inventaires, liste vide et formulaire de préparation. Aucun brouillon de démonstration créé dans les données locales. La soumission des formulaires et les détails en cours/annulés/clôturés sont vérifiés par les tests isolés.

Recette mobile du formulaire à 390 × 844 : largeur du document et du contenu égales à 390, contrôles lisibles ; taille normale restaurée. Aperçu local : `/tmp/gestion-stock-inv01.jpg`.

La migration `Version20261020220000` a été appliquée explicitement dans l’environnement local avec succès : une migration, six requêtes SQL. Elle ne crée ni campagne ni mouvement. Le registre Doctrine contient désormais neuf migrations ; démarrage web et init ne remplacent pas cette commande explicite.

## Contrat et périmètre

**60 opérations / 94 schémas : 47 disponibles / 13 planifiées.** Liste, création, détail, référence paginée, démarrage, soumission et annulation disponibles. Saisie des quantités dans INV-02 ; validation avec ajustements et clôture dans INV-03. [Guide](inventaires.md).

Branche de livraison : `codex/inv-01-inventory-campaigns`.
