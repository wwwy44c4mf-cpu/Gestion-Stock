# Validation INV-03 — Validation atomique et clôture

Recette Docker du 20 octobre 2026 : **249 tests PHP / 3 660 assertions réussis**, sortie 0, durée PHPUnit 95,584 s. Les **quatre validations indépendantes OpenAPI** réussissent. Recette ciblée : **9 tests / 140 assertions**, sortie 0, durée 4,681 s (`InventoryValidationTest` et `InventoryValidationConcurrencyTest`).

## Stocks, traçabilité et droits

- Scénario REC-10 : 10 théoriques / 8 comptés → un ajustement de sortie de 2 lié à l’inventaire, stock final 8 et réconciliation sans écart.
- Créateur, auteur du comptage et validateur conservés séparément ; date de clôture serveur. Le validateur devient auteur de l’ajustement, dont le motif est généré et non vide.
- Écarts positifs, négatifs, articles désactivés inclus dans la référence et quantités comptées à zéro traités. Écart nul : aucun mouvement ni événement et version du solde inchangée. Référence zéro / comptage zéro : aucun solde créé.
- Campagne de 31 articles clôturée intégralement au-delà de la pagination ; résultat contenant tous les identifiants d’ajustement.
- Complétude et état à valider requis ; dérive du solde et limite de version refusées, avec gel conservé et sans clôture partielle.
- USER et gestionnaire non affecté refusés. Affectation retirée : même le rejeu d’une validation déjà réussie devient interdit. CSRF, absence de corps et confirmation du formulaire contrôlés.
- Rejeu avec même clé et acteur : mêmes identifiants et aucune réécriture, même après un mouvement ultérieur. Nouvelle clé sur une campagne clôturée : 409.
- Audit de clôture en panne injectée après les ajustements : retour intégral des soldes, versions, mouvements, événements, marqueurs de clôture et résultat idempotent. La même clé peut ensuite réussir.
- En base : ajustement incohérent, doublon article/campagne et clôture sur solde non aligné rejetés. Présence anormale d’un ajustement préalable : clôture refusée explicitement.
- Campagne clôturée non modifiable, ajustements d’inventaire exclus des corrections ordinaires. Formulaire et liens réciproques entre inventaire et mouvements vérifiés sur schémas isolés.

## Concurrence réelle

Processus PHP et connexions PostgreSQL distincts :

1. Deux validations avec la même clé donnent deux réponses identiques et un seul ajustement.
2. Deux comptes autorisés validant simultanément donnent un succès et un conflit ; aucune duplication.
3. Validation et annulation concurrentes : une seule transition terminale, soldes conformes à l’issue retenue.
4. Une entrée attend la transaction de clôture, puis reprend à partir du solde ajusté. L’attente est observée dans `pg_stat_activity` ; la réconciliation ne présente aucun écart.

Les mouvements et événements durables sont en nombre égal dans la recette concurrente. Les données sont créées en schémas isolés puis supprimées ou annulées ; aucun stock ni inventaire réel de démonstration ajouté.

## Migration et exploitation

La onzième migration `Version20261021000000` ajoute l’index unique et les contrôles de cohérence des ajustements/clôtures. Application locale réussie le 20 octobre : une migration, cinq requêtes SQL, sans modification de quantité. Les services de développement ont été mis à jour. Le retour arrière refuse les clôtures ou ajustements existants.

Contrat : **60 opérations / 94 schémas : 49 disponibles / 11 planifiées**. `validateInventory` disponible, réponse `InventoryValidationResource`. Les identifiants retournés couvrent toute la campagne, sans limite liée à une page ou à l’ancien plafond de résultat planifié.

INV-02 intégré dans main (PR #71). Branche de livraison : `codex/inv-03-inventory-validation`. [Guide](inventaires.md).
