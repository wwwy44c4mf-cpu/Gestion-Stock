# Validation locale de la maquette — 13 novembre 2026

Périmètre et décisions : [maquette des évolutions](maquette-evolutions.md). Code non publié, production inchangée.

## Vérifications réalisées

- Création directe d’article par le gestionnaire, sans élargir les permissions de modification et de gestion des catégories.
- Suppression définitive : confirmation explicite, droits administrateur/gestionnaire, contrôle de tous les magasins concernés, nettoyage atomique des références, stocks et historiques. Aucun essai sur les données réelles.
- Retour sans consommation d’origine : formulaire réel, stock du magasin choisi augmenté, rejeu sans doublon, absence de consommation inventée.
- Commande : utilisateur en lecture seule, gestionnaire limité à ses magasins, retrait du signal après entrée ou ajustement positif.
- Outils : copies séparées, emprunt atomique, indisponibilité d’un exemplaire déjà sorti, retour de ses propres outils au magasin d’origine, refus d’une ancienne confirmation sur un nouvel emprunt.
- Tourets : références individuelles, mètres entiers, utilisation partielle, rejeu sans double déduction, dépassement refusé et diminution du nombre de tourets lors de l’épuisement.
- Conservation : anonymisation des emprunts terminés et du journal de câble sans changer les longueurs ; identité conservée pendant un emprunt ouvert, puis libérée lorsqu’elle est échue.
- Sauvegarde/restauration : REC-15 et REC-16 validés, 25 migrations, accès et stock contrôlés après restauration et recréation des conteneurs.
- Navigateur : écrans outillage, tourets, retour sans origine et stocks contrôlés en 375, 768 et 1 280 pixels ; création gestionnaire, scanner d’ajustement, navigation utilisateur et retrait de l’export vérifiés. Captures et résultat dans `var/maquette/` (données fictives uniquement).

## Essai de l’exploitant

1. Ouvrir http://127.0.0.1:18791 sur le Mac avec Docker démarré.
2. Tester le compte utilisateur, puis gestionnaire ou administrateur décrits dans le cadrage.
3. Vérifier les sorties, le formulaire unique de retour, les outils et les longueurs de câble.
4. Vérifier les articles commandés et les actions de gestion des articles.
5. Valider la maquette complète avant publication ; l’import du fichier Excel existant reste la dernière étape.

La caméra réelle et ses autorisations seront à contrôler sur iPhone (Safari, Brave) et Android (Firefox, Edge). Le test automatisé de navigateur ne remplace pas ces essais matériels. Aucun accès à la caméra physique n’a été simulé comme validé.

## Installation ultérieure

La migration `Version20261113010000` sera nécessaire. Le script opérateur de conservation change également : sa livraison devra utiliser la nouvelle image de base de données correspondant au futur code publié. Aucun identifiant de livraison n’est fixé avant validation et publication. Les anciennes images de production restent utilisées pour l’instant.

## Résultats enregistrés

- Recette générale : **505 tests PHP, 6 087 assertions**, quatre validations de contrat OpenAPI, scénarios métier HTTP réussis, 920 requêtes mesurées avec cinq sessions, zéro écart de rapprochement et zéro solde négatif. Rapport : `var/qa/20261113T165502Z-75696/`.
- Contrôles complémentaires après la dernière protection de catégorie : **37 tests, 405 assertions** (maquette CA, catalogue et propositions), dont suppression de tourets et de réintégrations, et refus de convertir un article avec historique vers un autre mode de suivi.
- Contrôles de conservation ciblés : 23 tests, 164 assertions. Sauvegarde et restauration isolées : REC-15/16 réussis en 57 secondes.
- Vérification des liens de documentation : 108 documents.

## Retour groupé de l’outillage

Le 13 novembre, sur demande de l’exploitant : regroupement de la liste par emprunteur et remplacement des boutons individuels par « Rendre les outils ». Retour atomique de tous les outils affichés pour cette personne dans le magasin sélectionné. Les autres magasins et emprunteurs sont préservés ; une ancienne liste ne peut pas rendre un nouvel emprunt.

Contrôle de cette modification : 13 tests ciblés réussis, 71 assertions ; vérification du bouton unique et de la présentation aux largeurs 375 et 1 280 pixels.

## Retour unifié

Un seul formulaire « Retour » accepte les dépôts avec ou sans sortie d’origine. Les unités sont rattachées aux consommations disponibles du bénéficiaire ; le reste est tracé comme réintégration sans consommation fictive. Les deux parties sont atomiques et rejouables sans doublon. Le commentaire est conservé aussi sur la partie sans origine. L’ancienne adresse du formulaire séparé redirige vers le formulaire unique.

Validation du retour unifié : 41 tests ciblés / 274 assertions réussis (maquette, retours et suppression). Formulaire unique, scanner et redirection de l’ancienne adresse contrôlés au navigateur en 375 et 1 280 pixels. Aucune publication de code ni mise en production.

## Accord de livraison

Maquette validée par l’exploitant le 13 novembre 2026 : « c’est bon, tu peux mettre en production ». Livraison en préparation ; aucun accès direct au serveur Home Assistant. Le redéploiement et sa vérification finale nécessitent les opérations de l’exploitant dans Portainer.
