# Recette AUTH-01 — 18 octobre 2026

## Résultat

Version livrée pour revue : [PR #55](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/pull/55), branche `codex/auth-01-authentication`, issue [#5](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/5). Base : SOC-04 intégré sur `main` (`9cb250fbe2c34bef1c004d000d05ee6bf09a8206`).

- **55 tests PHP / 809 assertions réussis**, dans Docker, dont 17 scénarios AUTH : 16 parcours applicatifs et un parcours PHP natif séparé. Les régressions des socles précédents restent couvertes.
- **4 tests indépendants du contrat** réussis : OpenAPI 3.1 officiel, schémas JSON Schema et références locales ; 58 opérations, dont sept disponibles et 51 planifiées.
- Services injectés valides, neuf fichiers YAML et onze modèles Twig valides.
- Audit Composer : aucune vulnérabilité connue signalée.
- Réconciliation des stocks : `consistent: true`, aucune différence.
- Routeur de production : sept opérations API disponibles ; aucune fixture `_test` enregistrée.

## Preuves fonctionnelles

| Exigence | Vérification |
|---|---|
| Connexion par courriel et mot de passe | Formulaire français sans JavaScript et API JSON ; normalisation de casse ; identité lue en base |
| Échec sans divulgation de compte | Même erreur 401 pour compte inconnu, mauvais mot de passe et compte inactif |
| Hachage éprouvé | Création Argon2id ; ancien bcrypt renouvelé après connexion ; session conservée après renouvellement |
| Profil | Lecture de sa propre identité et de ses affectations actives ; fuseau IANA modifiable ; rôle/courriel et champs supplémentaires refusés en écriture |
| Sessions | Identifiant renouvelé ; expiration après 30 minutes d’inactivité et 12 heures absolues ; vérification à la requête suivante |
| Invalidation du compte | Ancienne session refusée après désactivation, changement de mot de passe ou incrément de `auth_version` |
| Déconnexion | POST avec CSRF, réponse API 204, destruction côté serveur et retrait du cookie |
| Ancien cookie | Test PHP natif en environnement de production : ancien cookie anonyme refusé après connexion, ancien cookie authentifié refusé après déconnexion |
| Confidentialité du stockage | Ni mot de passe ni hachage de mot de passe dans le fichier de session natif ou dans le profil |
| Cookies | Nom attendu, HttpOnly, SameSite=Lax ; Secure vérifié sur une requête HTTPS dans un noyau de production |
| CSRF et formats | Rejet des jetons absents et de ceux d’avant connexion ; JSON malformé et champs inattendus refusés ; formulaires profil et déconnexion protégés |
| Tentatives | Cinq échecs par courriel/IP puis refus ; compteurs communs interface/API ; plafond de trente tentatives par IP même en changeant de courriel |
| Audit | Échecs et limitations persistés ; aucun mot de passe, courriel saisi ni IP brute dans leur contexte |
| Initialisation | Premier administrateur créé avec saisie masquée ; hachage Argon2id ; seconde création refusée ; aucun mot de passe par défaut |
| Accès anonyme | Profil API réel : HTTP 401 et problème JSON ; pages privées redirigées vers `/connexion` |

Les tests de parcours utilisent un schéma PostgreSQL transactionnel isolé, annulé après chaque test. Le test natif utilise des noyaux de production frais et de vrais fichiers/cookies PHP ; son schéma indépendant est supprimé dans un bloc `finally`. Aucun compte de test n’est créé dans le schéma applicatif `public`.

## Contrôle de l’interface

Écran de connexion observé dans le navigateur : libellés français, champs courriel/mot de passe, focus visible, absence de sélecteur de magasin avant connexion. Contrôle mobile : largeur de contenu égale à la largeur de fenêtre observée, sans débordement horizontal (375 pixels). Les formulaires profil/connexion/déconnexion sont aussi parcourus par BrowserKit, sans JavaScript ; l’identité contenant du HTML reste échappée.

## Commandes de recette

```sh
docker compose --profile test build app web test contract-test
docker compose run --rm test
docker compose run --rm --no-deps test composer audit
docker compose exec app php bin/console lint:container
docker compose exec app php bin/console lint:yaml config
docker compose exec app php bin/console lint:twig templates
docker compose exec app php bin/console debug:router --format=json
docker compose exec app php bin/console app:stock:reconcile
```

## Portée

L’administration des comptes (AUTH-02), les autorisations par opération/magasin et la sélection du magasin (AUTH-03), la réinitialisation par courriel (AUTH-04) et la consultation du journal (ADM-01) restent à livrer. Le pare-feu impose la connexion aux routes protégées, mais ne prétend pas remplacer les futurs contrôles métier et leurs recettes de stock inter-magasin.

Le cookie Secure est validé avec un noyau HTTPS ; aucun certificat ni reverse proxy de production n’est déployé dans AUTH-01. L’accès local reste en HTTP loopback. Les limites et sessions sont partagées entre processus d’un seul conteneur applicatif et sont réinitialisées à sa recréation ; plusieurs réplicas nécessiteraient un stockage commun. La recette ne constitue pas un audit de sécurité externe.
