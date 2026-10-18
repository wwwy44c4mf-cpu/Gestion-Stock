# Entrées et réapprovisionnements — STK-03

Depuis **Stocks → Enregistrer une entrée**, un administrateur ou un gestionnaire choisit un magasin et un article actifs, saisit la quantité reçue et le motif, puis valide. La référence est facultative, par exemple un numéro de bon de livraison. Les quantités sont des unités entières strictement positives.

Le magasin sélectionné dans l’en-tête est repris par défaut s’il autorise l’écriture. Le bouton de la fiche stock présélectionne le magasin et l’article de cette fiche. Les options de magasin sont limitées aux droits actuels ; même un administrateur ne peut pas enregistrer une entrée dans un magasin désactivé. Un formulaire sans magasin autorisé ou sans article actif explique la situation et désactive son bouton de validation.

Après succès, le formulaire redirige vers le détail du stock avec un message de confirmation. La quantité et le journal local sont actualisés. Le formulaire fonctionne sans JavaScript. Il conserve ses valeurs et sa clé lors d’une erreur de saisie ou d’un conflit métier ; les textes sont échappés. Un inventaire en cours ou en attente de validation bloque l’entrée jusqu’à sa clôture ou son annulation.

## Double envoi et traçabilité

Le formulaire conserve une clé par nouvelle entrée. Le même envoi répété restitue le même mouvement sans crédit supplémentaire. Le rejeu peut encore réussir après archivage ultérieur de l’article, car aucun nouveau mouvement n’est créé ; les droits actuels de l’opérateur restent obligatoires. Modifier une demande déjà validée en gardant sa clé donne un conflit, sans nouvelle écriture. Le lien « Ouvrir un nouveau formulaire » permet de commencer une nouvelle entrée distincte.

Le serveur fixe l’opérateur et la date UTC. Aucun auteur ni date fournis par le client ne sont acceptés. Le moteur STK-02 valide ensemble solde, mouvement, audit et événement durable. Il ne copie pas le motif ou la référence dans l’audit. L’événement de `stock_change_events` attend le consommateur des futurs lots d’alertes ; cet écran n’annonce pas l’envoi d’un courriel.

## API

`POST /api/v1/movements/entries` exige une session ADMIN/MANAGER autorisée, `Content-Type: application/json`, `X-CSRF-Token` et une clé `Idempotency-Key` conforme. Le corps suit `EntryCreate` :

```json
{
  "article_id": "11111111-1111-4111-8111-111111111111",
  "store_id": "22222222-2222-4222-8222-222222222222",
  "quantity": 10,
  "reason": "Réception fournisseur",
  "reference": "BL-EXEMPLE"
}
```

Ces UUID sont des exemples de format à remplacer par les identifiants réels autorisés. Une réponse 201 contient `MovementResource`, y compris lors d’un rejeu ; la lecture du solde est disponible par l’API Stocks. Aucun en-tête Location vers une route de mouvement encore planifiée n’est annoncé.

Les champs supplémentaires, paramètres de recherche sur l’écriture, nombres décimaux ou avec exposant, quantité nulle/négative, motif vide et référence dépassant 255 caractères sont refusés. Un magasin inaccessible renvoie 404 ; un article désactivé, un inventaire bloquant ou une clé réutilisée pour un autre contenu donnent 409. Un USER reçoit 403. Sans session, un jeton CSRF valide ne dispense pas de l’authentification (401) ; un jeton absent ou invalide est lui-même refusé (403).

Aucune nouvelle migration n’est nécessaire après STK-02 (`Version20261018200000`). [Moteur et garanties](moteur-stock.md) · [Recette](validation-stk03.md).

Une entrée enregistrée peut désormais être rectifiée depuis sa fiche stock avec **Corriger** (STK-05). Saisir directement la bonne quantité et le motif ; l’origine reste conservée. [Corrections](corrections-stock.md).
