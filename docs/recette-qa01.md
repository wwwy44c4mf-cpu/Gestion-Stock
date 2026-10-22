# QA-01 — Recette reproductible du MVP

## Exécuter

Depuis le dépôt, avec Docker Compose ≥ 2.24.4 et Python 3 disponibles :

- `sh bin/test-qa core` : construit les images depuis les sources, migre une base jetable, exécute PHPUnit et OpenAPI, vérifie les preuves de recette, les parcours HTTP et les performances.
- `sh bin/test-qa full` : ajoute sauvegarde/restauration, recréation des conteneurs, HTTPS, mise à jour sur données existantes et pannes worker/SMTP/base. C’est la recette complète REC-01 à REC-18.
- `sh bin/test-qa performance` : relance les parcours HTTP et la charge uniquement ; ne prétend pas remplacer la suite PHPUnit ni les scénarios de reprise.

Aucun `.env`, `.secrets`, port publié ou volume de l’installation existante n’est utilisé. Le projet, le réseau interne, la base en mémoire et les secrets fictifs ont leur propre cycle de vie. Les conteneurs et secrets sont supprimés en fin de commande, y compris après échec. Les rapports restent sous `var/qa/<date-UTC>-<pid>/`, répertoire exclu de Git. Ne pas exécuter la génération des données fictives sur une base métier.

Le lanceur construit ses images ; aucune image applicative préexistante n’est requise. Il initialise aussi Messenger avant les tests d’infrastructure. Le compte administrateur PostgreSQL fictif n’est monté que dans le conteneur PHPUnit pour la recette RGPD ; l’application HTTP utilise le rôle applicatif ordinaire. SMTP reste désactivé dans le banc ; la recette d’incident utilise uniquement un port local refusant la connexion.

## Preuves des critères

Le fichier `coverage.json` est produit à partir du JUnit réellement exécuté. Une preuve absente, échouée ou ignorée fait échouer le contrôle. En mode `core`, REC-15/16 restent explicitement à vérifier par la recette de reprise ; seul `full` les valide. Les contrôles supplémentaires portent aussi sur les retours concurrents, la publication du catalogue, le gel d’inventaire, les seuils, les périmètres et l’effacement des données.

| Critère | Preuve principale exécutée |
|---|---|
| REC-01 — API anonyme refusée | `ConsumptionTest::testEmptyOptionsContextNavigationAndAnonymousRequests` ; HTTP anonyme |
| REC-02 — magasin non affecté | `AccessControlTest::testMutationBoundariesAndBothTransferStores` ; HTTP avec stock inchangé |
| REC-03 — entrée 5 + 10 = 15 | `StockEntryTest::testApiCreditsFivePlusTenWithAuthorServerDateAndOneDurableEvent` ; HTTP |
| REC-04 — consommation 15 − 4 = 11 | `ConsumptionTest::testUserApiConsumesFourOfFifteenForSelfWithServerAttributionAndReceipt` ; HTTP et bénéficiaire |
| REC-05 — stock insuffisant | `ConsumptionTest::testInsufficientStockInventoryAndRevokedAssignmentsLeaveStockUnchanged` ; HTTP |
| REC-06 — dernière unité simultanée | `StockMovementConcurrencyTest::testLastUnitReplaysFirstCreditsAndOppositeTransfersAcrossProcesses` ; deux sessions HTTP synchronisées |
| REC-07 — transfert atomique | `StockTransferTest::testApiDebitsAndCreditsThreeWithOneCommonIdAndDurableEvent` ; HTTP |
| REC-08 — seuil et envoi unique | `AlertLifecycleTest::testExactThresholdsCriticalPriorityAndEveryDirection` et `NotificationConcurrencyTest::testTwoDispatchersAndTwoWorkersDoNotDuplicateDelivery` |
| REC-09 — résolution conservée | `AlertLifecycleTest::testResolvedAlertsAndTransitionsAreImmutable` |
| REC-10 — inventaire 10 → 8 | `InventoryValidationTest::testTenToEightClosesWithExactlyOneLinkedAdjustmentAndReleasesStore` ; HTTP et gel vérifié |
| REC-11 — période statistique | `ConsumptionStatisticsTest::testStoreCategoryAndDateFiltersApplyToEveryAggregate` |
| REC-12 — cloisonnement des lectures | `ConsumptionStatisticsTest::testKnownDatasetTotalsScopesAndSeparateReturnDates` et `AccessControlTest::testScopesApplyBeforeSumsCountsAndPagination` ; quatre API refusées hors magasin |
| REC-13 — SMTP indisponible | `NotificationTest::testSmtpFailureDoesNotRollBackMovementAndRetriesAreBoundedAndSanitized` ; refus TCP réel dans `bin/test-operations` |
| REC-14 — rejeu sans double sortie | `ConsumptionTest::testReplayKeepsTheOriginalAttributionAndDoesNotDebitAgain` ; HTTP avec même identifiant et solde |
| REC-15 — volumes préservés après down/up | `bin/test-backup-recovery`, recréation des conteneurs et authentification sur les données récupérées |
| REC-16 — restauration vierge | `bin/test-backup-recovery`, déchiffrement, empreintes, verrou de restauration, retraitement puis connexion et stocks |
| REC-17 — article mouvementé non supprimable | `CatalogTest::testArchivedArticleKeepsAllHistoryAndCannotBeConsumedEvenByDirectSql`, archivage, FK et refus HTTP DELETE |
| REC-18 — ancienne session désactivée | `UserAdministrationTest::testDeactivationImmediatelyRejectsSessionsAndNewLogins` ; HTTP, puis réactivation et nouvelle connexion |

