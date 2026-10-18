# Recette AUTH-02 — 18 octobre 2026

## Résultat

Livraison pour revue : [PR #57](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/57), branche `codex/auth-02-user-administration`, issue [#6](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/6). Base : AUTH-01 et administrateur de développement intégrés sur `main` (`0194c2cdb977e5f13fdc553813253273f737dfac`).

- **73 tests PHP / 1 001 assertions réussis** dans Docker, dont douze parcours d’administration, la recette native et les migrations.
- **Quatre tests indépendants du contrat OpenAPI réussis** : 58 opérations documentées, onze disponibles et 47 planifiées.
- Injection des services valide ; neuf fichiers YAML et quinze modèles Twig valides.
- Audit Composer : aucune vulnérabilité connue signalée.
- Migration `Version20261018120000` appliquée explicitement sur l’environnement local avant la mise en service.

## Couverture

| Exigence | Preuve |
|---|---|
| Création | API 201 avec Location, formulaire français sans JavaScript ; hachage Argon2id ; nouveau compte effectivement connecté |
| Consultation | Liste et détail réservés à ADMIN, filtres rôle/état, tri autorisé, pagination bornée et page vide avec total conservé |
| Modification | Identité, rôle et affectations enregistrés ; retrait des affectations possible ; envoi identique sans invalidation supplémentaire |
| Désactivation | Compte inactif refusé à la connexion et ancienne session rejetée dès la requête suivante |
| Réactivation | Connexion de nouveau possible, mais ancienne session toujours refusée, même si elle n’a pas servi pendant la désactivation |
| Sessions réelles | Scénario avec noyaux de production frais, fichiers/cookies PHP natifs et deux sessions du compte cible ; appels aux véritables API de modification |
| Droits | Anonyme refusé ; gestionnaire et utilisateur refusés en lecture/écriture et sur les URL directes des formulaires et de l’ancienne section administrative |
| CSRF | Écritures API et formulaires refusées sans jeton valable |
| Validation | Courriel unique sans distinction de casse ; nom vide, textes nuls/UTF-8 invalides, mot de passe trop court, rôle inconnu, magasins absents/inactifs/doublons refusés ; aucun enregistrement partiel |
| Champs protégés | `development_only`, `auth_version` et changement de mot de passe non acceptés par le schéma de modification |
| Préservation de l’administration | Auto-désactivation refusée, y compris UUID en majuscules ; retrait du dernier administrateur normal actif refusé ; promotion d’un autre compte puis changement de rôle possible |
| Anonymisation | Identité anonymisée consultable, mais modification et réactivation refusées |
| Idempotence | Rejeu persistant du résultat initial, même après modification ultérieure du compte ; conflit 409 pour un autre contenu ; un compte et un audit de création seulement |
| Confidentialité | Ni mot de passe ni hachage dans réponses, audit ou résultat de rejeu ; audit limité aux noms des champs modifiés ; mot de passe jamais réaffiché après erreur de formulaire |
| Développement | Comptes créés par l’administrateur de test également marqués dev ; connexion dev acceptée et session refusée par un noyau de production |
| Migrations | Installation/rejeu/retour arrière vide valides ; refus de supprimer des résultats de créations existants |

## Contrôle visuel

Liste administrative et formulaire de création observés sur l’application locale avec l’administrateur de test existant. Menu actif, compteurs, filtres, tableau, libellés français et champs masqués corrects. Formulaire observé sur mobile : largeur de contenu égale à celle du document (375 pixels), sans débordement horizontal. Le parcours complet création/modification/désactivation/réactivation des formulaires est exécuté par BrowserKit sans JavaScript, dans un schéma isolé.

Aucun compte supplémentaire n’a été créé dans les données applicatives pour la vérification visuelle. Les données de recette sont annulées par transaction ou supprimées avec le schéma temporaire du test natif ; l’administrateur de démonstration déjà demandé est conservé.

## Commandes

```sh
docker compose --profile test build app web test contract-test
docker compose run --rm test
docker compose run --rm --no-deps test composer audit
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:yaml config
docker compose exec app php bin/console lint:twig templates
```

Les écritures administratives sont sérialisées par verrou PostgreSQL sur les comptes. La recette vérifie les règles et le rejeu ; elle ne constitue pas un test de charge distribué. Les tests généraux d’infrastructure, de stock et d’authentification restent verts.

## Limites du lot

AUTH-03 centralisera les contrôles des ressources métier et magasins. AUTH-04 fournira la réinitialisation du mot de passe ; aucune invitation ou réinitialisation par courriel n’est simulée ici. ADM-01 fournira la consultation de l’audit. La rétention/anonymisation des identités présentes dans `user_creation_requests.response` est à intégrer à RGPD-01. HTTPS et le proxy réel restent dans OPS-02 ; les tests de cookies Secure utilisent un noyau configuré pour HTTPS.
