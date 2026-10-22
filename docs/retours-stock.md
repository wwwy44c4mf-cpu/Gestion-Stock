# Retours liés à une consommation — STK-07

Depuis **Consommations → Rendre des articles**, choisir une consommation ayant encore des unités à rendre, puis saisir une quantité entière positive et un commentaire facultatif. La confirmation de consommation donne aussi accès au retour. La liste est paginée par 25 consommations ; les consommations entièrement rendues, annulées ou remplacées sont exclues.

Le magasin et l’article sont imposés par la consommation : aucun autre magasin ni article ne peut être choisi. Exemple : sur 4 unités consommées, rendre 2 puis 2 est possible ; tout retour supplémentaire est refusé. Chaque retour possède son propre mouvement, son auteur, sa date serveur et son lien vers la consommation. La référence métier d’origine est conservée. Les retours restent distincts des consommations et ne sont pas comptés comme de nouvelles consommations.

## Droits

Un utilisateur peut rendre uniquement ses propres articles. Il conserve ce droit et la lecture de ses confirmations même après perte d’affectation au magasin, tant que celui-ci reste actif ; cela ne donne aucun accès au stock actuel de ce magasin. Un administrateur ou gestionnaire peut enregistrer un retour pour un autre bénéficiaire selon son périmètre magasin. Le bénéficiaire d’origine n’a pas besoin d’être affecté au magasin.

Tous les nouveaux retours exigent un magasin actif. Les administrateurs peuvent encore lire les anciennes confirmations d’un magasin désactivé, sans y enregistrer de retour. Les articles désactivés restent retournables : il s’agit de restituer des fournitures déjà sorties. Un administrateur ou gestionnaire peut aussi traiter un retour d’un bénéficiaire devenu inactif ; un compte inactif ne peut pas se connecter lui-même.

## Quantités, erreurs et concurrence

Le cumul des **retours effectifs** ne peut dépasser la quantité consommée. Un retour annulé n’entre plus dans le cumul ; lorsqu’il est corrigé, son remplacement entre à sa place. Les corrections et annulations de retour restent réservées à ADMIN/MANAGER autorisés. Les consommations ayant des retours actifs doivent d’abord voir ces retours annulés avant d’être corrigées ou annulées. Aucun retour nouveau ne vise une ancienne consommation déjà annulée ou corrigée : utiliser son remplacement.

Un inventaire en cours ou en attente de validation bloque le retour. La capacité maximale du stock et la version du solde sont contrôlées. Une erreur annule ensemble le solde, le mouvement, l’audit et l’événement durable. Les futures alertes utiliseront cet événement ; l’écran n’annonce pas d’envoi de courriel.

La consommation est verrouillée avant le calcul du reste à rendre. Retours, corrections et annulations de retours suivent le même ordre de verrous. Deux personnes ne peuvent donc pas rendre simultanément plus d’unités que la consommation, y compris lorsqu’un retour existant est corrigé en parallèle.

Le formulaire fonctionne sans JavaScript et conserve valeurs et clé en cas d’erreur de saisie ou métier. Un double envoi restitue le même mouvement sans nouveau crédit. Un contenu différent avec la même clé est refusé. Le rejeu exige toujours les droits actuels sur la consommation et un magasin actif ; il peut restituer un ancien résultat après archivage de l’article ou démarrage d’un inventaire, puisqu’aucune écriture n’est recréée.

Après succès, une confirmation affiche quantité, magasin d’origine, bénéficiaire de la consommation, opérateur du retour, date et commentaire. La consommation indique les unités rendues et encore à rendre. Le lien vers le stock actuel n’apparaît qu’avec le droit de lecture du magasin. Les confirmations des retours annulés ou corrigés le signalent et donnent accès au remplacement autorisé.

## API

`POST /api/v1/movements/returns` exige une session autorisée, `Content-Type: application/json`, `X-CSRF-Token` et `Idempotency-Key`.

```json
{
  "consumption_id": "11111111-1111-4111-8111-111111111111",
  "quantity": 2,
  "comment": "Fournitures non utilisées"
}
```

Remplacer l’UUID d’exemple par une consommation réelle autorisée. Réponse 201 `MovementResource`, y compris en rejeu. Le type est `return`, la destination est le magasin d’origine, la source est nulle et `related_movement_id` référence la consommation. `Location` pointe vers la confirmation **HTML** `/consommations/retours/{id}`.

Quantités nulles/négatives, fractionnaires, exposants, chaînes JSON numériques, dépassement de borne et champs supplémentaires : 422. Commentaire : 2 000 caractères maximum, texte UTF-8 sans nul binaire. Magasin, article, auteur, bénéficiaire et date fournis par le client sont refusés. Consommation inaccessible ou d’autrui pour USER : 404 ; quantité excessive, consommation compensée, inventaire, capacité dépassée ou clé conflictuelle : 409. CSRF absent/invalide : 403 ; session absente avec CSRF valide : 401.

Aucune nouvelle migration après STK-05 (`Version20261018223000`). L’historique personnel complet reste dans CON-01 et les statistiques dans STAT-01 ; ce lot fournit la sélection des consommations à rendre, les compteurs et les confirmations de retour. [Recette](validation-stk07.md) · [Moteur](moteur-stock.md).

## Conservation des données — RGPD-01

Après anonymisation du bénéficiaire, seuls les administrateurs et gestionnaires autorisés sur le magasin d’origine peuvent enregistrer un retour. Le plafond cumulé et les protections d’inventaire restent applicables. Voir la [conservation des données](conservation-donnees.md).
