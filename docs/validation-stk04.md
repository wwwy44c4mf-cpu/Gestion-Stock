# Validation STK-04 — Consommations

Recette Docker du 18 octobre 2026 : **167 tests PHP / 2 432 assertions et quatre validations indépendantes OpenAPI réussis**. Suite complète `docker compose run --rm test`, sortie 0, durée PHPUnit 72,712 s. Les 12 nouveaux tests de `tests/ConsumptionTest.php` couvrent 142 assertions ; recette ciblée initiale réussie puis suite complète après amélioration d’une assertion de confidentialité.

## Scénarios vérifiés

- REC-02 : consommation API de 4 sur un stock initial de 15 donnant 11 ; mouvement, audit et événement, attribution serveur et réponse conforme à `MovementResource`, réconciliation sans écart. Location mène à une vraie confirmation HTML.
- REC-04 : gestionnaire et administrateur enregistrent pour un technicien actif extérieur au magasin ; opérateur et bénéficiaire distincts. Un USER qui forge un bénéficiaire tiers reçoit 403, sans écriture.
- REC-05 : quantité supérieure au disponible refusée sans variation du solde, du journal, de l’audit ou des événements. Inventaires in_progress et pending_validation bloquants ; perte d’affectation après ouverture du formulaire également refusée.
- Formulaire sans JavaScript : utilisateur limité à lui-même sans liste de tiers ; gestionnaire avec noms minimaux des bénéficiaires actifs, sans courriels ni comptes inactifs ou réservés au développement. Soumission valide, référence et commentaire "0" conservés, textes échappés et confirmation opérateur/bénéficiaire.
- Double envoi : même résultat, un seul débit ; corps différent avec la même clé : 409. Le rejeu après désactivation du bénéficiaire conserve l’attribution initiale sans nouveau débit.
- Confirmation personnelle accessible sans affectation, sans exposition du stock actuel ni lien vers sa fiche. Autre utilisateur ou gestionnaire hors périmètre : 404 ; magasin désactivé : seul ADMIN peut consulter. Mouvement d’entrée, identifiant inexistant ou mal formé refusés.
- Articles inactifs, magasins inactifs ou non autorisés, bénéficiaires inactifs ou réservés au développement refusés. Présélection GET contrôlée, contexte magasin repris, lien de navigation raccordé ; gestionnaire sans magasin : options vides et validation désactivée, sans noms de tiers.
- Quantités zéro, négatives, fractionnaires, exposants, chaînes JSON, booléens, null et hors borne refusées ; références/commentaires trop longs, texte nul binaire ou non UTF-8, auteur/date/champs inconnus, tableaux et clé invalide refusés. Erreur de quantité conserve les valeurs et la clé ; CSRF invalide refusé. Visiteur anonyme redirigé à la connexion ; API avec CSRF valide sans session : 401.

Les écritures de test utilisent des schémas PostgreSQL isolés, annulés après chaque scénario. Les tests existants restent verts, y compris concurrence réelle du moteur et migrations. Les messages d’erreur des scénarios négatifs du socle et des migrations protégées sont attendus.

## Interface et exploitation

Contrôle dans le navigateur local avec l’administrateur de développement : navigation Consommations, liste des champs et bénéficiaire par défaut, puis examen visuel ordinateur et mobile 390 × 844. Largeur document et largeur utile égales à 375 pixels ; aucun débordement horizontal, bouton et champs du bas accessibles. Taille normale rétablie. Capture complète : `/tmp/gestion-stock-stk04.jpg`.

Aucun mouvement de démonstration soumis dans la base locale réelle. Images app/test/web/contract-test construites et services locaux actualisés. Aucune migration supplémentaire après STK-02 (`Version20261018200000`).

Contrat : 59 opérations, 89 schémas ; 33 opérations disponibles, 26 planifiées. Seule `createConsumption` est nouvellement disponible. La lecture complète d’historique personnel et les statistiques restent planifiées. Guide : [consommations.md](consommations.md).

Livraison : branche `codex/stk-04-consumptions`, basée sur main après intégration de la PR #64.
