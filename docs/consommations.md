# Consommations — STK-04

Depuis **Consommations** ou **Stocks → Enregistrer une consommation**, choisir un magasin et un article actifs, puis une quantité entière strictement positive. La référence d’intervention, chantier ou client et le commentaire sont facultatifs. Le magasin sélectionné dans l’en-tête est repris s’il autorise l’écriture ; depuis une fiche stock, magasin et article sont présélectionnés.

Un utilisateur enregistre pour lui-même. Un administrateur ou gestionnaire choisit tout compte actif comme bénéficiaire, même sans affectation de ce bénéficiaire au magasin. La liste affiche uniquement les noms ; les comptes désactivés, anonymisés et comptes réservés au développement hors environnement dev sont exclus. Les droits de l’opérateur sur le magasin sont toujours contrôlés côté serveur : magasin actif, affectation obligatoire pour MANAGER et USER. Sans magasin autorisé ou article actif, l’écran explique la situation et désactive la validation.

Après validation, une confirmation distingue le technicien bénéficiaire et l’opérateur, la quantité retirée, le magasin et la date serveur affichée dans le fuseau du compte. Référence et commentaire sont conservés et échappés. Le bénéficiaire peut consulter cette confirmation personnelle après perte d’affectation, tant que le magasin reste actif ; elle ne révèle pas le stock actuel. Le lien vers le stock n’apparaît qu’avec le droit de lecture actuel. Un magasin désactivé n’est consultable que par un administrateur. Les gestionnaires voient les confirmations de leurs magasins autorisés.

## Garanties

Le formulaire fonctionne sans JavaScript et conserve les valeurs et la clé de rejeu en cas d’erreur métier ou de quantité. Un double envoi ne débite pas deux fois le stock. La même clé avec un contenu différent donne un conflit. Un rejeu restitue le mouvement initial, même si son bénéficiaire a été désactivé depuis ; les droits actuels de l’opérateur restent exigés.

Stock insuffisant, article désactivé et inventaire en cours ou en attente de validation empêchent toute nouvelle sortie. Le moteur STK-02 écrit ensemble solde, mouvement, audit et événement durable ; une erreur annule tout. Auteur et date sont fixés par le serveur. Les événements sont traités par le [cycle des alertes](cycle-alertes.md) ; la confirmation de stock n’est pas une preuve de livraison de courriel.

L’historique personnel complet et les statistiques sont livrés par CON-01 et STAT-01. La confirmation indique les unités rendues et encore à rendre. Les corrections sont disponibles dans STK-05 pour ADMIN/MANAGER autorisés ; les retours partiels ou totaux sont disponibles dans STK-07 via « Rendre des articles ». La confirmation signale les consommations corrigées ou annulées. Aucun mouvement enregistré n’est modifié en place.

## API

`POST /api/v1/movements/consumptions` exige une session autorisée, `Content-Type: application/json`, `X-CSRF-Token` et `Idempotency-Key`. Corps `ConsumptionCreate` :

```json
{
  "article_id": "11111111-1111-4111-8111-111111111111",
  "store_id": "22222222-2222-4222-8222-222222222222",
  "quantity": 4,
  "reference": "Intervention exemple",
  "comment": "Fournitures utilisées"
}
```

Remplacer ces UUID d’exemple par les identifiants réels autorisés. Sans `consumer_id`, le bénéficiaire est l’opérateur ; ADMIN/MANAGER peuvent fournir l’UUID d’un autre bénéficiaire actif. Un USER qui impose un tiers reçoit 403. Réponse 201 `MovementResource`, y compris lors d’un rejeu ; `Location` pointe vers la confirmation HTML `/consommations/{id}`, pas vers une API de détail.

Quantités nulles, négatives, décimales, exposants, chaînes JSON numériques, champs inconnus et auteur/date fournis sont refusés (422). Référence : 255 caractères maximum ; commentaire : 2 000. Un magasin inaccessible donne 404 ; stock insuffisant, article inactif, inventaire bloquant et contenu différent avec la même clé donnent 409. CSRF absent ou invalide : 403 ; session absente avec CSRF valide : 401.

Aucune migration supplémentaire après STK-02 (`Version20261018200000`). [Moteur](moteur-stock.md) · [Validation](validation-stk04.md).
