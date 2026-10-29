# Historique des versions

## Non publié — MVP

Aucune version stable ni mise en production n’est déclarée. Les PR ouvertes sont des lots en revue ; consulter [suivi.md](suivi.md) pour leur état réel. Les entrées ci-dessous décrivent le code livré pour recette, pas une date de déploiement.

### Fonctionnalités

- Suppression des articles du catalogue par les administrateurs et gestionnaires, même avec du stock : confirmation protégée, stocks et historique conservés, retours possibles et absence de réactivation par une catégorie. Migration additive CAT-02 ; recette locale validée (#102).

- Le scanner conserve sa fenêtre et affiche une action de reprise lors d’une interruption de visibilité ou d’un refus caméra, au lieu de disparaître silencieusement. Demande caméra lancée avant le chargement du décodeur. Tests simulés réussis ; validation sur iPhone Brave encore nécessaire (#98).

- Thème orange #FF7900 et actions Sortie / Retour identiques côte à côte dans Consommations, y compris sur smartphone. Filtres adaptés aux petits écrans. Rendu approuvé localement ; recette sur les quatre navigateurs mobiles suivie dans #97.

- Le magasin favori initialise le bandeau commun et les formulaires. Un autre magasin choisi manuellement reste sélectionné pendant la navigation sans modifier la préférence personnelle.

- Retours par article et quantité dans le magasin de dépôt choisi, répartition automatique sur les consommations encore retournables, droits de retrait/dépôt des techniciens dans tout magasin actif et magasin favori personnel. Migration additive et anonymisation des liens de regroupement.

- Sur smartphone, les consommations encore retournables s’affichent en fiches avec un bouton « Rendre des articles » directement visible, sans défilement horizontal.

- Lecture des codes-barres et QR codes avec caméra arrière sur les entrées, consommations et retours ; plusieurs codes par article, validation du catalogue conservée et quantité saisie manuellement. Migration additive des associations, sans modification automatique du stock au scan.

- Variante Portainer Standalone / Home Assistant OS : volumes de secrets, initialisation neuve, raccordement au module Cloudflared, limites de ressources et recette de restauration isolée. Les prérequis d’exploitation restent à configurer sur le serveur réel.

- Socle Docker, PostgreSQL, Symfony/Twig/Stimulus, API OpenAPI et interface française.
- Connexion, profil, comptes et affectations ; administrateur de test limité au développement.
- Réinitialisation du mot de passe par lien de 30 minutes à usage unique, file durable sans jeton utilisable et révocation des anciennes sessions.
- Magasins, catégories, catalogue commun et propositions validées par un administrateur.
- Stocks, entrées, consommations, corrections compensées, transferts et retours liés.
- Inventaires avec photographie, gel, comptage et validation atomique.
- Seuils par article/magasin, alertes, destinataires, messages ciblés et rappel quotidien.
- Historiques personnels et globaux, statistiques, tableau de bord et audit.

### Corrections de finalisation

- Isolation de chaque formulaire de réinitialisation dans sa session, y compris plusieurs onglets ; formulaires expirés et clés CSRF nettoyés.
- Effacement des commentaires copiés dans les contre-mouvements récents d’une consommation ancienne.
- Migration 18 : libération immédiate d’un compte arrivé à échéance à la clôture de son dernier dossier récent, sans effacement prématuré des champs de ce dossier.
- PHP 8.4.27 verrouillé et rafraîchissement explicite des paquets Alpine.

### Exploitation et qualité

- Sauvegardes quotidiennes chiffrées conservées 90 jours, restauration contrôlée.
- HTTPS, procédure de mise à jour/reprise, diagnostics et journaux expurgés.
- Conservation à un an, traitement privilégié et blocage de réouverture après restauration.
- Recette Docker/CI, concurrence et mesures de performance sur données fictives.
- Guides consolidés, démonstration réservée au développement et régime propriétaire choisi par le propriétaire.

### Avant une version stable

Terminer la revue et l’intégration des PR, figer le commit livré, refaire les contrôles de sécurité, compléter les responsabilités et la configuration du serveur puis suivre [la fiche de livraison](docs/livraison.md). Le tag/version doit être créé uniquement après cette validation.
