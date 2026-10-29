# Suppression des articles — CAT-02

La suppression est réservée aux administrateurs et gestionnaires. Les gestionnaires conservent leur fonctionnement par propositions pour les créations et modifications ; ce nouveau droit ne leur donne pas accès à la modification administrative du catalogue.

Depuis Articles, utiliser **Supprimer**, ou ouvrir la fiche puis **Supprimer l’article**. La page de confirmation décrit les effets et permet d’annuler. Une consultation ne modifie rien ; la confirmation exige un jeton CSRF et la version courante de l’article.

L’article est retiré de toutes les listes du catalogue, même s’il reste du stock. Les quantités, magasins, mouvements, consommations, retours, références et codes de lecture sont conservés. Les vues de stocks et les historiques gardent accès à sa fiche. Les retours et les ajustements restent possibles selon les droits existants ; les nouvelles entrées, consommations et transferts sont refusés puisqu’il est inactif. Les codes restent utilisables pour retrouver les retours et ne sont pas réattribués à un autre article.

Cette suppression se distingue de la désactivation existante : la réactivation d’une catégorie ne réaffiche pas les articles supprimés. Une modification ou une proposition ne peut pas réactiver un article supprimé. Aucun effacement physique ni remise à zéro du stock n’est effectué, y compris pour un article sans mouvement. La référence reste réservée.

L’API `DELETE /api/v1/articles/{id}` reçoit `{ "version": 1 }` et retourne la fiche avec `deleted_at` renseigné. Le rejeu sur un article déjà supprimé est sans nouvelle mutation ni trace d’audit supplémentaire. Une version périmée d’un article encore présent est refusée. La suppression écrit `article.deleted` dans l’audit et utilise les mêmes verrous que l’administration pour se sérialiser avec les mouvements de stock.

La migration `Version20261029010000` ajoute uniquement la date de suppression et la contrainte interdisant qu’un article supprimé soit actif. Elle ne supprime ni ne convertit les données existantes. Le retour de schéma est refusé si des articles supprimés sont présents, afin de ne pas perdre cet état.

La nouvelle interface est d’abord à vérifier sur l’aperçu local contenant uniquement des données fictives. La recette ne vaut pas installation sur le serveur réel. Avant installation, conserver une sauvegarde chiffrée récente hors serveur et suivre le déroulement de migration contrôlée du [guide Portainer](installation-portainer.md). La stack Drive est indépendante et ne doit pas être redéployée pour cette modification.
