# Codes des magasins et références dans les alertes

Demandes du 20 novembre 2026, issues #132 (MAG-03) et #133 (NOT-04). Maquette locale avant validation et production.

## Codes des magasins

Un administrateur peut modifier le code d’un magasin existant dans Magasins → Modifier, ou par PATCH /api/v1/stores/{id}. Le code reste obligatoire (40 caractères maximum) et unique sans distinction de casse ou d’espaces périphériques, y compris face aux magasins désactivés ou supprimés. Un doublon renvoie un conflit clair et annule toute la modification. Les gestionnaires et utilisateurs restent exclus de cette administration.

L’identifiant du magasin reste stable ; ni les quantités, ni les mouvements, ni les affectations ou favoris ne changent. L’audit indique la modification du champ code. Les liens et filtres utilisent toujours l’identifiant du magasin.

## Courriels de stock

Les alertes de stock bas et critique affichent la référence et le nom dans leur objet et leurs deux versions texte et HTML. Le récapitulatif quotidien affiche les deux pour chaque article. Les caractères spéciaux sont échappés dans le HTML.

La référence est immuable dans le catalogue : elle est résolue à la préparation du courriel depuis l’identifiant présent dans son lien de stock. Cela couvre aussi les notifications déjà en file, sans réécrire leurs noms, quantités, dates ni contenus enregistrés. Une référence introuvable ou invalide bloque l’envoi plutôt que d’envoyer une alerte incomplète. Les autorisations des destinataires et les contrôles de retrait local restent inchangés.

Aucune migration et aucune modification de configuration SMTP. Aucun courriel réel envoyé pendant la recette. Les exemples utilisent uniquement les données fictives de la maquette.

## Validation

Recette générale : 520 tests PHP / 6 281 assertions, contrôles OpenAPI et 920 requêtes à cinq sessions réussis. Aucun stock négatif ni écart de rapprochement. Rapport : `var/qa/20261120T171500Z-13465`. Parcours mobile à 375 pixels : modification et rétablissement du code, refus de doublon, conservation des identifiants et des stocks, trois aperçus de courriels vérifiés. Les tests couvrent aussi les notifications déjà enregistrées, les références introuvables et l’échappement HTML.

Maquette : http://127.0.0.1:18791. Comptes de recette uniquement. Les aperçus sont conservés localement dans `var/review-20261120/`, sans envoi SMTP. Aucune migration ni publication de code pour ce lot à ce stade.

## Livraison autorisée

Maquette des deux modifications validée par l’exploitant et mise en production autorisée le 21 novembre 2026. Livraison depuis le commit installé 97626c5237bf7fe2678f377a9bb46d02d16c5665. Aucune migration ; conserver DATABASE_RELEASE_ID. Publication et contrôles de livraison avant le redéploiement Portainer, après sauvegarde récente copiée hors serveur.
