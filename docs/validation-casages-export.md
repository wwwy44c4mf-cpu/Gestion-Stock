# Recette locale — Casages et export Excel

Lots : [CAS-01 #111](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/111) et [EXP-01 #112](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/112). Préparés le 12 novembre 2026, sur données fictives. Aucun service de production modifié, aucun courriel réel envoyé.

## Contrôles effectués

- Suite générale : 475 tests PHP, 5 902 assertions réussies, contrôles OpenAPI, parcours métier HTTP et charge de 920 requêtes réussis. Aucun écart de rapprochement ni stock négatif. Rapport : `var/qa/20261112T163011Z-61288`.
- Complément ciblé : sept tests PHP, 60 assertions réussies. Casages facultatifs et indépendants par magasin, absence d’effet sur les stocks/alertes, conflits de version, limites du texte, droits gestionnaires, lecture seule des techniciens y compris hors affectation, CSRF, création d’article avec casage et annulation atomique en cas de magasin invalide.
- Export : vrai ZIP OOXML, 31 articles inclus malgré la pagination habituelle ; bon magasin, absence des casages d’un autre magasin ; protection des codes et textes commençant par `=`, champs vides, quantités numériques, refus des rôles et magasins non autorisés, état réactualisé au prochain clic.
- Lecture indépendante du fichier par openpyxl, puis import et rendu des deux feuilles par l’outil de vérification des tableurs. Les zéros initiaux sont vérifiés dans les cellules du fichier ; le moteur d’aperçu peut les normaliser visuellement à l’import.
- JavaScript : 27 tests réussis du scanner, de la caméra, du décodage et du Worker. Le scan de casage accepte aussi un article désactivé ; la sélection précédente est effacée dès le début d’une recherche pour éviter de montrer un ancien emplacement avec un code inconnu.
- Navigateur local : modification et enregistrement d’un casage, recherche par code, changement de magasin, téléchargement Excel, sorties et retours du technicien en lecture seule. Largeurs 375, 768 et 1 280 pixels, sans débordement de la nouvelle page de gestion des casages. Les tests de caméra sur les appareils physiques restent à effectuer après installation.
- Sauvegarde et restauration : onze tests réussis avec les 24 migrations, casage conservé dans l’archive et restauré, application récupérée, connexion et stock vérifiés sur une installation isolée.

- Recette Portainer isolée : installation des 24 migrations, redéploiement, contrôle de l’accès Cloudflared, sauvegarde chiffrée et restauration réussis. Rapport : `var/portainer/20261112T163945Z-62227`.

## Essai humain sur le Mac

Adresse : `http://127.0.0.1:18791`. Comptes de recette fictifs habituels, mots de passe de recette uniquement.

1. Ouvrir **Stocks → Gérer les casages** et sélectionner **Article fictif 1**. Dans le magasin A, il est rangé « Étagère A — hauteur 1 » ; dans le magasin B, « Étagère B — hauteur 2 ».
2. Modifier la valeur ou l’effacer, puis cliquer sur **Enregistrer le casage**. Aucun mouvement n’est nécessaire.
3. Ouvrir une sortie ou un retour, choisir cet article et changer de magasin : l’indication de rangement doit suivre la sélection.
4. Dans **Magasins**, télécharger le fichier Excel du magasin voulu et contrôler les feuilles **Stock** et **Magasin**.
5. À la création d’un nouvel article, laisser le casage vide ou renseigner un magasin et une case.

La démonstration contient deux magasins, vingt articles et un historique fictif. Le code de démonstration `CASAGE-DEMO-001` retrouve l’article fictif 1 via la saisie manuelle du scanner. Les exemples ne nécessitent aucune donnée réelle.

## État

Validation locale, publication puis installation confirmées par l’exploitant le 12 novembre 2026. Commit installé : `a9d72275fc83e79795fbabde6cdd1ee7818dcbe8`, branche `codex/cas-01-casages-export`, PR #113. Les cinq contrôles GitHub du run 43264075856 sont réussis, dont 482 tests PHP et 5 962 assertions. La migration `Version20261112010000` porte le total à 24.

## Validation de production — 12 novembre 2026

Sauvegarde et copie Drive confirmées par l’exploitant avant maintenance. Migration appliquée, redéploiement réussi, services sains et initialiseurs en code 0. Rapprochement des stocks : `{"consistent":true,"differences":[]}`. Le casage est conservé après rechargement et visible en sortie et en retour. Le fichier Excel est ouvert avec ses deux feuilles, les bonnes quantités et le casage. Issues #111 et #112 clôturées après ces confirmations. Aucun accès direct au serveur ; résultats communiqués par l’exploitant.
