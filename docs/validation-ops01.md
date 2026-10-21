# Validation OPS-01 — 21 octobre 2026

Résultats : **368 tests PHP / 4 797 assertions**, **4 validations OpenAPI** et **11 tests de sauvegarde/restauration** réussis sous Docker. Contrat métier inchangé : 61 opérations, 97 schémas ; aucune migration ajoutée.

La commande `sh bin/test-backup-recovery` a réalisé REC-15 et REC-16 en **45 secondes** sur cette machine avec images en cache. Projet et volumes distincts, aucun port public, faux secrets et SMTP désactivé ; nettoyage limité au projet de recette.

- Export PostgreSQL 18 et empreintes utilisant le même instantané : une écriture concurrente après le dump n’altère pas le manifeste de l’instantané.
- Chiffrement age, permissions, absence de clé privée/Git dans le paquet ; extraction des sources, configuration et quatre secrets de reprise avec modes préservés.
- Rejet d’une somme incorrecte, d’un chiffré altéré même avec somme recalculée, d’une mauvaise clé, des chemins dangereux, liens et membres inattendus.
- Rétention, préservation des fichiers inconnus/non vérifiés, verrou concurrent, panne de base sans perte de la dernière réussite, absence de paramètres et contrôle de santé périmé.
- Ordonnancement quotidien, rattrapage après redémarrage sans rejouer les jours passés, refus d’écraser une clé existante.
- Refus d’une cible non vide ou d’une confirmation incorrecte. Échec SQL injecté en fin de restauration : transaction annulée, aucun objet métier résiduel dans la base jetable.
- Restauration réelle sur PostgreSQL vierge : toutes les empreintes identiques, réconciliation sans écart, 16 migrations, trois mouvements, stock 8, inventaire et message durable récupérés.
- Connexion web, authentification et lecture du stock restauré ; arrêt/recréation des conteneurs sans suppression des volumes, données et file conservées.

## Installation de développement

Profil quotidien activé avec rétention 90 jours. Première archive créée le 21 octobre à 14:04:47 UTC, terminée à 14:04:48 ; contrôle de santé et inspection déchiffrée/empreintes réussis (33 tables). Clé privée locale 0600 appartenant à l’utilisateur ; aucun contenu secret publié. Application, base, web, worker, scheduler et sauvegarde sains.

Les données existantes sont conservées : 16 migrations, cinq mouvements, somme des stocks 12, un inventaire, zéro notification, un lot quotidien, 63 événements d’audit dont 45 connexions réussies. Aucune action métier fictive ajoutée à cette installation. Les échecs volontairement injectés produisent des erreurs attendues dans la suite de tests.

La destination hors serveur n’est pas choisie : aucune copie extérieure ni protection contre la perte complète du serveur ne sont annoncées. Avant production, choisir cette destination, y transférer les archives et protéger séparément la clé privée, puis mesurer une reprise complète depuis cette copie. La durée locale de 45 secondes ne garantit pas le délai en production ; objectif accepté : un jour ouvré, avec risque résiduel et environ 24 h de données exposées après la dernière réussite quotidienne.

[Guide d’exploitation](sauvegardes-restauration.md) · [Issue #34](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/34).
