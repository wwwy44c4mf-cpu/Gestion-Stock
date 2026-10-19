# Ajustements et corrections — STK-05

## Corriger une saisie

Ouvrir **Stocks**, choisir l’article et le magasin, puis cliquer sur **Corriger** dans l’historique local. Saisir la **quantité correcte**, pas la différence, et un motif obligatoire. Exemple : une entrée enregistrée à 10 devait être de 8 ; saisir 8 retire 2 unités du stock. Cela fonctionne aussi si une partie de l’entrée a déjà été consommée : seul le solde final est contrôlé, sans exiger artificiellement les 10 unités d’origine.

L’opération conserve l’original, crée un contre-mouvement exact et un remplacement à la bonne quantité, avec un lien immuable entre les trois. Le type, l’article, le sens, les magasins, le bénéficiaire, la référence et le commentaire restent ceux de l’origine. L’auteur des nouveaux mouvements et leur date sont fixés par le serveur. Pour un ajustement négatif, la quantité correcte est la valeur absolue ; son sens de retrait reste conservé. Pour changer le sens, annuler puis enregistrer un ajustement distinct.

Pour supprimer entièrement l’effet d’un mouvement, suivre **Annuler entièrement ce mouvement**, saisir un motif puis confirmer. La consultation de ce formulaire n’annule rien. Une correction doit avoir une quantité positive différente de l’original ; zéro passe par l’annulation. Après succès, retour au stock actualisé avec une confirmation. Les formulaires fonctionnent sans JavaScript.

Un mouvement déjà annulé/corrigé ne peut pas l’être une seconde fois ; le remplacement peut, lui, recevoir une nouvelle correction tracée. L’historique affiche « Corrigé » ou « Annulé ». Une confirmation personnelle de consommation signale aussi son annulation ou sa correction, avec un lien vers la consommation corrigée autorisée.

## Ajuster un écart

Depuis **Stocks → Ajuster le stock**, choisir le magasin, l’article et une variation entière signée non nulle : `2` ajoute deux unités ; `-2` retire deux unités. Le motif est obligatoire. Les articles désactivés restent sélectionnables pour rectifier un solde résiduel. Pour une erreur de saisie identifiable, préférer la correction liée au mouvement d’origine.

## Droits et blocages

- ADMIN et MANAGER uniquement ; le gestionnaire doit disposer de tous les magasins concernés. Un transfert exige les droits sur ses deux extrémités, même depuis un historique local.
- Tous les magasins doivent être actifs. Un inventaire en cours ou en attente de validation bloque ajustements, corrections et annulations. Les ajustements liés à un inventaire clôturé ne se corrigent pas ici ; un nouvel écart peut faire l’objet d’un ajustement distinct justifié.
- Aucun solde final négatif ou supérieur à 9 007 199 254 740 991. La correction applique la différence nette à chaque magasin, sous verrou, avant validation commune du journal, des soldes, de l’audit et des événements.
- Les contre-mouvements eux-mêmes ne sont pas annulables. Une consommation avec des retours encore actifs doit d’abord voir ses retours annulés. La correction d’un retour garde la consommation et le magasin d’origine et ne peut dépasser le reste retournable. Les nouveaux retours sont disponibles dans STK-07, avec le même verrou sur la consommation d’origine.
- Une consommation remplacée exige encore un article et un bénéficiaire actifs ; son annulation reste possible après leur désactivation, si les magasins et le stock le permettent. Les ajustements et autres corrections historiques peuvent porter sur un article désactivé.

Les droits et les états sont vérifiés lors de l’envoi, y compris après ouverture d’un formulaire. Une panne annule la transaction entière. Les valeurs et la clé du formulaire sont conservées lors d’une erreur de saisie. Un envoi répété restitue le même résultat sans nouveau mouvement ; changer le contenu avec la même clé donne un conflit. Les événements durables attendent les futurs traitements d’alertes.

## API

Session ADMIN/MANAGER, jeton `X-CSRF-Token`, clé `Idempotency-Key` et `Content-Type: application/json` requis. Auteur/date et champs supplémentaires refusés.

| Route | Corps | Réponse 201 |
|---|---|---|
| `POST /api/v1/movements/adjustments` | `article_id`, `store_id`, `quantity` signé, `reason` | `MovementResource` |
| `POST /api/v1/movements/{id}/correct` | `quantity` correcte positive, `reason` | `MovementCorrectionResource` : identifiant d’origine, `reversal` et `replacement` |
| `POST /api/v1/movements/{id}/reverse` | `reason` | `MovementResource` du contre-mouvement |

Motif de 1 à 2 000 caractères après contrôle de non-vacuité ; quantités JSON entières, sans fraction ni exposant. `quantity` ne doit pas être une chaîne numérique. Champs/quantités invalides : 422 ; périmètre inaccessible : 404 ; USER : 403 ; stock insuffisant, inventaire, origine déjà corrigée ou clé conflictuelle : 409. Aucun Location vers un détail d’API encore planifié.

Le rejeu exige toujours les droits actuels sur tous les magasins actifs. Il restitue l’attribution initiale sans recréer de mouvement, même après désactivation ultérieure d’un bénéficiaire ou démarrage d’un inventaire. Le journal local API conserve sa projection limitée sans détails d’autres magasins ; les actions HTML ne sont offertes qu’avec le périmètre complet.

## Migration et exploitation

Appliquer `Version20261018223000` avant de déployer les nouvelles pages :

```sh
docker compose run --rm --no-deps app php bin/console doctrine:migrations:migrate --no-interaction
docker compose up -d app web worker scheduler
```

La migration ajoute `movement_corrections` et ses contraintes : liens UUID, paire cohérente, unicité par origine et immutabilité. Les anciennes écritures restent intactes. Le retour arrière refuse de supprimer des corrections existantes ; restaurer une sauvegarde selon la procédure d’exploitation si nécessaire.

Les futures statistiques devront exclure les consommations annulées et remplacées pour compter les consommations effectives ; le solde se réconcilie avec tous les mouvements, contre-mouvements compris. [Moteur](moteur-stock.md) · [Recette](validation-stk05.md).
