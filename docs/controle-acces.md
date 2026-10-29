# Contrôle des accès — AUTH-03

## Règles

La sélection « Tous mes magasins » ou d’un magasin dans l’en-tête est un contexte d’affichage, jamais une autorisation. Le formulaire fonctionne sans JavaScript, par POST avec jeton CSRF. Le choix est conservé en session et revérifié à chaque requête ; une affectation retirée ou un magasin devenu inaccessible efface le choix. Les administrateurs peuvent sélectionner un magasin désactivé pour le consulter.

| Action | ADMIN | MANAGER | USER |
|---|---|---|---|
| Comptes, rôles et affectations | Tous | Non | Non |
| Administration magasins et catalogue | Oui | Non | Non |
| Lecture catalogue | Oui | Oui | Oui |
| Suppression du catalogue, stocks et historique conservés | Oui | Oui | Non |
| Proposition catalogue | Oui | Oui | Non |
| Stocks | Tous, désactivés compris | Actifs affectés | Actifs affectés |
| Saisie de sa consommation | Magasin actif | Actif affecté | Tout magasin actif, même sans affectation |
| Consommation pour autrui | Magasin actif | Actif affecté | Non |
| Entrées, ajustements, transferts et annulations | Magasins actifs | Actifs affectés | Non |
| Inventaires, alertes, seuils | Tous en lecture ; actifs en écriture | Actifs affectés | Non |
| Historique et statistiques de consommation | Tous | Actifs affectés | Ses consommations dans les magasins actifs, affectés ou non |
| Retour / dépôt | Dépôt actif ; origine historique lisible | Dépôt actif affecté | Ses consommations ; tout dépôt actif, même sans affectation |
| Audit | Tous, événements globaux compris | Événements de magasins actifs affectés | Non |
| Messages d’information | Oui | Non | Non |

Les gestionnaires voient leurs propres propositions de catalogue ; l’administrateur voit toutes les propositions. Une mutation de transfert ou son annulation exige l’accès aux **deux** magasins. Un mouvement impliquant un magasin désactivé devient réservé aux administrateurs, même si son autre magasin reste accessible. Les utilisateurs ne peuvent pas ouvrir le détail générique des mouvements : ils utilisent leur historique personnel.

La recherche des bénéficiaires est réservée aux administrateurs et aux gestionnaires affectés à au moins un magasin actif. Elle retourne uniquement `id` et `name` des comptes actifs, de tout rôle et tout magasin. Aucun courriel, rôle, affectation ou profil complet n’est exposé. Les comptes de développement restent invisibles hors de l’environnement `dev`.

Les retours restent liés à des consommations réelles, mais leur destination est le magasin de dépôt choisi. Le parcours par article répartit la quantité sur les consommations les plus anciennes encore retournables du bénéficiaire, sans donner accès à leurs magasins de sortie. Un utilisateur peut retirer et déposer dans tout magasin actif, uniquement pour lui-même ; les périmètres de lecture des stocks et des consommations d’autrui restent conservés. Les gestionnaires restent limités aux dépôts affectés pour leurs opérations. Le parcours détaillé exige aussi la lecture de l’origine ; un administrateur peut traiter une origine désactivée en déposant ailleurs. Voir [RET-02 et le magasin favori](retours-depot-magasin-favori.md).

## Intégration des services métier

- `Permission` est la matrice des rôles ; `AccessIdentity` vérifie en base activité, rôle, version d’authentification, empreinte du mot de passe et restriction de développement.
- `AccessPolicy::assert()` contrôle une cible `AccessTarget` composée d’identifiants uniquement. Magasin, bénéficiaire et propriétaire d’une ressource sont relus en base. Rôle interdit : 403 ; ressource inexistante, identifiant invalide ou hors périmètre : 404, sans distinction.
- `PermissionVoter` raccorde les permissions `APP_*` à Symfony. Permission inconnue, cible manquante ou mauvais type : refus. Les contrôles des menus ne remplacent jamais ceux des services.
- `assertConsumption()` contrôle le magasin puis le bénéficiaire actif ; l’affectation de ce dernier n’est pas requise. USER est limité à lui-même.
- Les scopes SQL `stockScope`, `movementScope`, `consumptionScope`, `resourceScope` et `proposalScope` fournissent condition et paramètres liés. Ils doivent être appliqués dans `WHERE` **avant** compte, pagination, regroupement et somme. Les alias SQL sont validés ; aucun fragment client n’est interpolé.
- Pour les statistiques de consommation, utiliser `consumptionScope(..., Permission::STATISTICS_READ)` ; le scope des stocks ne convient pas aux données personnelles. Les retours statistiques sont filtrés selon leur magasin de dépôt, avec propriété du bénéficiaire pour un utilisateur. Pour le tableau de bord de gestion, vérifier aussi le rôle via `DASHBOARD_READ`.
- Dans les services de mutation, effectuer autorisation et écriture dans la même transaction, après les verrous métier, puis vérifier les autres règles : gel d’inventaire, stock disponible, idempotence, cumul des retours, etc. AUTH-03 ne remplace pas ces invariants du moteur STK-02.

Les API disponibles dans ce lot sont `GET /api/v1/stores`, `GET /api/v1/stores/{id}` et `GET /api/v1/consumption-beneficiaries`. Le [guide API](api.md) décrit les endpoints métier livrés. Les écrans utilisent ces mêmes contrôles. Les données de démonstration restent un chargement explicite réservé au développement ([guide](demonstration.md)).

## Vérification

`AccessControlTest` utilise un schéma PostgreSQL transactionnel isolé : rôles, affectations multiples et révoquées, magasins désactivés, accès direct, stocks inchangés après refus, agrégats filtrés, historique personnel hors affectation, bénéficiaire tiers, inventaires, audit global, propositions, deux magasins d’un transfert, identité périmée ou falsifiée, formulaire de contexte et CSRF. Le schéma est annulé après chaque test.

La permission globale `APP_CATALOG_DELETE` autorise uniquement le retrait d’un article du catalogue. Elle est revérifiée en base après verrouillage et ne donne pas accès aux créations ou modifications administratives. La suppression est possible même si un magasin possède encore du stock ; les règles habituelles de lecture, de retour et d’ajustement restent applicables. Voir [CAT-02](suppression-articles.md).
