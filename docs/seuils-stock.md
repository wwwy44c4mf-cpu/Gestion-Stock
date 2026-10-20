# Seuils personnalisés — ALT-01

Depuis la fiche d’un stock, choisir **Configurer les seuils**. Un administrateur ou un gestionnaire affecté au magasin actif peut enregistrer un seuil bas et un seuil critique propres à cet article dans ce magasin, y compris pour un article désactivé.

Les valeurs sont des entiers positifs ou nuls (maximum 9 007 199 254 740 991). Un champ vide signifie « non configuré » ; zéro reste une valeur distincte et valide. Le seuil critique nécessite un seuil bas et doit lui être inférieur ou égal. Il n’existe aucune valeur globale imposée. Les états du stock utilisent une comparaison inférieure ou égale au seuil.

L’enregistrement ne modifie ni quantité, ni version du solde, ni journal des mouvements. Il reste possible pendant un inventaire. Chaque modification effective est auditée avec les valeurs avant et après ; une répétition identique ne crée aucun audit supplémentaire. Un solde virtuel sans configuration reste virtuel. Une première configuration crée un solde à zéro ; retirer les seuils conserve le solde existant.

Un formulaire ouvert avant une autre modification des seuils est refusé : utiliser « Recharger les seuils actuels ». Un changement de quantité seul n’invalide pas le formulaire.

## API

`PATCH /api/v1/stores/{storeId}/stocks/{articleId}/thresholds`, session et jeton CSRF requis, sans paramètres de recherche. Le corps JSON accepte `low_threshold` (obligatoire, entier ou null) et `critical_threshold` (facultatif, entier ou null). Une valeur critique omise conserve sa valeur actuelle ; null la retire explicitement. Le couple final doit rester cohérent. Réponse : `StockLevelResource`.

Les alertes persistantes et l’envoi de courriels seront développés dans ALT-02 et les lots MAIL. ALT-01 configure les seuils et les rend visibles dans les stocks.
