# Validation MAG-01 — 18 octobre 2026

## Résultat

**94 tests PHP / 1 304 assertions** réussis depuis les images Docker construites, ainsi que les quatre validations indépendantes OpenAPI. Aucune dépendance supplémentaire. La migration `Version20261018130000` a été appliquée explicitement par la commande de migration sur la base locale.

## Couverture

- Création avec valeurs par défaut, normalisation du code et du nom, adresse absente ou vide, conformité de réponse et en-tête Location.
- Code unique sans distinction de casse ni d’espaces périphériques, y compris pour les magasins archivés ; code non modifiable après création.
- Champs obligatoires, longueurs, caractères nuls, types, propriétés inconnues, PATCH vide et identifiants invalides/inexistants.
- Rejeu durable du résultat initial après modification/archivage, conflit de contenu, absence de second magasin et de second événement d’audit.
- **Deux processus PHP réels simultanés** : même clé/même contenu ⇒ un magasin et deux résultats identiques ; clés différentes/même code ⇒ une création et un conflit. Synchronisation de départ par verrou PostgreSQL, schéma temporaire supprimé en fin de test.
- Archivage et réactivation conservant les stocks, mouvements et affectations ; perte puis retour d’accès pour un utilisateur affecté. Suppression par API refusée ; protection des relations historiques par les clés étrangères testée dans SchemaTest.
- Désactivation refusée pour un inventaire en cours ou en attente de validation ; modification des informations autorisée ; désactivation autorisée après annulation, inventaire conservé.
- Contrôles ADMIN, refus des gestionnaires/utilisateurs, CSRF API et formulaires, formulaires sans JavaScript, filtres et échappement des textes.
- Audit limité aux noms de champs, sans valeurs de description/adresse ; pas d’événement pour une modification sans changement.
- Migration vierge/réexécution/retour arrière ; refus transactionnel du retour arrière si des créations de magasins existent.
- Non-régression de l’authentification, des sessions natives, de l’administrateur de développement, des comptes, des périmètres par magasin et des invariants de stock existants.

Les données de ces tests sont isolées dans des schémas PostgreSQL temporaires. Les erreurs volontairement provoquées dans les tests de refus sont attendues.

## Interface et exploitation locale

Liste et formulaire de création ouverts dans le navigateur avec l’administrateur de développement. Affichage sur ordinateur et mobile vérifié : à 375 pixels de largeur utile, aucun débordement horizontal ; champs et bouton accessibles. Les soumissions et modifications sont vérifiées par les tests fonctionnels isolés. Aucun magasin de démonstration n’est créé dans la base locale.

Application, serveur web, base, worker et scheduler sains après mise à jour. Les futurs services d’inventaire doivent respecter le verrouillage du magasin avant leurs transitions pour préserver l’exclusion avec l’archivage. Voir [le guide](gestion-magasins.md).
