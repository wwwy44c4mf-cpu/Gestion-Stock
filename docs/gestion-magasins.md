# Gestion des magasins — MAG-01

Le menu **Magasins** affiche les magasins autorisés. Les administrateurs disposent du bouton **Créer un magasin** et de l’action **Modifier**. Gestionnaires et utilisateurs consultent uniquement les magasins actifs auxquels ils sont affectés.

## Création et modification

- Code obligatoire, 40 caractères maximum ; unique sans distinction de casse ni d’espaces périphériques. Un code de magasin désactivé reste réservé. Le code est conservé après création conformément au contrat API.
- Nom obligatoire, 160 caractères maximum. Description facultative, 5 000 caractères maximum ; adresse facultative, 1 000 caractères maximum. Une adresse vide est enregistrée comme absente.
- Statut actif par défaut, modifiable à la création et ensuite. Les champs texte sont validés en UTF-8 ; caractères nuls et valeurs hors limites sont refusés. Les textes saisis sont échappés à l’affichage.
- Les formulaires fonctionnent sans JavaScript et sont protégés par un jeton CSRF. Un double envoi de création reprend le résultat initial ; une même clé avec un contenu différent produit un conflit.

## Archivage et réactivation

Décocher **Magasin actif**, puis enregistrer, désactive le magasin. Cette opération conserve l’identifiant, le code, les stocks, les affectations, les inventaires, les alertes et les mouvements. Aucune suppression physique n’est exposée par l’application. Les clés étrangères interdisent également de supprimer un magasin utilisé par un mouvement.

La désactivation est refusée lorsqu’un inventaire est `in_progress` ou `pending_validation` : il faut le terminer ou l’annuler d’abord. Les inventaires au brouillon ne bloquent pas l’archivage. Modifier le nom, la description ou l’adresse reste possible pendant un inventaire.

Un magasin désactivé reste consultable uniquement par les administrateurs. Toutes les écritures de stock sont refusées ; la réactivation rétablit l’accès des personnes dont les affectations ont été conservées. Le contexte de session est revérifié à chaque requête. Les affectations se gèrent dans **Administration → comptes**.

## API et transactions

`POST /api/v1/stores` et `PATCH /api/v1/stores/{id}` sont réservés à ADMIN, avec cookie de session et en-tête `X-CSRF-Token`. La création exige aussi `Idempotency-Key`. Les lectures déjà livrées dans AUTH-03 restent filtrées côté serveur.

| Réponse | Situation |
|---|---|
| 201 + Location | Création ou rejeu exact du résultat initial |
| 200 | Modification ou absence de changement effectif |
| 403 | Rôle interdit ou jeton CSRF absent/invalide |
| 404 | Magasin inexistant ou identifiant invalide |
| 409 | Code déjà utilisé, clé réutilisée avec un autre contenu, inventaire bloquant |
| 422 | Champs manquants, vides, inconnus ou invalides ; code non modifiable |
| 405 | Suppression non disponible |

La migration `Version20261018130000` doit être appliquée avant l’utilisation des créations. `StoreAdministration` sérialise les mutations administratives avec les verrous `users` puis `stores` et revérifie l’identité dans la transaction. Une création de magasin, son résultat de rejeu et son audit sont atomiques. Les futurs services d’inventaire devront verrouiller leur magasin avant le démarrage ou le changement d’état de l’inventaire, comme les autres mutations de stock : ce protocole prévient une course avec l’archivage.

L’audit conserve l’acteur, le magasin, l’action et la liste des champs modifiés, sans copie des noms, descriptions ou adresses dans son contexte. Une modification sans changement effectif n’ajoute pas d’événement. Ce lot ne crée pas de catalogue ni de solde initial : les entrées et les autres opérations de stock restent dans leurs lots métier.
