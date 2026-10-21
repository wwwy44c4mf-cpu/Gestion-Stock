# Validation RGPD-01 — 22 octobre 2026

## Périmètre

Suppression des liens nominatifs à un an, champs libres et copies, chaîne complète des bénéficiaires corrigés, dossiers ouverts, identité des comptes désactivés, simulation en lecture seule, commande opérateur, planification à installer et verrou de restauration. Migration 17 : `Version20261022000000`.

## Recettes exécutées

- `sh bin/test-privacy ...` : **46 tests / 527 assertions réussis**, sur base indépendante, identifiants aléatoires de recette et schémas fictifs. Couvre les 15 tests spécifiques de conservation et de simulation, les inventaires (création, comptage, validation et concurrence) et l’infrastructure.
- Simulation : frontière anniversaire incluse, microsecondes, année bissextile, conversion UTC, même horloge pour les corrections, instantané sans modification, absence d’identités dans le rapport et annulation après erreur.
- Traitement : anciennes identités détachées, chaîne de corrections récentes expurgée, stocks et journal quantitatif identiques, réexécution sans changement du journal, protections rétablies et compte applicatif refusé.
- Panne injectée après les modifications : transaction annulée, données et protections retrouvées, verrou de restauration maintenu.
- Dossiers ouverts : identité préservée ; clôture du premier dossier sans effacer le compte encore requis ; dernier dossier clôturé, identité effacée immédiatement. Comptages, écarts et versions conservés ; revue récente conservée. Réponse de clôture et rejeu idempotent sans réintroduire le créateur effacé.
- Notifications : audience, empreintes d’adresses, tentatives, récapitulatifs et messages associés purgés ensemble ; message récent indépendant conservé.
- Droits : historique personnel vidé des anciennes consommations, ancien bénéficiaire refusé pour le retour, administrateur autorisé et plafond toujours contrôlé.
- Restauration : HTTP 503 et traitement des messages bloqué avant succès ; accès de nouveau possible après réussite.
- `sh bin/test-backup-recovery` : recette chiffrée, restauration avec verrou, HTTP 503 constaté avant traitement, retraitement avant réouverture, connexion et stocks vérifiés puis recréation des conteneurs avec volumes. **REC-15/16 réussis**, 107 secondes sur cette machine.
- `sh bin/test-production` : contrôles HTTPS, mise à jour de 15 vers 17 migrations sur données existantes fictives, seconde mise à jour sans perte, restauration de l’archive du schéma précédent dans un nouveau volume et vérification quantitative réussies.

La première exécution de la suite complète dans la base indépendante a révélé l’absence d’initialisation du transport Messenger dans ce nouveau banc de recette. Le lanceur migre désormais sa base vierge et initialise les transports avant PHPUnit. Après correction, la suite complète finale réussit : **400 tests PHP / 5 053 assertions**, en 2 min 38 s. Les **quatre contrôles OpenAPI** réussissent également.

## Limites d’exploitation

Aucun traitement exécuté sur les données de développement existantes. Les secrets utilisés par la recette de conservation sont générés dans un répertoire temporaire indépendant ; la base, le réseau et ces secrets sont supprimés en fin de recette. Aucun secret administrateur existant n’est monté dans le conteneur de test.

Le timer de production et ses chemins doivent être installés sur le serveur ; il n’est pas activé sur le poste de développement. La suppression des liens ne garantit pas à elle seule l’absence de rapprochement externe avec les dates, articles et magasins conservés. Voir le [guide d’exploitation et ses limites](conservation-donnees.md) et la [cartographie](cartographie-donnees-personnelles.md).
