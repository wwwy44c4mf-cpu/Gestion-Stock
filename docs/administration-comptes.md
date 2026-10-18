# Administration des comptes — AUTH-02

## Parcours

Un administrateur ouvre **Administration** pour consulter les comptes. La liste propose les filtres rôle/état, le tri par nom, courriel ou date de création, ainsi qu’une pagination. Un utilisateur ou un gestionnaire ne peut accéder ni aux écrans ni aux API correspondants, même avec une URL directe.

**Créer un compte** demande un nom, un courriel, un mot de passe initial de 12 à 128 caractères, un rôle et éventuellement des magasins actifs. Le mot de passe est saisi masqué, haché avec Argon2id, jamais réaffiché ni envoyé par courriel. Sa transmission initiale au titulaire reste manuelle ; la réinitialisation par lien appartient à AUTH-04.

**Modifier** permet de changer l’identité, le rôle, les affectations et l’état actif. Décocher « Compte actif » désactive l’accès ; cocher la case le réactive. Aucun compte n’est supprimé et les historiques restent liés à son identifiant. Un compte anonymisé peut être consulté, mais ne peut être modifié ni réactivé.

Les formulaires fonctionnent sans JavaScript, vérifient le CSRF et ne réaffichent jamais un mot de passe après une erreur. Un double envoi du même formulaire de création utilise la même clé et ne crée pas deux comptes. Les erreurs de courriel déjà utilisé, de magasins absents/inactifs et de droits sont explicites.

Les rôles et affectations sont enregistrés ici conformément au contrat SOC-03. La centralisation des droits pour chaque ressource métier, la sélection du magasin et la recette d’isolation entre magasins restent dans AUTH-03.

## Sessions et protections

Toute modification effective incrémente `users.auth_version` ; un envoi sans changement ne le fait pas. Les sessions antérieures sont refusées à la requête suivante. Une désactivation interdit aussi toute nouvelle connexion. Une réactivation ne rétablit jamais les anciennes sessions, y compris celles qui n’ont pas été utilisées pendant la désactivation. Une modification de son propre compte peut donc demander à l’administrateur de se reconnecter.

L’auto-désactivation est refusée. Le dernier administrateur normal actif ne peut pas être désactivé ni rétrogradé, même s’il existe un administrateur de développement. Les écritures utilisent une transaction et un verrou PostgreSQL sur les comptes pour vérifier cette règle avant la modification et préserver l’unicité des créations concurrentes. Une promotion préalable d’un autre compte permet ensuite le changement de rôle.

Le marqueur `development_only` n’est pas modifiable par les API/formulaires. Les comptes créés par l’administrateur de test héritent de ce marqueur : ils restent utilisables uniquement en `dev` et n’empêchent pas l’initialisation du premier véritable administrateur. Hors développement, ces comptes ne figurent pas dans la liste administrative et ne peuvent pas être lus ou modifiés par ces routes.

## API

| Méthode | Route | Résultat |
|---|---|---|
| GET | `/api/v1/users` | Liste `{ data, meta }`, réservée à ADMIN |
| POST | `/api/v1/users` | Création active, 201 et `Location`, CSRF et `Idempotency-Key` requis |
| GET | `/api/v1/users/{id}` | Consultation `{ data }`, sans mot de passe ni hachage |
| PATCH | `/api/v1/users/{id}` | Modification d’au moins un champ autorisé, 200, avec CSRF |

Entrée de création : `email`, `name`, `password`, `role`, `store_ids`. Modification : `name`, `email`, `active`, `role`, `store_ids`. Le fuseau personnel reste géré sur `/auth/me`. Aucun champ `password`, `auth_version` ou `development_only` n’est accepté en modification administrative. Les champs inconnus sont refusés.

La liste suit les conventions SOC-03 : `page`, `limit` (au plus 100), `sort` parmi `name/email/created_at`, `order` asc/desc, `role` ADMIN/MANAGER/USER, `status` active/inactive. Le tri est complété par l’UUID. Une page vide conserve le total correspondant aux filtres.

Erreurs : 401 si session absente/invalide, 403 si droits/CSRF insuffisants, 404 si compte absent/UUID invalide/hors environnement, 409 si courriel déjà utilisé, protection administrative, compte anonymisé ou conflit d’idempotence, 422 si données invalides. Le corps suit `application/problem+json`.

### Rejeu d’une création

La migration `Version20261018120000` ajoute `user_creation_requests`. La clé est bornée à 8–160 caractères autorisés et isolée par acteur. Compte, affectations, audit et résultat sont persistés dans la même transaction. Un rejeu identique renvoie le résultat JSON initial et la même `Location`, même si le compte a été modifié depuis ; il ne réécrit aucun compte ni audit. Un contenu différent avec la même clé donne 409.

L’empreinte canonique de la requête est protégée par HMAC avec le secret applicatif afin de ne pas stocker une simple empreinte devinable du mot de passe. Seul le hachage Argon2id du compte est conservé ; le corps initial et le mot de passe ne sont jamais stockés dans la table de rejeu. Le résultat contient toutefois l’identité initiale : sa rétention/anonymisation devra être prise en charge par RGPD-01, avec les autres données nominatives. La rotation du secret invalide la vérification des anciennes empreintes : utiliser une nouvelle clé de création après cette opération d’exploitation.

### Audit

Événements append-only : `user.created`, `user.updated`, `user.deactivated`, `user.reactivated`. Ils relient l’administrateur au compte cible et ne conservent dans le contexte que les noms des champs changés, sans ancienne/nouvelle valeur d’identité, mot de passe ou hachage. Un envoi sans changement et un rejeu ne produisent pas d’événement supplémentaire. La consultation du journal reste dans ADM-01.

## Déploiement et vérification

```sh
docker compose --profile test build app web test contract-test
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose run --rm test
docker compose up -d --wait
```

Appliquer la migration avant de servir les nouvelles routes. Le retour arrière refuse de supprimer des résultats de création présents. Les tests isolent leurs données dans des schémas PostgreSQL temporaires ; la recette native utilise de vrais cookies et un noyau de production neuf par requête. Voir [la recette AUTH-02](validation-auth02.md).
