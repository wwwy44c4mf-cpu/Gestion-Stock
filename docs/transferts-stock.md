# Transferts entre magasins — STK-06

Depuis **Stocks → Transférer du stock**, choisir un magasin de départ, un magasin d’arrivée distinct, un article actif et une quantité entière positive. Le motif est facultatif ; s’il est fourni, il ne peut pas être composé uniquement d’espaces. Le magasin d’en-tête est repris comme départ ; depuis une fiche stock, le départ et l’article sont présélectionnés.

Les administrateurs peuvent transférer entre tous les magasins actifs. Les gestionnaires doivent être affectés aux deux magasins. Les utilisateurs simples ne peuvent pas transférer. Les listes ne proposent que les magasins actifs autorisés ; avec moins de deux magasins ou aucun article actif, le formulaire explique la situation et désactive la validation. Le formulaire fonctionne sans JavaScript et conserve les valeurs et la clé lors d’une erreur.

Une seule transaction retire la quantité au départ et l’ajoute à l’arrivée. Exemple : départ 5, arrivée 0, transfert 3 → départ 2, arrivée 3. Il n’y a aucune création ni perte d’unités. Un seul mouvement et un seul identifiant relient les deux variations ; l’audit et l’événement durable sont validés dans cette même transaction. Une erreur annule tout, y compris un solde créé pour l’occasion.

## Contrôles

Le stock de départ doit être suffisant, le solde d’arrivée doit rester au plus à 9 007 199 254 740 991 et les magasins doivent être distincts et actifs. Un inventaire en cours ou en attente de validation sur l’un des magasins bloque tout le transfert. Les droits et états sont revérifiés lors de l’envoi, même si le formulaire était déjà ouvert.

Deux envois identiques avec la même clé restituent le même mouvement sans deuxième débit/crédit. Un contenu différent avec cette clé donne un conflit. Un rejeu peut réussir après désactivation de l’article ou démarrage d’un inventaire, car il ne crée aucune nouvelle écriture ; les droits actuels sur les deux magasins actifs restent exigés.

## Confirmation et historique

Après validation, la confirmation affiche départ, arrivée, quantité, date serveur dans le fuseau du compte, opérateur, motif et identifiant commun. Elle donne accès aux deux fiches de stock et à la correction de quantité STK-05 lorsque les droits d’écriture actuels le permettent. Les transferts corrigés ou annulés sont signalés ; le remplacement corrigé dispose de sa propre confirmation.

Cette confirmation exige la lecture des **deux** magasins : elle ne révèle pas les détails d’un magasin extérieur au périmètre. Un administrateur peut encore la lire après désactivation d’un magasin, sans action de correction ; un gestionnaire ne peut pas lire les magasins désactivés. Le journal local d’un magasin reste consultable sans révéler l’autre extrémité : son API conserve uniquement identifiant commun, type, date et variation locale. Le lien vers la confirmation apparaît seulement si les deux magasins sont lisibles.

## API

`POST /api/v1/movements/transfers` exige une session ADMIN/MANAGER autorisée, `Content-Type: application/json`, `X-CSRF-Token` et `Idempotency-Key`.

```json
{
  "article_id": "11111111-1111-4111-8111-111111111111",
  "source_store_id": "22222222-2222-4222-8222-222222222222",
  "destination_store_id": "33333333-3333-4333-8333-333333333333",
  "quantity": 3,
  "reason": "Rééquilibrage"
}
```

UUID d’exemple à remplacer par les identifiants réels autorisés. Réponse 201 `MovementResource`, y compris en rejeu. `Location` pointe vers la confirmation **HTML** `/stocks/transferts/{id}`, pas vers un détail d’API encore planifié. `reason` est le seul texte facultatif ; référence et commentaire ne font pas partie de ce contrat. Date et auteur sont exclusivement fixés par le serveur.

Quantités décimales, exposants, chaînes numériques JSON, zéro/négatifs, bornes dépassées, champs supplémentaires et magasins identiques : 422. Périmètre inaccessible : 404 ; USER : 403 ; article inactif, stock insuffisant, capacité d’arrivée dépassée, inventaire ou clé conflictuelle : 409. CSRF invalide : 403 ; session absente avec CSRF valide : 401.

Aucune nouvelle migration après STK-05 (`Version20261018223000`). Les événements attendent les futurs traitements d’alertes ; cet écran n’annonce pas d’envoi de courriel. [Moteur](moteur-stock.md) · [Validation](validation-stk06.md).
