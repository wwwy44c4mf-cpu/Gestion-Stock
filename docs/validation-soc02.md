# Validation SOC-02

Date : 18 octobre 2026, Europe/Paris. Environnement Docker Linux/arm64, PHP 8.4.26 et PostgreSQL 18. SOC-01 est intégré à main par la PR #51 ; SOC-02 est livré pour revue séparée.

## Résultats constatés

| Exigence | Vérification | Résultat |
|---|---|---|
| Modèle du CDC et arbitrages | 17 tables métier, diagramme ER et documentation des invariants | Livrés |
| Base vierge | Vraie commande Doctrine dans des schémas de test isolés | Migration réussie |
| Migration locale | `doctrine:migrations:migrate --no-interaction` | 1 migration, 53 instructions SQL |
| Réexécution | Même commande sur la base locale | Déjà à la dernière version ; aucune duplication |
| Retour arrière vide | Migration vers 0 puis réapplication en test | Réussi |
| Retour arrière avec données | Comptes et article fictifs présents dans un schéma isolé | Refus explicite ; données conservées |
| Quantités entières | Fractions ordinaires et infimes, négatifs, NaN, Infinity, dépassement de borne | Écritures invalides refusées |
| Unicités et liens | Référence article, code magasin, courriel, couple stock, clés d’idempotence, FK | Contraintes vérifiées |
| Journal | UPDATE/DELETE et suppression d’objets référencés | Refusés ; archivage logique possible |
| Transferts et réconciliation | Journal avec entrée, sortie, transfert, retour et ajustement | Transfert net nul ; écarts et solde manquant détectés |
| Retours | Origine non consommation ou magasin incorrect | Refusés ; bénéficiaire sans affectation accepté dans le modèle |
| Inventaire | Comptage non saisi vs zéro, écart généré, campagne active unique | Vérifiés |
| Propositions | Approbation sans revue | Refusée, catalogue inchangé |
| Alertes et notifications | Alerte active unique, événement unique et rappel par jour/personne | Doublons refusés |
| Anonymisation et audit | Identité retirée, références conservées ; journal d’audit immuable | Vérifiés |
| Tests | `docker compose run --rm --no-deps test` | 18 tests réussis, 92 assertions |
| Configuration | `lint:container`, `lint:yaml config` | Réussis ; 6 fichiers YAML |
| Dépendances PHP | `composer audit` | Aucune vulnérabilité connue signalée |
| Isolation | Schémas `soc02_%` après tests | 0 schéma restant |
| Soldes locaux | `app:stock:reconcile` | `consistent: true`, aucun écart |

Les messages d’erreur de route inconnue et de rollback refusé dans les journaux de tests correspondent à des refus attendus, pas à des erreurs ignorées. Les tests de retour arrière utilisent une nouvelle factory Doctrine par commande pour reproduire les invocations CLI séparées.

## Périmètre de la preuve

Cette recette couvre le stockage et les migrations, pas les futurs parcours de gestion de stock. Les validations de rôle, la concurrence des retraits/retours, le gel des mouvements, les envois SMTP et les contrôles de transition seront vérifiés dans leurs lots. Les migrations ne sont pas appliquées automatiquement au démarrage ; leur procédure figure dans [migrations.md](migrations.md).

Aucune migration descendante n’a été lancée sur les données de l’environnement local principal. Les exemples restent dans des transactions temporaires annulées. Le contrôle de réconciliation est en lecture seule.
