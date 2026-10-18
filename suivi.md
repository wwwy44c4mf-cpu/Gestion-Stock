# Suivi du développement — Gestion-Stock

Date de préparation : 18 octobre 2026.
Source : [cdc.md](cdc.md), version 1.0 du 17 octobre 2026, et arbitrages utilisateur recueillis dans cette conversation.
État : SOC-01 à SOC-04 et AUTH-01/02 intégrés à main (PR #51 à #55 et #57). AUTH-03 intégré à main (PR #58). MAG-01 implémenté et vérifié, en revue ; autres fonctionnalités à développer.
Les décisions ci-dessous précisent ou remplacent les options correspondantes du CDC ; le CDC original est conservé.

## Décisions validées

| Sujet | Décision |
|---|---|
| Interface | Symfony / Twig / Stimulus ; interface française responsive. |
| Architecture | Application et dépendances intégralement sous Docker ; API REST /api/v1, PostgreSQL, worker et scheduler conteneurisés. Symfony 8.x proposé par le CDC ; versions PHP et dépendances compatibles à vérifier et épingler à l’implémentation. |
| Catalogue global | Les gestionnaires proposent créations et modifications ; un administrateur valide avant publication. Les administrateurs administrent directement le catalogue. |
| Consommation pour autrui | Réservée aux administrateurs et gestionnaires autorisés sur le magasin ; tout technicien actif peut être bénéficiaire, même sans affectation à ce magasin. |
| Désactivation pendant inventaire | Refus tant que l’inventaire est en cours ou en attente de validation ; le terminer ou l’annuler d’abord. |
| Magasin désactivé | Stocks et historiques accessibles uniquement aux administrateurs ; aucune écriture de stock. |
| Consultation personnelle | Les utilisateurs consultent leur historique et leurs statistiques de consommation, y compris pour un magasin actif non affecté. Un magasin désactivé reste réservé aux administrateurs. Cette permission n’ouvre pas les stocks des magasins non affectés. |
| Référence métier | Référence d’intervention, chantier ou client facultative dès le MVP ; gestion structurée des interventions en V2. |
| Inventaire | Mouvements du magasin bloqués pendant comptage et validation, y compris entrées, sorties, ajustements, retours et transferts impliquant le magasin. Seuls administrateurs et gestionnaires autorisés saisissent et valident. |
| Quantités | Une seule unité : « unité » ; nombres entiers uniquement. Les décimales et autres unités ne sont pas nécessaires au MVP. |
| Dates | Date et heure serveur à la saisie, sans rétroactivité ; stockage UTC, affichage local, Europe/Paris par défaut. |
| Retours | Dès le MVP, retours liés à la consommation initiale, dans le magasin d’origine ; administrateurs/gestionnaires selon périmètre, utilisateurs pour leurs propres consommations. Cumul limité à la quantité sortie. L’utilisateur conserve le droit de retour personnel après retrait d’affectation si le magasin d’origine est actif. |
| Seuils | Personnalisables par article et magasin. La dernière précision remplace l’idée initiale de valeurs par défaut : aucune valeur globale chiffrée n’a été validée. |
| Alertes immédiates | Courriels à toute heure lors des franchissements de seuil ; destinataires par défaut : gestionnaires du magasin et administrateurs. |
| Rappels | Dès le MVP, récapitulatif quotidien par destinataire à 08 h Europe/Paris tant que des alertes persistent. |
| Hébergement | Un serveur unique sous Docker Compose ; SMTP existant disponible ; reverse proxy encore inconnu. |
| Dimensionnement initial | 2 magasins, 5 utilisateurs simultanés, 20 références et environ 10 mouvements par jour. |
| Données nominatives | Conservation pendant un an puis anonymisation ; modalités précises à formaliser avant production. |
| Reprise | Remise en service souhaitée sous un jour ouvré. Aucune perte souhaitée, mais risque résiduel accepté sur un serveur unique ; second hébergement synchrone refusé. Aucun RPO nul garanti. |

## Points ouverts sans blocage de la préparation

Ces sujets ne constituent pas des choix déjà validés. Ils seront précisés une question à la fois au moment nécessaire.

- Avant déploiement : nom de domaine, reverse proxy existant ou à déployer, ressources du serveur, configuration SMTP sans partage de secrets dans Git, objectif de disponibilité.
- Avant production : rétention et destination des sauvegardes, fréquence et RPO effectif compatibles avec le risque accepté ; sauvegarde quotidienne minimale prévue au CDC. Ne pas inventer une rétention de 7/30/90 jours.
- Avant production : responsable de maintenance, supervision et restauration ; responsable de traitement des données.
- Avant anonymisation en production : date de référence du délai d’un an, données de comptes actifs et statistiques personnelles, méthode empêchant la réidentification et traitement des sauvegardes restaurées. Préserver le journal quantitatif.
- Avant diffusion : licence du code.
- À préciser dans la conception : règles fines sur articles inactifs et changements de seuils. La configuration absente doit rester distincte d’un seuil zéro.

## Organisation et ordre de réalisation

| Phase | Livrable | Condition de sortie |
|---|---|---|
| 0 — Préparation | Présent suivi et issues, décisions et points ouverts | Découpage complet et traçable ; paramètres ouverts identifiés |
| 1 — Socle | Docker, schéma, API, interface, authentification, droits, magasins, catalogue et file SMTP | Connexion et administration opérationnelles ; propositions d’articles validables |
| 2 — Stock | Moteur transactionnel, entrées, sorties, corrections, transferts et retours | Stock non négatif, idempotence et concurrence testés |
| 3 — Inventaires et alertes | Gel, comptages, validation, seuils, alertes et rappels | Recette inventaire et mails ; SMTP indisponible sans blocage métier |
| 4 — Suivi | Historique, consommations personnelles, statistiques, tableau de bord et audit | Agrégats cohérents et permissions vérifiées |
| 5 — Production | Sauvegardes, sécurité, supervision, anonymisation, recette et documentation | Restauration réussie et paramètres d’exploitation arrêtés |
| 6 — V2 | Extensions séparées | Cadrage dédié après MVP |

Commencer par SOC-01, puis SOC-02/SOC-03/SOC-04 ; suivre ensuite les dépendances de chaque lot. MAIL-01 appartient au socle pour débloquer la réinitialisation de mot de passe. Les tests métier accompagnent chaque lot ; QA-01 consolide la recette finale.
Aucune estimation de durée ou affectation n’est inventée ; celles-ci seront définies avec l’équipe.

## Registre des fonctionnalités et des issues

Chaque ligne correspond à une fonctionnalité programmable et à une issue. Les exigences proches sont regroupées dans un même lot ; les identifiants du CDC restent indiqués pour vérifier la couverture.
Statuts autorisés : À faire, En cours, Bloqué (avec raison), En revue, Terminé, V2 différée.

| Lot | Phase | Fonctionnalité | Références CDC | Dépendances | Statut | Issue |
|---|---|---|---|---|---|---|
| SOC-01 | 1 | Environnement Docker reproductible | DOCK-01,02,03,04,05,06,07,08,12; §7 | — | Terminé | [#1](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1) |
| SOC-02 | 1 | Modèle de données et migrations | §4,8; DOCK-09 | SOC-01 | Terminé | [#2](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2) |
| SOC-03 | 1 | Contrats API REST et erreurs normalisées | §9 | SOC-01 | Terminé | [#3](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/3) |
| SOC-04 | 1 | Interface française responsive et navigation | §6 | SOC-01 | Terminé | [#4](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/4) |
| AUTH-01 | 1 | Connexion, sessions et profil | AUTH-01,02,03,07; §10.1 | SOC-02, SOC-03, SOC-04 | Terminé | [#5](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/5) |
| AUTH-02 | 1 | Administration des comptes et désactivation | AUTH-04,09 | AUTH-01 | Terminé | [#6](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/6) |
| AUTH-03 | 1 | Rôles, affectations et contrôle des accès | AUTH-05; MAG-03; §2,4 | AUTH-02 | Terminé | [#7](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7) |
| AUTH-04 | 1 | Réinitialisation sécurisée du mot de passe | AUTH-06; MAIL-02 | AUTH-01, MAIL-01 | À faire | [#8](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/8) |
| MAG-01 | 1 | Création, modification et archivage des magasins | MAG-01,02,05 | AUTH-03 | En revue | [#9](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/9) |
| ART-01 | 1 | Catalogue global, catégories et archivage | ART-01,02,03; §4 | MAG-01 | À faire | [#10](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10) |
| ART-02 | 1 | Propositions d’articles et validation administrative | ART-01,02; décision utilisateur | ART-01 | À faire | [#11](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/11) |
| ART-03 | 1 | Recherche et consultation du catalogue | ART-04 | ART-01, SOC-04 | À faire | [#12](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/12) |
| STK-01 | 2 | Consultation des stocks et consolidation | STK-01; MAG-04 | ART-01, AUTH-03 | À faire | [#13](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/13) |
| STK-02 | 2 | Moteur transactionnel, concurrence et idempotence | STK-06,07,10; §4 | SOC-02, AUTH-03 | À faire | [#14](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14) |
| STK-03 | 2 | Entrées et réapprovisionnements | STK-02; parcours 5.3 | STK-02, STK-01 | À faire | [#15](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/15) |
| STK-04 | 2 | Consommations et bénéficiaire tiers | STK-03; CON-01,02; CON-05 partiel | STK-02, STK-01 | À faire | [#16](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/16) |
| STK-05 | 2 | Ajustements et corrections par contre-mouvement | STK-04,09 | STK-02 | À faire | [#17](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/17) |
| STK-06 | 2 | Transferts atomiques entre magasins | STK-05 | STK-02 | À faire | [#18](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/18) |
| STK-07 | 2 | Retours liés à une consommation | Décision utilisateur; STAT-06 | STK-04 | À faire | [#19](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/19) |
| STK-08 | 4 | Historique des mouvements filtrable | STK-08,09,10 | STK-03, STK-04, STK-05, STK-06, STK-07 | À faire | [#20](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/20) |
| INV-01 | 3 | Campagnes d’inventaire et gel des mouvements | INV-01,07 | STK-02 | À faire | [#21](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/21) |
| INV-02 | 3 | Saisie des comptages et calcul des écarts | INV-02,03 | INV-01 | À faire | [#22](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/22) |
| INV-03 | 3 | Validation atomique et clôture d’inventaire | INV-04,05,06 | INV-02, STK-05 | À faire | [#23](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/23) |
| ALT-01 | 3 | Seuils personnalisés par article et magasin | ART-05; ALT-01 | ART-01, AUTH-03 | À faire | [#24](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/24) |
| ALT-02 | 3 | Cycle de vie des alertes et franchissements | ALT-01,03,04 | ALT-01, STK-02, MAIL-01 | À faire | [#25](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/25) |
| ALT-03 | 3 | Écran des alertes et destinataires | ALT-02,05 | ALT-02 | À faire | [#26](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/26) |
| MAIL-01 | 1 | SMTP, file durable et modèles de courriels | MAIL-01,02,04,05,06,08 | SOC-02 | À faire | [#27](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27) |
| MAIL-02 | 3 | Messages d’information ciblés | MAIL-03 | MAIL-01, AUTH-03 | À faire | [#28](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/28) |
| MAIL-03 | 3 | Récapitulatif quotidien des alertes à 08 h | ALT-06 partiel; MAIL-07 partiel; décision utilisateur | ALT-03, MAIL-01 | À faire | [#29](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/29) |
| CON-01 | 4 | Historique personnel et suivi des consommations | CON-03,04; décision utilisateur | STK-04, STK-07, AUTH-03 | À faire | [#30](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/30) |
| STAT-01 | 4 | Statistiques de consommation et filtres | STAT-01,02,03,04,06 | CON-01 | À faire | [#31](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/31) |
| STAT-02 | 4 | Tableau de bord et indicateurs | STAT-05; §6 | STAT-01, ALT-03, STK-01 | À faire | [#32](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/32) |
| ADM-01 | 4 | Journal d’audit métier | §2,10.1,10.4 | AUTH-03, STK-02 | À faire | [#33](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/33) |
| OPS-01 | 5 | Sauvegardes, restauration et reprise | DOCK-10,11; §10.3 | SOC-01, SOC-02 | À faire | [#34](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/34) |
| OPS-02 | 5 | Déploiement HTTPS, sécurité et mises à jour | DOCK-03,04,05,07,08,09,10; §10.1 | SOC-01, AUTH-03 | À faire | [#35](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/35) |
| OPS-03 | 5 | Supervision et diagnostic | §10.4; DOCK-10 | SOC-01, MAIL-01 | À faire | [#36](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/36) |
| RGPD-01 | 5 | Anonymisation des données nominatives après un an | §10.1; décision utilisateur | ADM-01, SOC-02 | À faire | [#37](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/37) |
| QA-01 | 5 | Recette automatisée, concurrence et performances | REC-01 à REC-18; DOCK-12; §10.2,11 | INV-03, MAIL-03, STAT-02, OPS-01, OPS-02, RGPD-01 | À faire | [#38](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/38) |
| DOC-01 | 5 | Documentation, démonstration et livraison | §12,16 | QA-01 | À faire | [#39](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/39) |
| V2-01 | V2 | MFA et authentification SSO OIDC | AUTH-08 | AUTH-01 | V2 différée | [#40](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/40) |
| V2-02 | V2 | Emplacements internes des magasins | MAG-06 | MAG-01, STK-02 | V2 différée | [#41](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/41) |
| V2-03 | V2 | Images, codes-barres et références fournisseur | ART-06 | ART-01 | V2 différée | [#42](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/42) |
| V2-04 | V2 | Import et export CSV du catalogue | ART-07 | ART-01, ART-02 | V2 différée | [#43](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/43) |
| V2-05 | V2 | Réservations de stock | STK-11 | STK-02 | V2 différée | [#44](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/44) |
| V2-06 | V2 | Inventaire tournant et saisie par codes-barres | INV-08 | INV-03, V2-03 | V2 différée | [#45](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/45) |
| V2-07 | V2 | Accusés de réception et escalade des alertes | ALT-06 restant | ALT-03, MAIL-03 | V2 différée | [#46](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/46) |
| V2-08 | V2 | Gestion structurée des interventions et équipes | CON-05 restant | STK-04 | V2 différée | [#47](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/47) |
| V2-09 | V2 | Export CSV des consommations | CON-06 | CON-01 | V2 différée | [#48](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/48) |
| V2-10 | V2 | Exports statistiques et prévisions | STAT-07 | STAT-01 | V2 différée | [#49](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/49) |
| V2-11 | V2 | Abonnements et synthèses complémentaires | MAIL-07 restant | MAIL-03 | V2 différée | [#50](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/50) |

## Découpage détaillé pour la programmation

### SOC-01 — Environnement Docker reproductible

Issue : [#1](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1) · Terminé · Dépendances : aucune.

Sources : DOCK-01,02,03,04,05,06,07,08,12; §7.

- [x] Créer Dockerfile multi-étapes, Compose, .env.example et .dockerignore
- [x] Conteneuriser web, app, db, worker et scheduler ; réseaux privés, volumes, healthchecks et arrêt propre
- [x] Épingler des versions compatibles et vérifiées lors de l’implémentation ; secrets exclus de Git, privilèges minimaux

Critères d’acceptation : Un poste avec Docker seul démarre l’application et lance les tests ; base et workers non exposés publiquement.

Livraison : [PR #51](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/51), branche `codex/soc-01-docker`, liée à l’issue #1. Vérifications : [rapport SOC-01](docs/validation-soc01.md), 5 tests / 15 assertions, persistance après recréation, audit local des trois images sans vulnérabilité connue détectée. Démarrage : [README](README.md). HTTPS production et fonctionnalités métier restent dans leurs lots dédiés.

### SOC-02 — Modèle de données et migrations

Issue : [#2](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2) · Terminé · Dépendances : [SOC-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1).

Sources : §4,8; DOCK-09.

- [x] Modéliser les entités du CDC et les propositions d’articles, retours, rappels et événements durables
- [x] Définir clés étrangères, index, unicités et contraintes ; quantités entières en unité
- [x] Prévoir migrations explicites, diagramme et réconciliation des soldes

Critères d’acceptation : Une base vierge migre sans erreur ; références et couples article/magasin uniques ; aucune quantité fractionnaire.

Livraison : [PR #52](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/52), branche `codex/soc-02-data-model`, issue #2. [Modèle et diagramme](docs/modele-donnees.md), [migrations et retour arrière](docs/migrations.md), [rapport de validation](docs/validation-soc02.md). 17 tables métier, 18 tests / 92 assertions, réconciliation sans écart sur la base locale.

### SOC-03 — Contrats API REST et erreurs normalisées

Issue : [#3](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/3) · Terminé · Dépendances : [SOC-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1).

Sources : §9.

- [x] Définir /api/v1 et un contrat OpenAPI incluant propositions et retours
- [x] Normaliser validation, pagination, filtres, tri, codes HTTP et erreurs
- [x] Prévoir sessions cookie, CSRF, limitation de débit et clés d’idempotence

Critères d’acceptation : Les contrats documentent entrées, droits et erreurs de toutes les ressources ; pagination bornée.

Livraison : [PR #53](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/53), branche `codex/soc-03-api-contracts`, issue #3. [Contrat OpenAPI](docs/openapi.json), [conventions et intégration](docs/api.md), [rapport de validation](docs/validation-soc03.md). 58 opérations décrites, dont 3 routes techniques disponibles et 55 opérations métier planifiées. 34 tests PHP / 528 assertions et 4 tests indépendants du contrat. Droits métier et idempotence persistante à raccorder dans les lots concernés.

### SOC-04 — Interface française responsive et navigation

Issue : [#4](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/4) · Terminé · Dépendances : [SOC-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1).

Sources : §6.

- [x] Installer Twig et Stimulus ; préparer la navigation selon rôle et magasin sélectionné (contexte authentifié à raccorder dans AUTH-01/03)
- [x] Créer composants formulaires, erreurs, confirmations, tableaux et pagination
- [x] Prévoir navigation clavier, contrastes et affichage mobile

Critères d’acceptation : Les écrans sont utilisables au clavier et sur mobile ; messages et libellés en français.

Livraison intégrée : [PR #54](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/54). Interface commune Twig/Stimulus, tableau de bord et neuf états d’attente, six composants partagés. Navigation anonyme active ; règles par rôle et magasin testées, sélecteur désactivé en attendant AUTH. 38 tests / 589 assertions réussis. [Guide d’intégration](docs/interface.md), [rapport de validation](docs/validation-soc04.md).

### AUTH-01 — Connexion, sessions et profil

Issue : [#5](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/5) · Terminé · Dépendances : [SOC-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2), [SOC-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/3), [SOC-04](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/4).

Sources : AUTH-01,02,03,07; §10.1.

- [x] Créer connexion par courriel, hachage éprouvé et consultation du profil
- [x] Gérer déconnexion, expiration, cookies sécurisés et CSRF
- [x] Limiter les tentatives et auditer les échecs sans secrets

Critères d’acceptation : Connexion valide acceptée, invalide refusée ; session expirée rejetée ; REC-01.

Livraison AUTH-01 : [PR #55](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/55), branche `codex/auth-01-authentication`. Connexion interface/API, profil et fuseau, Argon2id, sessions avec expiration (30 minutes d’inactivité, 12 heures absolues), CSRF renouvelé, limites partagées et audit pseudonymisé. Commande interactive de premier administrateur ; aucun compte par défaut. [Guide](docs/authentification.md), [recette](docs/validation-auth01.md). Recette : 55 tests PHP / 809 assertions et quatre tests indépendants du contrat réussis. L’administration des comptes et les contrôles métier par magasin restent dans AUTH-02/03.

### AUTH-02 — Administration des comptes et désactivation

Issue : [#6](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/6) · Terminé · Dépendances : [AUTH-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/5).

Sources : AUTH-04,09.

- [x] Créer formulaires et API de création, modification, désactivation et réactivation
- [x] Réserver les opérations à l’administrateur et tracer les changements
- [x] Invalider les sessions actives dès désactivation

Critères d’acceptation : Un compte désactivé ne se connecte plus et son ancienne session est rejetée ; REC-18.

Livraison AUTH-02 : [PR #57](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/57), branche `codex/auth-02-user-administration`. Liste, filtres, formulaires et API de création/modification/désactivation/réactivation, rôle et affectations, accès réservé aux administrateurs. Révocation des sessions via `auth_version`, dernier administrateur protégé, résultats de création idempotents persistés et audit sans valeurs sensibles. Les comptes issus de l’administrateur de test héritent du marqueur dev. [Guide](docs/administration-comptes.md), [recette](docs/validation-auth02.md). Recette : 73 tests PHP / 1 001 assertions et quatre tests indépendants du contrat réussis. Le contrôle des périmètres métier reste dans AUTH-03.

### AUTH-03 — Rôles, affectations et contrôle des accès

Issue : [#7](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7) · Terminé · Dépendances : [AUTH-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/6).

Sources : AUTH-05; MAG-03; §2,4.

- [x] Implémenter ADMIN, MANAGER, USER et affectations multiples
- [x] Centraliser les contrôles serveur sur ressources, listes, agrégats et actions
- [x] Limiter les utilisateurs à leurs données personnelles ; bénéficiaire tiers actif sélectionnable par admin/gestionnaire sans ouvrir son profil complet

Critères d’acceptation : Accès inter-magasins refusé sur écrans et API ; aucun stock modifié ; REC-02,12.

Règle validée : les magasins désactivés, leurs stocks et leurs historiques sont réservés aux administrateurs ; écritures bloquées pour tous. Mise en œuvre vérifiée des contrôles centralisés, des listes filtrées, de la sélection du contexte magasin et du répertoire minimal des bénéficiaires. Retour personnel autorisé même après retrait d’affectation, uniquement pour une consommation propre et un magasin d’origine actif. [Guide](docs/controle-acces.md).

Livraison pour revue : [PR #58](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/58), branche `codex/auth-03-access-control`. Recette : 84 tests PHP / 1 186 assertions et quatre validations indépendantes du contrat réussis. [Validation](docs/validation-auth03.md). Les opérations de stock, inventaires et statistiques des lots suivants devront intégrer la politique commune ; leurs écrans restent en préparation.

### AUTH-04 — Réinitialisation sécurisée du mot de passe

Issue : [#8](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/8) · À faire · Dépendances : [AUTH-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/5), [MAIL-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27).

Sources : AUTH-06; MAIL-02.

- [ ] Créer demande, jeton temporaire à usage unique et formulaire de changement
- [ ] Éviter l’énumération des comptes et les secrets dans les logs
- [ ] Envoyer le lien par file durable

Critères d’acceptation : Jeton expiré ou déjà utilisé refusé ; mot de passe remplacé par un hash.

### MAG-01 — Création, modification et archivage des magasins

Issue : [#9](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/9) · En revue · Dépendances : [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : MAG-01,02,05.

- [x] Créer écrans et API avec code unique, nom, description, adresse facultative et statut
- [x] Réserver l’administration des magasins aux administrateurs
- [x] Interdire suppression des magasins mouvementés et préserver les relations historiques

Critères d’acceptation : Archivage conserve mouvements et données ; doublon de code rejeté.

Livraison vérifiée : liste autorisée, formulaires sans JavaScript et API de création/modification/archivage/réactivation. Code unique conservé ; création idempotente et audit atomiques. Désactivation refusée si un inventaire est en cours ou en attente de validation. Migration `Version20261018130000`. Recette : 94 tests PHP / 1 304 assertions et quatre validations OpenAPI réussis, dont créations simultanées par deux processus. [Guide](docs/gestion-magasins.md) · [Validation](docs/validation-mag01.md).

### ART-01 — Catalogue global, catégories et archivage

Issue : [#10](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10) · À faire · Dépendances : [MAG-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/9).

Sources : ART-01,02,03; §4.

- [ ] Créer articles avec référence immuable unique, catégorie, description, statut et unité unique
- [ ] Autoriser l’administration directe aux administrateurs
- [ ] Conserver historiques après archivage et interdire nouvelles consommations d’articles inactifs

Critères d’acceptation : Article mouvementé non supprimable ; référence préservée ; REC-17.

### ART-02 — Propositions d’articles et validation administrative

Issue : [#11](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/11) · À faire · Dépendances : [ART-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10).

Sources : ART-01,02; décision utilisateur.

- [ ] Permettre au gestionnaire de proposer une création ou modification sans changer le catalogue publié
- [ ] Créer file de validation pour administrateur et décisions tracées
- [ ] Détecter doublons de référence et propositions devenues obsolètes

Critères d’acceptation : Une proposition non approuvée n’affecte pas le catalogue ; seul un administrateur applique la proposition.

### ART-03 — Recherche et consultation du catalogue

Issue : [#12](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/12) · À faire · Dépendances : [ART-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10), [SOC-04](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/4).

Sources : ART-04.

- [ ] Créer liste et fiche article
- [ ] Ajouter recherche référence/nom et filtres catégorie/statut, tri et pagination
- [ ] Respecter les droits sur les informations de stocks associées

Critères d’acceptation : Recherche et filtres combinés retournent uniquement les résultats attendus.

### STK-01 — Consultation des stocks et consolidation

Issue : [#13](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/13) · À faire · Dépendances : [ART-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10), [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : STK-01; MAG-04.

- [ ] Afficher stocks par magasin, article et total autorisé
- [ ] Afficher unité et disponibilité ; paginer et filtrer
- [ ] Prévoir accès au détail et à l’historique

Critères d’acceptation : La consolidation ne compte aucun magasin non autorisé ; quantités entières.

### STK-02 — Moteur transactionnel, concurrence et idempotence

Issue : [#14](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14) · À faire · Dépendances : [SOC-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2), [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : STK-06,07,10; §4.

- [ ] Créer service de mouvement immuable et solde réconciliable
- [ ] Garantir transaction, verrouillage et stock non négatif ; UTC serveur sans date rétroactive
- [ ] Traiter clé d’idempotence, rejet d’une même clé avec contenu différent et rejouabilité

Critères d’acceptation : Deux sorties concurrentes de la dernière unité : une seule réussit ; rejeu sans doublon ; REC-05,06,14.

### STK-03 — Entrées et réapprovisionnements

Issue : [#15](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/15) · À faire · Dépendances : [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14), [STK-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/13).

Sources : STK-02; parcours 5.3.

- [ ] Créer formulaire et API d’entrée pour admin/gestionnaire autorisé
- [ ] Valider quantité entière strictement positive, motif et référence facultative
- [ ] Enregistrer auteur et date serveur avec événement de recalcul des alertes

Critères d’acceptation : Stock 5 + entrée 10 = 15 ; refus utilisateur simple ; REC-03.

### STK-04 — Consommations et bénéficiaire tiers

Issue : [#16](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/16) · À faire · Dépendances : [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14), [STK-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/13).

Sources : STK-03; CON-01,02; CON-05 partiel.

- [ ] Créer sortie avec quantité positive, commentaire et référence intervention/chantier/client facultative dès le MVP
- [ ] Utilisateur : bénéficiaire soi-même ; admin/gestionnaire : tout technicien actif, même non affecté au magasin
- [ ] Conserver distinctement opérateur et consommateur ; vérifier magasin autorisé et article actif

Critères d’acceptation : Sortie 4 sur 15 donne 11 ; tiers réservé aux rôles autorisés ; REC-02,04,05.

### STK-05 — Ajustements et corrections par contre-mouvement

Issue : [#17](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/17) · À faire · Dépendances : [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14).

Sources : STK-04,09.

- [ ] Créer ajustement signé avec motif obligatoire pour admin/gestionnaire
- [ ] Corriger via contre-mouvement lié à l’original, sans mutation silencieuse
- [ ] Empêcher stock négatif et doubles annulations ; auditer

Critères d’acceptation : Mouvement original inchangé ; correction liée et justifiée ; toute opération invalide laisse le stock intact.

### STK-06 — Transferts atomiques entre magasins

Issue : [#18](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/18) · À faire · Dépendances : [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14).

Sources : STK-05.

- [ ] Vérifier droits sur source et destination distinctes
- [ ] Débiter et créditer dans une transaction avec identifiant commun
- [ ] Gérer concurrence, stock insuffisant et inventaire bloquant

Critères d’acceptation : Transfert 3 : source -3, destination +3 ou aucun changement ; REC-07.

### STK-07 — Retours liés à une consommation

Issue : [#19](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/19) · À faire · Dépendances : [STK-04](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/16).

Sources : Décision utilisateur; STAT-06.

- [ ] Créer retour partiel ou total lié à la sortie d’origine dans le même magasin
- [ ] Autoriser admin/gestionnaire selon périmètre et utilisateur pour ses propres consommations
- [ ] Limiter le cumul des retours à la quantité consommée, y compris en concurrence ; conserver trace distincte

Critères d’acceptation : Retour autorisé crédite le magasin d’origine une seule fois ; retour excessif ou d’autrui refusé à l’utilisateur.

### STK-08 — Historique des mouvements filtrable

Issue : [#20](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/20) · À faire · Dépendances : [STK-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/15), [STK-04](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/16), [STK-05](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/17), [STK-06](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/18), [STK-07](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/19).

Sources : STK-08,09,10.

- [ ] Créer liste paginée avec filtres date, article, magasin, type et personne
- [ ] Afficher heure locale à partir d’UTC, auteur, bénéficiaire, motif et liens d’opération
- [ ] Afficher transferts, contre-mouvements et retours sans modification historique

Critères d’acceptation : Une recherche combine correctement les filtres et ne divulgue aucun magasin interdit.

### INV-01 — Campagnes d’inventaire et gel des mouvements

Issue : [#21](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/21) · À faire · Dépendances : [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14).

Sources : INV-01,07.

- [ ] Créer états brouillon, en cours, à valider, clôturée, annulée et transitions autorisées
- [ ] Figer référence et bloquer tous mouvements du magasin pendant comptage/validation, y compris transferts et retours
- [ ] Assurer verrouillage transactionnel entre démarrage de campagne et mouvements ; annulation tracée libère le gel

Critères d’acceptation : Aucun mouvement ne passe durant le gel ; un inventaire actif par magasin ; fin ou annulation libère le magasin.

### INV-02 — Saisie des comptages et calcul des écarts

Issue : [#22](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/22) · À faire · Dépendances : [INV-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/21).

Sources : INV-02,03.

- [ ] Réserver saisie à admin/gestionnaire du périmètre
- [ ] Distinguer quantité non saisie et zéro ; accepter entiers non négatifs
- [ ] Afficher théorique, compté, écart et pourcentage défini seulement si base pertinente

Critères d’acceptation : Comptage zéro conservé ; aucun calcul de pourcentage avec division par zéro ; utilisateur simple refusé.

### INV-03 — Validation atomique et clôture d’inventaire

Issue : [#23](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/23) · À faire · Dépendances : [INV-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/22), [STK-05](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/17).

Sources : INV-04,05,06.

- [ ] Contrôler complétude et rôle admin/gestionnaire
- [ ] Générer ajustements liés et clôturer en une transaction idempotente
- [ ] Conserver auteurs, validateur, dates et écarts ; rendre clôture immuable

Critères d’acceptation : 10 théoriques / 8 comptés donnent un seul ajustement -2, stock 8 ; REC-10.

### ALT-01 — Seuils personnalisés par article et magasin

Issue : [#24](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/24) · À faire · Dépendances : [ART-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10), [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : ART-05; ALT-01.

- [ ] Créer saisie des seuils bas et critique par couple article/magasin
- [ ] Valider nombres entiers non négatifs et critique inférieur ou égal au bas
- [ ] Ne pas imposer de seuil global chiffré non validé ; distinguer non configuré et zéro

Critères d’acceptation : Un article peut avoir des seuils différents selon magasin ; zéro est un seuil valide.

### ALT-02 — Cycle de vie des alertes et franchissements

Issue : [#25](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/25) · À faire · Dépendances : [ALT-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/24), [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14), [MAIL-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27).

Sources : ALT-01,03,04.

- [ ] Calculer normal/bas/critique après chaque mouvement et changement de seuil
- [ ] Prioriser critique, ouvrir/résoudre et historiser les alertes
- [ ] Émettre événement durable au changement de niveau sans doublon sur consultation

Critères d’acceptation : Franchissement produit un événement ; réapprovisionnement résout ; REC-08,09.

### ALT-03 — Écran des alertes et destinataires

Issue : [#26](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/26) · À faire · Dépendances : [ALT-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/25).

Sources : ALT-02,05.

- [ ] Lister alertes actives et historiques par magasin et gravité
- [ ] Déterminer par défaut les gestionnaires du magasin et administrateurs actifs ; dédupliquer les adresses
- [ ] Prévoir configuration autorisée des destinataires selon le CDC, avec contrôles serveur

Critères d’acceptation : Gestionnaire ne voit que ses magasins ; administrateur voit tous ; aucune fuite entre destinataires.

### MAIL-01 — SMTP, file durable et modèles de courriels

Issue : [#27](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27) · À faire · Dépendances : [SOC-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2).

Sources : MAIL-01,02,04,05,06,08.

- [ ] Configurer Symfony Mailer via secrets, SMTP externe existant et modèles lisibles
- [ ] Créer worker Messenger et événements persistés dans la transaction métier ; reprises bornées et file d’échecs
- [ ] Garantir idempotence, journal technique et absence de blocage du stock en panne SMTP

Critères d’acceptation : SMTP inaccessible : mouvement validé, message durable en attente ; REC-08,13 ; aucun secret journalisé.

### MAIL-02 — Messages d’information ciblés

Issue : [#28](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/28) · À faire · Dépendances : [MAIL-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27), [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : MAIL-03.

- [ ] Créer composition et sélection par magasin ou rôle pour administrateur uniquement
- [ ] Prévisualiser audience et message, confirmer puis mettre en file
- [ ] Dédupliquer destinataires et tracer l’envoi

Critères d’acceptation : Seul un administrateur déclenche l’envoi ; destinataires conformes à la cible.

### MAIL-03 — Récapitulatif quotidien des alertes à 08 h

Issue : [#29](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/29) · À faire · Dépendances : [ALT-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/26), [MAIL-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27).

Sources : ALT-06 partiel; MAIL-07 partiel; décision utilisateur.

- [ ] Planifier chaque jour à 08 h Europe/Paris dans un conteneur scheduler
- [ ] Produire un seul récapitulatif par destinataire des alertes persistantes dans son périmètre
- [ ] Dédupliquer par destinataire/date locale ; gérer redémarrage, changement d’heure et exclusion des alertes résolues

Critères d’acceptation : Deux exécutions le même jour ne doublonnent pas ; alertes immédiates restent envoyées à toute heure.

### CON-01 — Historique personnel et suivi des consommations

Issue : [#30](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/30) · À faire · Dépendances : [STK-04](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/16), [STK-07](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/19), [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : CON-03,04; décision utilisateur.

- [ ] Lister consommations filtrées par dates inclusives, article, catégorie, magasin et consommateur
- [ ] Utilisateur : ses consommations, y compris celles saisies pour lui ; gestionnaire : ses magasins ; admin : global
- [ ] Afficher quantités sorties et retours séparément, référence facultative et deux identités

Critères d’acceptation : Un utilisateur ne voit pas les consommations d’autrui ; ses données personnelles n’ouvrent pas le stock des magasins non affectés.

### STAT-01 — Statistiques de consommation et filtres

Issue : [#31](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/31) · À faire · Dépendances : [CON-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/30).

Sources : STAT-01,02,03,04,06.

- [ ] Créer top articles par quantité et nombre de sorties, évolutions jour/semaine/mois et répartitions
- [ ] Filtrer période inclusive, magasin et catégorie selon droits ; statistiques personnelles accessibles aux utilisateurs
- [ ] Exclure entrées/transferts/ajustements ; exposer retours séparément sans réécrire les sorties

Critères d’acceptation : Jeu connu produit les agrégats attendus ; aucun mélange retours/consommations ; REC-11,12.

### STAT-02 — Tableau de bord et indicateurs

Issue : [#32](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/32) · À faire · Dépendances : [STAT-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/31), [ALT-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/26), [STK-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/13).

Sources : STAT-05; §6.

- [ ] Afficher articles actifs, ruptures, sous-seuils, mouvements sur période et volume en unité
- [ ] Adapter indicateurs au rôle et à son périmètre ; vue utilisateur centrée sur ses consommations
- [ ] Relier indicateurs aux listes filtrées

Critères d’acceptation : Les totaux concordent avec les listes et ne révèlent pas de données interdites.

### ADM-01 — Journal d’audit métier

Issue : [#33](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/33) · À faire · Dépendances : [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7), [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14).

Sources : §2,10.1,10.4.

- [ ] Tracer changements sensibles comptes/droits/catalogue/inventaires/seuils et corrections
- [ ] Créer consultation paginée admin globale, gestionnaire limitée aux magasins, utilisateur refusée
- [ ] Conserver journal non modifiable via interface standard et masquer les secrets

Critères d’acceptation : Une action sensible est attribuable et horodatée ; aucune édition du journal par un utilisateur ordinaire.

### OPS-01 — Sauvegardes, restauration et reprise

Issue : [#34](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/34) · À faire · Dépendances : [SOC-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1), [SOC-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2).

Sources : DOCK-10,11; §10.3.

- [ ] Automatiser sauvegardes quotidiennes de PostgreSQL et volumes utiles, accès restreint et rétention configurable
- [ ] Documenter copie hors hôte, restauration vierge et contrôles d’intégrité
- [ ] Viser reprise sous 1 jour ouvré ; documenter risque résiduel accepté sur serveur unique et RPO effectif à arrêter

Critères d’acceptation : Restauration et recréation des conteneurs validées ; REC-15,16. À préciser avant production : rétention, destination et fréquence nécessaires au RPO retenu.

### OPS-02 — Déploiement HTTPS, sécurité et mises à jour

Issue : [#35](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/35) · À faire · Dépendances : [SOC-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1), [AUTH-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/7).

Sources : DOCK-03,04,05,07,08,09,10; §10.1.

- [ ] Préparer déploiement mono-serveur Compose, HTTPS et compatibilité avec proxy existant ou proxy conteneurisé
- [ ] Durcir cookies, CSRF, XSS, injections, comptes et conteneurs ; analyser images et dépendances
- [ ] Documenter migrations, mises à jour et retour arrière sans perte silencieuse

Critères d’acceptation : Contrôles de sécurité et procédure de rollback exécutables. Avant production : domaine, proxy, ressources et versions à confirmer.

### OPS-03 — Supervision et diagnostic

Issue : [#36](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/36) · À faire · Dépendances : [SOC-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/1), [MAIL-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/27).

Sources : §10.4; DOCK-10.

- [ ] Créer logs structurés UTC avec identifiant de requête et niveaux
- [ ] Suivre santé, erreurs, file d’attente et échecs courriels
- [ ] Documenter diagnostic et signaux d’alerte avec accès restreint

Critères d’acceptation : Panne worker ou SMTP observable sans exposer de secrets. Responsables et disponibilité cible à définir avant production.

### RGPD-01 — Anonymisation des données nominatives après un an

Issue : [#37](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/37) · À faire · Dépendances : [ADM-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/33), [SOC-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/2).

Sources : §10.1; décision utilisateur.

- [ ] Cartographier données nominatives, journaux, événements et sauvegardes
- [ ] Implémenter traitement planifié d’anonymisation à un an préservant quantités et cohérence historique
- [ ] Définir procédure contrôlée et information utilisateurs ; éviter pseudonyme réidentifiable et cohérence avec restauration

Critères d’acceptation : Un jeu de données ancien est anonymisé sans changer soldes ni agrégats. Avant production : responsable, point de départ de conservation et traitement des identités de comptes actifs à valider.

### QA-01 — Recette automatisée, concurrence et performances

Issue : [#38](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/38) · À faire · Dépendances : [INV-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/23), [MAIL-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/29), [STAT-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/32), [OPS-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/34), [OPS-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/35), [RGPD-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/37).

Sources : REC-01 à REC-18; DOCK-12; §10.2,11.

- [ ] Créer tests unitaires métier, intégration PostgreSQL/API, droits et parcours critiques exécutés en Docker/CI
- [ ] Couvrir concurrence, retours, validation catalogue, inventaire gelé et anonymisation en plus des REC
- [ ] Mesurer 5 utilisateurs simultanés, 2 magasins, 20 articles, 10 mouvements/jour ; cibles p95 liste <2 s et mouvement <3 s ; produire rapport

Critères d’acceptation : REC-01 à REC-18 passent ; tests de restauration et redémarrage documentés ; aucun accès inter-magasin.

### DOC-01 — Documentation, démonstration et livraison

Issue : [#39](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/39) · À faire · Dépendances : [QA-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/38).

Sources : §12,16.

- [ ] Rédiger README, guides utilisateur/admin, permissions, OpenAPI et diagrammes
- [ ] Fournir données fictives, SECURITY.md, CHANGELOG.md et contribution Docker
- [ ] Documenter sauvegarde/restauration, maintenance, mises à jour et recette ; choisir licence avec propriétaire

Critères d’acceptation : Installation Docker suivie sur environnement vierge et documentation cohérente. Licence et responsabilités à définir avant diffusion.

### V2-01 — MFA et authentification SSO OIDC

Issue : [#40](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/40) · V2 différée · Dépendances : [AUTH-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/5).

Sources : AUTH-08.

- [ ] Cadrer fournisseur OIDC, MFA, récupération et rattachement des comptes
- [ ] Implémenter et tester activation, révocation et conservation des droits

Critères d’acceptation : Hors MVP ; fournisseur et politique validés avant implémentation.

### V2-02 — Emplacements internes des magasins

Issue : [#41](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/41) · V2 différée · Dépendances : [MAG-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/9), [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14).

Sources : MAG-06.

- [ ] Modéliser allée/étagère/bac et rattachement au magasin
- [ ] Adapter mouvements et stocks sans perdre l’historique

Critères d’acceptation : Hors MVP ; stock par emplacement réconciliable au total magasin.

### V2-03 — Images, codes-barres et références fournisseur

Issue : [#42](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/42) · V2 différée · Dépendances : [ART-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10).

Sources : ART-06.

- [ ] Ajouter image, fabricant, référence fournisseur et code-barres/QR
- [ ] Valider téléversements et unicité selon règles définies

Critères d’acceptation : Hors MVP ; consultation article et lecture de code vérifiées.

### V2-04 — Import et export CSV du catalogue

Issue : [#43](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/43) · V2 différée · Dépendances : [ART-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/10), [ART-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/11).

Sources : ART-07.

- [ ] Définir format et validation des lignes, doublons et permissions
- [ ] Prévoir simulation, rapport d’erreurs et passage par validation administrative pour gestionnaires

Critères d’acceptation : Hors MVP ; import invalide ne corrompt pas les données.

### V2-05 — Réservations de stock

Issue : [#44](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/44) · V2 différée · Dépendances : [STK-02](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/14).

Sources : STK-11.

- [ ] Modéliser réservation, libération et consommation
- [ ] Distinguer stock physique et disponible avec concurrence

Critères d’acceptation : Hors MVP ; disponible cohérent sans double réservation.

### V2-06 — Inventaire tournant et saisie par codes-barres

Issue : [#45](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/45) · V2 différée · Dépendances : [INV-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/23), [V2-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/42).

Sources : INV-08.

- [ ] Cadrer périmètre du gel pour inventaire partiel
- [ ] Créer comptage mobile web et lecture codes-barres

Critères d’acceptation : Hors MVP ; inventaire partiel préserve traçabilité et cohérence.

### V2-07 — Accusés de réception et escalade des alertes

Issue : [#46](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/46) · V2 différée · Dépendances : [ALT-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/26), [MAIL-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/29).

Sources : ALT-06 restant.

- [ ] Cadrer accusés de réception, délai et chaîne d’escalade
- [ ] Tracer transitions et éviter doubles notifications

Critères d’acceptation : Hors MVP ; rappel quotidien déjà prévu au MVP par MAIL-03.

### V2-08 — Gestion structurée des interventions et équipes

Issue : [#47](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/47) · V2 différée · Dépendances : [STK-04](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/16).

Sources : CON-05 restant.

- [ ] Modéliser interventions, chantiers, équipes et ordres de travail si nécessaire
- [ ] Relier consommations et droits ; migrer la référence facultative MVP

Critères d’acceptation : Hors MVP ; simple référence libre déjà incluse au MVP.

### V2-09 — Export CSV des consommations

Issue : [#48](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/48) · V2 différée · Dépendances : [CON-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/30).

Sources : CON-06.

- [ ] Exporter filtres et colonnes documentées avec droits
- [ ] Neutraliser les formules CSV et inclure retours distincts

Critères d’acceptation : Hors MVP ; aucune consommation hors périmètre dans l’export.

### V2-10 — Exports statistiques et prévisions

Issue : [#49](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/49) · V2 différée · Dépendances : [STAT-01](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/31).

Sources : STAT-07.

- [ ] Ajouter exports PDF/CSV et comparaison de périodes
- [ ] Définir moyenne et estimation de rupture avec hypothèses visibles

Critères d’acceptation : Hors MVP ; résultats cohérents et exports autorisés uniquement.

### V2-11 — Abonnements et synthèses complémentaires

Issue : [#50](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/50) · V2 différée · Dépendances : [MAIL-03](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/29).

Sources : MAIL-07 restant.

- [ ] Ajouter préférences individuelles et synthèse hebdomadaire
- [ ] Conserver règles d’accès, déduplication et destinataires obligatoires définis

Critères d’acceptation : Hors MVP ; récapitulatif quotidien 08 h déjà livré par MAIL-03.

## Recette et traçabilité

| Scénario CDC | Lots principaux |
|---|---|
| REC-01 — Accès anonyme refusé | AUTH-01, AUTH-03 |
| REC-02 — Sortie hors affectation refusée | AUTH-03, STK-04 |
| REC-03 — Entrée 10 sur 5 donne 15 | STK-03 |
| REC-04 — Sortie 4 sur 15 donne 11 | STK-04 |
| REC-05 — Stock insuffisant rejeté | STK-02, STK-04 |
| REC-06 — Dernière unité concurrente | STK-02 |
| REC-07 — Transfert atomique | STK-06 |
| REC-08 — Seuil et courriel unique | ALT-02, MAIL-01 |
| REC-09 — Résolution après réapprovisionnement | ALT-02 |
| REC-10 — Inventaire 10 vers 8 | INV-01, INV-02, INV-03 |
| REC-11 — Agrégats de période | STAT-01 |
| REC-12 — Isolation des magasins | AUTH-03, CON-01, STAT-01 |
| REC-13 — Panne SMTP non bloquante | MAIL-01 |
| REC-14 — Rejeu idempotent | STK-02 |
| REC-15 — Persistance après recréation | SOC-01, OPS-01 |
| REC-16 — Restauration vierge | OPS-01 |
| REC-17 — Archivage article mouvementé | ART-01 |
| REC-18 — Session de compte désactivé | AUTH-02 |

Recette supplémentaire issue des arbitrages : proposition non approuvée invisible dans le catalogue publié ; sortie pour tiers réservée aux rôles autorisés ; accès aux consommations personnelles ; retour dans le magasin initial avec plafond cumulé et concurrence ; gel de tous les mouvements durant inventaire ; quantités fractionnaires et dates rétroactives rejetées ; rappel quotidien unique à 08 h Europe/Paris, y compris changement d’heure ; anonymisation sans modification des soldes.

## Définition de terminé et tenue du suivi

Pour chaque lot :
- [ ] Règles métier et droits contrôlés côté serveur ; validations et erreurs explicites.
- [ ] Tests pertinents, incluant cas limites et refus d’accès, exécutés dans Docker.
- [ ] Écran et/ou contrat API conformes au périmètre, documentation actualisée.
- [ ] Issue reliée à la livraison ; critères d’acceptation vérifiés.
- [ ] Statut de ce fichier actualisé avec lien de livraison et preuve de vérification.

La mise en production exige la recette complète, le contrôle sécurité et une restauration réussie ; un lot documenté n’est pas un lot implémenté.
Les achats, commandes automatiques, facturation, ERP, géolocalisation, équipements sérialisés, application native et fonctionnement hors ligne restent hors périmètre initial.

## Journal

- 18 octobre 2026 : lecture du CDC ; décisions utilisateur consolidées ; préparation de 39 lots MVP et 11 lots V2 ; une issue créée par lot, toutes ouvertes ; aucune implémentation démarrée.


- 18 octobre 2026 : SOC-01 implémenté sur `codex/soc-01-docker` : cinq services Docker, secrets hors Git, file durable, sondes, tests et documentation. Recette locale et audit hors réseau réussis ; passage en revue, issue ouverte jusqu’à intégration.

- 18 octobre 2026 : intégration de SOC-01 confirmée (PR #51 fusionnée). SOC-02 implémenté : schéma, contraintes, journal immuable, outbox/rappels, migration réversible à vide et refus protecteur avec données ; 18 tests / 92 assertions. Livraison pour revue, issue #2 ouverte jusqu’à intégration.

- 18 octobre 2026 : intégration de SOC-02 confirmée (PR #52 fusionnée). SOC-03 implémenté : contrat OpenAPI, erreurs JSON, outils de requête, sessions/CSRF, limitation de débit et empreinte idempotente. Recette : 34 tests PHP / 528 assertions et 4 tests indépendants ; livraison pour revue, issue #3 ouverte jusqu’à intégration.

- 18 octobre 2026 : SOC-03 et SOC-04 intégrés ; AUTH-01 livré pour revue : connexion, profil, expiration, révocation des sessions et audit des échecs. 55 tests PHP / 809 assertions et quatre tests de contrat réussis. Aucun compte réel créé automatiquement.

- 18 octobre 2026 : AUTH-01 intégré (PR #55). Ajout demandé d’un administrateur de test réservé à `APP_ENV=dev` : création explicite, marqueur persistant et refus hors développement pour les connexions et les sessions existantes. [Guide](docs/administrateur-test.md).

- 18 octobre 2026 : administrateur de test intégré (PR #56). AUTH-02 implémenté pour revue : administration des comptes, création idempotente et révocation des sessions.

- 18 octobre 2026 : AUTH-02 intégré à main (PR #57). AUTH-03 implémenté et vérifié pour revue ; décisions sur magasins désactivés et retours hors affectation intégrées.

- 18 octobre 2026 : AUTH-03 intégré à main (PR #58). MAG-01 développé et vérifié pour revue ; blocage de désactivation pendant inventaire validé par l’utilisateur.
