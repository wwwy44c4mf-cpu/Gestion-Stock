# Suppression d’un magasin

Issue [MAG-02 #107](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/107).

Seul un administrateur peut supprimer un magasin, depuis sa page de modification. Une page dédiée explique les conséquences et demande une confirmation explicite. Les gestionnaires ne reçoivent pas ce droit.

La suppression est refusée s’il reste une quantité non nulle dans un seul article, y compris un article désactivé ou retiré du catalogue. Transférer ou sortir le matériel avant de réessayer. Tous les inventaires doivent être terminés ou annulés, y compris les brouillons.

Une fois supprimé, le magasin ne figure plus dans les listes de magasins, dans les sélecteurs ni dans les stocks et indicateurs courants. Les affectations et favoris sont retirés. Il ne peut pas être réactivé. Son code reste réservé et ses données techniques, mouvements et inventaires sont conservés pour l’historique accessible aux administrateurs. Aucun mouvement, aucune quantité et aucune date historique ne sont supprimés.

Les écritures de stock, l’administration et la suppression partagent des verrous compatibles avec un ordre commun : une entrée simultanée ne peut pas survenir entre le contrôle du stock et la suppression. Une seconde demande de suppression ne répète pas l’action.

Migration complémentaire : `DoctrineMigrations\Version20261030020000`, total courant 23. Recette locale avant publication. Ne supprimer aucun volume Docker pour retirer un magasin.
