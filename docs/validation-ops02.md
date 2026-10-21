# Validation OPS-02 — 21 octobre 2026

**372 tests PHP / 4 853 assertions**, **4 contrôles OpenAPI**, **11 tests de sauvegarde/restauration** et **deux passages de 5 tests HTTPS réels** réussis sous Docker. Aucune migration métier ajoutée ; contrat inchangé : 61 opérations / 97 schémas.

## Production et reprise

`sh bin/test-production` utilise les véritables overlays de production, un domaine fictif, une autorité locale Caddy, de faux secrets et des volumes dédiés. Aucun port n’est publié et aucune autorité n’est installée dans le système hôte.

- Configuration valide acceptée ; HTTPS désactivé, proxy `0.0.0.0/0` et motif d’hôte permissif refusés par le contrôle préalable.
- Certificat TLS et nom de domaine vérifiés par le client avec la seule CA de recette. Redirection HTTP 308 vers HTTPS, hôte et chemin conservés.
- Connexion d’un compte productif et consultation du stock 8 ; cookies Secure/HttpOnly/SameSite=Lax, CSP, HSTS, protection iframe, type MIME et référent vérifiés.
- Compte `development_only` refusé en production (401), connexion sans CSRF refusée (403). En-têtes HTTPS et IP falsifiés par un client direct refusés avant création de session ; hôte extérieur refusé. Fichiers privés inaccessibles et JavaScript local servi avec `nosniff`.
- Migration réelle sur données présentes : installation initiale à 15 migrations, utilisateurs, trois mouvements, stock 8, inventaire, trois événements d’audit et message durable ; `bin/production update` sauvegarde puis applique la seizième migration. Seconde mise à jour sans nouvelle migration : données conservées et tests HTTPS repassés.
- Reprise de l’archive précédant cette migration dans un autre volume PostgreSQL vierge : déchiffrement, chargement transactionnel et empreintes de toutes les tables vérifiés. Retour à 15 migrations, absence de `daily_summary_batches`, trois mouvements, stock 8, un inventaire, trois audits, message durable et réconciliation sans écart confirmés. La base mise à jour n’est pas écrasée.
- La configuration privée réellement choisie par `PRODUCTION_ENV_FILE`, située hors dépôt pendant la recette, est montée en lecture seule et incluse dans le paquet de reprise. Tous les outils de sauvegarde/restauration utilisent la même image de version.

`sh bin/test-backup-recovery` confirme aussi les 11 scénarios de corruption, clé incorrecte, archives dangereuses, rétention, verrouillage, instantané concurrent et annulation d’un import SQL en échec. REC-15/16 réussis en 95 secondes lors de cette recette : connexion web après restauration et données/file conservées après recréation des conteneurs. Cette durée locale ne garantit pas la durée sur le futur serveur.

## Analyse des dépendances et images

`composer audit` : aucune alerte, aucun paquet abandonné. Trivy 0.76.1 épinglé, base publique téléchargée séparément ; images exportées et analysées dans un conteneur sans réseau, sans socket Docker et sans secrets hôte, sous l’UID/GID de l’opérateur. Script complet terminé avec succès, seuil bloquant HIGH/CRITICAL, aucune exclusion ni `ignore-unfixed`.

| Image | Résultat de l’analyse |
|---|---|
| Application PHP / dépendances Composer | Aucun signalement |
| Nginx | Aucun signalement |
| PostgreSQL 18 | Aucun signalement |
| Sauvegardes | Aucun signalement |
| Caddy recompilé | Aucun HIGH/CRITICAL/MEDIUM/LOW ; un UNKNOWN qualifié ci-dessous |

Corrections effectives : retrait des modules Nginx non chargés et de leurs bibliothèques graphiques ; retrait de `gosu` inutilisé dans la sauvegarde ; reconstruction de Caddy 2.11.7 avec Go 1.27.3 et `x/net` 0.60.0. Les verrous Go sont conservés dans le dépôt et les images amont sont épinglées par empreinte.

L’alerte [GO-2026-5932](https://pkg.go.dev/vuln/GO-2026-5932) désigne les paquets OpenPGP non maintenus du module `golang.org/x/crypto`. Le module est requis pour d’autres fonctions ; les paquets OpenPGP et leurs sous-paquets sont absents de la liste réelle des dépendances compilées. La construction échoue si cette absence n’est plus vérifiée ; la liste est conservée dans l’image et a été contrôlée sur l’image finale. Le signalement reste visible dans le rapport. Refaire l’analyse avant chaque mise en production.

## Installation locale existante

App, web, worker, scheduler et sauvegarde corrigés sont redémarrés et sains ; PostgreSQL n’a pas été remplacé. Connexion HTTP locale de développement toujours disponible, réponse réelle avec CSP et en-têtes attendus. Les contrôles HTTPS stricts restent activés par l’overlay de production.

Avant/après : **16 migrations, cinq mouvements, somme des stocks 12, un inventaire, zéro notification, un lot quotidien, 63 audits dont 45 connexions réussies**, réconciliation sans écart. Aucune donnée métier fictive ajoutée à cette installation, aucun courriel réel envoyé. Les erreurs intentionnelles des tests de refus et de panne sont attendues.

## Limites avant déploiement

Caddy intégré est le choix confirmé. La variante avec proxy existant conserve uniquement un port loopback et partage les contrôles applicatifs ; son exemple Nginx doit être adapté au proxy réellement installé. La composition intégrée ne publie que 80/443 et retire explicitement Nginx du réseau d’entrée public. Allocation dynamique et adresse fixe du proxy sont séparées pour éviter les collisions d’IP ; vérifier aussi l’absence de chevauchement avec les réseaux du serveur.

Domaine réel, DNS, ACME public, pare-feu, ressources et SMTP de production ne sont pas validés sur un serveur distant. La copie hors serveur et la protection séparée de la clé de sauvegarde restent à configurer après choix de destination. Les procédures exécutables et contrôles sont préparés ; aucune mise en production distante ni fusion GitHub réalisée.

[Guide de déploiement](deploiement-production.md) · [Reprise OPS-01](sauvegardes-restauration.md) · [Issue #35](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/35).

Livraison : [PR #84](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/84), basée sur OPS-01 (PR #83 encore ouverte lors de la publication). Issue #35 ouverte jusqu’à fusion.
