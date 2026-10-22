# Guide administrateur et gestionnaire

## Première configuration métier

La [réinitialisation du mot de passe](reinitialisation-mot-de-passe.md) exige SMTP activé et une URL publique correcte. Elle ne réactive pas un compte désactivé, ne change pas son rôle et n’envoie pas de mot de passe en clair.

1. Installer avec le [README](../README.md) ou la [procédure de production](deploiement-production.md). Appliquer toutes les migrations et créer le premier administrateur normal par saisie masquée ; ne pas utiliser les comptes de démo en production.
2. Créer les **Magasins**, puis les comptes, rôles et affectations dans **Administration**. L’administrateur a accès à tous les magasins ; le gestionnaire seulement aux magasins actifs affectés ; l’utilisateur consomme et consulte son périmètre personnel. [Comptes](administration-comptes.md) · [Permissions](controle-acces.md).
3. Créer catégories et articles du catalogue commun. La référence reste immuable. Les propositions d’un gestionnaire ne modifient pas le catalogue avant validation administrative. Désactiver ou réactiver une catégorie applique le statut à toute sa branche et à tous ses articles. [Catalogue](catalogue.md) · [Propositions](propositions.md).
4. Enregistrer les quantités initiales avec des **Entrées de stock** documentées. Les soldes ne se modifient pas par édition de la base. Configurer les seuils bas et critique par article et magasin : vide signifie non configuré, zéro est une valeur réelle. [Entrées](entrees-stock.md) · [Seuils](seuils-stock.md).

## Gestion quotidienne

- **Entrée** : quantité positive et motif ; la correction saisit la bonne quantité, conserve l’origine et produit des compensations. Un ajustement doit être justifié. [Corrections](corrections-stock.md).
- **Consommation et retour** : choisir le bon bénéficiaire ; les retours restent liés à l’origine et bornés. Après effacement du bénéficiaire ancien, seuls administrateurs et gestionnaires autorisés enregistrent son retour.
- **Transfert** : accès aux deux magasins actifs, opération atomique. Un gestionnaire ne voyant qu’un côté du journal obtient une ligne limitée à son magasin et les détails extérieurs sont masqués. [Transferts](transferts-stock.md) · [Journal](historique-mouvements.md).
- **Inventaire** : préparer, démarrer le gel, saisir toutes les quantités (zéro compris), soumettre puis valider. Le périmètre inclut les articles actifs et les articles désactivés encore en stock. Annuler libère sans ajustement ; valider aligne les soldes et trace les écarts. Terminer ou annuler avant de désactiver le magasin. [Procédure](inventaires.md).
- **Alertes** : configurer les destinataires parmi les administrateurs et gestionnaires du magasin. Un seuil est franchi à quantité inférieure ou égale. Notifications immédiates et récapitulatif à 08 h Europe/Paris, avec rattrapage du jour. L’envoi SMTP nécessite une activation explicite ; la mise en file n’est pas une preuve de livraison. [Alertes](alertes-et-destinataires.md) · [Courriels](courriels.md).
- **Messages** : l’administrateur peut cibler les comptes autorisés ; magasin et rôle combinés sélectionnent leur intersection. Vérifier l’aperçu avant confirmation. [Messages](messages-information.md).

Le **Tableau de bord** et les statistiques respectent les droits ; une référence en rupture dans deux magasins compte deux ruptures. L’**Audit** est en lecture seule, global pour l’administrateur et limité aux magasins affectés pour le gestionnaire. [Indicateurs](tableau-de-bord.md) · [Audit](journal-audit.md).

## Exploitation du serveur

Le rôle ADMIN dans l’application ne donne pas accès au serveur. L’opérateur Docker doit préparer HTTPS, SMTP, sauvegardes, supervision et traitement de conservation. Les procédures techniques sont réunies dans [la fiche de livraison](livraison.md) et [l’architecture](architecture.md). Une copie quotidienne uniquement sur le serveur ne protège pas de sa perte : la destination extérieure reste à configurer et tester.

La règle de conservation efface les liens nominatifs et champs libres des actions après un an, avec exceptions temporaires pour les dossiers ouverts ; l’identité d’un compte désactivé attend un an et la clôture de ses dossiers. Les sauvegardes gardent 90 jours ; une restauration est bloquée jusqu’au retraitement. [Règles et commande de simulation](conservation-donnees.md).

Avant d’ouvrir le service, renseigner les responsables et les contrôles de [livraison](livraison.md). En cas de panne : [diagnostic](supervision-diagnostic.md), puis [restauration](sauvegardes-restauration.md) si nécessaire. Ne pas supprimer de volume pour résoudre un problème de connexion.
