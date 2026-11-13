# Casages et export Excel d’un magasin

**Version historique installée le 12 novembre.** La [maquette suivante](maquette-evolutions.md) retire l’export manuel et autorise la création directe des articles par les gestionnaires. Les règles ci-dessous décrivent la livraison antérieure.

Lots [CAS-01 #111](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/111) et [EXP-01 #112](https://github.com/wwwy44c4mf-cpu/Gestion-Stock/issues/112).

## Casage facultatif

Un article peut avoir un seul casage par magasin, par exemple « Étagère A — hauteur 1 ». Un même article peut donc être rangé dans des cases différentes selon le magasin. Le texte est libre, facultatif, sur une ligne, avec un maximum de 160 caractères. Une valeur vide retire le casage.

Les administrateurs peuvent le renseigner lors de la création d’un article. Ensuite, les administrateurs et gestionnaires autorisés peuvent utiliser **Stocks → Gérer les casages**, choisir le magasin, scanner ou sélectionner l’article, saisir son emplacement et cliquer sur **Enregistrer le casage**. Ce bouton est également disponible sur le formulaire d’entrée de stock. Le lien **Modifier le casage** de la fiche de stock permet une modification sans caméra ni JavaScript.

Les gestionnaires restent limités à leurs magasins actifs. La procédure de proposition et de validation des articles reste applicable au catalogue commun. Un article proposé par un gestionnaire peut recevoir ses casages une fois sa création approuvée.

Le casage s’affiche dans les stocks, les sorties et les retours. Dans un retour, c’est celui du magasin de dépôt choisi, même si le matériel vient d’un autre magasin. Un technicien peut lire cette indication pour tout magasin actif où il peut retirer ou déposer du matériel, sans accéder pour autant aux quantités et historiques des magasins non affectés. Il ne peut pas modifier le casage.

L’enregistrement d’un casage ne crée aucun mouvement, aucune quantité et aucune alerte. Il est distinct de la validation d’une entrée. Une correction concurrente est signalée pour éviter d’écraser une modification récente. Le journal conserve l’auteur et l’article de la modification, sans recopier le texte libre du casage.

## Exporter l’état actuel

Dans **Magasins**, le bouton **Exporter en Excel** télécharge le fichier du magasin choisi. Il est aussi proposé dans **Stocks** lorsqu’un magasin précis est sélectionné et sur sa fiche d’administration.

L’export est réservé aux administrateurs et gestionnaires autorisés. Il contient deux feuilles :

- **Stock** : références, noms, catégories, casages, quantités, unité, seuils bas et critiques, disponibilité, état des articles, codes-barres/QR et descriptions.
- **Magasin** : code, nom, état, adresse, description, date et heure de l’extraction, nombre de références et périmètre.

Il s’agit de l’état complet au moment du clic, sans historique, sans identité des utilisateurs et sans limitation à la page ou aux filtres de recherche affichés. Les articles sans mouvement apparaissent avec zéro ; les casages et seuils non renseignés restent vides. Les articles désactivés ou retirés du catalogue restent identifiables pour correspondre au stock conservé. Les références et codes gardent leurs zéros initiaux. Les quantités sont numériques ; les entiers de plus de 15 chiffres sont conservés en texte pour éviter l’arrondi d’Excel.

Le fichier est un véritable `.xlsx`. Les textes sont écrits comme valeurs, jamais exécutés comme formules, liens ou macros. Les fichiers temporaires sont supprimés après génération. Les données sont lues dans un instantané cohérent : un mouvement simultané ne mélange pas les états avant et après son enregistrement.

## Installation et vérification

La migration additive `Version20261112010000` ajoute la table `article_locations`, indépendante des soldes. Elle porte le total à 24 migrations. La mise à jour devra suivre la procédure habituelle de sauvegarde, maintenance, migration et redéploiement ; conserver tous les volumes. Le retour arrière est refusé si des casages ont été enregistrés.

Développement et recette locale préparés le 12 novembre 2026 ; validation humaine locale reçue ; publication puis installation à effectuer. Voir [la recette ciblée](validation-casages-export.md).
