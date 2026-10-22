# Historique des versions

## Non publié — MVP

Aucune version stable ni mise en production n’est déclarée. Les PR ouvertes sont des lots en revue ; consulter [suivi.md](suivi.md) pour leur état réel. Les entrées ci-dessous décrivent le code livré pour recette, pas une date de déploiement.

### Fonctionnalités

- Socle Docker, PostgreSQL, Symfony/Twig/Stimulus, API OpenAPI et interface française.
- Connexion, profil, comptes et affectations ; administrateur de test limité au développement.
- Magasins, catégories, catalogue commun et propositions validées par un administrateur.
- Stocks, entrées, consommations, corrections compensées, transferts et retours liés.
- Inventaires avec photographie, gel, comptage et validation atomique.
- Seuils par article/magasin, alertes, destinataires, messages ciblés et rappel quotidien.
- Historiques personnels et globaux, statistiques, tableau de bord et audit.

### Exploitation et qualité

- Sauvegardes quotidiennes chiffrées conservées 90 jours, restauration contrôlée.
- HTTPS, procédure de mise à jour/reprise, diagnostics et journaux expurgés.
- Conservation à un an, traitement privilégié et blocage de réouverture après restauration.
- Recette Docker/CI, concurrence et mesures de performance sur données fictives.
- Guides consolidés, démonstration réservée au développement et régime propriétaire choisi par le propriétaire.

### Avant une version stable

Terminer la revue et l’intégration des PR, figer le commit livré, refaire les contrôles de sécurité, compléter les responsabilités et la configuration du serveur puis suivre [la fiche de livraison](docs/livraison.md). Le tag/version doit être créé uniquement après cette validation.
