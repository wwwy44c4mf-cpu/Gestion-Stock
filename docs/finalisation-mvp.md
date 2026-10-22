# Finalisation du MVP — 22 octobre 2026

## Périmètre de la livraison

Livraison regroupant les 39 lots MVP du [suivi](../suivi.md), jusqu’à AUTH-04, et les corrections de revue. Les onze lots V2 restent différés. Cette livraison de code ne constitue pas une ouverture en production.

Les PR [RGPD #86](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/86), [QA #87](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/87), [documentation #88](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/88) et [AUTH-04 #89](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/89) constituent cette livraison sur `main`. Le propriétaire a explicitement autorisé leur fusion le 22 octobre 2026. Le dépôt reste public, avec licence propriétaire tous droits réservés.

## Corrections vérifiées

La revue finale a relevé trois défauts : collision de liens entre onglets, commentaire personnel copié dans une correction récente et libération différée à tort d’un compte lors d’une clôture récente. Chaque cas possède désormais un test de régression. La migration additive `Version20261022130000` est la dix-huitième ; aucune migration déjà appliquée n’est réécrite. Elle ne lance pas de traitement d’effacement.

Recette finale sur **PHP 8.4.27 : 421 tests PHP / 5 316 assertions**, contrôles OpenAPI, mesures HTTP et charge (920 requêtes), sauvegarde/restauration, HTTPS, mise à jour/reprise et incidents réussis. Rapports locaux : `var/qa/20261022T122154Z-86716/` (`full_passed`). La [CI du code final](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/actions/runs/39778148438) réussit ses trois travaux : cœur, installation et reprise. Les changements ultérieurs de ce bilan et du suivi sont documentaires ; la CI est relancée sur `main` à la fusion.

## Sécurité de construction

L’audit Composer du 22 octobre ne relève aucun avis ni paquet abandonné. Les cinq images de recette ont été exportées puis analysées hors réseau, sans socket Docker ni secrets. L’analyse initiale ne relève aucun HIGH/CRITICAL, mais identifie une mise à jour `musl` disponible ; les paquets Alpine sont donc reconstruits avec une révision explicite de cache. Après reconstruction, quatre images ne présentent aucun signal dans cet audit. Le proxy conserve un signal UNKNOWN `GO-2026-5932` : les paquets OpenPGP concernés sont absents de son binaire compilé, vérification faite dans la liste des dépendances embarquée. Le rapport reste conservé sans exclusion globale ; ce constat ne garantit pas l’absence de toute vulnérabilité.

Une vérification de [l’avis officiel PHP](https://www.php.net/archive/2026.php) a aussi imposé le passage à **PHP 8.4.27**, avec nouvelle empreinte multiarchitecture et plateforme Composer cohérente. Les versions des dépendances applicatives sont conservées. La recette et le nouvel audit des cinq images confirment cette construction corrigée et la disparition du signal `musl`. Un audit de paquets système ne suffit pas à vérifier le moteur PHP fourni séparément.

Les rapports complets sont conservés sous `var/security/mvp-final/`, hors Git. Les résultats sont datés ; refaire l’audit avant le véritable déploiement. Aucun courriel réel n’a été envoyé par ces recettes.

## Exploitation et conditions d’ouverture

Le propriétaire assure serveur, sauvegardes et incidents. L’application locale reste sur loopback avec les courriels désactivés. Les soldes ont été réconciliés avant et après les migrations, sans écart ; aucune commande d’effacement n’a été lancée sur cette base de travail et le timer de conservation n’y est pas activé.

Avant une ouverture réelle : renseigner serveur/domaine/DNS, vérifier HTTPS et pare-feu, configurer et essayer SMTP sur boîtes autorisées, choisir et tester la copie de sauvegarde hors serveur avec clé séparée, installer le traitement de conservation et sa supervision, puis valider le parcours avec le responsable fonctionnel et les durées avec le responsable du traitement. Voir [la fiche de livraison](livraison.md) et [les procédures](deploiement-production.md).

Sauvegardes quotidiennes conservées 90 jours ; risque résiduel accepté sur serveur unique, jusqu’à environ 24 h depuis la dernière sauvegarde réussie. Objectif de reprise : un jour ouvré, à mesurer sur le serveur réel. Aucun tag stable ni déploiement distant n’est créé par cette finalisation du développement.