Les essais PHPUnit emploient une vraie base PostgreSQL et des schémas isolés ; les tests de concurrence concernés utilisent plusieurs processus. La recette HTTP utilise Nginx, PHP-FPM en mode production et les contrôles de session/CSRF réels. Les doubles de transport des tests unitaires ne sont pas présentés comme des envois SMTP réels.

## Mesure de charge

Données : cinq comptes actifs (un administrateur, deux gestionnaires et deux techniciens), deux magasins, vingt articles, quarante entrées initiales puis 360 jours de dix mouvements par jour (deux entrées et huit consommations). Le vingtième article est réservé aux cas de recette avec quantités connues. Le banc vérifie ensuite qu’il reste exactement cinq comptes, deux magasins et vingt articles, aucun solde négatif et aucun écart de réconciliation.

Après dix-huit lectures d’échauffement exclues des mesures, les cinq sessions démarrent ensemble et effectuent quarante cycles : stocks, historique, statistiques et consommation. Les trois comptes habilités lisent également le tableau de bord ; les techniciens n’obtiennent aucun droit supplémentaire. Résultat : **920 requêtes mesurées**, 200 par parcours commun et 120 pour le tableau de bord. Aucune temporisation artificielle ni suppression des contrôles métier.

Le p95 est la valeur de rang `ceil(0,95 × n)` parmi les temps triés. Au moins cent mesures sont requises par parcours ; une valeur invalide ou un statut HTTP inattendu fait échouer la recette. Chaque lecture doit avoir un p95 strictement inférieur à 2 000 ms ; l’écriture doit rester sous 3 000 ms. Les échecs ne sont pas exclus des données pour obtenir un résultat vert.

Le rapport inclut l’architecture, les ressources allouées à Docker, PHP/PostgreSQL, le nombre de mesures, p50/p95/max et les seuils. Le transport mesuré est **HTTP interne Docker**, sans réseau externe ni surcoût TLS. Les contrôles HTTPS sont réalisés séparément. Ces mesures locales ne garantissent pas les temps du serveur final : rejouer la recette sur des ressources comparables avant production.

## Intégration continue

Le workflow `.github/workflows/qa.yml` est déclenché sur les pull requests, les publications sur main et à la demande. Deux contrôles doivent réussir :

1. **Métier, concurrence, RGPD et performances** : `core`, JUnit, preuves et charge.
2. **Restauration, HTTPS et incidents** : les trois recettes d’exploitation sur installations jetables.

Les permissions du jeton sont limitées à la lecture du dépôt. Aucun secret de déploiement n’est requis ; les secrets de recette sont générés à chaque exécution. Le checkout est fixé à un commit et ne conserve pas les identifiants Git. Le résumé du job ne copie que des mesures agrégées, jamais les fixtures ou les journaux bruts. [Documentation officielle de checkout](https://github.com/actions/checkout).

Le workflow est livré comme code. Son état sur GitHub doit être distingué des recettes locales exécutées ; les règles de protection de branche restent du ressort du propriétaire du dépôt.
