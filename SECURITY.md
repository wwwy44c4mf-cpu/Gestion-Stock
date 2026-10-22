# Sécurité

## Périmètre maintenu

Le MVP en cours de recette est la seule version maintenue. Aucune version stable de production n’est encore publiée. Les corrections doivent repartir du dernier état validé et repasser la recette ; les anciens commits ne bénéficient pas d’un maintien indépendant.

## Signaler un problème

Ne pas publier de vulnérabilité exploitable, données personnelles, mot de passe, cookie, jeton, dump ou configuration SMTP dans une issue publique. Utiliser le canal privé habituel avec le propriétaire du dépôt, ou le signalement privé GitHub si celui-ci est activé. Aucun courriel de sécurité ni délai de réponse garanti n’est encore désigné. Transmettre version/commit, scénario minimal fictif, impact et horodatage ; convenir avec le propriétaire de la correction et de sa diffusion.

En cas d’incident réel : le responsable d’exploitation limite l’accès, conserve les journaux nécessaires dans un espace privé et examine les droits et secrets compromis. Une rotation de fichier ne change pas à elle seule le mot de passe PostgreSQL ; suivre la procédure d’exploitation. Ne pas restaurer un dump ni relancer les écritures sans contrôler son intégrité et retraiter les échéances de conservation.

## Mesures livrées

HTTPS en production, sessions protégées et révocables, Argon2id, contrôles serveur par ressource, CSRF, limitation des tentatives, validation OpenAPI et quantités entières, écritures atomiques et idempotentes, journal quantitatif protégé, comptes applicatifs distincts du superutilisateur PostgreSQL, secrets hors Git/images, journaux expurgés et sauvegardes chiffrées. Les comptes `development_only` sont refusés hors `dev` ; aucune donnée de démo n’est créée au démarrage.

La configuration locale HTTP reste sur loopback. La recette de charge interne ne constitue pas une vérification de sécurité du serveur public. Les audits de dépendances et images doivent être refaits avant chaque déploiement ; les résultats historiques n’attestent pas de l’état futur des vulnérabilités.

## Exploitation

- [Préparer HTTPS, audit et mises à jour](docs/deploiement-production.md)
- [Sauvegardes et reprise](docs/sauvegardes-restauration.md)
- [Supervision et diagnostic](docs/supervision-diagnostic.md)
- [Conservation et restauration protégée](docs/conservation-donnees.md)
- [Droits](docs/controle-acces.md) et [conditions de livraison](docs/livraison.md)
