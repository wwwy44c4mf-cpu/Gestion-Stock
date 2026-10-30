# Recette locale — Notifications, comptes, magasins et actions de stock

Lots : [ADMIN-04 #106](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/106), [USR-04 #108](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/108), [MAG-02 #107](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/107), [UI-03 #109](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/109).

Code préparé localement le 30 octobre 2026, en complément de la demande de compte USR-03 déjà approuvée visuellement. Validation locale confirmée par l’exploitant ; publication autorisée le 30 octobre 2026. L’installation sur le serveur reste à effectuer. Les issues GitHub servent au suivi.

## Contrôles

- Vérification générale PHP : 475 tests, 5 875 assertions réussis ; quatre contrôles OpenAPI, parcours métier HTTP et charge de 920 requêtes réussis, rapprochement des stocks sans écart ni solde négatif. Rapport : `var/qa/20261030T221610Z-71731`.

- Parcours ciblé des demandes, suppressions et notifications : 17 tests, 140 assertions réussis.
- Vérification finale complémentaire des suppressions, des sessions natives et de l’administration des magasins : 16 tests, 196 assertions réussis. Les contrôles incluent le refus de suppression d’un magasin non vide, les inventaires ouverts, le retrait des sélecteurs et les anciens liens de stock, l’effacement de l’identité, la conservation des mouvements, la protection de soi-même et l’indépendance des lectures entre administrateurs.
- Sauvegarde/restauration : onze tests réussis avec les 23 migrations, uniquement sur des données et clés fictives.
- Recette Portainer complète : réussite de la création des secrets fictifs, de la migration vers 23, du remplacement de l’image PostgreSQL avec données conservées, de la frontière Cloudflared, de la sauvegarde chiffrée et de la restauration avec retraitement avant réouverture. Rapport : `var/portainer/20261030T221255Z-71399`.
- Contrôle visuel par navigateur à 375, 768 et 1 280 pixels : quatre actions de stock de même largeur et de même hauteur, quatre colonnes sur grand écran, deux à largeur intermédiaire, une sur petit écran. Aucun débordement horizontal des nouveaux écrans de notification et de confirmation. Captures : `var/admin04/`.

## Essai humain

Démonstration sur le Mac uniquement : `http://127.0.0.1:18791`. Comptes et données fictifs, aucun envoi de courriel réel. Le compte administrateur de recette habituel peut consulter :

1. Stocks : quatre actions alignées.
2. Administration : pastille initiale de deux notifications et lien Notifications. Ouvrir un dossier retire sa notification de la pastille personnelle, sans l’approuver automatiquement.
3. Comptes : « Compte de démonstration », puis Modifier → Supprimer ce compte.
4. Magasins : « Magasin de démonstration vide », puis Modifier → Supprimer ce magasin. Les magasins A et B contiennent du stock ; leur suppression est refusée.

Les exemples supprimables sont conservés pour la revue humaine, sans suppression automatique lors du contrôle visuel. Les tests réels iPhone et Android restent distincts de la vérification des dimensions dans un navigateur de bureau.

## Installation future

La validation humaine de la démonstration est reçue. Publier la livraison puis effectuer la mise à jour contrôlée dans Portainer. La migration additive `DoctrineMigrations\Version20261030020000` porte le total à 23. Actualiser RELEASE_ID et DATABASE_RELEASE_ID avec la future livraison validée, garder les volumes, puis appliquer la procédure de sauvegarde, maintenance, migration et redéploiement. L’image de base de données doit embarquer la conservation des demandes et des états de lecture.
