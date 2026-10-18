# Contrôle des accès — AUTH-03

## Règles

La sélection « Tous mes magasins » ou d’un magasin dans l’en-tête est un contexte d’affichage, jamais une autorisation. Le formulaire fonctionne sans JavaScript, par POST avec jeton CSRF. Le choix est conservé en session et revérifié à chaque requête ; une affectation retirée ou un magasin devenu inaccessible efface le choix. Les administrateurs peuvent sélectionner un magasin désactivé pour le consulter.

| Action | ADMIN | MANAGER | USER |
|---|---|---|---|
| Comptes, rôles et affectations | Tous | Non | Non |
| Administration magasins et catalogue | Oui | Non | Non |
| Lecture catalogue | Oui | Oui | Oui |
| Proposition catalogue | Oui | Oui | Non |
| Stocks | Tous, désactivés compris | Actifs affectés | Actifs affectés |
| Saisie de sa consommation | Magasin actif | Actif affecté | Actif affecté |
| Consommation pour autrui | Magasin actif | Actif affecté | Non |
| Entrées, ajustements, transferts et annulations | Magasins actifs | Actifs affectés | Non |
| Inventaires, alertes, seuils | Tous en lecture ; actifs en écriture | Actifs affectés | Non |
| Historique et statistiques de consommation | Tous | Actifs affectés | Ses consommations dans les magasins actifs, affectés ou non |
| Retour lié à une consommation | Origine active | Origine active affectée | Ses consommations ; origine active, affectée ou non |
| Audit | Tous, événements globaux compris | Événements de magasins actifs affectés | Non |
| Messages d’information | Oui | Non | Non |

Les gestionnaires voient leurs propres propositions de catalogue ; l’administrateur voit toutes les propositions. Une mutation de transfert ou son annulation exige l’accès aux **deux** magasins. Un mouvement impliquant un magasin désactivé devient réservé aux administrateurs, même si son autre magasin reste accessible. Les utilisateurs ne peuvent pas ouvrir le détail générique des mouvements : ils utilisent leur historique personnel.

La recherche des bénéficiaires est réservée aux administrateurs et aux gestionnaires affectés à au moins un magasin actif. Elle retourne uniquement `id` et `name` des comptes actifs, de tout rôle et tout magasin. Aucun courriel, rôle, affectation ou profil complet n’est exposé. Les comptes de développement restent invisibles hors de l’environnement `dev`.

Les retours doivent référencer une consommation réelle ; son bénéficiaire et son magasin sont lus en base. Le magasin d’origine doit être actif. Un utilisateur peut rendre ses propres articles dans le magasin d’origine actif même après retrait de son affectation. Aucun endpoint de retour n’est livré par AUTH-03 ; la création et les limites quantitatives relèvent de STK-07.

## Intégration des prochains lots

- `Permission` est la matrice des rôles ; `AccessIdentity` vérifie en base activité, rôle, version d’authentification, empreinte du mot de passe et restriction de développement.
- `AccessPolicy::assert()` contrôle une cible `AccessTarget` composée d’identifiants uniquement. Magasin, bénéficiaire et propriétaire d’une ressource sont relus en base. Rôle interdit : 403 ; ressource inexistante, identifiant invalide ou hors périmètre : 404, sans distinction.
- `PermissionVoter` raccorde les permissions `APP_*` à Symfony. Permission inconnue, cible manquante ou mauvais type : refus. Les contrôles des menus ne remplacent jamais ceux des services.
- `assertConsumption()` contrôle le magasin puis le bénéficiaire actif ; l’affectation de ce dernier n’est pas requise. USER est limité à lui-même.
- Les scopes SQL `stockScope`, `movementScope`, `consumptionScope`, `resourceScope` et `proposalScope` fournissent condition et paramètres liés. Ils doivent être appliqués dans `WHERE` **avant** compte, pagination, regroupement et somme. Les alias SQL sont validés ; aucun fragment client n’est interpolé.
- Pour les statistiques de consommation, utiliser `consumptionScope(..., Permission::STATISTICS_READ)` ; le scope des stocks ne convient pas aux données personnelles. Pour le tableau de bord de gestion, vérifier aussi le rôle via `DASHBOARD_READ`.
- Dans les futurs services de mutation, effectuer autorisation et écriture dans la même transaction, après les verrous métier, puis vérifier les autres règles : gel d’inventaire, stock disponible, idempotence, cumul des retours, etc. AUTH-03 ne remplace pas ces invariants du moteur STK-02.

Les API disponibles dans ce lot sont `GET /api/v1/stores`, `GET /api/v1/stores/{id}` et `GET /api/v1/consumption-beneficiaries`. Le contrat et le guide API distinguent les autres endpoints encore prévus. Les écrans métier restent des états d’attente ; leurs futures requêtes devront utiliser ces contrôles. Aucune donnée métier de démonstration n’est ajoutée à la base locale.

## Vérification

`AccessControlTest` utilise un schéma PostgreSQL transactionnel isolé : rôles, affectations multiples et révoquées, magasins désactivés, accès direct, stocks inchangés après refus, agrégats filtrés, historique personnel hors affectation, bénéficiaire tiers, inventaires, audit global, propositions, deux magasins d’un transfert, identité périmée ou falsifiée, formulaire de contexte et CSRF. Le schéma est annulé après chaque test.
