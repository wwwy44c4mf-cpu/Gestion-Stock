# USR-03 — Recette locale des demandes de compte

État au 30 octobre 2026 : code préparé localement pour validation humaine. Issue [#105](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/105). Validation humaine reçue et publication autorisée le 30 octobre 2026 ; installation sur le serveur à effectuer.

- `sh bin/test-qa core` : 457 tests de non-régression, 5 705 assertions ; quatre contrôles OpenAPI et parcours HTTP métier réussis. Charge mesurée sur 920 requêtes, cinq sessions simultanées ; rapprochement de stock sans écart ni solde négatif. Rapport : `var/qa/20261030T213743Z-69008`.
- Recette ciblée finale `AccountRequestsTest`, `PasswordResetTest`, `PrivacyRetentionTest` : 38 tests, 357 assertions réussis. Rapport local : `/tmp/gestion-stock-cat02-preview/reports/usr03.xml`. Elle couvre demande et doublons, accès administrateur, protection de formulaire, approbation/refus, attribution de droits/magasins, activation avec connexion réelle au noyau web, expiration/remplacement/usage unique, rollback transactionnel, absence du lien utilisable dans la file, livraison simulée à un compte inactif approuvé, adresse neutre dans le courriel, anonymisation et maintien des fonctions antérieures.
- `sh bin/test-portainer` : préparation des secrets fictifs, URL neutre validée, services, migration, remplacement de l’image PostgreSQL avec données conservées et script de conservation actualisé, frontière du proxy, sauvegarde chiffrée et restauration réussis. Rapport : `var/portainer/20261030T214120Z-69356`.
- Contrôle par navigateur sur le formulaire public et l’examen administrateur à 375 et 1 280 pixels : aucun débordement horizontal ; captures `var/usr03/`. Demande fictive Camille Exemple conservée dans la démonstration pour la revue humaine.
- Contrôle des liens locaux de documentation : réussi.

Les tests de messagerie utilisent un expéditeur simulé, aucun compte Gmail réel. La démonstration est disponible uniquement sur `http://127.0.0.1:18791`. La validation humaine est reçue ; publication GitHub et déploiement à poursuivre. Une réception réelle du courriel d’activation devra être confirmée après installation.

Voir [le parcours et les instructions de déploiement](administration-comptes.md#demandes-de-compte-usr-03). La migration porte le total à 22. La future livraison doit également actualiser DATABASE_RELEASE_ID pour embarquer l’anonymisation des demandes dans l’image de base de données.
