# Suppression définitive des articles — ART-04

Fonction suivie par [#114](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/114). Elle concerne les vrais articles comme les articles de test. La suppression ordinaire du catalogue reste disponible pour retirer une fiche en conservant ses stocks et ses historiques.

## Droits

Les administrateurs peuvent supprimer définitivement un article dans tous les magasins. Un gestionnaire peut le faire uniquement si **tous les magasins concernés sont actifs et lui sont affectés**. Le périmètre comprend les soldes à zéro, les mouvements historiques dans les deux magasins d’un transfert, les casages et les lignes d’anciens inventaires, même sans solde physique. Les magasins désactivés ou supprimés restent réservés aux administrateurs.

La liste filtre les articles avant sa limite de recherche. Un accès direct à un article hors périmètre est refusé avant affichage des quantités et magasins. L’affectation est revérifiée dans la transaction de suppression : un retrait de droits ou l’apparition d’un autre magasin depuis le récapitulatif bloque l’opération. Les utilisateurs ordinaires n’ont pas accès à cette fonction.

## Parcours et avertissement

Depuis **Stocks** ou la fiche d’un article, ouvrir **Suppression définitive des articles**, rechercher puis choisir **Vérifier avant d’effacer**. Les administrateurs disposent aussi d’un lien depuis Administration. Un article retiré du catalogue reste accessible à la recherche de suppression définitive.

Le récapitulatif indique les magasins et quantités, mouvements, alertes, lignes d’inventaire et propositions concernés. L’effacement porte sur **un article à la fois, dans tous ses magasins autorisés**, avec sa fiche, ses codes-barres/QR, ses casages, ses seuils et ses historiques : entrées, sorties, retours, transferts et corrections.

Un avertissement visible avant le formulaire précise : **« Attention : suppression définitive et irréversible. La fiche de cet article, tous ses stocks et tout son historique seront effacés dans tous les magasins concernés, même s’il s’agit d’un article réel et qu’il reste du matériel en stock. »**

Il faut recopier exactement la référence et cocher une confirmation explicite de suppression définitive. Aucune attestation de données fictives n’est demandée. Le formulaire est valable quinze minutes. Si les données ont changé, il faut vérifier le nouveau récapitulatif et confirmer à nouveau. Un inventaire ou une proposition encore ouvert doit d’abord être terminé ou annulé/traité.

Les autres articles, magasins et comptes restent conservés. Dans un inventaire terminé partagé, seules les lignes et références d’ajustement de l’article effacé sont retirées. Un inventaire terminé devenu vide est retiré. Dans un récapitulatif d’alertes partagé, les autres articles restent présents ; un courriel en attente devenu vide est retiré de la file. Les marqueurs quotidiens empêchent de générer à nouveau les rappels déjà traités.

L’opération ne modifie ni les courriels déjà envoyés ni les anciennes sauvegardes chiffrées ; ils suivent leur conservation habituelle. Une trace administrative minimale conserve l’auteur, la date et les nombres de lignes effacées, sans recopier l’identité de l’article.

## Contrôles techniques et installation

Identité et rôle relus en base, périmètre complet des magasins vérifié avant lecture puis sous verrous, jeton CSRF, référence exacte, confirmation explicite et récapitulatif signé lié à l’auteur et à l’état des données.

Contrôles et effacement transactionnels. Les protections d’immutabilité sont suspendues uniquement sur les tables fixes nécessaires, derrière des verrous exclusifs, et réactivées avant validation. Une erreur ou un arrêt annule l’effacement et la suspension des protections. Aucun paramètre de session ne permet une mutation générale de l’historique ; les clés étrangères restent actives.

Aucune nouvelle migration : total conservé à 24. Aucun compte superutilisateur ni accès Docker ajouté à l’application. Développement sur données fictives uniquement, dans la maquette locale regroupant les modifications demandées. Validation de cette maquette complète avant publication et production. Voir [la recette](validation-suppression-definitive.md).
